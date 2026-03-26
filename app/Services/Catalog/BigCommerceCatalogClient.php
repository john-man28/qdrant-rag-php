<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

final class BigCommerceCatalogClient
{
    private const POOL_SIZE = 6;

    private const BATCH_DELAY_MS = 150;

    private const MAX_RETRIES = 3;

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $token,
    ) {}

    public static function fromConfig(): self
    {
        $hash = config('services.bigcommerce.store_hash');
        $token = config('services.bigcommerce.api_token');

        if (! is_string($hash) || $hash === '' || ! is_string($token) || $token === '') {
            throw new \RuntimeException('BC_API_TOKEN_PROD and BC_STORE_HASH_PROD must be set in .env');
        }

        $baseUrl = "https://api.bigcommerce.com/stores/{$hash}/v3/catalog";

        return new self($baseUrl, $token);
    }

    /**
     * @return array{0: int, 1: mixed}
     */
    public function get(string $url): array
    {
        $retries = 0;
        $backoff = 2;

        while (true) {
            $response = Http::timeout(60)
                ->withHeaders($this->headers())
                ->get($url);

            $httpCode = $response->status();

            if ($httpCode === 429 && $retries < self::MAX_RETRIES) {
                sleep($backoff);
                $backoff *= 2;
                $retries++;

                continue;
            }

            return [$httpCode, $response->json()];
        }
    }

    /**
     * @param  list<string>  $urls
     * @return array<string, array{0: int, 1: mixed}>
     */
    public function getBatch(array $urls): array
    {
        $retries = 0;
        $backoff = 2;

        while (true) {
            $responses = Http::pool(function (Pool $pool) use ($urls) {
                $pending = [];
                foreach ($urls as $i => $url) {
                    $pending[] = $pool->as((string) $i)
                        ->timeout(60)
                        ->withHeaders($this->headers())
                        ->get($url);
                }

                return $pending;
            });

            $has429 = false;
            $out = [];
            foreach ($urls as $i => $url) {
                /** @var Response $r */
                $r = $responses[(string) $i];
                $code = $r->status();
                if ($code === 429) {
                    $has429 = true;
                }
                $out[$url] = [$code, $r->json()];
            }

            if ($has429 && $retries < self::MAX_RETRIES) {
                sleep($backoff);
                $backoff *= 2;
                $retries++;

                continue;
            }

            return $out;
        }
    }

    public function poolSize(): int
    {
        return self::POOL_SIZE;
    }

    public function batchDelayMicroseconds(): int
    {
        return self::BATCH_DELAY_MS * 1000;
    }

    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return [
            'X-Auth-Token' => $this->token,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];
    }
}
