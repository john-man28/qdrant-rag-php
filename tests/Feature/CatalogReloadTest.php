<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\BeginCatalogReloadJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CatalogReloadTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
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

    public function test_reload_status_requires_run_id(): void
    {
        $this->getJson(route('catalog-agent.reload.status'))->assertStatus(422);
    }
}
