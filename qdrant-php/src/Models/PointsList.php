<?php

declare(strict_types=1);

namespace Qdrant\Models;

use Qdrant\Support\Arrayable;
use Qdrant\Support\Normalizer;

final class PointsList implements Arrayable
{
    /**
     * @param list<PointStruct> $points
     * @param int|string|list<int|string>|null $shardKey
     */
    public function __construct(
        public readonly array $points,
        public readonly int|string|array|null $shardKey = null,
        public readonly ?Filter $updateFilter = null,
        public readonly ?UpdateMode $updateMode = null
    ) {
    }

    public static function fromArray(array $data): static
    {
        $points = [];
        foreach ($data['points'] ?? [] as $point) {
            if ($point instanceof PointStruct) {
                $points[] = $point;
            } elseif (is_array($point)) {
                $points[] = PointStruct::fromArray($point);
            }
        }

        return new self(
            points: $points,
            shardKey: $data['shard_key'] ?? null,
            updateFilter: isset($data['update_filter']) && is_array($data['update_filter']) ? Filter::fromArray($data['update_filter']) : null,
            updateMode: isset($data['update_mode']) ? UpdateMode::from((string) $data['update_mode']) : null
        );
    }

    public function toArray(): array
    {
        return Normalizer::normalize([
            'points' => $this->points,
            'shard_key' => $this->shardKey,
            'update_filter' => $this->updateFilter,
            'update_mode' => $this->updateMode,
        ]);
    }
}
