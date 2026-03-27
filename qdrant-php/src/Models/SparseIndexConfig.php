<?php

declare(strict_types=1);

namespace Qdrant\Models;

use Qdrant\Support\Arrayable;
use Qdrant\Support\Normalizer;

final class SparseIndexConfig implements Arrayable
{
    public function __construct(
        public readonly ?bool $onDisk = null,
        public readonly ?int $fullScanThreshold = null
    ) {}

    public static function fromArray(array $data): static
    {
        return new self(
            onDisk: array_key_exists('on_disk', $data) ? (bool) $data['on_disk'] : null,
            fullScanThreshold: isset($data['full_scan_threshold']) ? (int) $data['full_scan_threshold'] : null,
        );
    }

    public function toArray(): array
    {
        return Normalizer::normalize([
            'on_disk' => $this->onDisk,
            'full_scan_threshold' => $this->fullScanThreshold,
        ]);
    }
}
