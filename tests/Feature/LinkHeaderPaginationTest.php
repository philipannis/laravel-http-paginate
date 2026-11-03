<?php

namespace PhilipAnnis\HttpPaginate\Tests\Feature;

use Illuminate\Support\Facades\Http;
use PhilipAnnis\HttpPaginate\Tests\TestCase;
use RuntimeException;

/**
 * Verify HTTP Link pagination through the public next argument.
 */
final class LinkHeaderPaginationTest extends TestCase
{
    /**
     * The shared fake endpoint used by Link header tests.
     */
    private const API_URL = 'https://api.example.test/users';

    /**
     * Confirm multiple relations and fields preserve the correct next target.
     */
    public function test_next_relations_are_followed_across_multiple_link_fields(): void
    {
        // Mix URI punctuation, quoted titles, and relation lists across two Link fields.
        Http::fakeSequence()
            ->push(['data' => [['id' => 1]]], 200, ['Link' => [
                '<'.self::API_URL.'?page=1>; rel="first", <?page=9>; rel="last"',
                '<?page=2&filter=a,b;c>; title="Pages, with; punctuation"; rel="alternate NEXT"',
            ]])
            ->push(['data' => [['id' => 2]]], 200, ['Link' => '<?page=1>; rel="prev"']);

        // Follow only the next relation and stop when the remaining link points backward.
        self::assertSame([['id' => 1], ['id' => 2]], Http::paginate(self::API_URL, next: 'header')->all());
        $this->assertRequestedUrls([self::API_URL, self::API_URL.'?page=2&filter=a,b;c']);
    }

    /**
     * Confirm missing Link headers stop after the current response.
     */
    public function test_missing_link_header_terminates_pagination(): void
    {
        // Omit Link entirely on a valid final item page.
        Http::fakeSequence()->push(['data' => [['id' => 1]]]);

        // Yield its item without discovering another page.
        self::assertSame([['id' => 1]], Http::paginate(self::API_URL, next: 'header')->all());
        Http::assertSentCount(1);
    }

    /**
     * Confirm the reserved selector never reads a JSON field named header.
     */
    public function test_header_is_reserved_for_the_http_link_strategy(): void
    {
        // Advertise a JSON header field without an HTTP Link header.
        Http::fakeSequence()->push(['data' => [['id' => 1]], 'header' => self::API_URL.'?page=2']);

        // Keep header selection tied to HTTP metadata instead of the JSON field.
        self::assertSame([['id' => 1]], Http::paginate(self::API_URL, next: 'header')->all());
        Http::assertSentCount(1);
    }

    /**
     * Confirm empty pages still follow an available next relation.
     */
    public function test_an_empty_page_can_still_supply_a_next_link(): void
    {
        // Put a root-relative next target on an empty initial page.
        Http::fakeSequence()->push(['data' => []], 200, ['Link' => '</v2/users?page=2>; rel=next'])->push(['data' => [['id' => 2]]]);

        // Continue until the relation disappears rather than stopping at the empty array.
        self::assertSame([['id' => 2]], Http::paginate(self::API_URL, next: 'header')->all());
        $this->assertRequestedUrls([self::API_URL, 'https://api.example.test/v2/users?page=2']);
    }

    /**
     * Confirm repeated Link URLs fail before another request is sent.
     */
    public function test_repeated_link_urls_are_rejected(): void
    {
        // Point next back to the initial URL with a different fragment.
        Http::fakeSequence()->push(['data' => []], 200, ['Link' => '<'.self::API_URL.'#same>; rel="next"']);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('HTTP paginate encountered a repeated next URL.');

        // Consume the self-referencing response while checking the final request count.
        try {
            // Detect the repeated destination after reading the initial Link header.
            Http::paginate(self::API_URL, next: 'header')->all();
        } finally {
            // Prevent another request from repeating the initial page.
            Http::assertSentCount(1);
        }
    }
}
