<?php

declare(strict_types=1);

namespace Qdrant\Models;

use Qdrant\Support\Arrayable;
use Qdrant\Support\Normalizer;

final class UpdateResult implements Arrayable
{
    public function __construct(
        public readonly ?int $operationId,
        public readonly UpdateStatus $status
    ) {
    }

    public static function fromArray(array $data): static
    {
        return new self(
            operationId: isset($data['operation_id']) ? (int) $data['operation_id'] : null,
            status: UpdateStatus::from((string) ($data['status'] ?? UpdateStatus::ACKNOWLEDGED->value))
        );
    }

    public function toArray(): array
    {
        return Normalizer::normalize([
            'operation_id' => $this->operationId,
            'status' => $this->status,
        ]);
    }
}
