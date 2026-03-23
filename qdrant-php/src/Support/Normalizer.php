<?php

declare(strict_types=1);

namespace Qdrant\Support;

use BackedEnum;
use DateTimeInterface;

final class Normalizer
{
    public static function normalize(mixed $value): mixed
    {
        if ($value instanceof BackedEnum) {
            return $value->value;
        }

        if ($value instanceof Arrayable) {
            return $value->toArray();
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format(DateTimeInterface::ATOM);
        }

        if (is_array($value)) {
            if (array_is_list($value)) {
                return array_map(self::normalize(...), $value);
            }

            $normalized = [];
            foreach ($value as $key => $item) {
                $item = self::normalize($item);
                if ($item === null) {
                    continue;
                }
                $normalized[$key] = $item;
            }

            return $normalized;
        }

        return $value;
    }
}
