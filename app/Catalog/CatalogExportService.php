<?php

declare(strict_types=1);

namespace App\Catalog;

use Illuminate\Support\Facades\File;
use RuntimeException;

final class CatalogExportService
{
    public function __construct(
        private readonly BigCommerceCatalogClient $client,
    ) {}

    /**
     * Export catalog NDJSON into $runDirectory (must exist).
     * Splits products.jsonl into chunk files under chunks/.
     */
    public function exportToRunDirectory(string $runDirectory): CatalogExportResult
    {
        if (! is_dir($runDirectory)) {
            throw new RuntimeException("Run directory does not exist: {$runDirectory}");
        }

        $baseUrl = $this->client->baseUrl();
        $categoriesFile = $runDirectory.'/categories.jsonl';
        $brandsFile = $runDirectory.'/brands.jsonl';
        $productsFile = $runDirectory.'/products.jsonl';
        $variantsFile = $runDirectory.'/variants.jsonl';

        $categories = $this->fetchAllCategories($baseUrl);
        $this->writeJsonLines($categoriesFile, $categories);
        $categoryLookup = $this->buildCategoryPathLookup($categories);

        $brands = $this->fetchAllBrands($baseUrl);
        $this->writeJsonLines($brandsFile, $brands);
        $brandLookup = $this->buildBrandLookup($brands);

        $productsFp = fopen($productsFile, 'w');
        if ($productsFp === false) {
            throw new RuntimeException("Cannot write {$productsFile}");
        }
        $variantsFp = fopen($variantsFile, 'w');
        if ($variantsFp === false) {
            fclose($productsFp);
            throw new RuntimeException("Cannot write {$variantsFile}");
        }

        [$productCount, $variantCount] = $this->fetchAndWriteProducts(
            $baseUrl,
            $productsFp,
            $variantsFp,
            $categoryLookup,
            $brandLookup
        );
        fclose($productsFp);
        fclose($variantsFp);

        $chunksDir = $runDirectory.'/chunks';
        File::ensureDirectoryExists($chunksDir);

        $linesPerChunk = max(1, (int) config('services.catalog_reload.lines_per_chunk_file', 256));
        $chunkRelativePaths = $this->splitJsonlIntoChunkFiles($productsFile, $chunksDir, $linesPerChunk);

        return new CatalogExportResult(
            productCount: $productCount,
            variantCount: $variantCount,
            productsJsonlPath: $productsFile,
            categoriesJsonlPath: $categoriesFile,
            brandsJsonlPath: $brandsFile,
            chunkRelativePaths: $chunkRelativePaths,
        );
    }

    /**
     * @param  list<array<string, mixed>>  $records
     */
    public function writeJsonLines(string $path, array $records): void
    {
        $fp = fopen($path, 'w');
        if ($fp === false) {
            throw new RuntimeException("Cannot write {$path}");
        }
        foreach ($records as $record) {
            fwrite($fp, json_encode($record)."\n");
        }
        fclose($fp);
    }

    /**
     * @return list<string> Relative paths from run directory (e.g. chunks/chunk-00001.jsonl)
     */
    public function splitJsonlIntoChunkFiles(string $productsJsonlPath, string $chunksDir, int $linesPerChunk): array
    {
        $relative = [];
        $chunkIndex = 1;
        $lineBuffer = '';
        $count = 0;
        $in = fopen($productsJsonlPath, 'r');
        if ($in === false) {
            throw new RuntimeException("Cannot read {$productsJsonlPath}");
        }

        $flush = function () use (&$lineBuffer, &$chunkIndex, $chunksDir, &$relative, &$count): void {
            if ($lineBuffer === '') {
                return;
            }
            $filename = sprintf('chunk-%05d.jsonl', $chunkIndex);
            $path = $chunksDir.'/'.$filename;
            file_put_contents($path, $lineBuffer);
            $relative[] = 'chunks/'.$filename;
            $chunkIndex++;
            $lineBuffer = '';
            $count = 0;
        };

        while (($line = fgets($in)) !== false) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $lineBuffer .= $line."\n";
            $count++;
            if ($count >= $linesPerChunk) {
                $flush();
            }
        }
        fclose($in);
        $flush();

        if ($relative === []) {
            $emptyChunk = $chunksDir.'/chunk-00001.jsonl';
            file_put_contents($emptyChunk, '');
            $relative[] = 'chunks/chunk-00001.jsonl';
        }

