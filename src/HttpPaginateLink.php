<?php

namespace PhilipAnnis\HttpPaginate;

use Illuminate\Http\Client\Response;

/**
 * Read the next relation without splitting quoted parameters or URI delimiters.
 *
 * @internal
 */
final class HttpPaginateLink
{
    /**
     * Find the first next relation across all Link header fields.
     *
     * @param  Response  $response  The current page's response.
     * @return string|null The next URI reference, or null when no next relation exists.
     */
    public static function next(Response $response): ?string
    {
        // Inspect separate Link fields in the order supplied by the API.
        foreach ($response->toPsrResponse()->getHeader('Link') as $header) {
            // Commas inside URI references and quoted titles are not link separators.
            preg_match_all('~<([^>]*)>((?:[^",]|"(?:[^"\\\\]|\\\\.)*")*)~', $header, $links, PREG_SET_ORDER);

            // Read each link's attributes without treating quoted punctuation as delimiters.
            foreach ($links as $link) {
                preg_match_all('~;\s*([^\s=;]+)\s*=\s*("(?:[^"\\\\]|\\\\.)*"|[^\s;,]+)~', $link[2], $parameters, PREG_SET_ORDER);
                $attributes = [];

                // Compare parameter names case-insensitively while preserving their values.
                foreach ($parameters as $parameter) {
                    $name = strtolower($parameter[1]);
                    $value = $parameter[2];
                    // Remove quoted-string escaping before interpreting the relation list.
                    if (str_starts_with($value, '"')) {
                        $value = preg_replace('~\\\\(.)~s', '$1', substr($value, 1, -1));
                    }

                    // RFC 8288 gives the first occurrence of a relation parameter precedence.
                    $attributes[$name] ??= $value;
                }

                // An explicit anchor changes the link's context, so it cannot paginate this response.
                if (array_key_exists('anchor', $attributes)) {
                    continue;
                }

                // Match complete relation tokens so next-page cannot be mistaken for next.
                $relations = preg_split('/\s+/', strtolower(trim($attributes['rel'] ?? '')));
                if (in_array('next', $relations, true)) {
                    return $link[1];
                }
            }
        }

        // End pagination when no applicable next relation exists.
        return null;
    }
}
