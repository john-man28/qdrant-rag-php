<?php

declare(strict_types=1);

namespace Qdrant\Models;

use Qdrant\Support\Arrayable;
use Qdrant\Support\Normalizer;

final class CreateCollectionRequest implements Arrayable
{
    /**
     * @param VectorParams|array<string, VectorParams>|null $vectors
     */
    public function __construct(
        public readonly VectorParams|array|null $vectors,
        public readonly ?int $shardNumber = null,
        public readonly ?int $replicationFactor = null,
        public readonly ?int $writeConsistencyFactor = null,
        public readonly ?bool $onDiskPayload = null,
        public readonly ?array $metadata = null
    ) {
    }

    public static function fromArray(array $data): static
    {
        $vectors = $data['vectors'] ?? null;

        if ($vectors instanceof VectorParams) {
            $normalizedVectors = $vectors;
        } elseif (is_array($vectors) && array_is_list($vectors)) {
            $normalizedVectors = $vectors;
        } elseif (is_array($vectors) && isset($vectors['size'], $vectors['distance'])) {
            $normalizedVectors = VectorParams::fromArray($vectors);
        } elseif (is_array($vectors)) {
            $normalizedVectors = [];
            foreach ($vectors as $name => $params) {
                if ($params instanceof VectorParams) {
                    $normalizedVectors[$name] = $params;
                } elseif (is_array($params)) {
                    $normalizedVectors[$name] = VectorParams::fromArray($params);
                }
            }
        } else {
            $normalizedVectors = null;
        }

        return new self(
            vectors: $normalizedVectors,
            shardNumber: isset($data['shard_number']) ? (int) $data['shard_number'] : null,
            replicationFactor: isset($data['replication_factor']) ? (int) $data['replication_factor'] : null,
            writeConsistencyFactor: isset($data['write_consistency_factor']) ? (int) $data['write_consistency_factor'] : null,
            onDiskPayload: array_key_exists('on_disk_payload', $data) ? (bool) $data['on_disk_payload'] : null,
            metadata: isset($data['metadata']) && is_array($data['metadata']) ? $data['metadata'] : null
        );
    }

    public function toArray(): array
    {
        return Normalizer::normalize([
            'vectors' => $this->vectors,
            'shard_number' => $this->shardNumber,
            'replication_factor' => $this->replicationFactor,
            'write_consistency_factor' => $this->writeConsistencyFactor,
            'on_disk_payload' => $this->onDiskPayload,
            'metadata' => $this->metadata,
        ]);
    }
}
