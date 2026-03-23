<?php

declare(strict_types=1);

namespace Qdrant\Models;

use Qdrant\Support\Arrayable;
use Qdrant\Support\Normalizer;

final class MatchValue implements Arrayable
{
    public function __construct(public readonly mixed $value)
    {
    }

    public static function fromArray(array $data): static
    {
        return new self($data['value'] ?? null);
    }

    public function toArray(): array
    {
        return Normalizer::normalize([
            'value' => $this->value,
        ]);
    }
}
