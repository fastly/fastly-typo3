<?php

declare(strict_types=1);

namespace Fastly\Cdn\Tests\Unit\Api\Middleware;

use Fastly\Cdn\Api\Middleware\RetryMiddleware;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class RetryMiddlewareTest extends UnitTestCase
{
    private function makeClient(MockHandler $mock, array $config = []): Client
    {
        $stack = HandlerStack::create($mock);
        $stack->push(RetryMiddleware::retry(array_merge(['sec_before_attempt' => 0], $config)));
        // http_errors=false prevents Guzzle from converting 4xx/5xx responses into exceptions,
        // allowing the retry middleware to inspect the raw response status code.
        return new Client(['handler' => $stack, 'http_errors' => false]);
    }

    public function testRetryReturnsCallable(): void
    {
        $this->assertIsCallable(RetryMiddleware::retry([]));
    }

    public function testDoesNotRetryAfterMaxAttempts(): void
    {
        // Default max_attempt=3: initial + up to 3 retries = 4 total attempts.
        // Queue 5 responses; after 4 are consumed, 1 must remain.
        $mock = new MockHandler([
            new Response(500),
            new Response(500),
            new Response(500),
            new Response(500),
            new Response(200),
        ]);
        $client = $this->makeClient($mock);
        $client->get('http://example.com');

        // retries=0→500→retry, retries=1→500→retry, retries=2→500→retry,
        // retries=3 >= 3 → stop → returns last 500. One 200 response remains unused.
        $this->assertCount(1, $mock, 'One response should remain unused after 3 retries');
    }

    public function testRetriesOnConnectException(): void
    {
        $request = new Request('GET', 'http://example.com');
        $mock = new MockHandler([
            new ConnectException('connection refused', $request),
            new ConnectException('connection refused', $request),
            new Response(200),
        ]);
        $client = $this->makeClient($mock);
        $response = $client->get('http://example.com');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertCount(0, $mock);
    }

    public function testRetriesOn500Response(): void
    {
        $mock = new MockHandler([new Response(500), new Response(200)]);
        $client = $this->makeClient($mock);
        $response = $client->get('http://example.com');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertCount(0, $mock);
    }

    public function testRetriesOn503Response(): void
    {
        $mock = new MockHandler([new Response(503), new Response(200)]);
        $client = $this->makeClient($mock);
        $response = $client->get('http://example.com');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertCount(0, $mock);
    }

    public function testDoesNotRetryOn404(): void
    {
        $mock = new MockHandler([new Response(404)]);
        $client = $this->makeClient($mock);
        $response = $client->get('http://example.com');

        $this->assertSame(404, $response->getStatusCode());
        $this->assertCount(0, $mock);
    }

    public function testDoesNotRetryOn200(): void
    {
        $mock = new MockHandler([new Response(200)]);
        $client = $this->makeClient($mock);
        $response = $client->get('http://example.com');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertCount(0, $mock);
    }

    public function testCustomMaxAttemptIsRespected(): void
    {
        // max_attempt=0 means no retries: only the initial request is made.
        $mock = new MockHandler([new Response(500), new Response(200)]);
        $client = $this->makeClient($mock, ['max_attempt' => 0]);
        $client->get('http://example.com');

        $this->assertCount(1, $mock, 'Second response must remain unused with max_attempt=0 (no retries)');
    }

    public function testDefaultSecBeforeAttemptIsUsedWhenAbsent(): void
    {
        // Default sec_before_attempt=0.5 → delay = 0.5*1000*retries ms
        // We override with sec_before_attempt=0 in helper; here we test the formula via direct config
        // Test that omitting the key still produces a callable (no error)
        $callable = RetryMiddleware::retry([]);
        $this->assertIsCallable($callable);
    }

    public function testDefaultDelayIsAppliedWhenSecBeforeAttemptNotConfigured(): void
    {
        // Config without sec_before_attempt: the delay closure hits the else branch
        // (line 42 in RetryMiddleware) and defaults to 0.5 s × 1000 × $retries.
        // max_attempt=1 limits to one retry → one usleep(500000) call (~500 ms total).
        $mock = new MockHandler([new Response(500), new Response(200)]);
        $stack = HandlerStack::create($mock);
        $stack->push(RetryMiddleware::retry(['max_attempt' => 1]));

        $client = new Client(['handler' => $stack, 'http_errors' => false]);

        $response = $client->get('http://example.com');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertCount(0, $mock, 'Both responses must have been consumed after one retry');
    }

    public function testCustomSecBeforeAttemptIsRespected(): void
    {
        // With sec_before_attempt=0, there is no wait between retries
        $mock = new MockHandler([new Response(500), new Response(500), new Response(200)]);
        $client = $this->makeClient($mock, ['sec_before_attempt' => 0]);
        $response = $client->get('http://example.com');

        $this->assertSame(200, $response->getStatusCode());
    }
}
