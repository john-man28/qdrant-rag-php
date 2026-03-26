<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Services\CatalogAgent\CatalogIds;
use InvalidArgumentException;
use Qdrant\Models\PointStruct;
use Qdrant\QdrantClient;

final class CatalogVectorIndexService
{
    public function __construct(
        private readonly CatalogEmbeddingService $embeddings,
        private readonly QdrantCatalogCollectionService $collectionService,
    ) {}

    /**
     * Read one chunk JSONL file, embed in sub-batches, upsert to Qdrant.
     */
    public function indexChunkFile(string $absolutePath): void
    {
        if (! is_readable($absolutePath)) {
            throw new InvalidArgumentException("Chunk file not readable: {$absolutePath}");
        }

        $collection = $this->collectionService->collectionName();
        $client = $this->collectionService->makeClient();

        $embedBatch = max(1, (int) config('catalog.reload.embed_batch_size', 64));
        $uploadBatch = max(1, (int) config('catalog.reload.qdrant_upload_batch_size', 64));

        $handle = fopen($absolutePath, 'r');
        if ($handle === false) {
            throw new InvalidArgumentException("Cannot open chunk: {$absolutePath}");
        }

        $recordBuffer = [];

        try {
            while (($line = fgets($handle)) !== false) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                $decoded = json_decode($line, true);
                if (! is_array($decoded)) {
                    continue;
                }
                $recordBuffer[] = $decoded;

                if (count($recordBuffer) >= $embedBatch) {
                    $this->embedAndUploadRecords($client, $collection, $recordBuffer, $uploadBatch);
                    $recordBuffer = [];
                }
            }

            if ($recordBuffer !== []) {
                $this->embedAndUploadRecords($client, $collection, $recordBuffer, $uploadBatch);
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $records
     */
    private function embedAndUploadRecords(
        QdrantClient $client,
        string $collection,
        array $records,
        int $uploadBatch
    ): void {
        $texts = [];
        foreach ($records as $record) {
            $texts[] = is_string($record['text'] ?? null) ? $record['text'] : '';
        }

        $vectors = $this->embeddings->embedBatch($texts);

        $points = [];
        foreach ($records as $i => $record) {
            $payload = $record['payload'] ?? null;
            if (! is_array($payload)) {
                continue;
            }
            $sku = $payload['sku'] ?? null;
            if (! is_string($sku) || $sku === '') {
                continue;
            }
            $vec = $vectors[$i] ?? null;
            if (! is_array($vec)) {
                continue;
            }
            $fullPayload = $payload;
            $fullPayload['text'] = is_string($payload['text'] ?? null)
                ? $payload['text']
                : (is_string($record['text'] ?? null) ? $record['text'] : '');
            $chunkKey = is_string($payload['chunk_key'] ?? null) && trim((string) $payload['chunk_key']) !== ''
                ? (string) $payload['chunk_key']
                : CatalogIds::PRIMARY_CHUNK_KEY;

            $points[] = new PointStruct(
                id: CatalogIds::catalogPointIdForChunk($sku, $chunkKey),
                vector: $vec,
                payload: $fullPayload,
            );
        }

        foreach (array_chunk($points, $uploadBatch) as $chunk) {
            if ($chunk === []) {
                continue;
            }
            $client->uploadPoints(
                collectionName: $collection,
                points: $chunk,
                batchSize: $uploadBatch,
                parallel: 1,
                wait: false,
            );
        }
    }
}
