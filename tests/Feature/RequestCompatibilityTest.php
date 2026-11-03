<?php

namespace PhilipAnnis\HttpPaginate\Tests\Feature;

use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use PhilipAnnis\HttpPaginate\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Verify every page retains Laravel's options, callbacks, and retry behavior.
 */
final class RequestCompatibilityTest extends TestCase
{
    /**
     * The shared fake endpoint used by request-compatibility tests.
     */
    private const API_URL = 'https://api.example.test/users';

    /**
     * Confirm every strategy retains authentication, headers, timeouts, and transfer options.
     *
     * @param  array<string, string>  $options  The selected pagination strategy.
     */
    #[DataProvider('strategies')]
    public function test_request_configuration_is_preserved_on_every_page(array $options): void
    {
        // Capture Guzzle options as each fake page reaches Laravel's transport.
        $transfers = [];
        Http::fake(static function (Request $request, array $options) use (&$transfers) {
            // Distinguish the terminal page while retaining both transfers' settings.
            $transfers[] = $options;
            $second = count($transfers) === 2;

            // Advertise continuation metadata for each supported strategy on the first page.
            return Http::response([
                'data' => $second ? [] : [['id' => 1]],
                'next_cursor' => $second ? null : 'second',
                'next' => $second ? null : '?page=2',
            ], headers: $second ? [] : ['Link' => '<?page=2>; rel="next"']);
        });

        // Configure the request through Laravel before selecting its pagination strategy.
        $users = Http::withToken('api-token')
            ->acceptJson()
            ->withHeaders(['X-Tenant-ID' => '42'])
            ->timeout(10)
            ->connectTimeout(4)
            ->withOptions(['read_timeout' => 7, 'verify' => false, 'version' => '1.1'])
            ->paginate(self::API_URL, ...$options);

        // Require the original headers, protocol, method, and item ordering on both requests.
        self::assertSame([['id' => 1]], $users->all());
        Http::assertSentCount(2);
        Http::assertNotSent(static fn (Request $request): bool => ! $request->hasHeader('Authorization', 'Bearer api-token')
            || ! $request->hasHeader('X-Tenant-ID', '42')
            || ! $request->hasHeader('Accept', 'application/json')
            || $request->toPsrRequest()->getProtocolVersion() !== '1.1'
            || $request->method() !== 'GET');

        // Check transport options that are not directly observable in the request headers.
        foreach ($transfers as $transfer) {
            // Retain separate total, connection, and read timeouts alongside TLS verification.
            self::assertSame(10, $transfer['timeout']);
            self::assertSame(4, $transfer['connect_timeout']);
            self::assertSame(7, $transfer['read_timeout']);
            self::assertFalse($transfer['verify']);
        }
    }

    /**
     * Provide every strategy that reuses request configuration.
     *
     * @return iterable<string, array{array<string, string>}> Named strategy arguments.
     */
    public static function strategies(): iterable
    {
        // Apply the same client-compatibility assertions to all continuation mechanisms.
        yield 'page' => [['page' => 'page']];
        yield 'cursor' => [['cursor' => 'next_cursor']];
        yield 'JSON links' => [['next' => 'next']];
        yield 'Link headers' => [['next' => 'header']];
    }

    /**
     * Confirm retry attempts stay inside their page and do not consume the page limit.
     */
    public function test_retries_are_preserved_on_each_page(): void
    {
        // Make each permitted page fail once before succeeding.
        Http::fakeSequence()
            ->push(['message' => 'Temporary failure'], 500)
            ->push(['data' => [['id' => 1]]])
            ->push(['message' => 'Temporary failure'], 500)
            ->push(['data' => [['id' => 2]]]);

        // Count completed pages separately from the attempts performed by Laravel's retry policy.
        self::assertSame([['id' => 1], ['id' => 2]], Http::retry(3, 0)->paginate(self::API_URL, limit: 2)->all());
        $this->assertRequestedUrls([
            self::API_URL.'?page=1', self::API_URL.'?page=1',
            self::API_URL.'?page=2', self::API_URL.'?page=2',
        ]);
    }

    /**
     * Confirm a retry callback can refresh credentials for the remaining pages.
     */
    public function test_retry_callback_changes_are_retained_during_the_traversal(): void
    {
        // Fail the initial credential once, then return a populated and a terminal page.
        Http::fakeSequence()->push(['message' => 'Expired token'], 401)->push(['data' => [['id' => 1]]])->push(['data' => []]);
        $calls = 0;

        // Refresh the configured builder using Laravel's normal retry callback contract.
        $users = Http::withToken('expired-token')
            ->retry(2, 0, static function (RequestException $exception, PendingRequest $request) use (&$calls): bool {
                // Replace expired credentials after confirming the upstream failure status.
                $calls++;
                self::assertSame(401, $exception->response->status());
                $request->withToken('refreshed-token');

                // Permit Laravel to retry the failed page using the updated request.
                return true;
            })
            ->paginate(self::API_URL);

        // Keep the refreshed token for both the retry and the following page.
        self::assertSame([['id' => 1]], $users->all());
        self::assertSame(1, $calls);
        $authorizations = Http::recorded()->map(static fn (array $pair): string => $pair[0]->header('Authorization')[0])->all();
        self::assertSame(['Bearer expired-token', 'Bearer refreshed-token', 'Bearer refreshed-token'], $authorizations);
    }

