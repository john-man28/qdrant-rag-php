<?php

declare(strict_types=1);

namespace Qdrant\Api\Rest;

use Qdrant\Models\CreateCollectionRequest;
use Qdrant\Transport\Rest\ApiClient;

final class CollectionsApi
{
    public function __construct(private readonly ApiClient $client)
    {
    }

    public function collectionExists(string $collectionName): bool
    {
        $result = $this->client->requestResult(
            'GET',
            sprintf('/collections/%s/exists', rawurlencode($collectionName))
        );

        return (bool) ($result['exists'] ?? false);
    }

    public function createCollection(
        string $collectionName,
        CreateCollectionRequest $request,
        ?int $timeout = null
    ): bool {
        $result = $this->client->requestResult(
            'PUT',
            sprintf('/collections/%s', rawurlencode($collectionName)),
            ['timeout' => $timeout],
            $request
        );

        return (bool) $result;
    }
}
