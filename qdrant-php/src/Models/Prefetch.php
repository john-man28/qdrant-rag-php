<?php

declare(strict_types=1);

namespace Qdrant\Models;

use Qdrant\Support\Arrayable;
use Qdrant\Support\Normalizer;

final class Prefetch implements Arrayable
{
    /**
     * @param  Prefetch|list<Prefetch>|null  $prefetch
     */
    public function __construct(
        public readonly mixed $query = null,
        public readonly ?string $using = null,
        public readonly ?Filter $filter = null,
        public readonly ?int $limit = null,
        public readonly ?int $offset = null,
        public readonly ?float $scoreThreshold = null,
        public readonly Prefetch|array|null $prefetch = null
    ) {}

    public static function fromArray(array $data): static
    {
        return new self(
            query: self::hydrateQuery($data['query'] ?? null),
            using: isset($data['using']) ? (string) $data['using'] : null,
            filter: isset($data['filter']) && is_array($data['filter']) ? Filter::fromArray($data['filter']) : null,
            limit: isset($data['limit']) ? (int) $data['limit'] : null,
            offset: isset($data['offset']) ? (int) $data['offset'] : null,
            scoreThreshold: isset($data['score_threshold']) ? (float) $data['score_threshold'] : null,
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
            'score_threshold' => $this->scoreThreshold,
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
