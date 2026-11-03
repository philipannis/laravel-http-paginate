<?php

namespace PhilipAnnis\HttpPaginate;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\LazyCollection;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Register lazy pagination on Laravel's existing HTTP request builder.
 *
 * @author Philip Annis
 *
 * @link https://github.com/philipannis
 */
class HttpPaginateServiceProvider extends ServiceProvider
{
    /**
     * Make paginate available on the HTTP facade and pending request chains.
     */
    public function boot(): void
    {
        // Register on PendingRequest so configured HTTP chains keep their existing behavior.
        PendingRequest::macro('paginate',
            /**
             * Yield individual API items without fetching pages before iteration.
             *
             * @param  string  $url  The API endpoint, including any initial query parameters.
             * @param  string|null  $page  The incrementing page parameter, defaulting to page when no strategy is selected.
             * @param  string|null  $cursor  The JSON path containing the next cursor, sent as cursor.
             * @param  string|null  $next  The next URL's JSON path, or header for HTTP Link headers.
             * @param  string  $items  The JSON path containing an array of items.
             * @param  int|null  $limit  The maximum number of pages to fetch, or null for no limit.
             * @return LazyCollection<int, mixed> The items in their original order.
             *
             * @throws InvalidArgumentException When pagination settings are invalid.
             */
            function (
                string $url,
                ?string $page = null,
                ?string $cursor = null,
                ?string $next = null,
                string $items = 'data',
                ?int $limit = null,
            ): LazyCollection {
                /** @var PendingRequest $this */
                // Require sequential responses for the collection's page-by-page traversal.
                if ($this->async) {
                    throw new InvalidArgumentException('HTTP paginate requires a synchronous request.');
                }

                // Capture the builder without changing requests that reuse the original chain.
                $request = clone $this;
                $query = $request->options['query'] ?? [];
                unset($request->options['query']);

                // Expand templates before pagination adds encoded query values, as Laravel does when sending.
                if (! Str::startsWith($url, ['http://', 'https://'])) {
                    $url = ltrim(rtrim($request->baseUrl, '/').'/'.ltrim($url, '/'), '/');
                }
                $url = $request->expandUrlParameters($url);

                // Return a lazy source with all pagination settings captured by this call.
                return (new HttpPaginatePaginator(
                    $request,
                    $url,
                    is_array($query) ? http_build_query($query, '', '&', PHP_QUERY_RFC3986) : $query,
                    $page,
                    $cursor,
                    $next,
                    $items,
                    $limit,
                ))->collect();
            },
        );
    }
}
