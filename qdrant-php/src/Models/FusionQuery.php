<?php

declare(strict_types=1);

namespace Qdrant\Models;

use Qdrant\Support\Arrayable;
use Qdrant\Support\Normalizer;

final class FusionQuery implements Arrayable
{
    public function __construct(public readonly Fusion $fusion) {}

    public static function fromArray(array $data): static
    {
        $fusion = $data['fusion'] ?? null;

        return new self(
            fusion: $fusion instanceof Fusion
                ? $fusion
                : Fusion::fromValue(is_string($fusion) ? $fusion : Fusion::RRF->value),
        );
    }

    public function toArray(): array
    {
        return Normalizer::normalize([
            'fusion' => $this->fusion,
        ]);
    }
}
