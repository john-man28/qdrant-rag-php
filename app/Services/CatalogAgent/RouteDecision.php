<?php

declare(strict_types=1);

namespace App\Services\CatalogAgent;

final readonly class RouteDecision
{
    /**
     * @param  list<string>  $seedSkus
     * @param  list<int>  $seedResultIndexes
     */
    public function __construct(
        public string $mode,
        public array $seedSkus,
        public array $seedResultIndexes,
        public string $reason,
    ) {}
}
