<?php

declare(strict_types=1);

namespace Qdrant\Api\Rest;

use Qdrant\Models\PointRequest;
use Qdrant\Models\PointsList;
use Qdrant\Models\Record;
use Qdrant\Models\ScrollRequest;
use Qdrant\Models\ScrollResponse;
use Qdrant\Models\UpdateResult;
use Qdrant\Models\WriteOrdering;
use Qdrant\Transport\Rest\ApiClient;

final class PointsApi
{
    public function __construct(private readonly ApiClient $client)
    {
    }

    public function upsertPoints(
        string $collectionName,
        PointsList $points,
        bool $wait = true,
        WriteOrdering|string|null $ordering = null,
        ?int $timeout = null
    ): UpdateResult {
        $result = $this->client->requestResult(
            'PUT',
            sprintf('/collections/%s/points', rawurlencode($collectionName)),
            [
                'wait' => $wait,
                'ordering' => $ordering instanceof WriteOrdering ? $ordering->value : $ordering,
                'timeout' => $timeout,
            ],
            $points
        );

        return UpdateResult::fromArray(is_array($result) ? $result : []);
    }

    /**
     * @return list<Record>
     */
    public function getPoints(
        string $collectionName,
        PointRequest $request,
        int|string|null $consistency = null,
        ?int $timeout = null
    ): array {
        $result = $this->client->requestResult(
            'POST',
            sprintf('/collections/%s/points', rawurlencode($collectionName)),
            [
                'consistency' => $consistency,
                'timeout' => $timeout,
            ],
            $request
        );

        $records = [];
        foreach ($result ?? [] as $record) {
            if (is_array($record)) {
                $records[] = Record::fromArray($record);
            }
        }

        return $records;
    }

    public function scrollPoints(
        string $collectionName,
        ScrollRequest $request,
        int|string|null $consistency = null,
        ?int $timeout = null
    ): ScrollResponse {
        $result = $this->client->requestResult(
            'POST',
            sprintf('/collections/%s/points/scroll', rawurlencode($collectionName)),
            [
                'consistency' => $consistency,
                'timeout' => $timeout,
            ],
            $request
        );

        return ScrollResponse::fromArray(is_array($result) ? $result : []);
    }
}
