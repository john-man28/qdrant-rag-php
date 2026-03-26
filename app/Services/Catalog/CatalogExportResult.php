<?php

declare(strict_types=1);

namespace App\Services\Catalog;

final readonly class CatalogExportResult
{
    /**
     * @param  list<string>  $chunkRelativePaths  Paths under the run dir, e.g. chunks/chunk-00001.jsonl
     */
    public function __construct(
        public int $productCount,
        public int $variantCount,
        public int $embeddingChunkCount,
        public string $productsJsonlPath,
        public string $categoriesJsonlPath,
        public string $brandsJsonlPath,
        public array $chunkRelativePaths,
    ) {}
}
