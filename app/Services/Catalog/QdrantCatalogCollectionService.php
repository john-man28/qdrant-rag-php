<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use Illuminate\Support\Facades\Http;
use Qdrant\Models\Distance;
use Qdrant\Models\VectorParams;
use Qdrant\QdrantClient;

final class QdrantCatalogCollectionService
{
    public function __construct(
        private readonly string $qdrantUrl,
        private readonly string $collectionName,
        private readonly int $timeoutSeconds,
        private readonly int $vectorSize,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            qdrantUrl: (string) config('services.qdrant.url', 'http://localhost:6333'),
            collectionName: (string) config('services.qdrant.collection', 'catalog'),
            timeoutSeconds: (int) config('services.qdrant.timeout', 60),
            vectorSize: (int) config('services.qdrant.vector_size', 1024),
        );
    }

    /**
     * Delete collection if it exists, then create empty collection with configured vector size.
     */
    public function resetCollection(): void
    {
        $base = rtrim($this->qdrantUrl, '/');
        $encoded = rawurlencode($this->collectionName);

        Http::timeout($this->timeoutSeconds)
            ->acceptJson()
            ->delete("{$base}/collections/{$encoded}");

        $client = new QdrantClient(
            url: $this->qdrantUrl,
            timeout: $this->timeoutSeconds,
        );

        $client->createCollection(
            $this->collectionName,
            new VectorParams(
                size: $this->vectorSize,
                distance: Distance::COSINE,
            ),
        );
    }

    public function makeClient(): QdrantClient
    {
        return new QdrantClient(
            url: $this->qdrantUrl,
            timeout: $this->timeoutSeconds,
        );
    }

    public function collectionName(): string
    {
        return $this->collectionName;
    }
}
