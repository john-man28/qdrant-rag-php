<?php

declare(strict_types=1);

namespace Qdrant\Models;

use Qdrant\Support\Arrayable;
use Qdrant\Support\Normalizer;

final class HnswConfigDiff implements Arrayable
{
    public function __construct(
        public readonly ?int $m = null,
        public readonly ?int $efConstruct = null,
        public readonly ?int $fullScanThreshold = null,
        public readonly ?int $maxIndexingThreads = null,
        public readonly ?bool $onDisk = null,
        public readonly ?int $payloadM = null
    ) {}

    public static function fromArray(array $data): static
    {
        return new self(
            m: isset($data['m']) ? (int) $data['m'] : null,
            efConstruct: isset($data['ef_construct']) ? (int) $data['ef_construct'] : null,
            fullScanThreshold: isset($data['full_scan_threshold']) ? (int) $data['full_scan_threshold'] : null,
            maxIndexingThreads: isset($data['max_indexing_threads']) ? (int) $data['max_indexing_threads'] : null,
            onDisk: array_key_exists('on_disk', $data) ? (bool) $data['on_disk'] : null,
            payloadM: isset($data['payload_m']) ? (int) $data['payload_m'] : null,
        );
    }

    public function toArray(): array
    {
        return Normalizer::normalize([
            'm' => $this->m,
            'ef_construct' => $this->efConstruct,
            'full_scan_threshold' => $this->fullScanThreshold,
            'max_indexing_threads' => $this->maxIndexingThreads,
            'on_disk' => $this->onDisk,
            'payload_m' => $this->payloadM,
        ]);
    }
}
