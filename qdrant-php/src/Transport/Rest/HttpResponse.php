<?php

declare(strict_types=1);

namespace Qdrant\Transport\Rest;

final class HttpResponse
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public readonly int $statusCode,
        array $headers,
        public readonly string $body
    ) {
        $normalized = [];
        foreach ($headers as $name => $value) {
            $normalized[strtolower($name)] = $value;
        }

        $this->headers = $normalized;
    }

    /**
     * @var array<string, string>
     */
    public readonly array $headers;

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function json(): ?array
    {
        $trimmed = trim($this->body);
        if ($trimmed === '') {
            return null;
        }

        $decoded = json_decode($trimmed, true);
        return is_array($decoded) ? $decoded : null;
    }
}
