<?php

declare(strict_types=1);

namespace Qdrant\Models;

enum MultiVectorComparator: string
{
    case MAX_SIM = 'max_sim';

    public static function fromValue(string $value): self
    {
        return self::from(mb_strtolower($value));
    }
}
