<?php

declare(strict_types=1);

namespace Qdrant;

use Qdrant\Api\Rest\CollectionsApi;
use Qdrant\Api\Rest\PointsApi;
use Qdrant\Api\Rest\SearchApi;
use Qdrant\Exceptions\ApiException;
use Qdrant\Exceptions\QdrantException;
use Qdrant\Exceptions\RateLimitException;
use Qdrant\Exceptions\TransportException;
use Qdrant\Models\CreateCollectionRequest;
use Qdrant\Models\Filter;
use Qdrant\Models\PointRequest;
use Qdrant\Models\PointsList;
use Qdrant\Models\PointStruct;
use Qdrant\Models\Prefetch;
use Qdrant\Models\QueryRequest;
use Qdrant\Models\QueryResponse;
use Qdrant\Models\Record;
use Qdrant\Models\ScrollRequest;
use Qdrant\Models\ScrollResponse;
use Qdrant\Models\SparseVectorParams;
use Qdrant\Models\UpdateMode;
use Qdrant\Models\UpdateResult;
use Qdrant\Models\VectorParams;
use Qdrant\Models\WriteOrdering;
use Qdrant\Transport\Rest\ApiClient;

final class QdrantClient
{
    private readonly ApiClient $apiClient;

    private readonly CollectionsApi $collectionsApi;

    private readonly PointsApi $pointsApi;

    private readonly SearchApi $searchApi;

    public function __construct(
        ?string $url = null,
        ?string $host = null,
        ?int $port = null,
        ?int $grpcPort = null,
        bool $preferGrpc = false,
        bool $https = false,
        ?string $apiKey = null,
        string $prefix = '',
        int|float $timeout = 60,
        array $headers = [],
        bool $checkCompatibility = false,
        int $poolSize = 1,
        mixed $authTokenProvider = null,
        ?ApiClient $apiClient = null
    ) {
        if ($preferGrpc) {
            throw new QdrantException('gRPC transport is not implemented in this REST-first milestone.');
        }

        unset($grpcPort, $checkCompatibility, $poolSize);

        $this->apiClient = $apiClient ?? new ApiClient(
            $this->resolveBaseUrl($url, $host, $port, $https, $prefix),
            $timeout,
            $headers,
            $apiKey,
            null,
            $authTokenProvider
        );

        $this->collectionsApi = new CollectionsApi($this->apiClient);
        $this->pointsApi = new PointsApi($this->apiClient);
        $this->searchApi = new SearchApi($this->apiClient);
    }

    public function getCollectionsApi(): CollectionsApi
    {
        return $this->collectionsApi;
    }

    public function getPointsApi(): PointsApi
    {
        return $this->pointsApi;
    }

    public function getSearchApi(): SearchApi
    {
        return $this->searchApi;
    }

    public function collectionExists(string $collectionName): bool
    {
        return $this->collectionsApi->collectionExists($collectionName);
    }

    /**
     * @param  VectorParams|array<string, VectorParams>  $vectorsConfig
     * @param  array<string, SparseVectorParams>|null  $sparseVectorsConfig
     */
    public function createCollection(
        string $collectionName,
        VectorParams|array $vectorsConfig,
        ?int $shardNumber = null,
        ?int $replicationFactor = null,
        ?int $writeConsistencyFactor = null,
        ?bool $onDiskPayload = null,
        ?int $timeout = null,
        ?array $metadata = null,
        ?array $sparseVectorsConfig = null
    ): bool {
        return $this->collectionsApi->createCollection(
            $collectionName,
            new CreateCollectionRequest(
                vectors: $vectorsConfig,
                sparseVectors: $sparseVectorsConfig,
                shardNumber: $shardNumber,
                replicationFactor: $replicationFactor,
                writeConsistencyFactor: $writeConsistencyFactor,
                onDiskPayload: $onDiskPayload,
                metadata: $metadata
            ),
            $timeout
        );
    }

    /**
     * @param  iterable<PointStruct|array{id:int|string,vector:array,payload?:array|null}>  $points
     */
    public function upsert(
        string $collectionName,
        iterable $points,
        bool $wait = true,
        WriteOrdering|string|null $ordering = null,
        int|string|array|null $shardKeySelector = null,
        ?Filter $updateFilter = null,
        ?UpdateMode $updateMode = null,
        ?int $timeout = null
    ): UpdateResult {
        return $this->pointsApi->upsertPoints(
            $collectionName,
            new PointsList(
                points: $this->coercePoints($points),
                shardKey: $shardKeySelector,
                updateFilter: $updateFilter,
                updateMode: $updateMode
            ),
            $wait,
            $ordering,
            $timeout
        );
    }

