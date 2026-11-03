<?php

namespace PhilipAnnis\HttpPaginate\Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PhilipAnnis\HttpPaginate\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Verify consumption controls requests and traversal state remains independent.
 */
final class LazyExecutionTest extends TestCase
{
    /**
     * The shared fake endpoint used by lazy-iteration tests.
     */
    private const API_URL = 'https://api.example.test/users';

    /**
     * Confirm requests occur only when iteration reaches the next page.
     */
    public function test_pages_are_fetched_only_as_items_are_consumed(): void
    {
        // Put two items on the initial page and one on the following page.
        Http::fakeSequence()->push(['data' => [['id' => 1], ['id' => 2]]])->push(['data' => [['id' => 3]]]);

        // Creating an iterator must leave both fake responses unused.
        $iterator = Http::paginate(self::API_URL)->getIterator();
        Http::assertNothingSent();

        // Starting iteration fetches only the first page.
        self::assertSame(['id' => 1], $iterator->current());
        Http::assertSentCount(1);

        // The next item reuses the already fetched page.
        $iterator->next();
        self::assertSame(['id' => 2], $iterator->current());
        Http::assertSentCount(1);

        // Crossing the page boundary fetches the second response.
        $iterator->next();
        self::assertSame(['id' => 3], $iterator->current());
        Http::assertSentCount(2);
    }

    /**
     * Confirm take stops at a page boundary without probing the next page.
     */
    public function test_take_does_not_fetch_an_unnecessary_page(): void
    {
        // Supply exactly the page needed by the requested item count.
        Http::fakeSequence()->push(['data' => [['id' => 1], ['id' => 2]]]);

        // Stop after the final required item without discovering a terminal page.
        self::assertSame([['id' => 1], ['id' => 2]], Http::paginate(self::API_URL)->take(2)->all());
        Http::assertSentCount(1);
    }

    /**
     * Confirm take fetches the next page only when its items are needed.
     */
    public function test_take_fetches_only_enough_pages_for_the_requested_items(): void
    {
        // Make the requested third item belong to a second page with unused remaining items.
        Http::fakeSequence()->push(['data' => [['id' => 1], ['id' => 2]]])->push(['data' => [['id' => 3], ['id' => 4]]]);

        // Consume only three items across the two required responses.
        self::assertSame([['id' => 1], ['id' => 2], ['id' => 3]], Http::paginate(self::API_URL)->take(3)->all());
        Http::assertSentCount(2);
    }

    /**
     * Confirm an empty take never starts the underlying request generator.
     */
    public function test_take_zero_does_not_send_a_request(): void
    {
        // Track transport calls without needing to define any page content.
        Http::fake();

        // A zero-item consumer must leave the lazy source untouched.
        self::assertSame([], Http::paginate(self::API_URL)->take(0)->all());
        Http::assertNothingSent();
    }

    /**
     * Confirm breaking a foreach loop does not prefetch another page.
     */
    public function test_breaking_iteration_stops_requests(): void
    {
        // Provide one page so an accidental prefetch exhausts the fake sequence.
        Http::fakeSequence()->push(['data' => [['id' => 1]]]);

        // Exit immediately after handling the first yielded item.
        foreach (Http::paginate(self::API_URL) as $user) {
            // Verify the item before intentionally ending traversal.
            self::assertSame(['id' => 1], $user);
            break;
        }

        // Require the stopped iterator to leave later pages unfetched.
        Http::assertSentCount(1);
    }

    /**
     * Confirm interleaved iterators do not share counters, cursors, or visited URLs.
     *
     * @param  array<string, string>  $options  The selected pagination strategy.
     */
    #[DataProvider('strategies')]
    public function test_independent_traversals_start_from_the_first_page(array $options): void
    {
        // Return deterministic pages according to the current request's continuation value.
        Http::fake(static function (Request $request) {
            // Recognize either numbered or cursor-based requests for the second page.
            $second = ($request['page'] ?? null) === '2' || ($request['cursor'] ?? null) === 'second';

            // Include metadata for each supported strategy in the same fake response.
            return Http::response([
                'data' => [['id' => $second ? 2 : 1]],
                'next_cursor' => $second ? null : 'second',
                'links' => ['next' => $second ? null : '?page=2'],
            ], headers: $second ? [] : ['Link' => '<?page=2>; rel="next"']);
        });

        // Create two iterators from one collection without starting either traversal.
        $users = Http::paginate(self::API_URL, ...$options, limit: 2);
        $first = $users->getIterator();
        $second = $users->getIterator();

        // Interleave consumption and require both iterators to see each page independently.
        self::assertSame(['id' => 1], $first->current());
        self::assertSame(['id' => 1], $second->current());
        $first->next();
        self::assertSame(['id' => 2], $first->current());
        $second->next();
        self::assertSame(['id' => 2], $second->current());

        // A later complete traversal must also start at the original endpoint.
        self::assertSame([['id' => 1], ['id' => 2]], $users->all());
        Http::assertSentCount(6);
    }

    /**
     * Provide all supported strategies for traversal isolation.
     *
     * @return iterable<string, array{array<string, string>}> Named strategy arguments.
     */
    public static function strategies(): iterable
    {
        // Exercise independent continuation state for each public strategy selector.
        yield 'page' => [['page' => 'page']];
        yield 'cursor' => [['cursor' => 'next_cursor']];
        yield 'JSON links' => [['next' => 'links.next']];
        yield 'Link headers' => [['next' => 'header']];
    }
}
