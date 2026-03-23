<?php

declare(strict_types=1);

namespace Qdrant\Models;

use Qdrant\Support\Arrayable;
use Qdrant\Support\Normalizer;

final class RecommendInput implements Arrayable
{
    /**
     * @param list<int|string> $positive
     * @param list<int|string> $negative
     */
    public function __construct(
        public readonly array $positive,
        public readonly array $negative = []
    ) {
    }

    public static function fromArray(array $data): static
    {
        return new self(
            positive: is_array($data['positive'] ?? null) ? array_values($data['positive']) : [],
            negative: is_array($data['negative'] ?? null) ? array_values($data['negative']) : []
        );
    }

    public function toArray(): array
    {
        return Normalizer::normalize([
            'positive' => $this->positive,
            'negative' => $this->negative,
        ]);
    }
}
