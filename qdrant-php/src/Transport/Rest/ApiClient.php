<?php

declare(strict_types=1);

namespace Qdrant\Transport\Rest;

use JsonException;
use Qdrant\Exceptions\ApiException;
use Qdrant\Exceptions\QdrantException;
use Qdrant\Exceptions\RateLimitException;
use Qdrant\Exceptions\ResponseDecodingException;
use Qdrant\Support\Normalizer;

final class ApiClient
{
    public const USER_AGENT = 'local-rag-qdrant-php-client/0.1.0';

    /**
     * @var list<callable(HttpRequest, callable): HttpResponse>
     */
    private array $middleware = [];

    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        private readonly string $baseUrl,
        private readonly int|float $timeout = 60,
        private readonly array $headers = [],
        private readonly ?string $apiKey = null,
        private readonly ?HttpTransportInterface $transport = null,
        private readonly mixed $authTokenProvider = null
    ) {
    }

    public function addMiddleware(callable $middleware): void
    {
        $this->middleware[] = $middleware;
    }

    public function request(string $method, string $path, array $query = [], mixed $body = null): array
    {
        $request = new HttpRequest(
            method: strtoupper($method),
            url: $this->buildUrl($path, $query),
            headers: $this->buildHeaders($body !== null),
            body: $this->encodeBody($body),
            timeout: $this->timeout
        );

        $response = $this->send($request);
        return $this->decodeResponse($response);
    }

    public function requestResult(string $method, string $path, array $query = [], mixed $body = null): mixed
    {
        $payload = $this->request($method, $path, $query, $body);
        return $payload['result'] ?? null;
    }

    private function send(HttpRequest $request): HttpResponse
    {
        $transport = $this->transport ?? new CurlHttpTransport();
        $runner = static fn (HttpRequest $request): HttpResponse => $transport->send($request);

        foreach (array_reverse($this->middleware) as $middleware) {
            $next = $runner;
            $runner = static fn (HttpRequest $request): HttpResponse => $middleware($request, $next);
        }

        return $runner($request);
    }

    private function buildUrl(string $path, array $query): string
    {
        $baseUrl = rtrim($this->baseUrl, '/');
        $path = ltrim($path, '/');
        $url = $baseUrl . '/' . $path;

        $query = array_filter(
            array_map(
                static function (mixed $value): mixed {
                    if (is_bool($value)) {
                        return $value ? 'true' : 'false';
                    }

                    if ($value === null) {
                        return null;
                    }

                    return (string) $value;
                },
                $query
            ),
            static fn (mixed $value): bool => $value !== null
        );

        if ($query === []) {
            return $url;
        }

        return $url . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * @return array<string, string>
     */
    private function buildHeaders(bool $hasBody): array
    {
        $headers = [
            'Accept' => 'application/json',
            'User-Agent' => self::USER_AGENT,
        ];

        if ($hasBody) {
            $headers['Content-Type'] = 'application/json';
        }

        if ($this->apiKey !== null && $this->apiKey !== '') {
            $headers['api-key'] = $this->apiKey;
        }

        if (is_callable($this->authTokenProvider)) {
            $token = (string) call_user_func($this->authTokenProvider);
            if ($token !== '') {
                $headers['Authorization'] = 'Bearer ' . $token;
            }
        }

        return array_replace($headers, $this->headers);
    }

    private function encodeBody(mixed $body): ?string
    {
        if ($body === null) {
            return null;
        }

        if (is_string($body)) {
            return $body;
        }

        try {
            return json_encode(Normalizer::normalize($body), JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new QdrantException('Unable to encode Qdrant request body: ' . $exception->getMessage(), 0, $exception);
        }
    }

    private function decodeResponse(HttpResponse $response): array
    {
        if ($response->statusCode === 429) {
            throw RateLimitException::fromResponse($response, $this->extractErrorMessage($response));
        }

        if (!in_array($response->statusCode, [200, 201, 202], true)) {
            throw ApiException::fromResponse($response, $this->extractErrorMessage($response));
        }

        $trimmedBody = trim($response->body);
        if ($trimmedBody === '') {
            return [];
        }

        try {
            $decoded = json_decode($trimmedBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new ResponseDecodingException(
                'Unable to decode Qdrant response JSON: ' . $exception->getMessage(),
                0,
                $exception
            );
        }

        if (!is_array($decoded)) {
            throw new ResponseDecodingException('Qdrant returned a non-object JSON response.');
        }

        return $decoded;
    }

    private function extractErrorMessage(HttpResponse $response): string
    {
        $payload = $response->json();
        if (is_array($payload)) {
            $status = $payload['status'] ?? null;
            if (is_array($status) && isset($status['error']) && is_string($status['error'])) {
                return $status['error'];
            }

            if (isset($payload['result']['error']) && is_string($payload['result']['error'])) {
                return $payload['result']['error'];
            }

            if (isset($payload['message']) && is_string($payload['message'])) {
                return $payload['message'];
            }
        }

        return sprintf('Qdrant request failed with HTTP %d.', $response->statusCode);
    }
}
