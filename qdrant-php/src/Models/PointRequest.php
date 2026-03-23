<?php

declare(strict_types=1);

namespace Qdrant\Models;

use Qdrant\Support\Arrayable;
use Qdrant\Support\Normalizer;

final class PointRequest implements Arrayable
{
    /**
     * @param list<int|string> $ids
     * @param bool|list<string>|null $withPayload
     * @param bool|list<string>|null $withVector
     * @param int|string|list<int|string>|null $shardKey
     */
    public function __construct(
        public readonly array $ids,
        public readonly bool|array|null $withPayload = true,
        public readonly bool|array|null $withVector = false,
        public readonly int|string|array|null $shardKey = null
    ) {
    }

    public static function fromArray(array $data): static
    {
        return new self(
            ids: is_array($data['ids'] ?? null) ? array_values($data['ids']) : [],
            withPayload: $data['with_payload'] ?? true,
            withVector: $data['with_vector'] ?? false,
            shardKey: $data['shard_key'] ?? null
        );
    }

    public function toArray(): array
    {
        return Normalizer::normalize([
            'ids' => $this->ids,
            'with_payload' => $this->withPayload,
            'with_vector' => $this->withVector,
            'shard_key' => $this->shardKey,
        ]);
    }
}
