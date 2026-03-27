<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use Qdrant\Models\PointStruct;

interface CatalogPointUploader
{
    /**
     * @param  iterable<PointStruct|array{id:int|string,vector:array,payload?:array|null}>  $points
     */
    public function uploadPoints(iterable $points, int $batchSize = 64, bool $wait = false): void;
}
