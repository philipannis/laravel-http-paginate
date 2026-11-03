<?php

namespace PhilipAnnis\HttpPaginate\Tests\Unit;

use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\Response;
use PhilipAnnis\HttpPaginate\HttpPaginateLink;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Verify common Link syntax without an application or network transport.
 */
final class LinkTest extends TestCase
{
    /**
     * Confirm relation parsing preserves URI and quoted-string punctuation.
     *
     * @param  string|list<string>  $header  The Link header field values.
     * @param  string|null  $expected  The first applicable next URI reference.
     */
    #[DataProvider('linkHeaders')]
    public function test_next_relation_is_parsed(string|array $header, ?string $expected): void
    {
        // Wrap the raw fields in Laravel's normal HTTP response type.
        $response = new Response(new PsrResponse(200, ['Link' => $header]));

        // Compare the extracted next reference without attempting an HTTP request.
        self::assertSame($expected, HttpPaginateLink::next($response));
    }

    /**
     * Provide relation lists, quoted attributes, URI delimiters, and unrelated links.
     *
     * @return iterable<string, array{string|list<string>, string|null}> Headers and expected next references.
     */
    public static function linkHeaders(): iterable
    {
        // Accept absolute references and the standard quoted or unquoted relation forms.
        yield 'absolute URL' => ['<https://api.example.test/users?page=2>; rel="next"', 'https://api.example.test/users?page=2'];
        yield 'unquoted relation' => ['<?page=2>; rel=next', '?page=2'];
        yield 'multiple links' => ['<?page=1>; rel="prev", <?page=2>; rel="next", <?page=9>; rel="last"', '?page=2'];
        yield 'multiple fields' => [['<?page=1>; rel="prev"', '<?page=2>; rel="next"'], '?page=2'];
        yield 'relation list' => ['<?page=2>; rel="alternate next"', '?page=2'];
        yield 'case insensitive' => ['<?page=2>; REL="NEXT"', '?page=2'];
        yield 'optional whitespace' => ["<?page=2>;\t rel = \"next\"", '?page=2'];

        // Preserve delimiters inside URI references and quoted parameter values.
        yield 'URI comma' => ['<?cursor=a,b>; rel="next"', '?cursor=a,b'];
        yield 'URI semicolon' => ['<?cursor=a;b>; rel="next"', '?cursor=a;b'];
        yield 'quoted comma' => ['<?page=2>; title="Two, three"; rel="next"', '?page=2'];
        yield 'quoted semicolon' => ['<?page=2>; title="Two; three"; rel="next"', '?page=2'];
        yield 'escaped quote' => ['<?page=2>; title="Two \\"quoted\\" pages, next"; rel="next"', '?page=2'];

        // Ignore relation-like text, duplicate overrides, anchored contexts, and unrelated links.
        yield 'quoted fake relation' => ['<?page=2>; title="; rel=next"; rel="prev"', null];
        yield 'first relation wins' => ['<?page=2>; rel="prev"; rel="next"', null];
        yield 'anchored link' => ['<?page=2>; rel="next"; anchor="/other"', null];
        yield 'anchored link before next' => ['<?page=2>; anchor="/other"; rel="next", <?page=3>; rel="next"', '?page=3'];
        yield 'unrelated relation' => ['<?page=2>; rel="next-page"', null];
        yield 'no relation' => ['<?page=2>; title="next"', null];
        yield 'empty header' => ['', null];
    }
}
