<?php

use App\Jobs\BeginCatalogReloadJob;
use App\Services\Catalog\CatalogReloadCoordinator;
use App\Services\Catalog\CatalogReloadStatusPresenter;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('catalog:reload {--wait : Poll status every 5s until the run completes or fails}', function (CatalogReloadCoordinator $coordinator): void {
    if ($coordinator->activeRunId() !== null) {
        $this->error('A catalog reload is already in progress (run '.$coordinator->activeRunId().').');

        return;
    }

    $runId = Str::uuid()->toString();
    $coordinator->setActiveRun($runId);
    BeginCatalogReloadJob::dispatch($runId);
    $this->info('Dispatched catalog reload '.$runId.'.');

    if (! $this->option('wait')) {
        return;
    }

    $this->info('Waiting for completion (polling every 5s)…');

    while (true) {
        sleep(5);
        $raw = $coordinator->getStatus($runId);
        $status = CatalogReloadStatusPresenter::enrich($raw);
        if ($status === null) {
            $this->error('Run status not found for '.$runId.'.');

            return;
        }

        $phase = is_string($status['phase'] ?? null) ? $status['phase'] : '';
        $label = $status['phase_label'] ?? $phase;
        $detail = isset($status['export_detail']) && is_string($status['export_detail']) ? $status['export_detail'] : '';
        $line = $detail !== '' ? "{$label} — {$detail}" : $label;

        $batchLine = '';
        if (isset($status['batch_id']) && is_string($status['batch_id'])) {
            $batch = Bus::findBatch($status['batch_id']);
            if ($batch !== null && $batch->totalJobs > 0) {
                $done = $batch->totalJobs - $batch->pendingJobs;
                $batchLine = " · chunks {$done}/{$batch->totalJobs} (".(int) round($batch->progress()).'%)';
            }
        }

        $warn = '';
        if (isset($status['last_chunk_error']) && is_string($status['last_chunk_error']) && $status['last_chunk_error'] !== '') {
            $warn = ' · chunk error: '.$status['last_chunk_error'];
        }

        $this->line($line.$batchLine.$warn);

        if ($phase === 'completed' || $phase === 'failed') {
            if ($phase === 'failed') {
                $err = isset($status['error']) && is_string($status['error']) ? $status['error'] : 'unknown error';
                $this->error('Catalog reload failed: '.$err);
            } else {
                $this->info('Catalog reload finished.');
            }

            return;
        }
    }
})->purpose('Queue BigCommerce export and Qdrant vector reload');
