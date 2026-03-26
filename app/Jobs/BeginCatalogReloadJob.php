<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Catalog\CatalogReloadCoordinator;
use App\Services\Catalog\QdrantCatalogCollectionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\File;
use Throwable;

class BeginCatalogReloadJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;

    public function __construct(
        public readonly string $runId,
    ) {}

    public function uniqueId(): string
    {
        return 'catalog-full-reload';
    }

    public function handle(
        QdrantCatalogCollectionService $qdrant,
        CatalogReloadCoordinator $coordinator,
    ): void {
        $dir = $coordinator->runDirectory($this->runId);
        File::ensureDirectoryExists($dir);

        $coordinator->rememberLatestRunId($this->runId);
        $coordinator->putStatus($this->runId, [
            'phase' => 'reset_qdrant',
            'batch_id' => null,
            'error' => null,
            'started_at' => now()->toIso8601String(),
            'finished_at' => null,
        ]);

        try {
            $qdrant->resetCollection();
        } catch (Throwable $exception) {
            $coordinator->markFailed($this->runId, $exception);

            throw $exception;
        }

        $coordinator->putStatus($this->runId, [
            'phase' => 'export',
        ]);

        ExportCatalogJob::dispatch($this->runId);
    }
}
