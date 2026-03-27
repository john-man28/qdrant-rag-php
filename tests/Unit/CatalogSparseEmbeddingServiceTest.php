<?php

declare(strict_types=1);

use App\Services\Catalog\CatalogSparseEmbeddingService;
use Illuminate\Support\Facades\Http;
use Qdrant\Models\SparseVector;

it('parses sparse vectors from the embeddings response payload', function () {
    Http::fake([
        'http://sparse.test/embeddings' => Http::response([
            'data' => [[
                'embedding' => [
                    'indices' => [1, 4],
                    'values' => [0.7, 0.2],
                ],
            ]],
        ]),
    ]);

    $service = new CatalogSparseEmbeddingService(
        baseUrl: 'http://sparse.test',
        apiKey: 'test-key',
        model: 'sparse-model',
        timeoutSeconds: 60,
    );

    $vectors = $service->embedBatch(['warehouse sensor']);

    expect($vectors)->toHaveCount(1)
        ->and($vectors[0])->toBeInstanceOf(SparseVector::class)
        ->and($vectors[0]->toArray())->toBe([
            'indices' => [1, 4],
            'values' => [0.7, 0.2],
        ]);
});

it('throws a precise error when the sparse endpoint returns dense embeddings', function () {
    Http::fake([
        'http://sparse.test/embeddings' => Http::response([
            'data' => [[
                'embedding' => [0.1, 0.2, 0.3],
            ]],
        ]),
    ]);

    $service = new CatalogSparseEmbeddingService(
        baseUrl: 'http://sparse.test',
        apiKey: 'test-key',
        model: 'text-embedding-jina-embeddings-v5-text-small-retrieval',
        timeoutSeconds: 60,
    );

    expect(fn () => $service->embedBatch(['warehouse sensor']))
        ->toThrow(
            RuntimeException::class,
            'returned a dense embedding vector',
        );
});
