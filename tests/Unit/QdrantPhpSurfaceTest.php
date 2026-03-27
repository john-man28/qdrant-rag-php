<?php

declare(strict_types=1);

use Qdrant\Models\CreateCollectionRequest;
use Qdrant\Models\Distance;
use Qdrant\Models\HnswConfigDiff;
use Qdrant\Models\Modifier;
use Qdrant\Models\MultiVectorComparator;
use Qdrant\Models\MultiVectorConfig;
use Qdrant\Models\PointStruct;
use Qdrant\Models\Prefetch;
use Qdrant\Models\QueryRequest;
use Qdrant\Models\SparseVector;
use Qdrant\Models\SparseVectorParams;
use Qdrant\Models\VectorParams;

it('serializes collection requests with sparse vectors and multivector config', function () {
    $request = new CreateCollectionRequest(
        vectors: [
            'dense' => new VectorParams(
                size: 1024,
                distance: Distance::COSINE,
            ),
            'late' => new VectorParams(
                size: 128,
                distance: Distance::COSINE,
                multivectorConfig: new MultiVectorConfig(MultiVectorComparator::MAX_SIM),
                hnswConfig: new HnswConfigDiff(m: 0),
            ),
        ],
        sparseVectors: [
            'sparse' => new SparseVectorParams(
                modifier: Modifier::IDF,
            ),
        ],
    );

    expect($request->toArray())->toBe([
        'vectors' => [
            'dense' => [
                'size' => 1024,
                'distance' => 'Cosine',
            ],
            'late' => [
                'size' => 128,
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

it('serializes points with named dense sparse and late vectors', function () {
    $point = new PointStruct(
        id: 'sku::primary',
        vector: [
            'dense' => [0.1, 0.2, 0.3],
            'sparse' => new SparseVector(indices: [1, 9], values: [0.4, 0.8]),
            'late' => [
                [0.5, 0.6],
                [0.7, 0.8],
            ],
        ],
        payload: [
            'sku' => 'OSFHU-ITW',
        ],
    );

    expect($point->toArray())->toBe([
        'id' => 'sku::primary',
        'vector' => [
            'dense' => [0.1, 0.2, 0.3],
            'sparse' => [
                'indices' => [1, 9],
                'values' => [0.4, 0.8],
            ],
            'late' => [
                [0.5, 0.6],
                [0.7, 0.8],
            ],
        ],
        'payload' => [
            'sku' => 'OSFHU-ITW',
        ],
    ]);
});

it('serializes query requests with list prefetches and a late interaction final query', function () {
    $request = new QueryRequest(
        query: [
            [0.1, 0.2],
            [0.3, 0.4],
        ],
        using: 'late',
        limit: 10,
        withPayload: true,
        prefetch: [
            new Prefetch(
                query: [0.9, 0.8, 0.7],
                using: 'dense',
                limit: 20,
            ),
            new Prefetch(
                query: new SparseVector(indices: [2, 5], values: [0.6, 0.1]),
                using: 'sparse',
                limit: 20,
            ),
        ],
    );

    expect($request->toArray())->toBe([
        'query' => [
            [0.1, 0.2],
            [0.3, 0.4],
        ],
        'using' => 'late',
        'limit' => 10,
        'with_payload' => true,
        'with_vector' => false,
        'prefetch' => [
            [
                'query' => [0.9, 0.8, 0.7],
                'using' => 'dense',
                'limit' => 20,
            ],
            [
                'query' => [
                    'indices' => [2, 5],
                    'values' => [0.6, 0.1],
                ],
                'using' => 'sparse',
                'limit' => 20,
            ],
        ],
    ]);
});
