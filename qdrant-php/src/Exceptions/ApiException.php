<?php

declare(strict_types=1);

namespace Qdrant\Exceptions;

use Qdrant\Transport\Rest\HttpResponse;

class ApiException extends QdrantException
{
    public function __construct(
        string $message,
        public readonly int $statusCode,
        public readonly ?array $responseBody = null,
        public readonly array $headers = []
    ) {
        parent::__construct($message);
    }

    public static function fromResponse(HttpResponse $response, ?string $message = null): self
    {
        return new self(
            $message ?? sprintf('Unexpected Qdrant API response (%d).', $response->statusCode),
            $response->statusCode,
            $response->json(),
            $response->headers
        );
    }
}
