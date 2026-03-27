<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use Qdrant\Models\SparseVector;
use RuntimeException;

final class CatalogVectorEncodingOrchestrator
{
    public function __construct(
        private readonly CatalogDenseEncoder $denseEncoder,
        private readonly CatalogSparseEncoder $sparseEncoder,
        private readonly CatalogLateInteractionEncoder $lateEncoder,
        private readonly CatalogSearchMode $searchMode,
    ) {}

    public function searchMode(): CatalogSearchMode
    {
        return $this->searchMode;
    }

    public function assertSearchEncodersConfigured(): void
    {
        if (! $this->searchMode->usesSparseVectors()) {
            return;
        }

        if (! $this->sparseEncoder->configured()) {
            throw new RuntimeException(
                sprintf(
                    'Catalog search mode [%s] is enabled, but the sparse model config is missing. ',
                    $this->searchMode->value,
                )
                .'Configure services.sparse_embedding.base_url, api_key, model, and timeout, '
                .'then rebuild the Qdrant collection and fully reindex the catalog.'
            );
        }

        if ($this->searchMode->usesLateInteraction() && ! $this->lateEncoder->configured()) {
            throw new RuntimeException(
                sprintf(
                    'Catalog search mode [%s] is enabled, but the late-interaction model config is missing. ',
                    $this->searchMode->value,
                )
                .'Configure services.late_interaction.base_url, api_key, model, and timeout, '
                .'then rebuild the Qdrant collection and fully reindex the catalog.'
            );
        }
    }

    /**
     * @param  list<string>  $texts
     * @return list<array{dense:list<float>,sparse?:SparseVector,late?:list<list<float>>}>
     */
    public function encodeDocuments(array $texts): array
    {
        if ($texts === []) {
            return [];
        }

        $denseVectors = $this->denseEncoder->embedBatch($texts);

        if (! $this->searchMode->usesSparseVectors()) {
            return array_map(
                static fn (array $denseVector): array => ['dense' => $denseVector],
                $denseVectors,
            );
        }

        $this->assertSearchEncodersConfigured();

        $sparseVectors = $this->sparseEncoder->embedBatch($texts);
        $lateVectors = $this->searchMode->usesLateInteraction()
            ? $this->lateEncoder->embedBatch($texts)
            : null;

        $this->assertBatchCounts($texts, $denseVectors, $sparseVectors, $lateVectors);

        $encoded = [];
        foreach ($texts as $index => $_text) {
            $item = [
                'dense' => $denseVectors[$index],
                'sparse' => $sparseVectors[$index],
            ];

            if ($this->searchMode->usesLateInteraction()) {
                $item['late'] = $lateVectors[$index];
            }

            $encoded[] = $item;
        }

        return $encoded;
    }

    /**
     * @return array{dense:list<float>,sparse?:SparseVector,late?:list<list<float>>}
     */
    public function encodeSearchQuery(string $queryText, ?string $denseQueryText = null): array
    {
        $denseVectors = $this->denseEncoder->embedBatch([$denseQueryText ?? $queryText]);
        $denseVector = $denseVectors[0] ?? [];

        if (! $this->searchMode->usesSparseVectors()) {
            return ['dense' => $denseVector];
        }

        $this->assertSearchEncodersConfigured();

        $sparseVector = $this->sparseEncoder->embedBatch([$queryText])[0] ?? new SparseVector([], []);
        $encoded = [
            'dense' => $denseVector,
            'sparse' => $sparseVector,
        ];

        if ($this->searchMode->usesLateInteraction()) {
            $encoded['late'] = $this->lateEncoder->embedBatch([$queryText])[0] ?? [];
        }

        return $encoded;
    }

    /**
     * @param  list<string>  $texts
     * @param  list<list<float>>  $denseVectors
     * @param  list<SparseVector>  $sparseVectors
     * @param  list<list<list<float>>>|null  $lateVectors
     */
    private function assertBatchCounts(
        array $texts,
        array $denseVectors,
        array $sparseVectors,
        ?array $lateVectors,
    ): void {
        $expectedCount = count($texts);

        if (
            count($denseVectors) !== $expectedCount
            || count($sparseVectors) !== $expectedCount
            || ($this->searchMode->usesLateInteraction() && count($lateVectors ?? []) !== $expectedCount)
        ) {
            throw new RuntimeException(sprintf(
                'The embedding services returned mismatched batch sizes for catalog search mode [%s].',
                $this->searchMode->value,
            ));
        }
    }
}
