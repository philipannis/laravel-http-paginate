<?php

namespace PhilipAnnis\HttpPaginate;

use Generator;
use GuzzleHttp\Psr7\Query;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\LazyCollection;
use InvalidArgumentException;
use JsonException;
use RuntimeException;
use UnexpectedValueException;

/**
 * Traverse one API a page at a time using the caller's configured HTTP client.
 *
 * @internal
 */
final class HttpPaginatePaginator
{
    /**
     * Validate pagination settings before returning a lazy collection.
     *
     * @param  PendingRequest  $request  An independent snapshot of the configured request builder.
     * @param  string  $url  The expanded initial endpoint.
     * @param  string  $query  Default query parameters captured from the request builder.
     * @param  string|null  $page  The incrementing page parameter.
     * @param  string|null  $cursor  The JSON path containing the next cursor.
     * @param  string|null  $next  The JSON next-link path, or header for Link headers.
     * @param  string  $items  The JSON path containing an array of items.
     * @param  int|null  $limit  The maximum number of pages to fetch.
     *
     * @throws InvalidArgumentException When pagination settings are invalid.
     */
    public function __construct(
        private readonly PendingRequest $request,
        private readonly string $url,
        private readonly string $query,
        private ?string $page,
        private readonly ?string $cursor,
        private readonly ?string $next,
        private readonly string $items,
        private readonly ?int $limit,
    ) {
        // Reject conflicting strategies before creating a collection or sending a request.
        if (count(array_filter([$page, $cursor, $next], static fn (?string $value): bool => $value !== null)) > 1) {
            throw new InvalidArgumentException('HTTP paginate accepts only one of page, cursor, or next.');
        }

        // Keep empty parameter names and JSON paths from silently selecting another behavior.
        foreach (['page' => $page, 'cursor' => $cursor, 'next' => $next, 'items' => $items] as $name => $value) {
            if ($value !== null && trim($value) === '') {
                throw new InvalidArgumentException('The HTTP paginate '.$name.' must not be empty.');
            }
        }

        // Require an explicit page limit to permit at least one request.
        if ($limit !== null && $limit <= 0) {
            throw new InvalidArgumentException('The HTTP paginate limit must be a positive number of pages.');
        }

        // Reject an empty endpoint before lazy execution can hide the argument error.
        if (trim($url) === '') {
            throw new InvalidArgumentException('The HTTP paginate URL must not be empty.');
        }

        // Default to numbered pages only when no strategy was explicitly selected.
        $this->page = $page ?? ($cursor === null && $next === null ? 'page' : null);
    }

