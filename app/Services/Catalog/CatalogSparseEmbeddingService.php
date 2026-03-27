<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use Illuminate\Support\Facades\Http;
use Qdrant\Models\SparseVector;
use RuntimeException;
use Throwable;

final class CatalogSparseEmbeddingService implements CatalogSparseEncoder
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
            baseUrl: (string) config('services.sparse_embedding.base_url', ''),
            apiKey: (string) config('services.sparse_embedding.api_key', ''),
            model: (string) config('services.sparse_embedding.model', ''),
            timeoutSeconds: (int) config('services.sparse_embedding.timeout', 60),
        );
    }

    public function configured(): bool
    {
        return trim($this->baseUrl) !== '' && trim($this->model) !== '';
    }

    /**
     * @param  list<string>  $texts
     * @return list<SparseVector>
     */
    public function embedBatch(array $texts): array
    {
        if ($texts === []) {
            return [];
        }

        if (! $this->configured()) {
            throw new RuntimeException('Sparse embedding service is not configured.');
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
                    'Unable to create sparse embeddings via %s with model %s: %s',
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
            throw new RuntimeException('Sparse embedding response did not contain a data or embeddings array.');
        }

        $vectors = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                throw new RuntimeException('Sparse embedding response item was not an object.');
            }

            $vectorPayload = isset($item['embedding']) && is_array($item['embedding'])
                ? $item['embedding']
                : $item;

            if (! is_array($vectorPayload) || ! isset($vectorPayload['indices'], $vectorPayload['values'])) {
                throw new RuntimeException('Sparse embedding response item did not include indices and values.');
            }

            $vectors[] = SparseVector::fromArray($vectorPayload);
        }

        return $vectors;
    }
}
