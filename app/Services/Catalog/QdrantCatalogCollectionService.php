<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use Illuminate\Support\Facades\Http;
use Qdrant\Models\CreateCollectionRequest;
use Qdrant\Models\Distance;
use Qdrant\Models\HnswConfigDiff;
use Qdrant\Models\Modifier;
use Qdrant\Models\MultiVectorComparator;
use Qdrant\Models\MultiVectorConfig;
use Qdrant\Models\SparseVectorParams;
use Qdrant\Models\VectorParams;
use Qdrant\QdrantClient;
use RuntimeException;

final class QdrantCatalogCollectionService implements CatalogPointUploader
{
    public function __construct(
        private readonly string $qdrantUrl,
        private readonly string $collectionName,
        private readonly int $timeoutSeconds,
        private readonly int $denseVectorSize,
        private readonly ?int $lateVectorSize,
        private readonly CatalogSearchMode $searchMode,
        private readonly string $denseVectorName,
        private readonly string $sparseVectorName,
        private readonly string $lateVectorName,
        private readonly Modifier $sparseModifier,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            qdrantUrl: (string) config('services.qdrant.url', 'http://localhost:6333'),
            collectionName: (string) config('services.qdrant.collection', 'catalog'),
            timeoutSeconds: (int) config('services.qdrant.timeout', 60),
            denseVectorSize: (int) config('services.qdrant.dense_vector_size', config('services.qdrant.vector_size', 1024)),
            lateVectorSize: filled(config('services.qdrant.late_vector_size'))
                ? (int) config('services.qdrant.late_vector_size')
                : null,
            searchMode: CatalogSearchMode::fromConfig(
                config('catalog.search.mode'),
                (bool) config('catalog.search.hybrid_enabled', false),
            ),
            denseVectorName: (string) config('services.qdrant.dense_vector_name', 'dense'),
            sparseVectorName: (string) config('services.qdrant.sparse_vector_name', 'sparse'),
            lateVectorName: (string) config('services.qdrant.late_vector_name', 'late'),
            sparseModifier: Modifier::fromValue((string) config('services.qdrant.sparse_modifier', Modifier::IDF->value)),
        );
    }

    /**
     * Delete collection if it exists, then create empty collection with configured vector size.
     */
    public function resetCollection(): void
    {
        $base = rtrim($this->qdrantUrl, '/');
        $encoded = rawurlencode($this->collectionName);
        $request = $this->createCollectionRequest();
        $client = $this->makeClient();

        Http::timeout($this->timeoutSeconds)
            ->connectTimeout($this->timeoutSeconds)
            ->acceptJson()
            ->delete("{$base}/collections/{$encoded}");

        $client->createCollection(
            collectionName: $this->collectionName,
            vectorsConfig: $request->vectors ?? [],
            sparseVectorsConfig: $request->sparseVectors,
        );
    }

    public function makeClient(): QdrantClient
    {
        return new QdrantClient(
            url: $this->qdrantUrl,
            timeout: $this->timeoutSeconds,
        );
    }

    public function collectionExists(): bool
    {
        return $this->makeClient()->collectionExists($this->collectionName);
    }

    public function searchMode(): CatalogSearchMode
    {
        return $this->searchMode;
    }

    public function denseVectorName(): string
    {
        return $this->denseVectorName;
    }

    public function sparseVectorName(): string
    {
        return $this->sparseVectorName;
    }

    public function lateVectorName(): string
    {
        return $this->lateVectorName;
    }

    public function collectionName(): string
    {
        return $this->collectionName;
    }

    public function uploadPoints(iterable $points, int $batchSize = 64, bool $wait = false): void
    {
        $this->makeClient()->uploadPoints(
            collectionName: $this->collectionName,
            points: $points,
            batchSize: $batchSize,
            parallel: 1,
            wait: $wait,
        );
    }

    public function createCollectionRequest(): CreateCollectionRequest
    {
        return match ($this->searchMode) {
            CatalogSearchMode::Dense => new CreateCollectionRequest(
                vectors: new VectorParams(
                    size: $this->denseVectorSize,
                    distance: Distance::COSINE,
                ),
            ),
            CatalogSearchMode::Hybrid => $this->hybridCollectionRequest(),
            CatalogSearchMode::HybridRerank => $this->hybridRerankCollectionRequest(),
        };
    }

    public function assertSearchCollectionSchema(): void
    {
        if (! $this->searchMode->usesNamedVectors()) {
            return;
        }

        $this->assertSearchQdrantConfig();

        $result = $this->collectionDetails();
        $vectors = data_get($result, 'config.params.vectors');
        $sparseVectors = data_get($result, 'config.params.sparse_vectors');

        if (! is_array($vectors) || isset($vectors['size']) || ! is_array($sparseVectors)) {
            throw new RuntimeException($this->modeRuntimeError('the Qdrant collection is still using the old dense-only schema.'));
        }

        $denseVector = $vectors[$this->denseVectorName] ?? null;
        $sparseVector = $sparseVectors[$this->sparseVectorName] ?? null;

        if (! is_array($denseVector) || (int) ($denseVector['size'] ?? 0) !== $this->denseVectorSize) {
            throw new RuntimeException($this->modeRuntimeError('the dense vector config does not match the configured schema.'));
        }

        if ((string) data_get($sparseVector, 'modifier') !== $this->sparseModifier->value) {
            throw new RuntimeException($this->modeRuntimeError('the sparse vector config does not match the configured schema.'));
        }

        if (! $this->searchMode->usesLateInteraction()) {
            return;
        }

        $lateVector = $vectors[$this->lateVectorName] ?? null;

        if (
            ! is_array($lateVector)
            || (int) ($lateVector['size'] ?? 0) !== $this->lateVectorSize
            || (string) data_get($lateVector, 'multivector_config.comparator') !== MultiVectorComparator::MAX_SIM->value
            || (int) data_get($lateVector, 'hnsw_config.m', -1) !== 0
        ) {
            throw new RuntimeException($this->modeRuntimeError('the late-interaction vector config does not match the configured schema.'));
        }
    }

    private function hybridCollectionRequest(): CreateCollectionRequest
    {
        $this->assertSearchQdrantConfig();

        return new CreateCollectionRequest(
            vectors: [
                $this->denseVectorName => new VectorParams(
                    size: $this->denseVectorSize,
                    distance: Distance::COSINE,
                ),
            ],
            sparseVectors: [
                $this->sparseVectorName => new SparseVectorParams(
                    modifier: $this->sparseModifier,
                ),
            ],
        );
    }

    private function hybridRerankCollectionRequest(): CreateCollectionRequest
    {
        $this->assertSearchQdrantConfig();

        return new CreateCollectionRequest(
            vectors: [
                $this->denseVectorName => new VectorParams(
                    size: $this->denseVectorSize,
                    distance: Distance::COSINE,
                ),
                $this->lateVectorName => new VectorParams(
                    size: $this->lateVectorSize ?? 0,
                    distance: Distance::COSINE,
                    multivectorConfig: new MultiVectorConfig(MultiVectorComparator::MAX_SIM),
                    hnswConfig: new HnswConfigDiff(m: 0),
                ),
            ],
            sparseVectors: [
                $this->sparseVectorName => new SparseVectorParams(
                    modifier: $this->sparseModifier,
                ),
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function collectionDetails(): array
    {
        $result = Http::timeout($this->timeoutSeconds)
            ->connectTimeout($this->timeoutSeconds)
            ->acceptJson()
            ->get(rtrim($this->qdrantUrl, '/').'/collections/'.rawurlencode($this->collectionName))
            ->throw()
            ->json('result');

        if (! is_array($result)) {
            throw new RuntimeException('Qdrant did not return collection details.');
        }

        return $result;
    }

    private function assertSearchQdrantConfig(): void
    {
        if ($this->denseVectorSize < 1) {
            throw new RuntimeException($this->modeRuntimeError('the dense vector size is missing or invalid.'));
        }

        $requiredNames = [
            $this->denseVectorName,
            $this->sparseVectorName,
        ];

        if ($this->searchMode->usesLateInteraction()) {
            if (($this->lateVectorSize ?? 0) < 1) {
                throw new RuntimeException($this->modeRuntimeError('the late-interaction vector size is missing or invalid.'));
            }

            $requiredNames[] = $this->lateVectorName;
        }

        foreach ($requiredNames as $name) {
            if (trim($name) === '') {
                throw new RuntimeException($this->modeRuntimeError('one or more Qdrant vector names are missing.'));
            }
        }
    }

    private function modeRuntimeError(string $reason): string
    {
        return sprintf(
            'Catalog search mode [%s] is enabled, but %s Rebuild the Qdrant collection and fully reindex the catalog.',
            $this->searchMode->value,
            $reason,
        );
    }
}
