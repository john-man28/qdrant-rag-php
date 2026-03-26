<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Catalog\ProductDescriptionNormalizer;
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

    public function test_build_product_text_artifacts_emits_three_chunks_with_product_context(): void
    {
        $artifacts = ProductDescriptionNormalizer::buildProductTextArtifacts(
            <<<'HTML'
<h2>Overview</h2>
<p>Ceiling sensor for large open spaces.</p>
<h2>Key Features and Benefits</h2>
<ul><li>High sensitivity</li></ul>
<h2>Specifications</h2>
<ul><li>120-277V</li></ul>
<h2>Common Uses</h2>
<p>Warehouses and gymnasiums.</p>
<h2>Other Details</h2>
<ul><li>5 year warranty</li></ul>
HTML,
            'Fixture Sensor',
            'OSFHU-ITW',
            'Acme',
            ['Sensors'],
            ['Price: 10'],
        );

        $this->assertCount(3, $artifacts['chunks']);
        $this->assertSame('main', $artifacts['chunks'][0]['key']);
        $this->assertSame('features_specs', $artifacts['chunks'][1]['key']);
        $this->assertSame('uses_other', $artifacts['chunks'][2]['key']);

        foreach ($artifacts['chunks'] as $chunk) {
            $this->assertStringContainsString('Product: Fixture Sensor', $chunk['text']);
            $this->assertStringContainsString('SKU: OSFHU-ITW', $chunk['text']);
        }

        $this->assertStringContainsString('Introduction:', $artifacts['chunks'][0]['text']);
        $this->assertStringContainsString('Key Features and Benefits:', $artifacts['chunks'][1]['text']);
        $this->assertStringContainsString('Specifications:', $artifacts['chunks'][1]['text']);
        $this->assertStringContainsString('Common Uses:', $artifacts['chunks'][2]['text']);
        $this->assertStringContainsString('Other Details:', $artifacts['chunks'][2]['text']);
    }

    public function test_build_product_text_artifacts_keeps_a_primary_main_chunk_when_introduction_is_missing(): void
    {
        $artifacts = ProductDescriptionNormalizer::buildProductTextArtifacts(
            <<<'HTML'
<h2>Key Features and Benefits</h2>
<ul><li>Fast response</li></ul>
<h2>Specifications</h2>
<ul><li>277V</li></ul>
HTML,
            'Fixture Sensor',
            'OSFHU-ITW',
        );

        $this->assertSame('main', $artifacts['chunks'][0]['key']);
        $this->assertTrue($artifacts['chunks'][0]['is_primary']);
        $this->assertStringContainsString('Key Features and Benefits:', $artifacts['chunks'][0]['text']);
        $this->assertStringContainsString('SKU: OSFHU-ITW', $artifacts['chunks'][0]['text']);
    }
}
