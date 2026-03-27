<?php

declare(strict_types=1);

namespace Qdrant\Models;

enum Modifier: string
{
    case IDF = 'idf';

    public static function fromValue(string $value): self
    {
        return self::from(mb_strtolower($value));
    }
}