    /**
     * @param  iterable<PointStruct|array{id:int|string,vector:array,payload?:array|null}>  $points
     */
    public function uploadPoints(
        string $collectionName,
        iterable $points,
        int $batchSize = 64,
        int $parallel = 1,
        ?string $method = null,
        int $maxRetries = 3,
        bool $wait = false,
        int|string|array|null $shardKeySelector = null,
        ?Filter $updateFilter = null,
        ?UpdateMode $updateMode = null
    ): void {
        if ($batchSize < 1) {
            throw new QdrantException('batchSize must be greater than 0.');
        }

        if ($parallel !== 1) {
            throw new QdrantException('Parallel upload is not implemented in this REST-first milestone. Use parallel=1.');
        }

        if ($method !== null) {
            throw new QdrantException('Custom parallel start methods are not implemented in this REST-first milestone.');
        }

        $batch = [];
        foreach ($points as $point) {
            $batch[] = $point instanceof PointStruct ? $point : PointStruct::fromArray($point);

            if (count($batch) >= $batchSize) {
                $this->uploadBatch(
                    $collectionName,
                    $batch,
                    $maxRetries,
                    $wait,
                    $shardKeySelector,
                    $updateFilter,
                    $updateMode
                );
                $batch = [];
            }
        }

        if ($batch !== []) {
            $this->uploadBatch(
                $collectionName,
                $batch,
                $maxRetries,
                $wait,
                $shardKeySelector,
                $updateFilter,
                $updateMode
            );
        }
    }

    public function queryPoints(
        string $collectionName,
        mixed $query = null,
        ?string $using = null,
        ?Filter $queryFilter = null,
        int $limit = 10,
        ?int $offset = null,
        bool|array $withPayload = true,
        bool|array $withVectors = false,
        ?float $scoreThreshold = null,
        int|string|null $consistency = null,
        int|string|array|null $shardKeySelector = null,
        ?int $timeout = null,
        Prefetch|array|null $prefetch = null
    ): QueryResponse {
        return $this->searchApi->queryPoints(
            $collectionName,
            new QueryRequest(
                query: $query,
                using: $using,
                filter: $queryFilter,
                limit: $limit,
                offset: $offset,
                withPayload: $withPayload,
                withVector: $withVectors,
                scoreThreshold: $scoreThreshold,
                shardKey: $shardKeySelector,
                prefetch: $prefetch
            ),
            $consistency,
            $timeout
        );
    }

    /**
     * @param  list<int|string>  $ids
     * @return list<Record>
     */
    public function retrieve(
        string $collectionName,
        array $ids,
        bool|array $withPayload = true,
        bool|array $withVectors = false,
        int|string|null $consistency = null,
        int|string|array|null $shardKeySelector = null,
        ?int $timeout = null
    ): array {
        return $this->pointsApi->getPoints(
            $collectionName,
            new PointRequest(
                ids: $ids,
                withPayload: $withPayload,
                withVector: $withVectors,
                shardKey: $shardKeySelector
            ),
            $consistency,
            $timeout
        );
    }

    public function scroll(
        string $collectionName,
        ?Filter $scrollFilter = null,
        int $limit = 10,
        int|string|null $offset = null,
        bool|array $withPayload = true,
        bool|array $withVectors = false,
        int|string|null $consistency = null,
        int|string|array|null $shardKeySelector = null,
        ?int $timeout = null,
        string|array|null $orderBy = null
    ): ScrollResponse {
        return $this->pointsApi->scrollPoints(
            $collectionName,
            new ScrollRequest(
                filter: $scrollFilter,
                limit: $limit,
                offset: $offset,
                withPayload: $withPayload,
                withVector: $withVectors,
                shardKey: $shardKeySelector,
                orderBy: $orderBy
            ),
            $consistency,
            $timeout
        );
    }

    private function uploadBatch(
        string $collectionName,
        array $batch,
        int $maxRetries,
        bool $wait,
        int|string|array|null $shardKeySelector,
        ?Filter $updateFilter,
        ?UpdateMode $updateMode
    ): void {
        $attempt = 0;

        while (true) {
            try {
                $this->upsert(
                    $collectionName,
                    $batch,
                    $wait,
                    null,
                    $shardKeySelector,
                    $updateFilter,
                    $updateMode
                );

                return;
            } catch (RateLimitException $exception) {
                $attempt++;
                if ($attempt > $maxRetries) {
                    throw $exception;
                }

                if ($exception->retryAfterSeconds !== null && $exception->retryAfterSeconds > 0) {
                    sleep($exception->retryAfterSeconds);
                }
            } catch (ApiException|TransportException $exception) {
                $attempt++;
                if ($attempt > $maxRetries) {
                    throw $exception;
                }

                usleep($attempt * 250_000);
            }
        }
    }

    /**
     * @param  iterable<PointStruct|array{id:int|string,vector:array,payload?:array|null}>  $points
     * @return list<PointStruct>
     */
    private function coercePoints(iterable $points): array
    {
        $normalized = [];

        foreach ($points as $point) {
            $normalized[] = $point instanceof PointStruct ? $point : PointStruct::fromArray($point);
        }

        return $normalized;
    }

    private function resolveBaseUrl(
        ?string $url,
        ?string $host,
        ?int $port,
        bool $https,
        string $prefix
    ): string {
        $resolved = $url;
        if ($resolved === null || $resolved === '') {
            $scheme = $https ? 'https' : 'http';
            $resolvedHost = $host ?: 'localhost';
            $resolvedPort = $port ?? 6333;
            $resolved = sprintf('%s://%s:%d', $scheme, $resolvedHost, $resolvedPort);
        }

        $resolved = rtrim($resolved, '/');
        $trimmedPrefix = trim($prefix, '/');

        if ($trimmedPrefix === '') {
            return $resolved;
        }

        return $resolved.'/'.$trimmedPrefix;
    }
}