    /**
     * Confirm disabling retry's final throw does not hide a failed pagination page.
     */
    public function test_exhausted_retries_still_reject_http_errors(): void
    {
        // Exhaust a two-attempt policy with unsuccessful responses.
        Http::fakeSequence()->pushStatus(503)->pushStatus(503);
        $this->expectException(RequestException::class);

        // Preserve Laravel's attempts while requiring pagination to reject the final failure.
        try {
            // Disable the retry helper's final throw without accepting an unsuccessful page.
            Http::retry(2, 0, throw: false)->paginate(self::API_URL)->all();
        } finally {
            // Keep the original two-attempt request policy intact.
            Http::assertSentCount(2);
        }
    }

    /**
     * Confirm middleware and preparation callbacks run on every page.
     */
    public function test_request_middleware_and_callbacks_are_preserved(): void
    {
        // Track preparation and response middleware around two controlled pages.
        Http::fakeSequence()->push(['data' => [['id' => 1]]])->push(['data' => []]);
        $prepared = [];
        $responses = 0;

        // Add the caller's request, response, and preparation hooks before pagination.
        $users = Http::withRequestMiddleware(static fn (RequestInterface $request): RequestInterface => $request->withHeader('X-Middleware', 'yes'))
            ->withResponseMiddleware(static function (ResponseInterface $response) use (&$responses): ResponseInterface {
                // Count each response while returning the original PSR response unchanged.
                $responses++;

                // Preserve the response for Laravel's remaining middleware and callbacks.
                return $response;
            })
            ->beforeSending(static function (Request $request, array $options) use (&$prepared): void {
                // Observe the prepared URL and timeout supplied to each transfer.
                $prepared[] = $request->url();
                self::assertSame(10, $options['timeout']);
            })
            ->timeout(10)
            ->paginate(self::API_URL);

        // Require every hook and middleware header on both pagination requests.
        self::assertSame([['id' => 1]], $users->all());
        self::assertSame([self::API_URL.'?page=1', self::API_URL.'?page=2'], $prepared);
        self::assertSame(2, $responses);
        Http::assertNotSent(static fn (Request $request): bool => ! $request->hasHeader('X-Middleware', 'yes'));
    }

    /**
     * Confirm custom Guzzle middleware stays installed on all pages.
     */
    public function test_custom_guzzle_middleware_is_preserved(): void
    {
        // Return one item page and one terminal page through the middleware stack.
        Http::fakeSequence()->push(['data' => [['id' => 1]]])->push(['data' => []]);
        $calls = 0;

        // Decorate Laravel's existing handler instead of replacing its request pipeline.
        $users = Http::withMiddleware(static function (callable $handler) use (&$calls): callable {
            // Keep the next handler and call counter local to the installed middleware.
            return static function (RequestInterface $request, array $options) use ($handler, &$calls) {
                // Count the transfer before passing the modified request to Laravel's handler.
                $calls++;

                // Continue through the original handler with the caller's transfer options.
                return $handler($request->withHeader('X-Custom-Middleware', 'yes'), $options);
            };
        })->paginate(self::API_URL);

        // Require the installed middleware to run for both pages.
        self::assertSame([['id' => 1]], $users->all());
        self::assertSame(2, $calls);
        Http::assertNotSent(static fn (Request $request): bool => ! $request->hasHeader('X-Custom-Middleware', 'yes'));
    }

    /**
     * Confirm pagination retains Laravel's request and response events.
     */
    public function test_request_and_response_events_are_preserved(): void
    {
        // Observe the application's existing dispatcher retained by Laravel's HTTP factory.
        $sending = 0;
        $received = 0;
        Event::listen(RequestSending::class, static function () use (&$sending): void {
            // Count each prepared request event.
            $sending++;
        });
        Event::listen(ResponseReceived::class, static function () use (&$received): void {
            // Count each completed response event.
            $received++;
        });

        // Consume a populated page followed by the empty terminal response.
        Http::fakeSequence()->push(['data' => [['id' => 1]]])->push(['data' => []]);
        Http::paginate(self::API_URL)->all();

        // Match the native request and response event counts to the two transfers.
        self::assertSame(2, $sending);
        self::assertSame(2, $received);
    }

    /**
     * Confirm pagination does not change a reused builder's original query or options.
     */
    public function test_pagination_does_not_mutate_the_original_builder(): void
    {
        // Retain the original builder options before making paginated and ordinary requests.
        Http::fakeSequence()->push(['data' => [['id' => 1]]])->push(['data' => []])->push(['ok' => true]);
        $request = Http::withToken('api-token')->withQueryParameters(['tenant' => '42']);
        $options = $request->getOptions();

        // Consume pagination before reusing the same builder for an unrelated GET request.
        self::assertSame([['id' => 1]], $request->paginate(self::API_URL)->all());
        self::assertSame($options, $request->getOptions());
        $request->get(self::API_URL);

        // Keep pagination values out of the original builder's later request.
        $this->assertRequestedUrls([
            self::API_URL.'?tenant=42&page=1',
            self::API_URL.'?tenant=42&page=2',
            self::API_URL.'?tenant=42',
        ]);
    }

    /**
     * Confirm later option changes do not alter an already-created collection.
     */
    public function test_later_builder_option_changes_do_not_change_the_collection(): void
    {
        // Capture original credentials and query defaults when creating the collection.
        Http::fakeSequence()->push(['data' => []]);
        $request = Http::withToken('original-token')->withQueryParameters(['tenant' => '42']);
        $users = $request->paginate(self::API_URL);

        // Reconfigure the original builder before beginning the captured traversal.
        $request->withToken('different-token')->withQueryParameters(['tenant' => '99']);
        $users->all();

        // Require pagination to use the options supplied when paginate was called.
        Http::assertSent(static fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer original-token')
            && $request->url() === self::API_URL.'?tenant=42&page=1');
    }
}
