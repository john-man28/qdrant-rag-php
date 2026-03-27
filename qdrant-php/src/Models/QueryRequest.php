<?php

declare(strict_types=1);

namespace Qdrant\Models;

use Qdrant\Support\Arrayable;
use Qdrant\Support\Normalizer;

final class QueryRequest implements Arrayable
{
    /**
     * @param  bool|list<string>|null  $withPayload
     * @param  bool|list<string>|null  $withVector
     * @param  int|string|list<int|string>|null  $shardKey
     * @param  Prefetch|list<Prefetch>|null  $prefetch
     */
    public function __construct(
        public readonly mixed $query = null,
        public readonly ?string $using = null,
        public readonly ?Filter $filter = null,
        public readonly ?int $limit = 10,
        public readonly ?int $offset = null,
        public readonly bool|array|null $withPayload = true,
        public readonly bool|array|null $withVector = false,
        public readonly ?float $scoreThreshold = null,
        public readonly int|string|array|null $shardKey = null,
        public readonly Prefetch|array|null $prefetch = null
    ) {}

    public static function fromArray(array $data): static
    {
        return new self(
            query: self::hydrateQuery($data['query'] ?? null),
            using: isset($data['using']) ? (string) $data['using'] : null,
            filter: isset($data['filter']) && is_array($data['filter']) ? Filter::fromArray($data['filter']) : null,
            limit: isset($data['limit']) ? (int) $data['limit'] : 10,
            offset: isset($data['offset']) ? (int) $data['offset'] : null,
            withPayload: $data['with_payload'] ?? true,
            withVector: $data['with_vector'] ?? false,
            scoreThreshold: isset($data['score_threshold']) ? (float) $data['score_threshold'] : null,
            shardKey: $data['shard_key'] ?? null,
            prefetch: self::hydratePrefetch($data['prefetch'] ?? null),
        );
    }

    public function toArray(): array
    {
        return Normalizer::normalize([
            'query' => $this->query,
            'using' => $this->using,
            'filter' => $this->filter,
            'limit' => $this->limit,
            'offset' => $this->offset,
            'with_payload' => $this->withPayload,
            'with_vector' => $this->withVector,
            'score_threshold' => $this->scoreThreshold,
            'shard_key' => $this->shardKey,
            'prefetch' => $this->prefetch,
        ]);
    }

    private static function hydrateQuery(mixed $query): mixed
    {
        if (! is_array($query)) {
            return $query;
        }

        if (isset($query['nearest']) && is_array($query['nearest'])) {
            return NearestQuery::fromArray($query);
        }

        if (isset($query['recommend']) && is_array($query['recommend'])) {
            return RecommendQuery::fromArray($query);
        }

        if (array_key_exists('fusion', $query)) {
            return FusionQuery::fromArray($query);
        }

        if (isset($query['indices'], $query['values'])) {
            return SparseVector::fromArray($query);
        }

        return $query;
    }

    /**
     * @return Prefetch|list<Prefetch>|null
     */
    private static function hydratePrefetch(mixed $prefetch): Prefetch|array|null
    {
        if (! is_array($prefetch)) {
            return null;
        }

        if (array_is_list($prefetch)) {
            $normalized = [];

            foreach ($prefetch as $item) {
                if ($item instanceof Prefetch) {
                    $normalized[] = $item;

                    continue;
                }

                if (is_array($item)) {
                    $normalized[] = Prefetch::fromArray($item);
                }
            }

            return $normalized;
        }

        return Prefetch::fromArray($prefetch);
    }
}
