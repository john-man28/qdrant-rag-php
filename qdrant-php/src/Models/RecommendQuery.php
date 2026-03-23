<?php

declare(strict_types=1);

namespace Qdrant\Models;

use Qdrant\Support\Arrayable;
use Qdrant\Support\Normalizer;

final class RecommendQuery implements Arrayable
{
    public function __construct(public readonly RecommendInput $recommend)
    {
    }

    public static function fromArray(array $data): static
    {
        return new self(
            recommend: RecommendInput::fromArray(
                isset($data['recommend']) && is_array($data['recommend']) ? $data['recommend'] : []
            )
        );
    }

    public function toArray(): array
    {
        return Normalizer::normalize([
            'recommend' => $this->recommend,
        ]);
    }
}
