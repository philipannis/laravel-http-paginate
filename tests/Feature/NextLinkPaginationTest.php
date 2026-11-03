<?php

namespace PhilipAnnis\HttpPaginate\Tests\Feature;

use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\TransferStats;
use Illuminate\Support\Facades\Http;
use PhilipAnnis\HttpPaginate\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\RequestInterface;
use RuntimeException;
use UnexpectedValueException;

/**
 * Verify JSON next links, relative resolution, and repeated-URL detection.
 */
final class NextLinkPaginationTest extends TestCase
{
    /**
     * The shared fake endpoint used by next-link tests.
     */
    private const API_URL = 'https://api.example.test/v1/users';

    /**
     * Confirm absolute URLs are followed without adding a page parameter.
     */
    public function test_absolute_next_urls_are_followed(): void
    {
        // Supply one absolute continuation URL before the final null link.
        Http::fakeSequence()
            ->push(['data' => [['id' => 1]], 'links' => ['next' => self::API_URL.'?page=2']])
            ->push(['data' => [['id' => 2]], 'links' => ['next' => null]]);

        // Follow exactly the API's link and retain both pages' individual items.
        self::assertSame([['id' => 1], ['id' => 2]], Http::paginate(self::API_URL, next: 'links.next')->all());
        $this->assertRequestedUrls([self::API_URL, self::API_URL.'?page=2']);
    }

    /**
     * Confirm relative references resolve against the current request.
     *
     * @param  string  $next  The URI reference supplied by the response.
     * @param  string  $expected  The resolved destination.
     */
    #[DataProvider('relativeLinks')]
    public function test_relative_urls_are_resolved(string $next, string $expected): void
    {
        // Allow an empty page to advertise a valid continuation link.
        Http::fakeSequence()->push(['data' => [], 'links' => ['next' => $next]])->push(['data' => [['id' => 2]]]);

        // Resolve the link before sending the next request and yielding its item.
        self::assertSame([['id' => 2]], Http::paginate(self::API_URL.'?page=1', next: 'links.next')->all());
        $this->assertRequestedUrls([self::API_URL.'?page=1', $expected]);
    }

    /**
     * Provide the supported relative-reference forms.
     *
     * @return iterable<string, array{string, string}> References and their resolved URLs.
     */
    public static function relativeLinks(): iterable
    {
        // Cover query references, directory changes, authority references, and fragments.
        yield 'query' => ['?page=2', self::API_URL.'?page=2'];
        yield 'same directory' => ['users?page=2', self::API_URL.'?page=2'];
        yield 'parent directory' => ['../v2/users?page=2', 'https://api.example.test/v2/users?page=2'];
        yield 'root path' => ['/v2/users?page=2', 'https://api.example.test/v2/users?page=2'];
        yield 'network path' => ['//api.example.test/v2/users?page=2', 'https://api.example.test/v2/users?page=2'];
        yield 'fragment' => ['?page=2#results', self::API_URL.'?page=2'];
    }

    /**
     * Confirm later links resolve against the latest page rather than the initial endpoint.
     */
    public function test_each_link_uses_the_current_request_as_its_base(): void
    {
        // Change directories before returning a path-relative reference on the second page.
        Http::fakeSequence()
            ->push(['data' => [['id' => 1]], 'next' => '/v2/pages/second'])
            ->push(['data' => [['id' => 2]], 'next' => 'third'])
            ->push(['data' => [['id' => 3]]]);

        // Resolve each continuation from its own page's location.
        self::assertSame([['id' => 1], ['id' => 2], ['id' => 3]], Http::paginate(self::API_URL, next: 'next')->all());
        $this->assertRequestedUrls([
            self::API_URL,
            'https://api.example.test/v2/pages/second',
            'https://api.example.test/v2/pages/third',
        ]);
    }

    /**
     * Confirm next URLs override stale query defaults while persistent filters remain.
     */
    public function test_next_urls_override_configured_query_defaults(): void
    {
        // Return a next URL that overrides the builder's initial page value.
        Http::fakeSequence()->push(['data' => [], 'links' => ['next' => '?page=2']])->push(['data' => [['id' => 2]]]);

        // Keep the tenant as a default on every request without overwriting continuation values.
        $users = Http::withQueryParameters(['tenant' => '42', 'page' => 1])->paginate(self::API_URL, next: 'links.next');

        // Require the tenant to persist while the API's page value takes precedence.
        self::assertSame([['id' => 2]], $users->all());
        $this->assertRequestedUrls([self::API_URL.'?tenant=42&page=1', self::API_URL.'?tenant=42&page=2']);
    }

