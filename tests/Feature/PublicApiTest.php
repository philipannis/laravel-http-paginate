<?php

namespace PhilipAnnis\HttpPaginate\Tests\Feature;

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\LazyCollection;
use InvalidArgumentException;
use PhilipAnnis\HttpPaginate\HttpPaginateServiceProvider;
use PhilipAnnis\HttpPaginate\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;

/**
 * Verify the single pagination entry point through Laravel's public HTTP API.
 */
final class PublicApiTest extends TestCase
{
    /**
     * The shared fake endpoint used by public API tests.
     */
    private const API_URL = 'https://api.example.test/users';

    /**
     * Confirm facade calls return Laravel's native collection without sending a request.
     */
    public function test_facade_returns_a_native_lazy_collection(): void
    {
        // Install a fake before creating the lazy collection.
        Http::fake();

        // Invoke the macro directly through Laravel's facade.
        $users = Http::paginate(self::API_URL);

        // Require the native class and verify that creation does not reach the transport.
        self::assertSame(LazyCollection::class, $users::class);
        Http::assertNothingSent();
    }

    /**
     * Confirm named arguments work after ordinary request configuration.
     */
    public function test_configured_request_accepts_named_arguments(): void
    {
        // Provide one nested item page for a collection bounded by its page limit.
        Http::fake(['*' => Http::response(['response' => ['items' => [['id' => 1]]]])]);

        // Supply named arguments out of signature order on an authenticated request.
        $users = Http::withToken('api-token')->paginate(
            self::API_URL,
            items: 'response.items',
            limit: 1,
            page: 'page_number',
        );

        // Verify the selected item path, query parameter, authentication, and request count.
        self::assertSame([['id' => 1]], $users->all());
        Http::assertSent(static fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer api-token')
            && $request->url() === self::API_URL.'?page_number=1');
        Http::assertSentCount(1);
    }

    /**
     * Confirm boot adds exactly one PendingRequest macro and no facade-level macro.
     */
    public function test_provider_registers_only_the_paginate_macro(): void
    {
        // Retain unrelated application macros while isolating the registration under test.
        $registry = new ReflectionProperty(PendingRequest::class, 'macros');
        $original = $registry->getValue();

        // Exercise repeated provider boot without retaining changes to the global registry.
        try {
            // Boot twice to verify registration remains idempotent.
            PendingRequest::flushMacros();
            $provider = new HttpPaginateServiceProvider($this->app);
            $provider->boot();
            $provider->boot();

            // Require exactly one macro on the request builder.
            self::assertSame(['paginate'], array_keys($registry->getValue()));
            self::assertFalse(Factory::hasMacro('paginate'));
        } finally {
            // Restore the registry for other application tests.
            $registry->setValue(null, $original);
        }
    }

    /**
     * Confirm conflicting strategies fail before the collection can execute.
     *
     * @param  array<string, string>  $options  The conflicting strategy arguments.
     */
    #[DataProvider('conflictingStrategies')]
    public function test_conflicting_strategies_are_rejected(array $options): void
    {
        // Expect immediate validation without a transport call.
        Http::fake();
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('HTTP paginate accepts only one of page, cursor, or next.');

        // Check the request count even when argument validation throws.
        try {
            // Attempt the incompatible named strategy combination.
            Http::paginate(self::API_URL, ...$options);
        } finally {
            // Keep validation independent of HTTP execution.
            Http::assertNothingSent();
        }
    }

    /**
     * Provide every unsupported strategy combination.
     *
     * @return iterable<string, array{array<string, string>}> Conflicting named arguments.
     */
    public static function conflictingStrategies(): iterable
    {
        // Reject pairs and the complete set rather than silently preferring one strategy.
        yield 'page and cursor' => [['page' => 'page', 'cursor' => 'meta.next_cursor']];
        yield 'page and next' => [['page' => 'page', 'next' => 'links.next']];
        yield 'cursor and next' => [['cursor' => 'meta.next_cursor', 'next' => 'links.next']];
        yield 'all strategies' => [['page' => 'page', 'cursor' => 'meta.next_cursor', 'next' => 'header']];
    }

    /**
     * Confirm blank names cannot silently select a different strategy or item path.
     *
     * @param  array<string, string>  $options  The invalid named argument.
     */
    #[DataProvider('blankNames')]
    public function test_blank_names_are_rejected(array $options): void
    {
        // Require argument validation before any collection is returned.
        $this->expectException(InvalidArgumentException::class);

        // Supply an empty parameter name or response path.
        Http::paginate(self::API_URL, ...$options);
    }

    /**
     * Provide blank pagination names and paths.
     *
     * @return iterable<string, array{array<string, string>}> Invalid named arguments.
     */
    public static function blankNames(): iterable
    {
        // Check both empty strings and strings containing only whitespace.
        foreach (['page', 'cursor', 'next', 'items'] as $name) {
            // Apply the same validation expectations to each named argument.
            yield 'empty '.$name => [[$name => '']];
            yield 'whitespace '.$name => [[$name => '   ']];
        }
    }

    /**
     * Confirm async builders cannot silently change the synchronous pagination contract.
     */
    public function test_async_requests_are_rejected(): void
    {
        // Require the unsupported request mode to fail before lazy consumption.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('HTTP paginate requires a synchronous request.');

        // Attempt to paginate with Laravel's asynchronous request flag enabled.
        Http::async()->paginate(self::API_URL);
    }

    /**
     * Confirm empty endpoints fail before iteration.
     */
    public function test_empty_url_is_rejected(): void
    {
        // Require a useful endpoint instead of deferring an empty URL to the transport.
        $this->expectException(InvalidArgumentException::class);

        // Attempt the default strategy without an endpoint.
        Http::paginate('');
    }
}
