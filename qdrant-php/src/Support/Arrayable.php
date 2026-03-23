<?php

declare(strict_types=1);

namespace Qdrant\Support;

interface Arrayable
{
    public static function fromArray(array $data): static;

    public function toArray(): array;
}
