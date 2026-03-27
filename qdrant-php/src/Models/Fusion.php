<?php

declare(strict_types=1);

namespace Qdrant\Models;

enum Fusion: string
{
    case RRF = 'rrf';
    case DBSF = 'dbsf';

    public static function fromValue(string $value): self
    {
        return self::from(mb_strtolower($value));
    }
}
