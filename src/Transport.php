<?php

declare(strict_types=1);

namespace Minstrel\ApiClient;

use Generator;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;
use Psr\Http\Message\ResponseInterface;

/**
 * The HTTP side of the client: bearer token, JSON, the error envelope as
 * MinstrelApiException, cursor pages, and an Idempotency-Key on every write
 * (a fresh one per call unless you pass yours — reuse it to retry safely).
 * Money comes back as `{minor, currency, formatted}` integers; send integers.
 */
final class Transport
{
    public const USER_AGENT = 'minstrel-api-client-php/1';

    private ClientInterface $http;

    /** @param  string  $baseUrl  the API root, e.g. https://acme.minstrel.app/api/v1 */
    public function __construct(
        private readonly string $baseUrl,
        private ?string $accessToken = null,
        ?ClientInterface $http = null,
    ) {
        $this->http = $http ?? new Client(['timeout' => 30, 'connect_timeout' => 10]);
    }

    /**
     * Trade client credentials for an access token at `{host}/oauth/token`.
     *
     * @param  list<string>  $scopes  empty = every scope the client holds
     */
    public function authenticate(string $clientId, string $clientSecret, array $scopes = []): string
    {
        $response = $this->send('POST', $this->tokenUrl(), [
            RequestOptions::FORM_PARAMS => array_filter([
                'grant_type' => 'client_credentials',
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'scope' => implode(' ', $scopes),
            ], fn (string $v): bool => $v !== ''),
        ], auth: false);
        $token = $this->decode($response)['access_token'] ?? null;
        if (! is_string($token)) {
            throw new MinstrelApiException($response->getStatusCode(), 'invalid_token_response', 'The token endpoint answered without an access_token.');
        }

        return $this->accessToken = $token;
    }

    public function setAccessToken(string $token): void
    {
        $this->accessToken = $token;
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function get(string $path, array $query = []): array
    {
        return $this->decode($this->send('GET', $this->url($path), [RequestOptions::QUERY => self::query($query)]));
    }

    /** @param  array<string, mixed>  $query */
    public function page(string $path, array $query = []): Page
    {
        $json = $this->get($path, $query);
        $meta = is_array($json['meta'] ?? null) ? $json['meta'] : [];

        return new Page(
            array_values(is_array($json['data'] ?? null) ? $json['data'] : []),
            is_string($meta['next_cursor'] ?? null) ? $meta['next_cursor'] : null,
            is_string($meta['prev_cursor'] ?? null) ? $meta['prev_cursor'] : null,
            is_string($json['request_id'] ?? null) ? $json['request_id'] : null,
        );
    }

    /**
     * Every record of a list, page by page.
     *
     * @param  array<string, mixed>  $query
     * @return Generator<int, array<string, mixed>>
     */
    public function all(string $path, array $query = []): Generator
    {
        do {
            $page = $this->page($path, $query);
            yield from $page->data;
            $query['cursor'] = $page->nextCursor;
        } while ($page->nextCursor !== null);
    }

    /**
     * @param  array<string, mixed>|null  $body
     * @return array<string, mixed>
     */
    public function write(string $method, string $path, ?array $body = null, ?string $idempotencyKey = null): array
    {
        $options = [RequestOptions::HEADERS => ['Idempotency-Key' => $idempotencyKey ?? self::uuid()]];
        if ($body !== null) {
            $options[RequestOptions::JSON] = $body;
        }

        return $this->decode($this->send($method, $this->url($path), $options));
    }

    /** @param  array<string, mixed>  $options */
    private function send(string $method, string $url, array $options, bool $auth = true): ResponseInterface
    {
        $headers = ['Accept' => 'application/json', 'User-Agent' => self::USER_AGENT] + ($options[RequestOptions::HEADERS] ?? []);
        if ($auth && $this->accessToken !== null) {
            $headers['Authorization'] = 'Bearer '.$this->accessToken;
        }
        $options[RequestOptions::HEADERS] = $headers;
        $options[RequestOptions::HTTP_ERRORS] = false;

        try {
            $response = $this->http->request($method, $url, $options);
        } catch (GuzzleException $e) {
            throw new MinstrelApiException(0, 'transport_error', $e->getMessage());
        }

        if ($response->getStatusCode() >= 400) {
            $json = json_decode((string) $response->getBody(), true);
            $error = is_array($json) && is_array($json['error'] ?? null) ? $json['error'] : [];

            throw new MinstrelApiException(
                $response->getStatusCode(),
                is_string($error['code'] ?? null) ? $error['code'] : (is_string($json['error'] ?? null) ? $json['error'] : 'http_'.$response->getStatusCode()),
                is_string($error['message'] ?? null) ? $error['message'] : $response->getReasonPhrase(),
                is_array($error['details'] ?? null) ? array_values($error['details']) : [],
                is_string($error['request_id'] ?? null) ? $error['request_id'] : ($response->getHeaderLine('X-Request-Id') ?: null),
            );
        }

        return $response;
    }

    /** @return array<string, mixed> */
    private function decode(ResponseInterface $response): array
    {
        $body = (string) $response->getBody();
        if ($body === '') {
            return [];
        }
        $json = json_decode($body, true);

        return is_array($json) ? $json : [];
    }

    private function url(string $path): string
    {
        return rtrim($this->baseUrl, '/').'/'.ltrim($path, '/');
    }

    private function tokenUrl(): string
    {
        $parts = parse_url($this->baseUrl);

        return ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '').(isset($parts['port']) ? ':'.$parts['port'] : '').'/oauth/token';
    }

    /**
     * `filter[status]=a,b`, `fields[event]=id,name`: nested arrays become the bracketed keys.
     *
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private static function query(array $query): array
    {
        return array_filter($query, fn ($v): bool => $v !== null);
    }

    private static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
