<?php

namespace PhilipAnnis\HttpPaginate\Tests;

use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase as Orchestra;
use PhilipAnnis\HttpPaginate\HttpPaginateServiceProvider;

/**
 * Boot an isolated Laravel application for package integration tests.
 */
abstract class TestCase extends Orchestra
{
    /**
     * Prevent every package test from reaching the network.
     */
    protected function setUp(): void
    {
        // Boot Laravel before applying the HTTP transport guard.
        parent::setUp();

        // Turn incomplete HTTP fakes into immediate test failures.
        Http::preventStrayRequests();
    }

    /**
     * Register the package service provider.
     *
     * @param  mixed  $app  The Testbench application.
     * @return array<int, class-string> The package providers.
     */
    protected function getPackageProviders($app): array
    {
        // Register the provider that makes paginate available on configured requests.
        return [HttpPaginateServiceProvider::class];
    }

    /**
     * Compare the complete request order through Laravel's HTTP recorder.
     *
     * @param  list<string>  $urls  The expected request URLs.
     */
    protected function assertRequestedUrls(array $urls): void
    {
        // Inspect recorded requests through Laravel's public testing API.
        self::assertSame($urls, Http::recorded()->map(static fn (array $pair): string => $pair[0]->url())->all());
    }
}
