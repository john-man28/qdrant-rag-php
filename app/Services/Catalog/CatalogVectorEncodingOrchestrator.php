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
        private readonly bool $hybridEnabled,
    ) {}

    public function hybridEnabled(): bool
    {
        return $this->hybridEnabled;
    }

    public function assertHybridEncodersConfigured(): void
    {
        if (! $this->hybridEnabled) {
            return;
        }

        if (! $this->sparseEncoder->configured()) {
            throw new RuntimeException(
                'Hybrid catalog search is enabled, but the sparse model config is missing. '
                .'Configure services.sparse_embedding.base_url, api_key, model, and timeout, '
                .'then rebuild the Qdrant collection and fully reindex the catalog.'
            );
        }

        if (! $this->lateEncoder->configured()) {
            throw new RuntimeException(
                'Hybrid catalog search is enabled, but the late-interaction model config is missing. '
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

        if (! $this->hybridEnabled) {
            return array_map(
                static fn (array $denseVector): array => ['dense' => $denseVector],
                $denseVectors,
            );
        }

        $this->assertHybridEncodersConfigured();

        $sparseVectors = $this->sparseEncoder->embedBatch($texts);
        $lateVectors = $this->lateEncoder->embedBatch($texts);

        $this->assertBatchCounts($texts, $denseVectors, $sparseVectors, $lateVectors);

        $encoded = [];
        foreach ($texts as $index => $_text) {
            $encoded[] = [
                'dense' => $denseVectors[$index],
                'sparse' => $sparseVectors[$index],
                'late' => $lateVectors[$index],
            ];
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

        if (! $this->hybridEnabled) {
            return ['dense' => $denseVector];
        }

        $this->assertHybridEncodersConfigured();

        $sparseVector = $this->sparseEncoder->embedBatch([$queryText])[0] ?? new SparseVector([], []);
        $lateVector = $this->lateEncoder->embedBatch([$queryText])[0] ?? [];

        return [
            'dense' => $denseVector,
            'sparse' => $sparseVector,
            'late' => $lateVector,
        ];
    }

    /**
     * @param  list<string>  $texts
     * @param  list<list<float>>  $denseVectors
     * @param  list<SparseVector>  $sparseVectors
     * @param  list<list<list<float>>>  $lateVectors
     */
    private function assertBatchCounts(
        array $texts,
        array $denseVectors,
        array $sparseVectors,
        array $lateVectors,
    ): void {
        $expectedCount = count($texts);

        if (
            count($denseVectors) !== $expectedCount
            || count($sparseVectors) !== $expectedCount
            || count($lateVectors) !== $expectedCount
        ) {
            throw new RuntimeException('The embedding services returned mismatched batch sizes for hybrid catalog encoding.');
        }
    }
}
