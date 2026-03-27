<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

final class CatalogLateInteractionEmbeddingService implements CatalogLateInteractionEncoder
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiKey,
        private readonly string $model,
        private readonly int $timeoutSeconds,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            baseUrl: (string) config('services.late_interaction.base_url', ''),
            apiKey: (string) config('services.late_interaction.api_key', ''),
            model: (string) config('services.late_interaction.model', ''),
            timeoutSeconds: (int) config('services.late_interaction.timeout', 60),
        );
    }

    public function configured(): bool
    {
        return trim($this->baseUrl) !== '' && trim($this->model) !== '';
    }

    /**
     * @param  list<string>  $texts
     * @return list<list<list<float>>>
     */
    public function embedBatch(array $texts): array
    {
        if ($texts === []) {
            return [];
        }

        if (! $this->configured()) {
            throw new RuntimeException('Late-interaction embedding service is not configured.');
        }

        try {
            $request = Http::timeout($this->timeoutSeconds)
                ->connectTimeout($this->timeoutSeconds)
                ->acceptJson();

            if ($this->apiKey !== '') {
                $request = $request->withToken($this->apiKey);
            }

            $response = $request
                ->post(rtrim($this->baseUrl, '/').'/embeddings', [
                    'model' => $this->model,
                    'input' => $texts,
                ])
                ->throw();
        } catch (Throwable $exception) {
            throw new RuntimeException(
                sprintf(
                    'Unable to create late-interaction embeddings via %s with model %s: %s',
                    $this->baseUrl,
                    $this->model,
                    $exception->getMessage(),
                ),
                previous: $exception,
            );
        }

        $items = $response->json('data');
        if (! is_array($items)) {
            $items = $response->json('embeddings');
        }

        if (! is_array($items)) {
            throw new RuntimeException('Late-interaction embedding response did not contain a data or embeddings array.');
        }

        $vectors = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                throw new RuntimeException('Late-interaction embedding response item was not an object.');
            }

            $vectorPayload = isset($item['embedding']) && is_array($item['embedding'])
                ? $item['embedding']
                : $item;

            if (! is_array($vectorPayload) || ! array_is_list($vectorPayload)) {
                throw new RuntimeException('Late-interaction embedding response item was not a token matrix.');
            }

            $vectors[] = array_map(
                static function (mixed $tokenVector): array {
                    if (! is_array($tokenVector)) {
                        throw new RuntimeException('Late-interaction token vector was not an array.');
                    }

                    return array_values(array_map(
                        static fn (mixed $value): float => (float) $value,
                        $tokenVector,
                    ));
                },
                $vectorPayload,
            );
        }

        return $vectors;
    }
}
