<?php

declare(strict_types=1);

namespace Qdrant\Exceptions;

use Qdrant\Transport\Rest\HttpResponse;

final class RateLimitException extends ApiException
{
    public function __construct(
        string $message,
        int $statusCode,
        ?array $responseBody = null,
        array $headers = [],
        public readonly ?int $retryAfterSeconds = null
    ) {
        parent::__construct($message, $statusCode, $responseBody, $headers);
    }

    public static function fromResponse(HttpResponse $response, ?string $message = null): self
    {
        $retryAfter = $response->header('Retry-After');
        $retryAfterSeconds = is_numeric($retryAfter) ? (int) $retryAfter : null;

        return new self(
            $message ?? 'Qdrant rate limit exceeded.',
            $response->statusCode,
            $response->json(),
            $response->headers,
            $retryAfterSeconds
        );
    }
}
