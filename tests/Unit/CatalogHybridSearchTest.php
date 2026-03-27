<?php

declare(strict_types=1);

use App\Services\Catalog\CatalogDenseEncoder;
use App\Services\Catalog\CatalogLateInteractionEncoder;
use App\Services\Catalog\CatalogSearchMode;
use App\Services\Catalog\CatalogSparseEncoder;
use App\Services\Catalog\CatalogVectorEncodingOrchestrator;
use App\Services\Catalog\QdrantCatalogCollectionService;
use App\Services\CatalogAgent\CatalogAgentConfig;
use App\Services\CatalogAgent\CatalogChatAgent;
use App\Services\CatalogAgent\ChatSessionState;
use Illuminate\Support\Facades\Http;
use Qdrant\Models\SparseVector;
use Qdrant\QdrantClient;
use Qdrant\Transport\Rest\ApiClient;
use Qdrant\Transport\Rest\HttpRequest;
use Qdrant\Transport\Rest\HttpResponse;
use Qdrant\Transport\Rest\HttpTransportInterface;

beforeEach(function () {
    config()->set('catalog.search.mode', CatalogSearchMode::HybridRerank->value);
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

it('uses the hybrid fusion nearest query path and preserves response shape and session state', function () {
    config()->set('catalog.search.mode', CatalogSearchMode::Hybrid->value);

    $transport = new CatalogHybridCaptureTransport(
        fn (HttpRequest $request): HttpResponse => str_contains($request->url, '/points/query')
            ? qdrantJsonResponse([
                'result' => [
                    'points' => [[
                        'id' => 'osfhu-itw::primary',
                        'version' => 1,
                        'score' => 0.98,
                        'payload' => [
                            'sku' => 'OSFHU-ITW',
                            'name' => 'Fixture Sensor',
                            'brand' => 'Acme',
                            'categories' => ['Sensors'],
                            'text' => "Product: Fixture Sensor\nSKU: OSFHU-ITW\n\nHigh bay occupancy sensor",
                        ],
                    ]],
                ],
            ])
            : qdrantJsonResponse(['result' => ['exists' => true]]),
    );

    $agent = new CatalogHybridAgentHarness(
        config: makeAgentConfig(CatalogSearchMode::Hybrid),
        vectorEncodings: new CatalogVectorEncodingOrchestrator(
            new CatalogHybridDenseEncoder([[0.1, 0.2, 0.3]]),
            new CatalogHybridSparseEncoder([new SparseVector(indices: [2, 5], values: [0.6, 0.1])]),
            new CatalogHybridLateEncoder([[[0.4, 0.5], [0.6, 0.7]]]),
            CatalogSearchMode::Hybrid,
        ),
        collectionService: QdrantCatalogCollectionService::fromConfig(),
        qdrant: makeQdrantClient($transport),
    );

    $state = new ChatSessionState;
    $result = $agent->nearestQueryForTest([
        'query_text' => 'warehouse sensor',
        'limit' => 2,
    ], $state);

    $body = json_decode($transport->lastRequest?->body ?? '', true, 512, JSON_THROW_ON_ERROR);

    expect($body)->toMatchArray([
        'query' => [
            'fusion' => 'rrf',
        ],
    ])
        ->and($body)->not->toHaveKey('using')
        ->and($body['prefetch'])->toHaveCount(2)
        ->and($body['prefetch'][0])->toBe([
            'query' => [0.1, 0.2, 0.3],
            'using' => 'dense',
            'limit' => 20,
        ])
        ->and($body['prefetch'][1])->toBe([
            'query' => [
                'indices' => [2, 5],
                'values' => [0.6, 0.1],
            ],
            'using' => 'sparse',
            'limit' => 20,
        ])
        ->and($result['tool'])->toBe('nearestQuery')
        ->and($result['message'])->toBeNull()
        ->and($result['hits'])->toHaveCount(1)
        ->and($result['hits'][0])->toMatchArray([
            'result_index' => 1,
            'point_id' => 'osfhu-itw::primary',
            'sku' => 'OSFHU-ITW',
            'name' => 'Fixture Sensor',
        ])
        ->and($state->lastToolName)->toBe('nearestQuery')
        ->and($state->lastResults)->toHaveCount(1)
        ->and($state->lastResults[0])->toMatchArray([
            'result_index' => 1,
            'point_id' => 'osfhu-itw::primary',
            'sku' => 'OSFHU-ITW',
            'name' => 'Fixture Sensor',
        ])
        ->and($state->lastResults[0])->not->toHaveKey('text')
        ->and($state->lastResults[0]['text_snippet'])->toBe('');
});

it('uses the hybrid rerank nearest query path and preserves response shape and session state', function () {
    $transport = new CatalogHybridCaptureTransport(
        fn (HttpRequest $request): HttpResponse => str_contains($request->url, '/points/query')
            ? qdrantJsonResponse([
                'result' => [
                    'points' => [[
                        'id' => 'osfhu-itw::primary',
                        'version' => 1,
                        'score' => 0.98,
                        'payload' => [
                            'sku' => 'OSFHU-ITW',
                            'name' => 'Fixture Sensor',
                            'brand' => 'Acme',
                            'categories' => ['Sensors'],
                            'text' => "Product: Fixture Sensor\nSKU: OSFHU-ITW\n\nHigh bay occupancy sensor",
                        ],
                    ]],
                ],
            ])
            : qdrantJsonResponse(['result' => ['exists' => true]]),
    );

    $agent = new CatalogHybridAgentHarness(
        config: makeAgentConfig(CatalogSearchMode::HybridRerank),
        vectorEncodings: new CatalogVectorEncodingOrchestrator(
            new CatalogHybridDenseEncoder([[0.1, 0.2, 0.3]]),
            new CatalogHybridSparseEncoder([new SparseVector(indices: [2, 5], values: [0.6, 0.1])]),
            new CatalogHybridLateEncoder([[[0.4, 0.5], [0.6, 0.7]]]),
            CatalogSearchMode::HybridRerank,
        ),
        collectionService: QdrantCatalogCollectionService::fromConfig(),
        qdrant: makeQdrantClient($transport),
    );

    $state = new ChatSessionState;
    $result = $agent->nearestQueryForTest([
        'query_text' => 'warehouse sensor',
        'limit' => 2,
    ], $state);

    $body = json_decode($transport->lastRequest?->body ?? '', true, 512, JSON_THROW_ON_ERROR);

    expect($body)->toMatchArray([
        'query' => [
            [0.4, 0.5],
            [0.6, 0.7],
        ],
        'using' => 'late',
    ])
        ->and($body['prefetch'])->toHaveCount(2)
        ->and($body['prefetch'][0])->toBe([
            'query' => [0.1, 0.2, 0.3],
            'using' => 'dense',
            'limit' => 20,
        ])
        ->and($body['prefetch'][1])->toBe([
            'query' => [
                'indices' => [2, 5],
                'values' => [0.6, 0.1],
            ],
            'using' => 'sparse',
            'limit' => 20,
        ])
        ->and($result['tool'])->toBe('nearestQuery')
        ->and($result['message'])->toBeNull()
        ->and($result['hits'])->toHaveCount(1)
        ->and($state->lastToolName)->toBe('nearestQuery')
        ->and($state->lastResults)->toHaveCount(1);
});

it('keeps dense-only nearest search unchanged when hybrid is disabled', function () {
    config()->set('catalog.search.mode', CatalogSearchMode::Dense->value);
    config()->set('catalog.search.hybrid_enabled', false);

    $calls = (object) ['dense' => 0, 'sparse' => 0, 'late' => 0];
    $transport = new CatalogHybridCaptureTransport(
        fn (HttpRequest $request): HttpResponse => qdrantJsonResponse([
            'result' => [
                'points' => [[
                    'id' => 'osfhu-itw::primary',
                    'version' => 1,
                    'score' => 0.97,
                    'payload' => [
                        'sku' => 'OSFHU-ITW',
                        'name' => 'Fixture Sensor',
                        'brand' => 'Acme',
                        'categories' => ['Sensors'],
                        'text' => "Product: Fixture Sensor\nSKU: OSFHU-ITW\n\nHigh bay occupancy sensor",
                    ],
                ]],
            ],
        ]),
    );

    $agent = new CatalogHybridAgentHarness(
        config: makeAgentConfig(CatalogSearchMode::Dense),
        vectorEncodings: new CatalogVectorEncodingOrchestrator(
            new CatalogHybridDenseEncoder([[0.9, 0.8, 0.7]], $calls),
            new CatalogHybridSparseEncoder([new SparseVector(indices: [1], values: [1.0])], $calls),
            new CatalogHybridLateEncoder([[[0.4, 0.5]]], $calls),
            CatalogSearchMode::Dense,
        ),
        collectionService: QdrantCatalogCollectionService::fromConfig(),
        qdrant: makeQdrantClient($transport),
    );

    $state = new ChatSessionState;
    $result = $agent->nearestQueryForTest([
        'query_text' => 'warehouse sensor',
        'limit' => 2,
    ], $state);

    $body = json_decode($transport->lastRequest?->body ?? '', true, 512, JSON_THROW_ON_ERROR);

    expect($calls->dense)->toBe(1)
        ->and($calls->sparse)->toBe(0)
        ->and($calls->late)->toBe(0)
        ->and($body['query'])->toBe([
            'nearest' => [0.9, 0.8, 0.7],
        ])
        ->and($body)->not->toHaveKey('prefetch')
        ->and($body)->not->toHaveKey('using')
        ->and($result['tool'])->toBe('nearestQuery')
        ->and($state->lastToolName)->toBe('nearestQuery')
        ->and($state->lastResults)->toHaveCount(1);
});

it('fails fast when hybrid search is enabled without a sparse model config', function () {
    config()->set('catalog.search.mode', CatalogSearchMode::Hybrid->value);

    $transport = new CatalogHybridCaptureTransport(
        fn (HttpRequest $request): HttpResponse => qdrantJsonResponse(['result' => ['exists' => true]]),
    );

    $agent = new CatalogHybridAgentHarness(
        config: makeAgentConfig(CatalogSearchMode::Hybrid),
        vectorEncodings: new CatalogVectorEncodingOrchestrator(
            new CatalogHybridDenseEncoder([[0.1, 0.2, 0.3]]),
            new CatalogHybridSparseEncoder([], configured: false),
            new CatalogHybridLateEncoder([[[0.4, 0.5], [0.6, 0.7]]]),
            CatalogSearchMode::Hybrid,
        ),
        collectionService: QdrantCatalogCollectionService::fromConfig(),
        qdrant: makeQdrantClient($transport),
    );

    expect($agent->runtimeError())->toContain('sparse model config is missing');
});

it('fails fast when hybrid rerank search is enabled without a late interaction model config', function () {
    $transport = new CatalogHybridCaptureTransport(
        fn (HttpRequest $request): HttpResponse => qdrantJsonResponse(['result' => ['exists' => true]]),
    );

    $agent = new CatalogHybridAgentHarness(
        config: makeAgentConfig(CatalogSearchMode::HybridRerank),
        vectorEncodings: new CatalogVectorEncodingOrchestrator(
            new CatalogHybridDenseEncoder([[0.1, 0.2, 0.3]]),
            new CatalogHybridSparseEncoder([new SparseVector(indices: [2], values: [0.4])]),
            new CatalogHybridLateEncoder([], configured: false),
            CatalogSearchMode::HybridRerank,
        ),
        collectionService: QdrantCatalogCollectionService::fromConfig(),
        qdrant: makeQdrantClient($transport),
    );

    expect($agent->runtimeError())->toContain('late-interaction model config is missing');
});

it('fails fast when hybrid search is enabled against an old dense-only collection schema', function () {
    config()->set('catalog.search.mode', CatalogSearchMode::Hybrid->value);

    Http::fake([
        'http://qdrant.test/collections/catalog' => Http::response([
            'result' => [
                'config' => [
                    'params' => [
                        'vectors' => [
                            'size' => 1024,
                            'distance' => 'Cosine',
                        ],
                    ],
                ],
            ],
        ]),
    ]);

    $transport = new CatalogHybridCaptureTransport(
        fn (HttpRequest $request): HttpResponse => qdrantJsonResponse(['result' => ['exists' => true]]),
    );

    $agent = new CatalogHybridAgentHarness(
        config: makeAgentConfig(CatalogSearchMode::Hybrid),
        vectorEncodings: new CatalogVectorEncodingOrchestrator(
            new CatalogHybridDenseEncoder([[0.1, 0.2, 0.3]]),
            new CatalogHybridSparseEncoder([new SparseVector(indices: [2], values: [0.4])]),
            new CatalogHybridLateEncoder([[[0.4, 0.5], [0.6, 0.7]]]),
            CatalogSearchMode::Hybrid,
        ),
        collectionService: QdrantCatalogCollectionService::fromConfig(),
        qdrant: makeQdrantClient($transport),
    );

    expect($agent->runtimeError())->toContain('old dense-only schema');
});

it('uses the legacy hybrid flag as a hybrid rerank alias when no explicit mode is set', function () {
    config()->set('catalog.search.mode', null);
    config()->set('catalog.search.hybrid_enabled', true);

    expect(CatalogAgentConfig::fromConfig()->searchMode)->toBe(CatalogSearchMode::HybridRerank)
        ->and(QdrantCatalogCollectionService::fromConfig()->searchMode())->toBe(CatalogSearchMode::HybridRerank);
});

function makeAgentConfig(CatalogSearchMode $searchMode): CatalogAgentConfig
{
    return new CatalogAgentConfig(
        openaiBaseUrl: 'http://openai.test/v1',
        openaiApiKey: 'test-key',
        openaiModel: 'chat-model',
        embeddingModel: 'dense-model',
        openaiTimeout: 60,
        qdrantUrl: 'http://qdrant.test',
        qdrantCollection: 'catalog',
        qdrantTimeout: 60,
        chatTopK: 10,
        searchMode: $searchMode,
        hybridPrefetchLimit: 20,
    );
}

function makeQdrantClient(CatalogHybridCaptureTransport $transport): QdrantClient
{
    return new QdrantClient(
        apiClient: new ApiClient(
            baseUrl: 'http://qdrant.test',
            transport: $transport,
        ),
    );
}

function qdrantJsonResponse(array $payload): HttpResponse
{
    return new HttpResponse(
        statusCode: 200,
        headers: ['Content-Type' => 'application/json'],
        body: json_encode($payload, JSON_THROW_ON_ERROR),
    );
}

final class CatalogHybridAgentHarness extends CatalogChatAgent
{
    public function nearestQueryForTest(array $arguments, ChatSessionState $state): array
    {
        return $this->nearestQuery($arguments, $state);
    }
}

final class CatalogHybridCaptureTransport implements HttpTransportInterface
{
    public ?HttpRequest $lastRequest = null;

    public function __construct(private readonly Closure $handler) {}

    public function send(HttpRequest $request): HttpResponse
    {
        $this->lastRequest = $request;

        return ($this->handler)($request);
    }
}

final class CatalogHybridDenseEncoder implements CatalogDenseEncoder
{
    public function __construct(
        private readonly array $embeddings,
        private readonly ?object $calls = null,
    ) {}

    public function configured(): bool
    {
        return true;
    }

    public function embedBatch(array $texts): array
    {
        if ($this->calls !== null) {
            $this->calls->dense++;
        }

        return $this->embeddings;
    }
}

final class CatalogHybridSparseEncoder implements CatalogSparseEncoder
{
    public function __construct(
        private readonly array $embeddings,
        private readonly ?object $calls = null,
        private readonly bool $configured = true,
    ) {}

    public function configured(): bool
    {
        return $this->configured;
    }

    public function embedBatch(array $texts): array
    {
        if ($this->calls !== null) {
            $this->calls->sparse++;
        }

        return $this->embeddings;
    }
}

final class CatalogHybridLateEncoder implements CatalogLateInteractionEncoder
{
    public function __construct(
        private readonly array $embeddings,
        private readonly ?object $calls = null,
        private readonly bool $configured = true,
    ) {}

    public function configured(): bool
    {
        return $this->configured;
    }

    public function embedBatch(array $texts): array
    {
        if ($this->calls !== null) {
            $this->calls->late++;
        }

        return $this->embeddings;
    }
}
