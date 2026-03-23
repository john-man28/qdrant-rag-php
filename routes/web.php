<?php

use App\Http\Controllers\CatalogAgentController;
use Illuminate\Support\Facades\Route;

Route::get('/', [CatalogAgentController::class, 'home'])->name('home');
Route::post('/catalog-agent/messages', [CatalogAgentController::class, 'message'])->name('catalog-agent.message');
Route::post('/catalog-agent/reset', [CatalogAgentController::class, 'reset'])->name('catalog-agent.reset');
