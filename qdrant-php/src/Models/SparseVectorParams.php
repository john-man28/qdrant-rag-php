<?php

declare(strict_types=1);

namespace Qdrant\Models;

use Qdrant\Support\Arrayable;
use Qdrant\Support\Normalizer;

final class SparseVectorParams implements Arrayable
{
    public function __construct(
        public readonly ?SparseIndexConfig $index = null,
        public readonly ?Modifier $modifier = null
    ) {}

    public static function fromArray(array $data): static
    {
        return new self(
            index: isset($data['index']) && is_array($data['index'])
                ? SparseIndexConfig::fromArray($data['index'])
                : null,
            modifier: isset($data['modifier']) && is_string($data['modifier'])
                ? Modifier::fromValue($data['modifier'])
                : null,
        );
    }

    public function toArray(): array
    {
        return Normalizer::normalize([
            'index' => $this->index,
            'modifier' => $this->modifier,
        ]);
    }
}
