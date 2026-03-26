<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Catalog\CatalogExportService;
use App\Services\Catalog\CatalogReloadCoordinator;
use Illuminate\Bus\Batch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Throwable;

class ExportCatalogJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 0;

    public function __construct(
        public readonly string $runId,
    ) {}

    public function handle(
        CatalogExportService $export,
        CatalogReloadCoordinator $coordinator,
    ): void {
        $dir = $coordinator->runDirectory($this->runId);

        try {
            $runId = $this->runId;
            $result = $export->exportToRunDirectory($dir, function (array $fields) use ($coordinator, $runId): void {
                $coordinator->putStatus($runId, $fields);
            });
        } catch (Throwable $exception) {
            $coordinator->markFailed($this->runId, $exception);

            throw $exception;
        }

        $coordinator->putStatus($this->runId, [
            'phase' => 'indexing',
            'product_count' => $result->productCount,
            'variant_count' => $result->variantCount,
            'embedding_chunk_count' => $result->embeddingChunkCount,
            'chunk_count' => count($result->chunkRelativePaths),
        ]);

        $jobs = [];
        foreach ($result->chunkRelativePaths as $rel) {
            $jobs[] = new IndexCatalogChunkJob($this->runId, $rel);
        }

        if ($jobs === []) {
            $coordinator->markCompleted($this->runId);

            return;
        }

        $batch = Bus::batch($jobs)
            ->name('Catalog vector index '.$runId)
            ->allowFailures(false)
            ->then(function (Batch $batch) use ($runId, $coordinator) {
                $coordinator->markCompleted($runId);
            })
            ->catch(function (Batch $batch, Throwable $exception) use ($runId, $coordinator) {
                $coordinator->markFailed($runId, $exception);
            })
            ->finally(function (Batch $batch) use ($runId, $coordinator) {
                $coordinator->markBatchFinished($runId);
            })
            ->dispatch();

        $coordinator->putStatus($this->runId, [
            'batch_id' => $batch->id,
        ]);
    }
}
