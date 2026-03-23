<?php

declare(strict_types=1);

namespace Qdrant\Models;

use Qdrant\Support\Arrayable;
use Qdrant\Support\Normalizer;

final class VectorParams implements Arrayable
{
    public function __construct(
        public readonly int $size,
        public readonly Distance $distance
    ) {
    }

    public static function fromArray(array $data): static
    {
        return new self(
            size: (int) ($data['size'] ?? 0),
            distance: Distance::from((string) ($data['distance'] ?? Distance::COSINE->value))
        );
    }

    public function toArray(): array
    {
        return Normalizer::normalize([
            'size' => $this->size,
            'distance' => $this->distance,
        ]);
    }
}