    /**
     * Confirm missing, null, and empty next links terminate without a probe request.
     *
     * @param  array<string, mixed>  $metadata  The response's optional next link.
     */
    #[DataProvider('terminalLinks')]
    public function test_terminal_links_stop_pagination(array $metadata): void
    {
        // Supply a final item page with the selected terminal link value.
        Http::fakeSequence()->push(['data' => [['id' => 1]]] + $metadata);

        // Retain the final item and stop after its response.
        self::assertSame([['id' => 1]], Http::paginate(self::API_URL, next: 'links.next')->all());
        Http::assertSentCount(1);
    }

    /**
     * Provide supported end-of-pagination link values.
     *
     * @return iterable<string, array{array<string, mixed>}> Terminal link metadata.
     */
    public static function terminalLinks(): iterable
    {
        // Accept omission, null, and blank strings as termination signals.
        yield 'missing' => [[]];
        yield 'null' => [['links' => ['next' => null]]];
        yield 'empty' => [['links' => ['next' => '']]];
        yield 'blank' => [['links' => ['next' => '   ']]];
    }

    /**
     * Confirm cycles cannot fetch an earlier URL for a second time.
     */
    public function test_repeated_url_cycles_are_rejected(): void
    {
        // Return to the initial URL with only a fragment distinguishing its spelling.
        Http::fakeSequence()->push(['data' => [], 'next' => '?page=2'])->push(['data' => [], 'next' => self::API_URL.'#again']);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('HTTP paginate encountered a repeated next URL.');

        // Consume both distinct requests before rejecting the cycle.
        try {
            // Read continuation links until the initial destination is advertised again.
            Http::paginate(self::API_URL, next: 'next')->all();
        } finally {
            // Treat fragment changes as the same HTTP destination.
            Http::assertSentCount(2);
        }
    }

    /**
     * Confirm malformed URL metadata is rejected during consumption.
     *
     * @param  mixed  $next  The unsupported next-link value.
     */
    #[DataProvider('invalidLinks')]
    public function test_invalid_next_link_values_are_rejected(mixed $next): void
    {
        // Return invalid continuation metadata beside a valid empty item array.
        Http::fakeSequence()->push(['data' => [], 'next' => $next]);
        $this->expectException(UnexpectedValueException::class);

        // Reject the metadata instead of silently ending or guessing a destination.
        Http::paginate(self::API_URL, next: 'next')->all();
    }

    /**
     * Provide unsupported JSON types for a next URL.
     *
     * @return iterable<string, array{mixed}> Invalid next-link values.
     */
    public static function invalidLinks(): iterable
    {
        // Restrict URL metadata to strings and explicit termination values.
        yield 'false' => [false];
        yield 'integer' => [2];
        yield 'array' => [['url' => self::API_URL]];
    }

    /**
     * Confirm relative links use the effective location after an HTTP redirect.
     */
    public function test_relative_links_use_the_effective_redirect_url(): void
    {
        // Replace the network transport while retaining Guzzle's normal redirect middleware.
        Http::allowStrayRequests();
        $urls = [];
        $responses = [
            new Response(302, ['Location' => '/v2/pages/first']),
            new Response(200, [], '{"data":[{"id":1}],"next":"second"}'),
            new Response(200, [], '{"data":[{"id":2}]}'),
        ];

        // Record controlled transfers and report the effective URI through Guzzle's statistics hook.
        $users = Http::setHandler(static function (RequestInterface $request, array $options) use (&$urls, &$responses) {
            // Complete each in-memory transfer without making a network request.
            $urls[] = (string) $request->getUri();
            $response = array_shift($responses);
            ($options['on_stats'])(new TransferStats($request, $response, 0));

            // Retain the promise contract expected by Guzzle's redirect middleware.
            return Create::promiseFor($response);
        })->paginate(self::API_URL, next: 'next');

        // Resolve the final relative link from the redirected directory.
        self::assertSame([['id' => 1], ['id' => 2]], $users->all());
        self::assertSame([
            self::API_URL,
            'https://api.example.test/v2/pages/first',
            'https://api.example.test/v2/pages/second',
        ], $urls);
    }
}
