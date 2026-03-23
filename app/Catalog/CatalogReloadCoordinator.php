<?php

declare(strict_types=1);

namespace App\Catalog;

use Illuminate\Support\Facades\Cache;

final class CatalogReloadCoordinator
{
    private const CACHE_TTL_SECONDS = 86400;

    public function runDirectory(string $runId): string
    {
        return storage_path('app/catalog/runs/'.$runId);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    public function putStatus(string $runId, array $extra): void
    {
        $key = $this->statusKey($runId);
        $existing = Cache::get($key, []);
        if (! is_array($existing)) {
            $existing = [];
        }
        Cache::put($key, array_merge($existing, $extra), self::CACHE_TTL_SECONDS);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getStatus(string $runId): ?array
    {
        $v = Cache::get($this->statusKey($runId));
        if (! is_array($v)) {
            return null;
        }

        return $v;
    }

    public function statusKey(string $runId): string
    {
        return 'catalog_reload:status:'.$runId;
    }

    public function rememberLatestRunId(string $runId): void
    {
        Cache::put('catalog_reload:latest_run_id', $runId, self::CACHE_TTL_SECONDS);
    }

    public function latestRunId(): ?string
    {
        $v = Cache::get('catalog_reload:latest_run_id');

        return is_string($v) && $v !== '' ? $v : null;
    }

    public function forgetStatus(string $runId): void
    {
        Cache::forget($this->statusKey($runId));
    }

    public function activeRunCacheKey(): string
    {
        return 'catalog_reload:active_run_id';
    }

    public function setActiveRun(string $runId): void
    {
        Cache::put($this->activeRunCacheKey(), $runId, self::CACHE_TTL_SECONDS);
    }

    public function clearActiveRun(): void
    {
        Cache::forget($this->activeRunCacheKey());
    }

    public function activeRunId(): ?string
    {
        $v = Cache::get($this->activeRunCacheKey());

        return is_string($v) && $v !== '' ? $v : null;
    }
}
