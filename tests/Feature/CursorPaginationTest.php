<?php

namespace PhilipAnnis\HttpPaginate\Tests\Feature;

use Illuminate\Support\Facades\Http;
use PhilipAnnis\HttpPaginate\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use UnexpectedValueException;

/**
 * Verify cursor extraction, termination, and repeated-token detection.
 */
final class CursorPaginationTest extends TestCase
{
    /**
     * The shared fake endpoint used by cursor tests.
     */
    private const API_URL = 'https://api.example.test/users';

    /**
     * Confirm nested tokens use the cursor parameter without losing URL filters.
     */
    public function test_nested_cursors_are_sent_on_following_requests(): void
    {
        // Supply nested tokens with reserved characters before a terminal null token.
        Http::fakeSequence()
            ->push(['data' => [['id' => 1]], 'meta' => ['next_cursor' => 'a+b/==']])
            ->push(['data' => [['id' => 2]], 'meta' => ['next_cursor' => 'last']])
            ->push(['data' => [['id' => 3]], 'meta' => ['next_cursor' => null]]);

        // Consume all pages while preserving the original active filter.
        self::assertSame([['id' => 1], ['id' => 2], ['id' => 3]], Http::paginate(
            self::API_URL.'?active=yes',
            cursor: 'meta.next_cursor',
        )->all());

        // Verify each token replaces the previous value without adding a page parameter.
        $this->assertRequestedUrls([
            self::API_URL.'?active=yes',
            self::API_URL.'?active=yes&cursor=a%2Bb/%3D%3D',
            self::API_URL.'?active=yes&cursor=last',
        ]);
    }

    /**
     * Confirm absent, null, and blank tokens terminate after the current page.
     *
     * @param  array<string, mixed>  $metadata  The cursor metadata returned by the API.
     */
    #[DataProvider('terminalCursors')]
    public function test_terminal_cursors_do_not_request_another_page(array $metadata): void
    {
        // Return one item alongside the selected terminal metadata.
        Http::fakeSequence()->push(['data' => [['id' => 1]]] + $metadata);

        // Yield the final item without making another request.
        self::assertSame([['id' => 1]], Http::paginate(self::API_URL, cursor: 'meta.next_cursor')->all());
        Http::assertSentCount(1);
    }

    /**
     * Provide supported end-of-pagination cursor values.
     *
     * @return iterable<string, array{array<string, mixed>}> Terminal response metadata.
     */
    public static function terminalCursors(): iterable
    {
        // Cover omitted metadata, omitted paths, and explicit empty values.
        yield 'missing metadata' => [[]];
        yield 'missing cursor' => [['meta' => []]];
        yield 'null cursor' => [['meta' => ['next_cursor' => null]]];
        yield 'empty cursor' => [['meta' => ['next_cursor' => '']]];
        yield 'blank cursor' => [['meta' => ['next_cursor' => '   ']]];
    }

    /**
     * Confirm zero is a valid token rather than an end-of-pagination signal.
     *
     * @param  string|int  $cursor  The zero-valued cursor returned by the API.
     */
    #[DataProvider('zeroCursors')]
    public function test_zero_cursors_are_followed(string|int $cursor): void
    {
        // Return a continuation token on an empty page before the final item page.
        Http::fakeSequence()->push(['data' => [], 'next_cursor' => $cursor])->push(['data' => [['id' => 1]]]);

        // Follow zero instead of treating the empty initial page or token as termination.
        self::assertSame([['id' => 1]], Http::paginate(self::API_URL, cursor: 'next_cursor')->all());
        $this->assertRequestedUrls([self::API_URL, self::API_URL.'?cursor=0']);
    }

    /**
     * Provide both JSON representations of a zero token.
     *
     * @return iterable<string, array{string|int}> Zero-valued cursors.
     */
    public static function zeroCursors(): iterable
    {
        // Preserve integer and string zero as equivalent request tokens.
        yield 'integer' => [0];
        yield 'string' => ['0'];
    }

    /**
     * Confirm cycles fail before a previously used token can be requested again.
     */
    public function test_repeated_cursor_cycles_are_rejected(): void
    {
        // Return a cycle that revisits an earlier token after an intervening cursor.
        Http::fakeSequence()
            ->push(['data' => [['id' => 1]], 'next_cursor' => 'first'])
            ->push(['data' => [['id' => 2]], 'next_cursor' => 'second'])
            ->push(['data' => [['id' => 3]], 'next_cursor' => 'first']);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('HTTP paginate encountered a repeated cursor.');

        // Assert the final request count even when traversal rejects the cycle.
        try {
            // Consume enough pages to observe the repeated continuation token.
            Http::paginate(self::API_URL, cursor: 'next_cursor')->all();
        } finally {
            // Stop before a fourth request could repeat the first cursor page.
            Http::assertSentCount(3);
        }
    }

    /**
     * Confirm an initial URL cursor participates in loop detection.
     */
    public function test_initial_cursor_is_not_requested_twice(): void
    {
        // Return the same cursor supplied by the initial URL.
        Http::fakeSequence()->push(['data' => [['id' => 1]], 'next_cursor' => 'initial']);
        $this->expectException(RuntimeException::class);

        // Exercise loop detection with a traversal that begins midway through an API.
        try {
            // Seed pagination history from the initial cursor parameter.
            Http::paginate(self::API_URL.'?cursor=initial', cursor: 'next_cursor')->all();
        } finally {
            // Reject the self-loop before requesting the same page again.
            Http::assertSentCount(1);
        }
    }

    /**
     * Confirm configured initial cursors are replaced rather than duplicated.
     */
    public function test_configured_cursor_and_query_defaults_are_preserved(): void
    {
        // Continue through an empty page when it supplies a valid next cursor.
        Http::fakeSequence()->push(['data' => [], 'next_cursor' => 'second'])->push(['data' => [['id' => 1]]]);

        // Provide the starting cursor and persistent tenant through Laravel's query options.
        $users = Http::withQueryParameters(['tenant' => '42', 'cursor' => 'initial'])
            ->paginate(self::API_URL, cursor: 'next_cursor');

        // Preserve the tenant while replacing only the cursor value.
        self::assertSame([['id' => 1]], $users->all());
        $this->assertRequestedUrls([self::API_URL.'?tenant=42&cursor=initial', self::API_URL.'?tenant=42&cursor=second']);
    }

    /**
     * Confirm malformed tokens cannot masquerade as successful termination.
     *
     * @param  mixed  $cursor  The unsupported token returned by the API.
     */
    #[DataProvider('invalidCursors')]
    public function test_invalid_cursor_values_are_rejected(mixed $cursor): void
    {
        // Expect invalid response metadata to fail during consumption.
        Http::fakeSequence()->push(['data' => [], 'next_cursor' => $cursor]);
        $this->expectException(UnexpectedValueException::class);

        // Read the malformed token from an otherwise valid JSON page.
        Http::paginate(self::API_URL, cursor: 'next_cursor')->all();
    }

    /**
     * Provide cursor values with unsupported JSON types.
     *
     * @return iterable<string, array{mixed}> Invalid response cursor values.
     */
    public static function invalidCursors(): iterable
    {
        // Reject booleans, fractions, arrays, and objects instead of coercing their values.
        yield 'false' => [false];
        yield 'true' => [true];
        yield 'fraction' => [1.5];
        yield 'array' => [['token']];
        yield 'object' => [(object) ['token' => 'abc']];
    }
}
