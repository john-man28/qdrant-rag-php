<?php

declare(strict_types=1);

namespace Qdrant\Models;

use Qdrant\Support\Arrayable;
use Qdrant\Support\Normalizer;

final class QueryResponse implements Arrayable
{
    /**
     * @param list<ScoredPoint> $points
     */
    public function __construct(public readonly array $points)
    {
    }

    public static function fromArray(array $data): static
    {
        $points = [];
        foreach ($data['points'] ?? [] as $point) {
            if (is_array($point)) {
                $points[] = ScoredPoint::fromArray($point);
            }
        }

        return new self($points);
    }

    public function toArray(): array
    {
        return Normalizer::normalize([
            'points' => $this->points,
        ]);
    }
}
