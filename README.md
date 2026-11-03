# Laravel HTTP Paginate

![PHP](https://img.shields.io/badge/PHP-8.2%E2%80%938.5-777BB4?style=flat-square)
![Laravel](https://img.shields.io/badge/Laravel-12%E2%80%9313-FF2D20?style=flat-square)
[![License](https://img.shields.io/badge/license-MIT-334155?style=flat-square)](LICENSE)

Add `paginate()` to Laravel's HTTP client to read paginated APIs with a native `LazyCollection`. Choose numbered pages, cursors, JSON next links, or HTTP Link headers using named arguments. Your existing authentication, headers, middleware, timeouts, and retries apply to every page.

Requests run as you iterate through the collection. Items arrive in their original order, and pagination stops when you stop iterating.

## Contents

- [Installation](#installation)
- [Pagination modes](#pagination-modes)
- [Per-request options](#per-request-options)
- [Lazy collections](#lazy-collections)
- [Responses and failures](#responses-and-failures)
- [Request options and limits](#request-options-and-limits)
- [Testing](#testing)
- [License](#license)

## Installation

Requires PHP 8.2+ with Laravel 12, or PHP 8.3+ with Laravel 13.

```bash
composer require philipannis/laravel-http-paginate
```

Then call `paginate()` instead of sending a single GET request.

```php
use Illuminate\Support\Facades\Http;

// Fetch pages as their individual products are consumed.
$products = Http::withToken(config('services.catalog.token'))
    ->acceptJson()
    ->paginate('https://api.example.com/products');

foreach ($products as $product) {
    // Process product.
}
```

By default, requests start with `?page=1`, read items from the response's `data` array, and continue until an empty array is returned. The examples below use the same `Http` import.

Laravel discovers the service provider automatically. There are no package migrations, routes, commands, or scheduled jobs to set up. You don't need a published config file.

If you've disabled package discovery, add `PhilipAnnis\HttpPaginate\HttpPaginateServiceProvider::class` to the existing array in `bootstrap/providers.php`.

## Pagination modes

Select **one strategy** with `page`, `cursor`, or `next`. Omitting all three uses numbered pages with the query parameter `page`. Passing more than one throws `InvalidArgumentException` immediately, including when `page: 'page'` is combined with a cursor or next link.

### Numbered pages (default)

```php
// Use the default page query parameter.
$products = Http::paginate('https://api.example.com/products');

// Use the API's own page parameter name.
$products = Http::paginate('https://api.example.com/products', page: 'page_number');
```

The page number always starts at **1** and increases after each successful page. An existing value of the selected parameter is replaced. Unrelated query parameters are preserved without adding duplicate page parameters.

Pagination stops when the items array is empty. Without a page limit, one final request is needed to find that empty page. Page size and total-page metadata do not determine when pagination stops.

### Cursors

Pass the JSON path containing the **next cursor**, using Laravel's dot notation.

```php
// Read the next token from nested response metadata.
$products = Http::paginate('https://api.example.com/products', cursor: 'meta.next_cursor');
```

For this response, the following request includes `?cursor=abc123`.

```json
{
    "data": [{"id": 1}, {"id": 2}],
    "meta": {"next_cursor": "abc123"}
}
```

The cursor query parameter is always **`cursor`**. The `cursor` argument selects the response path, not the request parameter name. To begin with an existing cursor, include it in the initial URL or use `withQueryParameters(['cursor' => $token])`.

A missing, null, or blank cursor ends pagination. Strings and integers are accepted, including `"0"` and `0`. Repeated cursors throw `RuntimeException` before requesting the same token again. Other cursor value types throw `UnexpectedValueException`.

### JSON next links

```php
// Follow the next URL supplied in the JSON response.
$products = Http::paginate('https://api.example.com/products', next: 'links.next');
```

```json
{
    "data": [{"id": 1}, {"id": 2}],
    "links": {"next": "https://api.example.com/products?page=2"}
}
```

Absolute and relative links are supported. Relative references such as `?page=2`, `/v2/products?page=2`, and `../products?page=2` resolve against the current page URL, including its effective location after redirects.

A missing, null, or blank next URL ends pagination. Repeated request URLs throw `RuntimeException` before being fetched again. URL fragments do not identify a different request. Non-string next URLs throw `UnexpectedValueException`.

### HTTP Link headers

The special value **`header`** is reserved for the HTTP Link strategy. It does not read a JSON field named `header`.

```php
// Follow the next relation supplied in HTTP Link headers.
$products = Http::paginate('https://api.example.com/products', next: 'header');
```

```http
Link: <https://api.example.com/products?page=2>; rel="next", <https://api.example.com/products?page=9>; rel="last"
```

The first applicable link with a `next` relation supplies the following URL. Multiple header fields, multiple relations, quoted parameters, and relative URLs are supported. Links with an explicit `anchor` are ignored because they describe a different context.

Pagination ends when no next relation exists. Repeated URLs use the same loop detection as JSON next links. Items still come from the JSON response body.

## Per-request options

```php
// Read nested items while fetching no more than five numbered pages.
$products = Http::paginate(
    'https://api.example.com/products',
    page: 'page',
    items: 'response.data.items',
    limit: 5,
);
```

| Argument | Default | Purpose |
| --- | --- | --- |
| `url` | Required | The initial GET endpoint. Base URLs and URL templates work as usual. |
| `page` | `null` | The incrementing page query parameter. Uses `page` when no strategy is selected. |
| `cursor` | `null` | The JSON path containing the next cursor. Requests use the parameter `cursor`. |
| `next` | `null` | The JSON next-link path, or `header` for HTTP Link headers. |
| `items` | `'data'` | The dot-notation path containing a JSON array of items. |
| `limit` | `null` | The maximum number of pages to fetch, with no default cap. |

Blank parameter names and paths are rejected. Passing `null` for a strategy is the same as leaving it out. All settings are passed directly to `paginate()`.

### Limit the number of pages

```php
// Fetch at most five pages, retaining every item on those pages.
$products = Http::paginate('https://api.example.com/products', limit: 5);
```

`limit` counts **pages**, not items. Reaching the limit stops without a discovery request or reading another continuation token. Laravel's retries belong to the current page and can perform additional HTTP attempts. Zero and negative limits throw `InvalidArgumentException` immediately. Omit the argument or pass `null` for no page limit.

Use the collection's `take()` method to limit items instead.

## Lazy collections

```php
// Creating the collection sends no requests.
$products = Http::paginate('https://api.example.com/products');

foreach ($products as $product) {
    // Process product.
}

// Fetch only the pages needed to retrieve ten items.
$firstTen = Http::paginate('https://api.example.com/products')->take(10)->all();
```

Pages are fetched one at a time. Items are returned in order with continuous numeric keys. The package keeps the current page in memory and tracks cursor or URL values to detect repeated pages.

Breaking a loop or using `take()` stops additional page requests. A fetched page is still downloaded in full. Laravel's usual collection methods are available. Methods such as `all()` collect the returned items in memory.

Each iterator keeps its own pagination state and starts from the initial URL. Iterating the same collection again sends new requests.

## Responses and failures

Each response must have a successful 2xx status and contain valid JSON. The selected `items` path must resolve to a **JSON array**, even on the final page. Individual objects in that array are returned as associative PHP arrays, matching Laravel's `Response::json()` behavior.

```php
// Extract items from the API's results array.
$products = Http::paginate('https://api.example.com/products', items: 'results');

// Use dot notation for nested item arrays.
$products = Http::paginate('https://api.example.com/products', items: 'response.data.items');
```

Missing or null item paths, JSON objects in place of item arrays, invalid JSON, and other unexpected structures throw `UnexpectedValueException`. They never silently become an empty result. An empty HTTP 204 body also fails JSON validation. An empty items array ends numbered pagination. Cursor and link strategies continue when the response supplies a valid next cursor or link.

HTTP 4xx and 5xx responses use Laravel's `RequestException`, with its normal response attached. Connection failures retain Laravel's `ConnectionException`. An unfollowed redirect or other non-successful status outside those HTTP error ranges throws `UnexpectedValueException`.

Arguments are validated when `paginate()` is called. Network failures, invalid responses, and repeated cursors or URLs are reported during iteration. Items from earlier pages may already have been processed when a later page fails. Exceptions from application middleware or callbacks propagate normally.

## Request options and limits

### Client setup

Configure the request before calling `paginate()`.

```php
// Keep authentication and timeouts on every cursor request.
$products = Http::withToken(config('services.catalog.token'))
    ->timeout(10)
    ->paginate('https://api.example.com/products', cursor: 'meta.next_cursor');

// Apply tenant headers and Laravel's retry policy to every numbered page.
$products = Http::withToken(config('services.catalog.token'))
    ->acceptJson()
    ->withHeaders(['X-Tenant-ID' => '42'])
    ->timeout(10)
    ->retry(3, 100)
    ->paginate('https://api.example.com/products');
```

Authentication, headers, timeouts, retry rules, middleware, callbacks, transfer options, HTTP events, and fakes keep using Laravel's client. Retry callbacks that update credentials apply to later pages in the same traversal. Pagination does not change the original builder's query or request options.

Base URLs and URL parameters are expanded before adding pagination values. Parameters from `withQueryParameters()` or the `query` transfer option act as defaults on every page. Values already present in a page URL take precedence, and numbered pagination replaces its own parameter with the current page number.

For link strategies, the next URL controls the next request's path and query. Filters supplied only in the initial URL are not automatically copied into later links. Use `withQueryParameters()` for filters that should apply to every page when the API leaves them out.

Pagination sends synchronous **GET** requests with JSON item arrays at a non-empty path. Async builders are rejected. The return value is a collection, so further request configuration belongs before `paginate()`.

## Testing

### Test paginated requests in your application

`Http::fake()` and Laravel's ordinary HTTP assertions work with pagination. Configure fakes before creating the collection, then consume it before asserting requests.

```php
Http::preventStrayRequests();

Http::fakeSequence()
    ->push(['data' => [['id' => 1], ['id' => 2]]])
    ->push(['data' => [['id' => 3]]])
    ->push(['data' => []]);

$products = Http::paginate('https://api.example.com/products');

Http::assertNothingSent();

$this->assertSame([1, 2, 3], $products->pluck('id')->all());

Http::assertSentCount(3);
```

Include the terminal empty page in the request count for numbered pagination. Cursor and link strategies stop when their continuation value is missing, so return the same cursor or link metadata your API supplies in your fake responses.

## License

Released under the [MIT license](LICENSE).
