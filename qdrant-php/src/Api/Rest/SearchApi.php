<?php

declare(strict_types=1);

namespace Qdrant\Api\Rest;

use Qdrant\Models\QueryRequest;
use Qdrant\Models\QueryResponse;
use Qdrant\Transport\Rest\ApiClient;

final class SearchApi
{
    public function __construct(private readonly ApiClient $client)
    {
    }

    public function queryPoints(
        string $collectionName,
        QueryRequest $request,
        int|string|null $consistency = null,
        ?int $timeout = null
    ): QueryResponse {
        $result = $this->client->requestResult(
            'POST',
            sprintf('/collections/%s/points/query', rawurlencode($collectionName)),
            [
                'consistency' => $consistency,
                'timeout' => $timeout,
            ],
            $request
        );

        return QueryResponse::fromArray(is_array($result) ? $result : []);
    }
}
