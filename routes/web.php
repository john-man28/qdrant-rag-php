<?php

use App\Http\Controllers\CatalogAgentController;
use Illuminate\Support\Facades\Route;

Route::controller(CatalogAgentController::class)->group(function (): void {
    Route::get('/', 'home')->name('home');

    Route::prefix('catalog-agent')->name('catalog-agent.')->group(function (): void {
        Route::post('messages', 'message')->name('message');
        Route::post('reset', 'reset')->name('reset');

        Route::prefix('reload')->name('reload.')->group(function (): void {
            Route::post('/', 'startCatalogReload')->name('start');
            Route::get('status', 'catalogReloadStatus')->name('status');
        });
    });
});
