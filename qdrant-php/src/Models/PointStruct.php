<?php

declare(strict_types=1);

namespace Qdrant\Models;

use Qdrant\Support\Arrayable;
use Qdrant\Support\Normalizer;

final class PointStruct implements Arrayable
{
    /**
     * @param  list<float>|array<string, list<float>|list<list<float>>|SparseVector>  $vector
     */
    public function __construct(
        public readonly int|string $id,
        public readonly array $vector,
        public readonly ?array $payload = null
    ) {}

    public static function fromArray(array $data): static
    {
        return new self(
            id: $data['id'],
            vector: is_array($data['vector'] ?? null) ? self::hydrateVector($data['vector']) : [],
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

    private static function hydrateVector(array $vector): array
    {
        if (self::isSparseVector($vector)) {
            return SparseVector::fromArray($vector)->toArray();
        }

        if (array_is_list($vector)) {
            return $vector;
        }

        $normalized = [];
        foreach ($vector as $name => $value) {
            if ($value instanceof SparseVector) {
                $normalized[$name] = $value;

                continue;
            }

            if (is_array($value) && self::isSparseVector($value)) {
                $normalized[$name] = SparseVector::fromArray($value);

                continue;
            }

            $normalized[$name] = $value;
        }

        return $normalized;
    }

    private static function isSparseVector(array $vector): bool
    {
        return isset($vector['indices'], $vector['values'])
            && is_array($vector['indices'])
            && is_array($vector['values']);
    }
}
