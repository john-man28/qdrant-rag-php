<?php

use App\Catalog\CatalogReloadCoordinator;
use App\Jobs\BeginCatalogReloadJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('catalog:reload', function (CatalogReloadCoordinator $coordinator): void {
    if ($coordinator->activeRunId() !== null) {
        $this->error('A catalog reload is already in progress (run '.$coordinator->activeRunId().').');

        return;
    }

    $runId = Str::uuid()->toString();
    $coordinator->setActiveRun($runId);
    BeginCatalogReloadJob::dispatch($runId);
    $this->info('Dispatched catalog reload '.$runId.'.');
})->purpose('Queue BigCommerce export and Qdrant vector reload');
