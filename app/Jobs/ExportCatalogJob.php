<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Catalog\CatalogExportService;
use App\Catalog\CatalogReloadCoordinator;
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
            $result = $export->exportToRunDirectory($dir);
        } catch (Throwable $e) {
            $coordinator->putStatus($this->runId, [
                'phase' => 'failed',
                'error' => $e->getMessage(),
                'finished_at' => now()->toIso8601String(),
            ]);
            $coordinator->clearActiveRun();
            throw $e;
        }

        $coordinator->putStatus($this->runId, [
            'phase' => 'indexing',
            'product_count' => $result->productCount,
            'variant_count' => $result->variantCount,
            'chunk_count' => count($result->chunkRelativePaths),
        ]);

        $jobs = [];
        foreach ($result->chunkRelativePaths as $rel) {
            $jobs[] = new IndexCatalogChunkJob($this->runId, $rel);
        }

        if ($jobs === []) {
            $coordinator->putStatus($this->runId, [
                'phase' => 'completed',
                'finished_at' => now()->toIso8601String(),
                'error' => null,
            ]);
            $coordinator->clearActiveRun();

            return;
        }

        $runId = $this->runId;

        $batch = Bus::batch($jobs)
            ->name('Catalog vector index '.$runId)
            ->allowFailures(false)
            ->then(function (Batch $batch) use ($runId, $coordinator) {
                $coordinator->putStatus($runId, [
                    'phase' => 'completed',
                    'finished_at' => now()->toIso8601String(),
                    'error' => null,
                ]);
            })
            ->catch(function (Batch $batch, Throwable $e) use ($runId, $coordinator) {
                $coordinator->putStatus($runId, [
                    'phase' => 'failed',
                    'error' => $e->getMessage(),
                    'finished_at' => now()->toIso8601String(),
                ]);
            })
            ->finally(function (Batch $batch) use ($runId, $coordinator) {
                $coordinator->putStatus($runId, [
                    'batch_finished_at' => now()->toIso8601String(),
                ]);
                $coordinator->clearActiveRun();
            })
            ->dispatch();

        $coordinator->putStatus($this->runId, [
            'batch_id' => $batch->id,
        ]);
    }
}
