<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Catalog\BigCommerceCatalogClient;
use App\Services\Catalog\CatalogEmbeddingService;
use App\Services\Catalog\CatalogExportService;
use App\Services\Catalog\CatalogReloadCoordinator;
use App\Services\Catalog\CatalogVectorIndexService;
use App\Services\Catalog\QdrantCatalogCollectionService;
use App\Services\CatalogAgent\CatalogAgentConfig;
use App\Services\CatalogAgent\CatalogChatAgent;
use Illuminate\Support\ServiceProvider;

class CatalogServiceProvider extends ServiceProvider
{
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
        $this->app->singleton(CatalogAgentConfig::class, fn () => CatalogAgentConfig::fromConfig());
        $this->app->singleton(CatalogChatAgent::class, fn ($app) => new CatalogChatAgent(
            $app->make(CatalogAgentConfig::class),
        ));
    }
}
