<?php

declare(strict_types=1);

namespace Qdrant\Models;

use Qdrant\Support\Arrayable;
use Qdrant\Support\Normalizer;

final class ScrollResponse implements Arrayable
{
    /**
     * @param list<Record> $points
     */
    public function __construct(
        public readonly array $points,
        public readonly int|string|null $nextPageOffset = null
    ) {
    }

    public static function fromArray(array $data): static
    {
        $points = [];
        foreach ($data['points'] ?? [] as $point) {
            if (is_array($point)) {
                $points[] = Record::fromArray($point);
            }
        }

        return new self($points, $data['next_page_offset'] ?? null);
    }

    public function toArray(): array
    {
        return Normalizer::normalize([
            'points' => $this->points,
            'next_page_offset' => $this->nextPageOffset,
        ]);
    }
}