        return $relative;
    }

    /**
     * @param  list<array<string, mixed>>  $brands
     * @return array<int, string>
     */
    public function buildBrandLookup(array $brands): array
    {
        $lookup = [];

        foreach ($brands as $brand) {
            $brandId = (int) ($brand['id'] ?? 0);
            $brandName = trim((string) ($brand['name'] ?? ''));

            if ($brandId > 0 && $brandName !== '') {
                $lookup[$brandId] = $brandName;
            }
        }

        return $lookup;
    }

    /**
     * @param  list<array<string, mixed>>  $categories
     * @return array<int, string>
     */
    public function buildCategoryPathLookup(array $categories): array
    {
        $categoriesById = [];
        foreach ($categories as $category) {
            $categoryId = (int) ($category['id'] ?? 0);
            if ($categoryId > 0) {
                $categoriesById[$categoryId] = $category;
            }
        }

        $lookup = [];
        foreach (array_keys($categoriesById) as $categoryId) {
            $path = $this->buildCategoryPath($categoryId, $categoriesById);
            if ($path !== '') {
                $lookup[$categoryId] = $path;
            }
        }

        return $lookup;
    }

    /**
     * @param  array<int, array<string, mixed>>  $categoriesById
     */
    public function buildCategoryPath(int $categoryId, array $categoriesById): string
    {
        $parts = [];
        $seen = [];

        while ($categoryId > 0 && isset($categoriesById[$categoryId]) && ! isset($seen[$categoryId])) {
            $seen[$categoryId] = true;
            $category = $categoriesById[$categoryId];
            $name = trim((string) ($category['name'] ?? ''));

            if ($name !== '') {
                $parts[] = $name;
            }

            $categoryId = (int) ($category['parent_id'] ?? 0);
        }

        if (empty($parts)) {
            return '';
        }

        return implode(' > ', array_reverse($parts));
    }

    /**
     * @param  array<int, string>  $brandLookup
     */
    public function resolveProductBrand(array $product, array $brandLookup): ?string
    {
        $brandId = (int) ($product['brand_id'] ?? 0);
        if ($brandId > 0 && isset($brandLookup[$brandId])) {
            return $brandLookup[$brandId];
        }

        $brandName = trim((string) ($product['brand_name'] ?? ''));

        return $brandName !== '' ? $brandName : null;
    }

    /**
     * @param  array<int, string>  $categoryLookup
     * @return list<string>
     */
    public function resolveProductCategories(array $product, array $categoryLookup): array
    {
        $categoryIds = $product['categories'] ?? $product['category_ids'] ?? [];
        if (! is_array($categoryIds)) {
            return [];
        }

        $categories = [];
        foreach ($categoryIds as $categoryId) {
            $categoryId = (int) $categoryId;
            if ($categoryId <= 0 || ! isset($categoryLookup[$categoryId])) {
                continue;
            }

            $categoryPath = trim((string) $categoryLookup[$categoryId]);
            if ($categoryPath !== '' && ! in_array($categoryPath, $categories, true)) {
                $categories[] = $categoryPath;
            }
        }

        return $categories;
    }

    /**
     * @param  resource  $productsFp
     * @param  resource  $variantsFp
     * @param  array<int, string>  $categoryLookup
     * @param  array<int, string>  $brandLookup
     * @return array{0: int, 1: int}
     */
    public function fetchAndWriteProducts(
        string $baseUrl,
        $productsFp,
        $variantsFp,
        array $categoryLookup = [],
        array $brandLookup = []
    ): array {
        $limit = 250;
        $url = "{$baseUrl}/products?include=images,variants&limit={$limit}&page=1";
        [$httpCode, $data] = $this->client->get($url);

        if ($httpCode !== 200) {
            return [0, 0];
        }

        $productCount = 0;
        $variantCount = 0;

        $writeProducts = function (array $products) use (
            $productsFp,
            $categoryLookup,
            $brandLookup,
            &$productCount,
            &$variantCount
        ) {
            foreach ($products as $product) {
                $sku = (string) ($product['sku'] ?? '');
                if (str_starts_with($sku, 'VE-') || str_starts_with($sku, 'RDV-') || str_starts_with($sku, 'VEQ-')) {
                    continue;
                }
                if (empty($product['description']) || strlen((string) $product['description']) < 80 || ($product['is_visible'] ?? false) !== true) {
                    continue;
                }
                $brand = $this->resolveProductBrand($product, $brandLookup);
                $categories = $this->resolveProductCategories($product, $categoryLookup);
                $priceLines = CatalogPricing::buildProductPriceLines($product);
                $normalized = ProductDescriptionNormalizer::normalizeProductDescriptionToText(
                    $product['description'] ?? null,
                    $product['name'] ?? null,
                    $product['sku'] ?? null,
                    $brand,
                    $categories,
                    $priceLines
                );

                $formatted = [
                    'payload' => CatalogPricing::normalizeProductPayload($product) + [
                        'brand' => $brand,
                        'categories' => $categories,
                    ],
                    'text' => $normalized,
                ];
                fwrite($productsFp, json_encode($formatted)."\n");
                $productCount++;
                $variantCount += is_array($product['variants'] ?? null) ? count($product['variants']) : 0;
            }
        };

        $writeProducts($data['data'] ?? []);
        $total = $data['meta']['pagination']['total'] ?? 0;
        $totalPages = (int) ceil($total / $limit);

        if ($totalPages <= 1) {
            return [$productCount, $variantCount];
        }

        $urls = [];
        for ($page = 2; $page <= $totalPages; $page++) {
            $urls[] = "{$baseUrl}/products?include=images,variants&limit={$limit}&page={$page}";
        }

        foreach (array_chunk($urls, $this->client->poolSize()) as $batch) {
            $results = $this->client->getBatch($batch);
            foreach ($results as $resp) {
                [$code, $pageData] = $resp;
                if ($code === 200 && ! empty($pageData['data'])) {
                    $writeProducts($pageData['data']);
                }
            }
            usleep($this->client->batchDelayMicroseconds());
        }

        return [$productCount, $variantCount];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function fetchAllCategories(string $baseUrl): array
    {
        [$httpCode, $data] = $this->client->get("{$baseUrl}/trees");
        if ($httpCode !== 200) {
            return [];
        }

        $trees = $data['data'] ?? [];
        $allCategories = [];

        foreach ($trees as $tree) {
            $treeId = $tree['id'] ?? 0;
            $url = "{$baseUrl}/trees/{$treeId}/categories?depth=10";
            [$code, $catData] = $this->client->get($url);

            if ($code !== 200) {
                continue;
            }

            $categories = $catData['data'] ?? [];
            $allCategories = array_merge($allCategories, $this->flattenCategories($categories, $treeId));
            usleep($this->client->batchDelayMicroseconds());
        }

        return $allCategories;
    }

    /**
     * @param  list<array<string, mixed>>  $categories
     * @return list<array<string, mixed>>
     */
    public function flattenCategories(array $categories, int $treeId, ?int $parentId = null): array
    {
        $flat = [];
        foreach ($categories as $cat) {
            $item = [
                'id' => $cat['id'] ?? null,
                'name' => $cat['name'] ?? '',
                'parent_id' => $parentId,
                'tree_id' => $treeId,
                'description' => $cat['description'] ?? '',
                'sort_order' => $cat['sort_order'] ?? 0,
            ];
            $flat[] = $item;

            if (! empty($cat['children'])) {
                $flat = array_merge(
                    $flat,
                    $this->flattenCategories($cat['children'], $treeId, $cat['id'] ?? null)
                );
            }
        }

        return $flat;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function fetchAllBrands(string $baseUrl): array
    {
        $limit = 250;
        $url = "{$baseUrl}/brands?limit={$limit}&page=1";
        [$httpCode, $data] = $this->client->get($url);

        if ($httpCode !== 200) {
            return [];
        }

        $brands = $data['data'] ?? [];

        $total = $data['meta']['pagination']['total'] ?? 0;
        $totalPages = (int) ceil($total / $limit);

        if ($totalPages <= 1) {
            return $brands;
        }

        $urls = [];
        for ($page = 2; $page <= $totalPages; $page++) {
            $urls[] = "{$baseUrl}/brands?limit={$limit}&page={$page}";
        }

        foreach (array_chunk($urls, $this->client->poolSize()) as $batch) {
            $results = $this->client->getBatch($batch);
            foreach ($results as $resp) {
                [$code, $pageData] = $resp;
                if ($code === 200 && ! empty($pageData['data'])) {
                    $brands = array_merge($brands, $pageData['data']);
                }
            }
            usleep($this->client->batchDelayMicroseconds());
        }

        return $brands;
    }
}
