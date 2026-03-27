<?php

declare(strict_types=1);

namespace Qdrant\Models;

use Qdrant\Support\Arrayable;
use Qdrant\Support\Normalizer;

final class MultiVectorConfig implements Arrayable
{
    public function __construct(public readonly MultiVectorComparator $comparator) {}

    public static function fromArray(array $data): static
    {
        return new self(
            comparator: MultiVectorComparator::fromValue(
                (string) ($data['comparator'] ?? MultiVectorComparator::MAX_SIM->value)
            ),
        );
    }

    public function toArray(): array
    {
        return Normalizer::normalize([
            'comparator' => $this->comparator,
        ]);
    }
}
