<?php

namespace PhilipAnnis\HttpPaginate\Tests\Feature;

use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use PhilipAnnis\HttpPaginate\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Verify limits count pages and never require a final discovery request.
 */
final class PageLimitTest extends TestCase
{
    /**
     * The shared fake endpoint used by page-limit tests.
     */
    private const API_URL = 'https://api.example.test/users';

    /**
     * Confirm every strategy stops at the page limit while retaining all its items.
     *
     * @param  array<string, string>  $options  The selected pagination strategy.
     */
    #[DataProvider('strategies')]
    public function test_limit_counts_pages_and_does_not_fetch_an_additional_page(array $options): void
    {
        // Make both pages advertise another page so only the configured limit can stop traversal.
        Http::fakeSequence()
            ->push(['data' => [['id' => 1], ['id' => 2]], 'next_cursor' => 'second', 'next' => '?page=2'], 200, ['Link' => '<?page=2>; rel="next"'])
            ->push(['data' => [['id' => 3], ['id' => 4]], 'next_cursor' => 'third', 'next' => '?page=3'], 200, ['Link' => '<?page=3>; rel="next"']);

        // Retain every item from the two permitted pages without requesting the third.
        self::assertSame([['id' => 1], ['id' => 2], ['id' => 3], ['id' => 4]], Http::paginate(self::API_URL, ...$options, limit: 2)->all());
        Http::assertSentCount(2);
    }

    /**
     * Provide strategies supported by the shared page limit.
     *
     * @return iterable<string, array{array<string, string>}> Named strategy arguments.
     */
    public static function strategies(): iterable
    {
        // Include omission as well as each explicitly selected strategy.
        yield 'default' => [[]];
        yield 'page' => [['page' => 'page']];
        yield 'cursor' => [['cursor' => 'next_cursor']];
        yield 'JSON links' => [['next' => 'next']];
        yield 'Link headers' => [['next' => 'header']];
    }

    /**
     * Confirm zero and negative limits fail immediately without a request.
     *
     * @param  int  $limit  The invalid maximum number of pages.
     */
    #[DataProvider('invalidLimits')]
    public function test_invalid_limits_are_rejected(int $limit): void
    {
        // Require immediate validation with the documented exception message.
        Http::fake();
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The HTTP paginate limit must be a positive number of pages.');

        // Assert that invalid limits cannot begin a traversal.
        try {
            // Attempt to create a collection with a non-positive page limit.
            Http::paginate(self::API_URL, limit: $limit);
        } finally {
            // Confirm the error occurs before reaching the HTTP transport.
            Http::assertNothingSent();
        }
    }

    /**
     * Provide non-positive page limits.
     *
     * @return iterable<string, array{int}> Invalid limits keyed by purpose.
     */
    public static function invalidLimits(): iterable
    {
        // Reject limits that cannot permit a first page.
        yield 'zero' => [0];
        yield 'negative' => [-1];
    }

    /**
     * Confirm an omitted limit permits traversal beyond a short default budget.
     */
    public function test_omitted_limit_does_not_impose_an_arbitrary_page_cap(): void
    {
        // Prepare more populated pages than a common arbitrary default would allow.
        $sequence = Http::fakeSequence();
        foreach (range(1, 101) as $id) {
            // Give each page one distinguishable item to verify complete traversal.
            $sequence->push(['data' => [['id' => $id]]]);
        }

        // Terminate using the API's empty page rather than an internal request budget.
        $sequence->push(['data' => []]);

        // Require every item and the final discovery request to be present.
        self::assertSame(range(1, 101), Http::paginate(self::API_URL)->pluck('id')->all());
        Http::assertSentCount(102);
    }
}
