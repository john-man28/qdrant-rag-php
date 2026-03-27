<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Catalog\BigCommerceCatalogClient;
use App\Services\Catalog\CatalogDenseEncoder;
use App\Services\Catalog\CatalogEmbeddingService;
use App\Services\Catalog\CatalogExportService;
use App\Services\Catalog\CatalogLateInteractionEmbeddingService;
use App\Services\Catalog\CatalogLateInteractionEncoder;
use App\Services\Catalog\CatalogPointUploader;
use App\Services\Catalog\CatalogReloadCoordinator;
use App\Services\Catalog\CatalogSparseEmbeddingService;
use App\Services\Catalog\CatalogSparseEncoder;
use App\Services\Catalog\CatalogVectorEncodingOrchestrator;
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
        $this->app->singleton(CatalogSparseEmbeddingService::class, fn () => CatalogSparseEmbeddingService::fromConfig());
        $this->app->singleton(CatalogLateInteractionEmbeddingService::class, fn () => CatalogLateInteractionEmbeddingService::fromConfig());
        $this->app->singleton(CatalogDenseEncoder::class, fn ($app) => $app->make(CatalogEmbeddingService::class));
        $this->app->singleton(CatalogSparseEncoder::class, fn ($app) => $app->make(CatalogSparseEmbeddingService::class));
        $this->app->singleton(CatalogLateInteractionEncoder::class, fn ($app) => $app->make(CatalogLateInteractionEmbeddingService::class));
        $this->app->singleton(CatalogPointUploader::class, fn ($app) => $app->make(QdrantCatalogCollectionService::class));
        $this->app->singleton(CatalogVectorEncodingOrchestrator::class, fn ($app) => new CatalogVectorEncodingOrchestrator(
            $app->make(CatalogDenseEncoder::class),
            $app->make(CatalogSparseEncoder::class),
            $app->make(CatalogLateInteractionEncoder::class),
            (bool) config('catalog.search.hybrid_enabled', false),
        ));
        $this->app->singleton(CatalogVectorIndexService::class, fn ($app) => new CatalogVectorIndexService(
            $app->make(CatalogVectorEncodingOrchestrator::class),
            $app->make(QdrantCatalogCollectionService::class),
            $app->make(CatalogPointUploader::class),
        ));
        $this->app->singleton(CatalogReloadCoordinator::class, fn () => new CatalogReloadCoordinator);
        $this->app->singleton(CatalogExportService::class, fn ($app) => new CatalogExportService(
            $app->make(BigCommerceCatalogClient::class),
        ));
        $this->app->singleton(CatalogAgentConfig::class, fn () => CatalogAgentConfig::fromConfig());
        $this->app->singleton(CatalogChatAgent::class, fn ($app) => new CatalogChatAgent(
            $app->make(CatalogAgentConfig::class),
            $app->make(CatalogVectorEncodingOrchestrator::class),
            $app->make(QdrantCatalogCollectionService::class),
        ));
    }
}
