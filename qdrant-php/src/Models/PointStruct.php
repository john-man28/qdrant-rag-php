<?php

declare(strict_types=1);

namespace Qdrant\Models;

use Qdrant\Support\Arrayable;
use Qdrant\Support\Normalizer;

final class PointStruct implements Arrayable
{
    public function __construct(
        public readonly int|string $id,
        public readonly array $vector,
        public readonly ?array $payload = null
    ) {
    }

    public static function fromArray(array $data): static
    {
        return new self(
            id: $data['id'],
            vector: is_array($data['vector'] ?? null) ? $data['vector'] : [],
            payload: isset($data['payload']) && is_array($data['payload']) ? $data['payload'] : null
        );
    }

    public function toArray(): array
    {
        return Normalizer::normalize([
            'id' => $this->id,
            'vector' => $this->vector,
            'payload' => $this->payload,
        ]);
    }
}
