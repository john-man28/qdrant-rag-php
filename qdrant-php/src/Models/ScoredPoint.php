<?php

declare(strict_types=1);

namespace Qdrant\Models;

use Qdrant\Support\Normalizer;

final class ScoredPoint extends Record
{
    public function __construct(
        int|string $id,
        public readonly int $version,
        public readonly float $score,
        ?array $payload = null,
        ?array $vector = null,
        int|string|null $shardKey = null,
        int|float|null $orderValue = null
    ) {
        parent::__construct($id, $payload, $vector, $shardKey, $orderValue);
    }

    public static function fromArray(array $data): static
    {
        return new self(
            id: $data['id'],
            version: (int) ($data['version'] ?? 0),
            score: (float) ($data['score'] ?? 0.0),
            payload: isset($data['payload']) && is_array($data['payload']) ? $data['payload'] : null,
            vector: isset($data['vector']) && is_array($data['vector']) ? $data['vector'] : null,
            shardKey: $data['shard_key'] ?? null,
            orderValue: isset($data['order_value']) && is_numeric($data['order_value']) ? $data['order_value'] + 0 : null
        );
    }

    public function toArray(): array
    {
        return Normalizer::normalize(parent::toArray() + [
            'version' => $this->version,
            'score' => $this->score,
        ]);
    }
}
