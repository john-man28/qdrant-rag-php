<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\BeginCatalogReloadJob;
use App\Services\Catalog\CatalogReloadCoordinator;
use App\Services\CatalogAgent\CatalogChatAgent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class CatalogReloadTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        Cache::flush();
        parent::tearDown();
    }

    public function test_reload_start_dispatches_begin_job_and_returns_run_id(): void
    {
        Queue::fake();

        $response = $this->postJson(route('catalog-agent.reload.start'));

        $response->assertStatus(202)->assertJsonStructure(['ok', 'run_id']);
        Queue::assertPushed(BeginCatalogReloadJob::class, function (BeginCatalogReloadJob $job): bool {
            return $job->runId !== '';
        });
    }

    public function test_reload_start_returns_conflict_when_a_run_is_active(): void
    {
        Queue::fake();

        $first = $this->postJson(route('catalog-agent.reload.start'));
        $first->assertStatus(202);
        $runId = $first->json('run_id');

        $second = $this->postJson(route('catalog-agent.reload.start'));
        $second->assertStatus(409)->assertJson([
            'ok' => false,
            'run_id' => $runId,
        ]);
        Queue::assertPushed(BeginCatalogReloadJob::class, 1);
    }

    public function test_reload_start_reclaims_a_stale_active_run_when_the_queue_is_idle(): void
    {
        Queue::fake();

        $staleRunId = Str::uuid()->toString();
        $coordinator = app(CatalogReloadCoordinator::class);
        $coordinator->setActiveRun($staleRunId);
        Cache::put($coordinator->statusKey($staleRunId), [
            'phase' => 'indexing',
            'batch_id' => null,
            'last_activity_at' => now()->subMinutes(30)->toIso8601String(),
        ], 3600);

        $response = $this->postJson(route('catalog-agent.reload.start'));

        $response->assertStatus(202)->assertJsonPath('ok', true);
        $this->assertNotSame($staleRunId, $response->json('run_id'));
        Queue::assertPushed(BeginCatalogReloadJob::class, 1);
    }

    public function test_reload_status_requires_run_id(): void
    {
        $this->getJson(route('catalog-agent.reload.status'))->assertStatus(422);
    }

    public function test_reload_status_requires_a_valid_uuid_run_id(): void
    {
        $this->getJson(route('catalog-agent.reload.status', ['run_id' => 'invalid-run-id']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['run_id']);
    }

    public function test_reload_status_returns_enriched_labels(): void
    {
        $runId = Str::uuid()->toString();
        $coordinator = app(CatalogReloadCoordinator::class);
        $agent = Mockery::mock(CatalogChatAgent::class);
        $agent->shouldReceive('runtimeError')->once()->andReturn('Collection schema mismatch.');
        $this->app->instance(CatalogChatAgent::class, $agent);
        Cache::put($coordinator->statusKey($runId), [
            'phase' => 'export',
            'export_step' => 'products',
            'export_page' => 1,
            'export_total_pages' => 4,
            'export_progress' => 25,
        ], 3600);

        $response = $this->getJson(route('catalog-agent.reload.status', ['run_id' => $runId]));

        $response->assertOk()
            ->assertJsonPath('run.phase', 'export')
            ->assertJsonPath('run.phase_label', 'Fetching products from BigCommerce')
            ->assertJsonPath('run.export_detail', 'Product pages 1 / 4 (25%)')
            ->assertJsonPath('runtimeError', 'Collection schema mismatch.');
    }
}
