<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use GuzzleHttp\Client as GuzzleClient;
use OpenAI\Client as OpenAIClient;
use RuntimeException;
use Throwable;

final class CatalogEmbeddingService
{
    private readonly OpenAIClient $openai;

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiKey,
        private readonly string $embeddingModel,
        private readonly GuzzleClient $httpClient,
    ) {
        $this->openai = \OpenAI::factory()
            ->withApiKey($this->apiKey)
            ->withBaseUri($this->baseUrl)
            ->withHttpClient($this->httpClient)
            ->make();
    }

    public static function fromConfig(?GuzzleClient $guzzle = null): self
    {
        $t = (int) config('services.openai.timeout', 60);
        $client = $guzzle ?? new GuzzleClient([
            'timeout' => $t,
            'connect_timeout' => $t,
        ]);

        return new self(
            baseUrl: (string) config('services.openai.base_url', 'http://localhost:1234/v1'),
            apiKey: (string) config('services.openai.api_key', 'lm-studio'),
            embeddingModel: (string) config('services.openai.embedding_model', 'text-embedding-mxbai-embed-large-v1'),
            httpClient: $client,
        );
    }

    /**
     * @param  list<string>  $texts
     * @return list<list<float>>
     */
    public function embedBatch(array $texts): array
    {
        if ($texts === []) {
            return [];
        }

        try {
            $response = $this->openai->embeddings()->create([
                'model' => $this->embeddingModel,
                'input' => $texts,
            ]);
        } catch (Throwable $e) {
            throw new RuntimeException(
                sprintf(
                    'Unable to create embeddings via %s with model %s: %s',
                    $this->baseUrl,
                    $this->embeddingModel,
                    $e->getMessage()
                ),
                0,
                $e
            );
        }

        $embeddings = array_map(
            static fn ($embedding): array => $embedding->embedding,
            $response->embeddings,
        );

        return $embeddings;
    }
}
