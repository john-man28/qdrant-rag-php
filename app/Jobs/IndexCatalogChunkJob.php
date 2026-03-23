<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Catalog\CatalogReloadCoordinator;
use App\Catalog\CatalogVectorIndexService;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class IndexCatalogChunkJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 300;

    /**
     * @var list<int>
     */
    public array $backoff = [30, 120];

    public function __construct(
        public readonly string $runId,
        public readonly string $chunkRelativePath,
    ) {}

    public function handle(
        CatalogVectorIndexService $indexer,
        CatalogReloadCoordinator $coordinator,
    ): void {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $base = $coordinator->runDirectory($this->runId);
        $path = $base.'/'.str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $this->chunkRelativePath);

        try {
            $indexer->indexChunkFile($path);
        } catch (Throwable $e) {
            $coordinator->putStatus($this->runId, [
                'last_chunk_error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
