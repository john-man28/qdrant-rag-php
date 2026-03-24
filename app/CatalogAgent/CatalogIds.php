<?php

declare(strict_types=1);

namespace App\CatalogAgent;

use InvalidArgumentException;
use Symfony\Component\Uid\Uuid;

final class CatalogIds
{
    public const PRIMARY_CHUNK_KEY = 'main';

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
        return self::catalogPointIdForChunk($sku, self::PRIMARY_CHUNK_KEY);
    }

    public static function catalogPointIdForChunk(string $sku, string $chunkKey): string
    {
        $normalizedChunkKey = strtolower(preg_replace('/[^[:alnum:]]/u', '', $chunkKey) ?? '');
        if ($normalizedChunkKey === '') {
            throw new InvalidArgumentException('Chunk key must contain at least one alphanumeric character.');
        }

        $name = sprintf('product:%s:%s', self::normalizeSku($sku), $normalizedChunkKey);

        return Uuid::v5(Uuid::fromString(Uuid::NAMESPACE_DNS), $name)->toRfc4122();
    }
}
