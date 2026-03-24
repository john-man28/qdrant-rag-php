<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Catalog\BigCommerceCatalogClient;
use App\Catalog\CatalogExportService;
use PHPUnit\Framework\TestCase;

class CatalogExportServiceTest extends TestCase
{
    public function test_build_product_chunk_records_repeats_full_payload_text_across_chunks(): void
    {
        $service = new CatalogExportService(
            new BigCommerceCatalogClient('https://example.com', 'token'),
        );

        $records = $service->buildProductChunkRecords(
            [
                'id' => 1,
                'name' => 'Fixture Sensor',
                'sku' => 'OSFHU-ITW',
                'description' => <<<'HTML'
<h2>Overview</h2>
<p>Ceiling sensor for large open spaces.</p>
<h2>Key Features and Benefits</h2>
<ul><li>High sensitivity</li></ul>
<h2>Specifications</h2>
<ul><li>120-277V</li></ul>
<h2>Common Uses</h2>
<p>Warehouses and gymnasiums.</p>
HTML,
            ],
            'Acme',
            ['Sensors'],
            ['Price: 10'],
        );

        $this->assertCount(3, $records);
        $this->assertSame('main', $records[0]['payload']['chunk_key']);
        $this->assertSame('features_specs', $records[1]['payload']['chunk_key']);
        $this->assertSame('uses_other', $records[2]['payload']['chunk_key']);

        $fullText = $records[0]['payload']['text'];
        foreach ($records as $record) {
            $this->assertSame($fullText, $record['payload']['text']);
            $this->assertStringContainsString('Product: Fixture Sensor', $record['text']);
            $this->assertStringContainsString('SKU: OSFHU-ITW', $record['text']);
        }
    }
}
