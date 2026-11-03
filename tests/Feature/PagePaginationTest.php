<?php

namespace PhilipAnnis\HttpPaginate\Tests\Feature;

use Illuminate\Support\Facades\Http;
use PhilipAnnis\HttpPaginate\Tests\TestCase;

/**
 * Verify numbered pages, item ordering, and query parameter replacement.
 */
final class PagePaginationTest extends TestCase
{
    /**
     * The shared fake endpoint used by numbered-page tests.
     */
    private const API_URL = 'https://api.example.test/users';

    /**
     * Confirm defaults start at one and stop on the first empty page.
     */
    public function test_default_pagination_increments_until_an_empty_page(): void
    {
        // Supply two populated pages followed by the terminal empty page.
        Http::fakeSequence()
            ->push(['data' => [['id' => 1], ['id' => 2]]])
            ->push(['data' => [['id' => 3], ['id' => 4]]])
            ->push(['data' => []]);

        // Preserve every item and its order across page boundaries.
        self::assertSame([['id' => 1], ['id' => 2], ['id' => 3], ['id' => 4]], Http::paginate(self::API_URL)->all());

        // Include the final empty-page discovery request in the expected sequence.
        $this->assertRequestedUrls([self::API_URL.'?page=1', self::API_URL.'?page=2', self::API_URL.'?page=3']);
    }

    /**
     * Confirm custom page parameters replace earlier values without disturbing filters.
     */
    public function test_custom_page_parameter_preserves_the_original_query(): void
    {
        // Use a custom response path and one terminal empty response.
        Http::fakeSequence()->push(['results' => [['id' => 1]]])->push(['results' => []]);

        // Include dotted filters, repeated unrelated values, and duplicate initial page values.
        $users = Http::paginate(
            self::API_URL.'?filter.name=Ada%20Lovelace&tag=a&tag=b&page_number=9&page_number=10',
            page: 'page_number',
            items: 'results',
        );

        // Replace only the page parameter while preserving the remaining query spelling.
        self::assertSame([['id' => 1]], $users->all());
        $this->assertRequestedUrls([
            self::API_URL.'?filter.name=Ada%20Lovelace&tag=a&tag=b&page_number=1',
            self::API_URL.'?filter.name=Ada%20Lovelace&tag=a&tag=b&page_number=2',
        ]);
    }

    /**
     * Confirm query defaults and URL filters survive every numbered request.
     */
    public function test_configured_query_parameters_do_not_accumulate(): void
    {
        // Return enough pages to detect accumulated query values.
        Http::fakeSequence()->push(['data' => [['id' => 1]]])->push(['data' => [['id' => 2]]])->push(['data' => []]);

        // Let the URL override one configured filter and pagination replace the initial page.
        $users = Http::withQueryParameters(['tenant' => '42', 'page' => 99, 'active' => 'no'])
            ->paginate(self::API_URL.'?active=yes');

        // Keep a single current page value alongside the persistent tenant and URL filter.
        self::assertSame([['id' => 1], ['id' => 2]], $users->all());
        $this->assertRequestedUrls([
            self::API_URL.'?tenant=42&active=yes&page=1',
            self::API_URL.'?tenant=42&active=yes&page=2',
            self::API_URL.'?tenant=42&active=yes&page=3',
        ]);
    }

    /**
     * Confirm raw query options retain repeated values and dotted names.
     */
    public function test_string_query_options_are_preserved(): void
    {
        // Return an empty initial page to keep the assertion focused on query preservation.
        Http::fake(['*' => Http::response(['data' => []])]);

        // Use Guzzle's string query option instead of an associative query array.
        Http::withOptions(['query' => 'filter.name=Ada&tag=a&tag=b&page=7'])->paginate(self::API_URL)->all();

        // Preserve unrelated query bytes while replacing the initial page value.
        $this->assertRequestedUrls([self::API_URL.'?filter.name=Ada&tag=a&tag=b&page=1']);
    }

    /**
     * Confirm an initially empty page returns an empty collection after one request.
     */
    public function test_empty_first_page_terminates_pagination(): void
    {
        // Supply only the terminal page so an extra request would exhaust the fake.
        Http::fakeSequence()->push(['data' => []]);

        // Stop immediately after consuming the valid empty array.
        self::assertSame([], Http::paginate(self::API_URL)->all());
        Http::assertSentCount(1);
    }

    /**
     * Confirm nested item paths return individual associative items.
     */
    public function test_nested_item_paths_use_laravel_dot_notation(): void
    {
        // Return a nested item array with a nested object in its first item.
        Http::fakeSequence()
            ->push(['response' => ['data' => ['items' => [['id' => 1, 'profile' => ['name' => 'Ada']]]]]])
            ->push(['response' => ['data' => ['items' => []]]]);

        // Retain Laravel's associative decoding at every level of the returned item.
        self::assertSame(
            [['id' => 1, 'profile' => ['name' => 'Ada']]],
            Http::paginate(self::API_URL, items: 'response.data.items')->all(),
        );
        Http::assertSentCount(2);
    }

    /**
     * Confirm base URLs and templates expand before adding pagination values.
     */
    public function test_base_url_and_url_parameters_are_preserved(): void
    {
        // Provide one terminal page after expanding the configured endpoint.
        Http::fakeSequence()->push(['data' => []]);

        // Combine Laravel's base URL and URI template features with the macro.
        Http::baseUrl('https://api.example.test/v1')->withUrlParameters(['resource' => 'users'])
            ->paginate('{resource}?active=yes')->all();

        // Preserve the expanded path and original query when adding the first page number.
        $this->assertRequestedUrls(['https://api.example.test/v1/users?active=yes&page=1']);
    }
}
