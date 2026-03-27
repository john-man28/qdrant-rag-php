<?php

declare(strict_types=1);

use App\Services\Catalog\CatalogDenseEncoder;
use App\Services\Catalog\CatalogLateInteractionEncoder;
use App\Services\Catalog\CatalogPointUploader;
use App\Services\Catalog\CatalogSparseEncoder;
use App\Services\Catalog\CatalogVectorEncodingOrchestrator;
use App\Services\Catalog\CatalogVectorIndexService;
use App\Services\Catalog\QdrantCatalogCollectionService;
use Qdrant\Models\PointStruct;
use Qdrant\Models\SparseVector;

beforeEach(function () {
    config()->set('catalog.search.hybrid_enabled', true);
    config()->set('services.qdrant.url', 'http://qdrant.test');
    config()->set('services.qdrant.collection', 'catalog');
    config()->set('services.qdrant.timeout', 60);
    config()->set('services.qdrant.dense_vector_size', 3);
    config()->set('services.qdrant.late_vector_size', 2);
    config()->set('services.qdrant.dense_vector_name', 'dense');
    config()->set('services.qdrant.sparse_vector_name', 'sparse');
    config()->set('services.qdrant.late_vector_name', 'late');
    config()->set('services.qdrant.sparse_modifier', 'idf');
});

it('invokes all three encoders for hybrid document encoding', function () {
    $calls = (object) ['dense' => 0, 'sparse' => 0, 'late' => 0];

    $orchestrator = new CatalogVectorEncodingOrchestrator(
        new class($calls) implements CatalogDenseEncoder
        {
            public function __construct(private readonly object $calls) {}

            public function configured(): bool
            {
                return true;
            }

            public function embedBatch(array $texts): array
            {
                $this->calls->dense++;

                return [[0.1, 0.2, 0.3]];
            }
        },
        new class($calls) implements CatalogSparseEncoder
        {
            public function __construct(private readonly object $calls) {}

            public function configured(): bool
            {
                return true;
            }

            public function embedBatch(array $texts): array
            {
                $this->calls->sparse++;

                return [new SparseVector(indices: [1, 4], values: [0.7, 0.2])];
            }
        },
        new class($calls) implements CatalogLateInteractionEncoder
        {
            public function __construct(private readonly object $calls) {}

            public function configured(): bool
            {
                return true;
            }

            public function embedBatch(array $texts): array
            {
                $this->calls->late++;

                return [[[0.4, 0.5], [0.6, 0.7]]];
            }
        },
        true,
    );

    $encoded = $orchestrator->encodeDocuments(['warehouse sensor']);

    expect($calls->dense)->toBe(1)
        ->and($calls->sparse)->toBe(1)
        ->and($calls->late)->toBe(1)
        ->and($encoded)->toHaveCount(1)
        ->and($encoded[0]['dense'])->toBe([0.1, 0.2, 0.3])
        ->and($encoded[0]['sparse']->toArray())->toBe([
            'indices' => [1, 4],
            'values' => [0.7, 0.2],
        ])
        ->and($encoded[0]['late'])->toBe([
            [0.4, 0.5],
            [0.6, 0.7],
        ]);
});

it('uploads named dense sparse and late vectors when hybrid indexing is enabled', function () {
    $collectionService = QdrantCatalogCollectionService::fromConfig();
    $capturedUploader = new class implements CatalogPointUploader
    {
        /** @var list<PointStruct> */
        public array $capturedPoints = [];

        public function uploadPoints(iterable $points, int $batchSize = 64, bool $wait = false): void
        {
            foreach ($points as $point) {
                $this->capturedPoints[] = $point;
            }
        }
    };

    $indexer = new CatalogVectorIndexService(
        new CatalogVectorEncodingOrchestrator(
            new class implements CatalogDenseEncoder
            {
                public function configured(): bool
                {
                    return true;
                }

                public function embedBatch(array $texts): array
                {
                    return [[0.1, 0.2, 0.3]];
                }
            },
            new class implements CatalogSparseEncoder
            {
                public function configured(): bool
                {
                    return true;
                }

                public function embedBatch(array $texts): array
                {
                    return [new SparseVector(indices: [3, 9], values: [0.9, 0.4])];
                }
            },
            new class implements CatalogLateInteractionEncoder
            {
                public function configured(): bool
                {
                    return true;
                }

                public function embedBatch(array $texts): array
                {
                    return [[[0.4, 0.5], [0.6, 0.7]]];
                }
            },
            true,
        ),
        $collectionService,
        $capturedUploader,
    );

    $path = tempnam(sys_get_temp_dir(), 'catalog-hybrid-index');
    file_put_contents($path, json_encode([
        'text' => "Product: Fixture Sensor\nSKU: OSFHU-ITW",
        'payload' => [
            'sku' => 'OSFHU-ITW',
            'name' => 'Fixture Sensor',
            'brand' => 'Acme',
            'categories' => ['Sensors'],
            'chunk_key' => 'primary',
        ],
    ], JSON_THROW_ON_ERROR).PHP_EOL);

    try {
        $indexer->indexChunkFile($path);
    } finally {
        @unlink($path);
    }

    expect($capturedUploader->capturedPoints)->toHaveCount(1);

    $serializedPoint = $capturedUploader->capturedPoints[0]->toArray();

    expect($serializedPoint['vector'])->toBe([
        'dense' => [0.1, 0.2, 0.3],
        'sparse' => [
            'indices' => [3, 9],
            'values' => [0.9, 0.4],
        ],
        'late' => [
            [0.4, 0.5],
            [0.6, 0.7],
        ],
    ]);
});

it('builds the hybrid collection schema on reset', function () {
    $request = QdrantCatalogCollectionService::fromConfig()->createCollectionRequest();

    expect($request->toArray())->toBe([
        'vectors' => [
            'dense' => [
                'size' => 3,
                'distance' => 'Cosine',
            ],
            'late' => [
                'size' => 2,
                'distance' => 'Cosine',
                'multivector_config' => [
                    'comparator' => 'max_sim',
                ],
                'hnsw_config' => [
                    'm' => 0,
                ],
            ],
        ],
        'sparse_vectors' => [
            'sparse' => [
                'modifier' => 'idf',
            ],
        ],
    ]);
});
