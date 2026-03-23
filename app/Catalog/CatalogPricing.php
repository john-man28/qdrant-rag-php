<?php

declare(strict_types=1);

namespace App\Catalog;

final class CatalogPricing
{
    /**
     * @return array{id: mixed, name: mixed, sku: mixed}
     */
    public static function normalizeProductPayload(array $product): array
    {
        return [
            'id' => $product['id'] ?? null,
            'name' => $product['name'] ?? null,
            'sku' => $product['sku'] ?? null,
        ];
    }

    /**
     * @return list<string>
     */
    public static function buildProductPriceLines(array $product): array
    {
        $variants = is_array($product['variants'] ?? null) ? $product['variants'] : [];

        if (empty($variants)) {
            $lines = [];
            $price = self::formatCatalogPrice($product['price'] ?? null);
            if ($price !== null) {
                $lines[] = 'Price: '.$price;
            }

            $salePrice = $product['sale_price'] ?? null;
            if (self::isPositiveCatalogPrice($salePrice) && isset($product['price']) && is_numeric($salePrice) && is_numeric($product['price']) && (float) $salePrice < (float) $product['price']) {
                $formattedSalePrice = self::formatCatalogPrice($salePrice);
                if ($formattedSalePrice !== null) {
                    $lines[] = 'Now on sale for '.$formattedSalePrice;
                }
            }

            return $lines;
        }

        $variantPrices = [];
        $variantSalePrices = [];

        foreach ($variants as $variant) {
            $price = $variant['calculated_price'] ?? $variant['price'] ?? null;
            if (is_numeric($price)) {
                $variantPrices[] = (float) $price;
            }

            $salePrice = $variant['sale_price'] ?? null;
            if (self::isPositiveCatalogPrice($salePrice) && is_numeric($price) && is_numeric($salePrice) && (float) $salePrice < (float) $price) {
                $variantSalePrices[] = (float) $salePrice;
            }
        }

        $lines = [];

        $priceLine = self::buildCatalogPriceLine('Price: ', $variantPrices);
        if ($priceLine !== null) {
            $lines[] = $priceLine;
        }

        $salePriceLine = self::buildCatalogPriceLine('Now on sale for ', $variantSalePrices);
        if ($salePriceLine !== null) {
            $lines[] = $salePriceLine;
        }

        return $lines;
    }

    /**
     * @param  list<float>  $values
     */
    public static function buildCatalogPriceLine(string $prefix, array $values): ?string
    {
        if (empty($values)) {
            return null;
        }

        $min = min($values);
        $max = max($values);
        $formattedMin = self::formatCatalogPrice($min);
        $formattedMax = self::formatCatalogPrice($max);

        if ($formattedMin === null) {
            return null;
        }

        if ($formattedMax === null || self::catalogPricesMatch($min, $max)) {
            return $prefix.$formattedMin;
        }

        return $prefix.$formattedMin.' - '.$formattedMax;
    }

    public static function formatCatalogPrice(string|int|float|null $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            $value = trim((string) $value);

            return $value === '' ? null : $value;
        }

        $formatted = number_format((float) $value, 2, '.', '');

        return rtrim(rtrim($formatted, '0'), '.');
    }

    public static function isPositiveCatalogPrice(mixed $value): bool
    {
        return is_numeric($value) && (float) $value > 0;
    }

    public static function catalogPricesMatch(float $left, float $right): bool
    {
        return abs($left - $right) < 0.00001;
    }
}
