<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Jobs\BeginCatalogReloadJob;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Throwable;

final class CatalogReloadCoordinator
{
    public function runDirectory(string $runId): string
    {
        return storage_path('app/catalog/runs/'.$runId);
    }

    /**
     * @return array{started: bool, run_id: string}
     */
    public function startCatalogReload(): array
    {
        return Cache::lock(
            'catalog-reload-mutex',
            $this->lockTtlSeconds(),
        )->block($this->lockWaitSeconds(), function (): array {
            $activeRunId = $this->activeRunId();
            if ($activeRunId !== null && $this->activeRunIsStale($activeRunId)) {
                $this->clearActiveRun();
                $activeRunId = null;
            }

            if ($activeRunId !== null) {
                return [
                    'started' => false,
                    'run_id' => $activeRunId,
                ];
            }

            $runId = (string) Str::uuid();
            $this->setActiveRun($runId);
            $this->rememberLatestRunId($runId);
            $this->putStatus($runId, [
                'phase' => 'queued',
                'batch_id' => null,
                'error' => null,
                'queued_at' => now()->toIso8601String(),
                'started_at' => null,
                'finished_at' => null,
            ]);
            BeginCatalogReloadJob::dispatch($runId);

            return [
                'started' => true,
                'run_id' => $runId,
            ];
        });
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
        Cache::put($key, array_merge($existing, $extra, [
            'last_activity_at' => now()->toIso8601String(),
        ]), $this->statusTtlSeconds());
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
        Cache::put('catalog_reload:latest_run_id', $runId, $this->statusTtlSeconds());
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
        Cache::put($this->activeRunCacheKey(), $runId, $this->statusTtlSeconds());
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

    public function markFailed(string $runId, Throwable|string $error): void
    {
        $this->putStatus($runId, [
            'phase' => 'failed',
            'error' => $this->errorMessage($error),
            'finished_at' => now()->toIso8601String(),
        ]);
        $this->clearActiveRun();
    }

    public function markCompleted(string $runId): void
    {
        $this->putStatus($runId, [
            'phase' => 'completed',
            'finished_at' => now()->toIso8601String(),
            'error' => null,
        ]);
        $this->clearActiveRun();
    }

    public function markBatchFinished(string $runId): void
    {
        $this->putStatus($runId, [
            'batch_finished_at' => now()->toIso8601String(),
        ]);
        $this->clearActiveRun();
    }

    private function errorMessage(Throwable|string $error): string
    {
        if ($error instanceof Throwable) {
            return $error->getMessage();
        }

        return $error;
    }

    private function statusTtlSeconds(): int
    {
        return max(1, (int) config('catalog.reload.status_ttl_seconds', 86400));
    }

    private function activeRunIsStale(string $runId): bool
    {
        $status = $this->getStatus($runId);
        if ($status === null) {
            return $this->queueIsIdle();
        }

        $phase = is_string($status['phase'] ?? null) ? $status['phase'] : null;
        if (in_array($phase, ['completed', 'failed'], true)) {
            return true;
        }

        $batchId = is_string($status['batch_id'] ?? null) ? $status['batch_id'] : null;
        if ($batchId !== null) {
            $batch = Bus::findBatch($batchId);

            if ($batch === null || $batch->finished() || $batch->cancelled()) {
                return true;
            }

            if ($phase === 'indexing' && $batch->pendingJobs > 0 && $this->queueIsIdle()) {
                return true;
            }
        }

        $lastActivityAt = $this->lastActivityAt($status);

        if ($lastActivityAt === null) {
            return $this->queueIsIdle();
        }

        return $this->queueIsIdle() && $lastActivityAt->diffInSeconds(now()) >= $this->staleAfterSeconds();
    }

    /**
     * @param  array<string, mixed>  $status
     */
    private function lastActivityAt(array $status): ?CarbonInterface
    {
        foreach ([
            'last_activity_at',
            'batch_finished_at',
            'finished_at',
            'started_at',
            'queued_at',
        ] as $field) {
            $value = $status[$field] ?? null;

            if (is_string($value) && trim($value) !== '') {
                return Carbon::parse($value);
            }
        }

        return null;
    }

    private function queueIsIdle(): bool
    {
        return Queue::size() === 0;
    }

    private function staleAfterSeconds(): int
    {
        return max(60, (int) config('catalog.reload.stale_after_seconds', 900));
    }

    private function lockTtlSeconds(): int
    {
        return max(1, (int) config('catalog.reload.lock_ttl_seconds', 120));
    }

    private function lockWaitSeconds(): int
    {
        return max(1, (int) config('catalog.reload.lock_wait_seconds', 5));
    }
}
