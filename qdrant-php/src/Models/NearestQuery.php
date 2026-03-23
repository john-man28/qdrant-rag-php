<?php

declare(strict_types=1);

namespace Qdrant\Models;

use Qdrant\Support\Arrayable;
use Qdrant\Support\Normalizer;

final class NearestQuery implements Arrayable
{
    public function __construct(public readonly array $nearest)
    {
    }

    public static function fromArray(array $data): static
    {
        return new self(is_array($data['nearest'] ?? null) ? $data['nearest'] : []);
    }

    public function toArray(): array
    {
        return Normalizer::normalize([
            'nearest' => $this->nearest,
        ]);
    }
}
