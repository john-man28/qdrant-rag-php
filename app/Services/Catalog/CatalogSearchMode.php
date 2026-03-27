<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use RuntimeException;

enum CatalogSearchMode: string
{
    case Dense = 'dense';
    case Hybrid = 'hybrid';
    case HybridRerank = 'hybrid_rerank';

    public static function fromConfig(mixed $mode, bool $hybridEnabledAlias = false): self
    {
        if ($mode instanceof self) {
            return $mode;
        }

        if (is_string($mode)) {
            $normalized = mb_strtolower(trim($mode));

            if ($normalized !== '') {
                return self::tryFrom($normalized)
                    ?? throw new RuntimeException(sprintf(
                        'Invalid catalog search mode [%s]. Expected dense, hybrid, or hybrid_rerank.',
                        $mode,
                    ));
            }
        }

        return $hybridEnabledAlias ? self::HybridRerank : self::Dense;
    }

    public function usesNamedVectors(): bool
    {
        return $this !== self::Dense;
    }

    public function usesSparseVectors(): bool
    {
        return $this !== self::Dense;
    }

    public function usesLateInteraction(): bool
    {
        return $this === self::HybridRerank;
    }
}
