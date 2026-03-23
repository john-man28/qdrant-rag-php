<?php

declare(strict_types=1);

namespace App\CatalogAgent;

use InvalidArgumentException;
use Symfony\Component\Uid\Uuid;

final class CatalogIds
{
    public static function normalizeSku(string $sku): string
    {
        $normalized = strtolower(preg_replace('/[^[:alnum:]]/u', '', $sku) ?? '');

        if ($normalized === '') {
            throw new InvalidArgumentException('SKU must contain at least one alphanumeric character.');
        }

        return $normalized;
    }

    public static function catalogPointIdForSku(string $sku): string
    {
        $name = sprintf('product:%s:main', self::normalizeSku($sku));

        return Uuid::v5(Uuid::fromString(Uuid::NAMESPACE_DNS), $name)->toRfc4122();
    }
}
