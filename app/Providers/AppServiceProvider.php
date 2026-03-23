<?php

namespace App\Providers;

use App\Catalog\BigCommerceCatalogClient;
use App\Catalog\CatalogEmbeddingService;
use App\Catalog\CatalogExportService;
use App\Catalog\CatalogReloadCoordinator;
use App\Catalog\CatalogVectorIndexService;
use App\Catalog\QdrantCatalogCollectionService;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(BigCommerceCatalogClient::class, fn () => BigCommerceCatalogClient::fromConfig());
        $this->app->singleton(QdrantCatalogCollectionService::class, fn () => QdrantCatalogCollectionService::fromConfig());
        $this->app->singleton(CatalogEmbeddingService::class, fn () => CatalogEmbeddingService::fromConfig());
        $this->app->singleton(CatalogVectorIndexService::class, fn ($app) => new CatalogVectorIndexService(
            $app->make(CatalogEmbeddingService::class),
            $app->make(QdrantCatalogCollectionService::class),
        ));
        $this->app->singleton(CatalogReloadCoordinator::class, fn () => new CatalogReloadCoordinator);
        $this->app->singleton(CatalogExportService::class, fn ($app) => new CatalogExportService(
            $app->make(BigCommerceCatalogClient::class),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);
    }
}
