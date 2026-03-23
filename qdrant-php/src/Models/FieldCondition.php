<?php

declare(strict_types=1);

namespace Qdrant\Models;

use Qdrant\Support\Arrayable;
use Qdrant\Support\Normalizer;

final class FieldCondition implements Arrayable
{
    public function __construct(
        public readonly string $key,
        public readonly ?MatchValue $match = null
    ) {
    }

    public static function matchValue(string $key, mixed $value): self
    {
        return new self($key, new MatchValue($value));
    }

    public static function fromArray(array $data): static
    {
        return new self(
            key: (string) ($data['key'] ?? ''),
            match: isset($data['match']) && is_array($data['match']) ? MatchValue::fromArray($data['match']) : null
        );
    }

    public function toArray(): array
    {
        return Normalizer::normalize([
            'key' => $this->key,
            'match' => $this->match,
        ]);
    }
}
