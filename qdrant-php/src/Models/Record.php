<?php

declare(strict_types=1);

namespace Qdrant\Models;

use Qdrant\Support\Arrayable;
use Qdrant\Support\Normalizer;

class Record implements Arrayable
{
    public function __construct(
        public readonly int|string $id,
        public readonly ?array $payload = null,
        public readonly ?array $vector = null,
        public readonly int|string|null $shardKey = null,
        public readonly int|float|null $orderValue = null
    ) {
    }

    public static function fromArray(array $data): static
    {
        return new static(
            id: $data['id'],
            payload: isset($data['payload']) && is_array($data['payload']) ? $data['payload'] : null,
            vector: isset($data['vector']) && is_array($data['vector']) ? $data['vector'] : null,
            shardKey: $data['shard_key'] ?? null,
            orderValue: isset($data['order_value']) && is_numeric($data['order_value']) ? $data['order_value'] + 0 : null
        );
    }

    public function toArray(): array
    {
        return Normalizer::normalize([
            'id' => $this->id,
            'payload' => $this->payload,
            'vector' => $this->vector,
            'shard_key' => $this->shardKey,
            'order_value' => $this->orderValue,
        ]);
    }
}
