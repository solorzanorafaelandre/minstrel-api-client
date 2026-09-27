<?php

declare(strict_types=1);

namespace Minstrel\ApiClient\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Minstrel\ApiClient\MinstrelApiException;
use Minstrel\ApiClient\MinstrelClient;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

/** The client against canned answers shaped like docs/12 §1: no network. */
final class TransportTest extends TestCase
{
    /** @var list<array{request: RequestInterface}> */
    private array $history = [];

    /** @param  list<Response>  $responses */
    private function client(array $responses, ?string $token = 'tok'): MinstrelClient
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));
        $http = new Client(['handler' => $stack]);

        return $token === null
            ? MinstrelClient::withClientCredentials('https://acme.minstrel.app/api/v1', 'id', 'secret', ['crm.read'], $http)
            : MinstrelClient::withToken('https://acme.minstrel.app/api/v1', $token, $http);
    }

    private static function json(array $body, int $status = 200): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR));
    }

    public function test_it_trades_client_credentials_for_a_token_and_sends_it(): void
    {
        $client = $this->client([self::json(['access_token' => 'abc', 'expires_in' => 3600]), self::json(['data' => ['id' => 'x'], 'request_id' => 'r'])], token: null);

        $client->me()->get();

        $token = $this->history[0]['request'];
        self::assertSame('https://acme.minstrel.app/oauth/token', (string) $token->getUri());
        parse_str((string) $token->getBody(), $form);
        self::assertSame(['grant_type' => 'client_credentials', 'client_id' => 'id', 'client_secret' => 'secret', 'scope' => 'crm.read'], $form);
        self::assertSame('Bearer abc', $this->history[1]['request']->getHeaderLine('Authorization'));
    }

    public function test_it_pages_through_every_record_with_the_cursors(): void
    {
        $client = $this->client([
            self::json(['data' => [['id' => 1], ['id' => 2]], 'meta' => ['limit' => 2, 'next_cursor' => 'c2', 'prev_cursor' => null], 'request_id' => 'a']),
            self::json(['data' => [['id' => 3]], 'meta' => ['limit' => 2, 'next_cursor' => null, 'prev_cursor' => 'c1'], 'request_id' => 'b']),
        ]);

        $ids = array_map(fn (array $r): int => $r['id'], iterator_to_array($client->accounts()->listAll(['limit' => 2, 'filter' => ['email' => 'a@b.c']]), false));

        self::assertSame([1, 2, 3], $ids);
        self::assertSame('limit=2&filter%5Bemail%5D=a%40b.c', $this->history[0]['request']->getUri()->getQuery());
        self::assertStringContainsString('cursor=c2', $this->history[1]['request']->getUri()->getQuery());
    }

    public function test_every_write_carries_an_idempotency_key_and_a_json_body(): void
    {
        $client = $this->client([self::json(['data' => ['id' => 9]], 201), self::json(['data' => ['id' => 9]], 201)]);

        $client->accounts()->create(['name' => 'Acme', 'credit_limit_minor' => 500000], 'my-key');
        $client->accounts()->create(['name' => 'Acme']);

        $first = $this->history[0]['request'];
        self::assertSame('POST', $first->getMethod());
        self::assertSame('my-key', $first->getHeaderLine('Idempotency-Key'));
        self::assertSame(['name' => 'Acme', 'credit_limit_minor' => 500000], json_decode((string) $first->getBody(), true));
        self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $this->history[1]['request']->getHeaderLine('Idempotency-Key'));
    }

    public function test_it_raises_the_error_envelope(): void
    {
        $client = $this->client([self::json(['error' => ['code' => 'not_found', 'message' => 'No such account.', 'details' => [], 'request_id' => 'req-1']], 404)]);

        try {
            $client->accounts()->get(123);
            self::fail('Expected an exception.');
        } catch (MinstrelApiException $e) {
            self::assertSame(404, $e->status);
            self::assertSame('not_found', $e->errorCode);
            self::assertSame('req-1', $e->requestId);
        }
    }

    public function test_path_parameters_are_encoded(): void
    {
        $client = $this->client([self::json(['data' => []])]);

        $client->folios()->get('a/b');

        self::assertSame('/api/v1/folios/a%2Fb', $this->history[0]['request']->getUri()->getPath());
    }
}
