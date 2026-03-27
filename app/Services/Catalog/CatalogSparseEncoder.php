<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use Qdrant\Models\SparseVector;

interface CatalogSparseEncoder
{
    public function configured(): bool;

    /**
     * @param  list<string>  $texts
     * @return list<SparseVector>
     */
    public function embedBatch(array $texts): array;
}
