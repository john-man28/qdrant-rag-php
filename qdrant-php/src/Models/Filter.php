<?php

declare(strict_types=1);

namespace Qdrant\Models;

use Qdrant\Support\Arrayable;
use Qdrant\Support\Normalizer;

final class Filter implements Arrayable
{
    /**
     * @param list<FieldCondition> $must
     * @param list<FieldCondition> $should
     * @param list<FieldCondition> $mustNot
     */
    public function __construct(
        public readonly array $must = [],
        public readonly array $should = [],
        public readonly array $mustNot = []
    ) {
    }

    public static function fromArray(array $data): static
    {
        return new self(
            must: self::hydrateConditions($data['must'] ?? []),
            should: self::hydrateConditions($data['should'] ?? []),
            mustNot: self::hydrateConditions($data['must_not'] ?? [])
        );
    }

    public function toArray(): array
    {
        return Normalizer::normalize([
            'must' => $this->must,
            'should' => $this->should,
            'must_not' => $this->mustNot,
        ]);
    }

    /**
     * @param mixed $value
     * @return list<FieldCondition>
     */
    private static function hydrateConditions(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $conditions = [];
        foreach ($value as $item) {
            if ($item instanceof FieldCondition) {
                $conditions[] = $item;
                continue;
            }

            if (is_array($item)) {
                $conditions[] = FieldCondition::fromArray($item);
            }
        }

        return $conditions;
    }
}
