<?php

namespace PhilipAnnis\HttpPaginate\Tests\Feature;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use PhilipAnnis\HttpPaginate\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use UnexpectedValueException;

/**
 * Verify unsuccessful or malformed responses never become successful empty pages.
 */
final class ResponseHandlingTest extends TestCase
{
    /**
     * The shared fake endpoint used by response-validation tests.
     */
    private const API_URL = 'https://api.example.test/users';

    /**
     * Confirm HTTP failures retain Laravel's request exception and response.
     *
     * @param  int  $status  The HTTP error returned by the API.
     */
    #[DataProvider('errorStatuses')]
    public function test_http_errors_throw_laravel_request_exceptions(int $status): void
    {
        // Supply a recognizable error body for each representative failure status.
        Http::fakeSequence()->push(['message' => 'API failure'], $status);

        // Inspect Laravel's native exception instead of replacing it with a package exception.
        try {
            // Fail explicitly if an unsuccessful response is accepted as a page.
            Http::paginate(self::API_URL)->all();
            self::fail('An unsuccessful API response did not throw.');
        } catch (RequestException $exception) {
            // Keep the upstream status and JSON body attached to the exception.
            self::assertSame($status, $exception->response->status());
            self::assertSame('API failure', $exception->response->json('message'));
        }

        // Stop traversal at the failed request.
        Http::assertSentCount(1);
    }

    /**
     * Provide representative client and server failures.
     *
     * @return iterable<string, array{int}> HTTP error status codes.
     */
    public static function errorStatuses(): iterable
    {
        // Cover authentication, missing resources, throttling, and server failures.
        yield 'unauthorized' => [401];
        yield 'not found' => [404];
        yield 'rate limited' => [429];
        yield 'server failure' => [500];
    }

    /**
     * Confirm invalid JSON reports a response format error during consumption.
     *
     * @param  string  $body  The malformed response body.
     */
    #[DataProvider('invalidJsonBodies')]
    public function test_invalid_json_is_rejected(string $body): void
    {
        // Create a collection without eagerly inspecting the malformed fake response.
        Http::fakeSequence()->push($body);
        $users = Http::paginate(self::API_URL);
        Http::assertNothingSent();

        // Require the error to identify JSON decoding when the collection is consumed.
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('The HTTP paginate response must contain valid JSON.');

        // Begin lazy response validation.
        $users->all();
    }

    /**
     * Provide invalid bodies that Laravel would otherwise decode to null.
     *
     * @return iterable<string, array{string}> Invalid JSON responses.
     */
    public static function invalidJsonBodies(): iterable
    {
        // Keep empty bodies, HTML, truncated JSON, and trailing content visible as errors.
        yield 'empty' => [''];
        yield 'HTML' => ['<html>API failure</html>'];
        yield 'truncated' => ['{"data": ['];
        yield 'trailing content' => ['{"data": []} trailing'];
    }

    /**
     * Confirm missing, null, scalar, and object item values are rejected.
     *
     * @param  string  $body  The JSON response with an invalid item structure.
     */
    #[DataProvider('invalidItemBodies')]
    public function test_invalid_item_structures_are_rejected(string $body): void
    {
        // Supply valid JSON whose selected item path is not a JSON array.
        Http::fakeSequence()->push($body);
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('The HTTP paginate items at data must be a JSON array.');

        // Reject malformed item structures instead of treating them as an empty page.
        Http::paginate(self::API_URL)->all();
    }

    /**
     * Provide valid JSON that cannot represent the expected item page.
     *
     * @return iterable<string, array{string}> Invalid response structures.
     */
    public static function invalidItemBodies(): iterable
    {
        // Reject omitted values and scalar item payloads.
        yield 'missing items' => ['{"other": []}'];
        yield 'null items' => ['{"data": null}'];
        yield 'string items' => ['{"data": "users"}'];
        yield 'boolean items' => ['{"data": false}'];
        yield 'integer items' => ['{"data": 1}'];

        // Distinguish JSON objects from arrays even when associative decoding looks identical.
        yield 'empty object' => ['{"data": {}}'];
        yield 'nonempty object' => ['{"data": {"first": {"id": 1}}}'];
        yield 'numeric object keys' => ['{"data": {"0": {"id": 1}}}'];

        // Reject scalar documents that cannot expose the configured item path.
        yield 'null document' => ['null'];
        yield 'scalar document' => ['1'];
    }

    /**
     * Confirm failures on later pages are not treated as normal termination.
     */
    public function test_a_later_page_error_is_not_treated_as_termination(): void
    {
        // Let the first page succeed before the next request fails.
        Http::fakeSequence()->push(['data' => [['id' => 1]]])->push(['message' => 'API failure'], 503);
        $iterator = Http::paginate(self::API_URL)->getIterator();

        // Permit processing the earlier item before reaching the unsuccessful page.
        self::assertSame(['id' => 1], $iterator->current());
        $this->expectException(RequestException::class);

        // Cross the page boundary and propagate Laravel's HTTP exception.
        $iterator->next();
    }

    /**
     * Confirm connection failures retain Laravel's native exception type.
     */
    public function test_connection_failures_propagate(): void
    {
        // Simulate a connection failure without opening a real connection.
        Http::fake(['*' => Http::failedConnection()]);
        $this->expectException(ConnectionException::class);

        // Preserve the connection exception during lazy request execution.
        Http::paginate(self::API_URL)->all();
    }

    /**
     * Confirm an unfollowed redirect cannot be mistaken for a successful page.
     */
    public function test_unsuccessful_redirect_response_is_rejected(): void
    {
        // Return valid-looking item JSON with a non-successful redirect status.
        Http::fakeSequence()->push(['data' => []], 302, ['Location' => '/login']);
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('The HTTP paginate response must have a successful status.');

        // Respect the caller's redirect setting while rejecting the unresolved response.
        Http::withoutRedirecting()->paginate(self::API_URL)->all();
    }
}
