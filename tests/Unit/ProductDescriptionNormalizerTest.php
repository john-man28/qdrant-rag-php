<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Catalog\ProductDescriptionNormalizer;
use PHPUnit\Framework\TestCase;

class ProductDescriptionNormalizerTest extends TestCase
{
    public function test_build_metadata_header_includes_sku_and_brand(): void
    {
        $header = ProductDescriptionNormalizer::buildMetadataHeader(
            'Test Product',
            'SKU-1',
            'Acme',
            ['Cat A'],
            ['Price: 10']
        );

        $this->assertStringContainsString('Product: Test Product', $header);
        $this->assertStringContainsString('SKU: SKU-1', $header);
        $this->assertStringContainsString('Brand: Acme', $header);
        $this->assertStringContainsString('Category: Cat A', $header);
        $this->assertStringContainsString('Price: 10', $header);
    }
}
