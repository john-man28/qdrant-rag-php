<?php

declare(strict_types=1);

namespace App\Services\Catalog;

interface CatalogDenseEncoder
{
    public function configured(): bool;

    /**
     * @param  list<string>  $texts
     * @return list<list<float>>
     */
    public function embedBatch(array $texts): array;
}
