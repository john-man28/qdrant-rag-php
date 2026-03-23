<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Catalog\CatalogReloadStatusPresenter;
use PHPUnit\Framework\TestCase;

class CatalogReloadStatusPresenterTest extends TestCase
{
    public function test_enrich_returns_null_for_null_status(): void
    {
        $this->assertNull(CatalogReloadStatusPresenter::enrich(null));
    }

    public function test_enrich_adds_phase_label_and_export_detail_for_product_pages(): void
    {
        $enriched = CatalogReloadStatusPresenter::enrich([
            'phase' => 'export',
            'export_step' => 'products',
            'export_page' => 2,
            'export_total_pages' => 5,
            'export_progress' => 40,
        ]);

        $this->assertNotNull($enriched);
        $this->assertSame('Fetching products from BigCommerce', $enriched['phase_label']);
        $this->assertSame('Product pages 2 / 5 (40%)', $enriched['export_detail']);
    }

    public function test_enrich_labels_indexing_phase(): void
    {
        $enriched = CatalogReloadStatusPresenter::enrich([
            'phase' => 'indexing',
            'product_count' => 10,
            'chunk_count' => 3,
        ]);

        $this->assertNotNull($enriched);
        $this->assertSame('Embedding chunks and uploading to Qdrant', $enriched['phase_label']);
        $this->assertArrayNotHasKey('export_detail', $enriched);
    }
}
