<?php

declare(strict_types=1);

namespace App\Services\CatalogAgent;

use App\Services\Catalog\CatalogSearchMode;

final readonly class CatalogAgentConfig
{
    public function __construct(
        public string $openaiBaseUrl,
        public string $openaiApiKey,
        public string $openaiModel,
        public string $embeddingModel,
        public int $openaiTimeout,
        public string $qdrantUrl,
        public string $qdrantCollection,
        public int $qdrantTimeout,
        public int $chatTopK,
        public CatalogSearchMode $searchMode,
        public int $hybridPrefetchLimit,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            openaiBaseUrl: (string) config('services.openai.base_url', 'http://localhost:1234/v1'),
            openaiApiKey: (string) config('services.openai.api_key', 'lm-studio'),
            openaiModel: (string) config('services.openai.model', 'qwen3.5-4b-mlx@4bit'),
            embeddingModel: (string) config('services.openai.embedding_model', 'text-embedding-mxbai-embed-large-v1'),
            openaiTimeout: (int) config('services.openai.timeout', 60),
            qdrantUrl: (string) config('services.qdrant.url', 'http://localhost:6333'),
            qdrantCollection: (string) config('services.qdrant.collection', 'catalog'),
            qdrantTimeout: (int) config('services.qdrant.timeout', 60),
            chatTopK: (int) config('catalog.agent.chat_top_k', 10),
            searchMode: CatalogSearchMode::fromConfig(
                config('catalog.search.mode'),
                (bool) config('catalog.search.hybrid_enabled', false),
            ),
            hybridPrefetchLimit: max(1, (int) config('catalog.search.hybrid_prefetch_limit', 20)),
        );
    }
}