    /**
     * Create a native collection with independent state for every traversal.
     *
     * @return LazyCollection<int, mixed> The ordered items from each fetched page.
     */
    public function collect(): LazyCollection
    {
        // Defer the request and its iteration state until Laravel consumes the collection.
        return new LazyCollection(function (): Generator {
            // Start each traversal with its own builder, counters, and continuation history.
            $request = clone $this->request;
            $url = $this->withQuery($this->url);
            $page = 1;
            $key = 0;
            $seenUrls = [];
            $initialCursor = Query::parse((new Uri($url))->getQuery())['cursor'] ?? null;
            $seenCursors = is_string($initialCursor) ? [$initialCursor => true] : [];

            // Fetch sequentially until the selected strategy or page limit terminates traversal.
            while (true) {
                // Replace the selected page parameter without accumulating earlier values.
                if ($this->page !== null) {
                    $url = (string) Uri::withQueryValue(new Uri($url), $this->page, (string) $page);
                }

                // Ignore fragments because they never identify a different HTTP request.
                $url = (string) (new Uri($url))->withFragment('');

                // Reject a previously fetched link before another request can repeat its items.
                if ($this->next !== null && isset($seenUrls[$url])) {
                    throw new RuntimeException('HTTP paginate encountered a repeated next URL.');
                }
                // Retain URL history only for strategies that follow next-page links.
                if ($this->next !== null) {
                    $seenUrls[$url] = true;
                }

                // Send through Laravel so authentication, middleware, retries, and fakes still apply.
                $response = $request->get($url)->throw();
                $items = $this->responseItems($response);

                // Assign continuous keys so collecting multiple pages cannot overwrite earlier items.
                foreach ($items as $item) {
                    yield $key++ => $item;
                }

                // Finish before reading continuation metadata or probing an unnecessary page.
                if (($this->limit !== null && $page >= $this->limit) || ($this->page !== null && $items === [])) {
                    return;
                }

                // Advance using the selected response metadata after all current items are consumed.
                if ($this->cursor !== null) {
                    // Read nested cursor paths using Laravel's usual JSON access behavior.
                    $cursor = $response->json($this->cursor);

                    // Missing, null, and blank cursors mark the end while preserving zero tokens.
                    if ($cursor === null || (is_string($cursor) && trim($cursor) === '')) {
                        return;
                    }
                    // Reject malformed tokens instead of coercing them into a new request.
                    if (! is_string($cursor) && ! is_int($cursor)) {
                        throw new UnexpectedValueException('The HTTP paginate next cursor must be a string, integer, or null.');
                    }
                    // Detect both immediate repetitions and cycles through earlier cursors.
                    if (isset($seenCursors[(string) $cursor])) {
                        throw new RuntimeException('HTTP paginate encountered a repeated cursor.');
                    }
                    // Remember the token and replace the cursor parameter for the following page.
                    $seenCursors[(string) $cursor] = true;
                    $url = (string) Uri::withQueryValue(new Uri($url), 'cursor', (string) $cursor);
                } elseif ($this->next !== null) {
                    // Reserve header for Link parsing and use JSON paths for other next selectors.
                    $next = $this->next === 'header' ? HttpPaginateLink::next($response) : $response->json($this->next);

                    // Missing, null, and blank links terminate without a discovery request.
                    if ($next === null || (is_string($next) && trim($next) === '')) {
                        return;
                    }
                    // Keep malformed link metadata visible instead of guessing a destination.
                    if (! is_string($next)) {
                        throw new UnexpectedValueException('The HTTP paginate next URL must be a string or null.');
                    }

                    // Transfer statistics provide the URL after redirects. Faked responses use the requested URL.
                    $url = $this->withQuery((string) UriResolver::resolve($response->effectiveUri() ?? new Uri($url), new Uri($next)));
                }

                // Count a completed page independently of any retry attempts within its request.
                $page++;
            }
        });
    }

    /**
     * Extract an actual JSON array while retaining Laravel's associative item values.
     *
     * @param  Response  $response  The response after Laravel's retries and callbacks.
     * @return array<int, mixed> The items from this page.
     *
     * @throws UnexpectedValueException When the response is not a successful JSON page.
     */
    private function responseItems(Response $response): array
    {
        // Reject other unsuccessful statuses after Laravel has handled HTTP errors.
        if (! $response->successful()) {
            throw new UnexpectedValueException('The HTTP paginate response must have a successful status.');
        }

        // Preserve JSON array types because associative decoding also turns empty objects into arrays.
        try {
            $data = json_decode($response->body(), flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            // Report invalid JSON while retaining the original decoding failure as its cause.
            throw new UnexpectedValueException('The HTTP paginate response must contain valid JSON.', 0, $exception);
        }

        // Require an actual array at the selected path, including on terminal pages.
        if (! is_array(data_get($data, $this->items))) {
            throw new UnexpectedValueException('The HTTP paginate items at '.$this->items.' must be a JSON array.');
        }

        // Return the same associative item values Laravel exposes through Response::json().
        return $response->json($this->items);
    }

    /**
     * Preserve configured query defaults while allowing each URL to supply its own values.
     *
     * @param  string  $url  The initial or resolved next-page URL.
     * @return string The URL with non-conflicting request query parameters.
     */
    private function withQuery(string $url): string
    {
        // Keep raw query strings so repeated values and dotted parameter names remain intact.
        $uri = new Uri($url);
        $defaults = (new Uri)->withQuery($this->query);

        // Remove overlapping defaults without normalizing dotted names or repeated URL parameters.
        foreach (array_keys(Query::parse($uri->getQuery())) as $name) {
            $defaults = Uri::withoutQueryValue($defaults, (string) $name);
        }

        // Join remaining defaults with the URL's own values using a single query separator.
        $query = $defaults->getQuery();
        if ($query !== '' && $uri->getQuery() !== '') {
            $query .= '&';
        }

        // Preserve the URI's other components while replacing only its merged query.
        return (string) $uri->withQuery($query.$uri->getQuery());
    }
}
