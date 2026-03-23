<?php

declare(strict_types=1);

namespace Qdrant\Models;

use Qdrant\Support\Arrayable;
use Qdrant\Support\Normalizer;

final class ScrollRequest implements Arrayable
{
    /**
     * @param bool|list<string>|null $withPayload
     * @param bool|list<string>|null $withVector
     * @param int|string|list<int|string>|null $shardKey
     */
    public function __construct(
        public readonly ?Filter $filter = null,
        public readonly ?int $limit = 10,
        public readonly int|string|null $offset = null,
        public readonly bool|array|null $withPayload = true,
        public readonly bool|array|null $withVector = false,
        public readonly int|string|array|null $shardKey = null,
        public readonly string|array|null $orderBy = null
    ) {
    }

    public static function fromArray(array $data): static
    {
        return new self(
            filter: isset($data['filter']) && is_array($data['filter']) ? Filter::fromArray($data['filter']) : null,
            limit: isset($data['limit']) ? (int) $data['limit'] : 10,
            offset: $data['offset'] ?? null,
            withPayload: $data['with_payload'] ?? true,
            withVector: $data['with_vector'] ?? false,
            shardKey: $data['shard_key'] ?? null,
            orderBy: $data['order_by'] ?? null
        );
    }

    public function toArray(): array
    {
        return Normalizer::normalize([
            'filter' => $this->filter,
            'limit' => $this->limit,
            'offset' => $this->offset,
            'with_payload' => $this->withPayload,
            'with_vector' => $this->withVector,
            'shard_key' => $this->shardKey,
            'order_by' => $this->orderBy,
        ]);
    }
}
