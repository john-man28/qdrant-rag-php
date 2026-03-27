<?php

declare(strict_types=1);

namespace Qdrant\Models;

use Qdrant\Support\Arrayable;
use Qdrant\Support\Normalizer;

final class VectorParams implements Arrayable
{
    public function __construct(
        public readonly int $size,
        public readonly Distance $distance,
        public readonly ?MultiVectorConfig $multivectorConfig = null,
        public readonly ?HnswConfigDiff $hnswConfig = null
    ) {}

    public static function fromArray(array $data): static
    {
        return new self(
            size: (int) ($data['size'] ?? 0),
            distance: Distance::from((string) ($data['distance'] ?? Distance::COSINE->value)),
            multivectorConfig: isset($data['multivector_config']) && is_array($data['multivector_config'])
                ? MultiVectorConfig::fromArray($data['multivector_config'])
                : null,
            hnswConfig: isset($data['hnsw_config']) && is_array($data['hnsw_config'])
                ? HnswConfigDiff::fromArray($data['hnsw_config'])
                : null,
        );
    }

    public function toArray(): array
    {
        return Normalizer::normalize([
            'size' => $this->size,
            'distance' => $this->distance,
            'multivector_config' => $this->multivectorConfig,
            'hnsw_config' => $this->hnswConfig,
        ]);
    }
}
