<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\CatalogAgent\CatalogIds;
use PHPUnit\Framework\TestCase;

class CatalogIdsTest extends TestCase
{
    public function test_catalog_point_id_for_sku_maps_to_the_primary_chunk_id(): void
    {
        $this->assertSame(
            CatalogIds::catalogPointIdForChunk('OSFHU-ITW', CatalogIds::PRIMARY_CHUNK_KEY),
            CatalogIds::catalogPointIdForSku('OSFHU-ITW'),
        );
    }

    public function test_catalog_point_id_for_chunk_changes_with_chunk_key(): void
    {
        $this->assertNotSame(
            CatalogIds::catalogPointIdForChunk('OSFHU-ITW', 'main'),
            CatalogIds::catalogPointIdForChunk('OSFHU-ITW', 'features_specs'),
        );
    }
}
