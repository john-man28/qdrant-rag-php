<?php

declare(strict_types=1);

namespace Qdrant\Models;

use Qdrant\Support\Arrayable;
use Qdrant\Support\Normalizer;

final class SparseVector implements Arrayable
{
    /**
     * @param  list<int>  $indices
     * @param  list<float>  $values
     */
    public function __construct(
        public readonly array $indices,
        public readonly array $values
    ) {}

    public static function fromArray(array $data): static
    {
        return new self(
            indices: array_values(array_map(
                static fn (mixed $index): int => (int) $index,
                is_array($data['indices'] ?? null) ? $data['indices'] : []
            )),
            values: array_values(array_map(
                static fn (mixed $value): float => (float) $value,
                is_array($data['values'] ?? null) ? $data['values'] : []
            )),
        );
    }

    public function toArray(): array
    {
        return Normalizer::normalize([
            'indices' => $this->indices,
            'values' => $this->values,
        ]);
    }
}
