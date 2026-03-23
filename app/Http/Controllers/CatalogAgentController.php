<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Catalog\CatalogReloadCoordinator;
use App\Catalog\CatalogReloadStatusPresenter;
use App\CatalogAgent\AgentRuntimeException;
use App\CatalogAgent\CatalogChatAgent;
use App\Jobs\BeginCatalogReloadJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class CatalogAgentController extends Controller
{
    public function home(Request $request, CatalogChatAgent $agent): Response
    {
        return Inertia::render('CatalogAgentApp', $this->pageProps($request, $agent));
    }

    public function message(Request $request, CatalogChatAgent $agent): JsonResponse
    {
        $validated = $request->validate([
            'message' => ['required', 'string', 'max:4000'],
        ]);

        try {
            $reply = $agent->chat($request->session(), $validated['message']);

            return response()->json([
                'ok' => true,
                'reply' => $reply,
                ...$this->chatPayload($request, $agent),
            ]);
        } catch (AgentRuntimeException $exception) {
            return response()->json([
                'ok' => false,
                'error' => $exception->getMessage(),
                ...$this->chatPayload($request, $agent, $exception->getMessage()),
            ], 422);
        }
    }

    public function reset(Request $request, CatalogChatAgent $agent): JsonResponse
    {
        $agent->reset($request->session());

        return response()->json([
            'ok' => true,
            ...$this->chatPayload($request, $agent),
        ]);
    }

    public function startCatalogReload(CatalogReloadCoordinator $coordinator): JsonResponse
    {
        $lock = Cache::lock('catalog-reload-mutex', 120);
        $runId = '';

        try {
            $lock->block(5);

            if ($coordinator->activeRunId() !== null) {
                return response()->json([
                    'ok' => false,
                    'error' => 'A catalog reload is already in progress.',
                    'run_id' => $coordinator->activeRunId(),
                ], 409);
            }

            $runId = Str::uuid()->toString();
            $coordinator->setActiveRun($runId);
            BeginCatalogReloadJob::dispatch($runId);
        } finally {
            $lock->release();
        }

        return response()->json([
            'ok' => true,
            'run_id' => $runId,
        ], 202);
    }

    public function catalogReloadStatus(Request $request, CatalogReloadCoordinator $coordinator): JsonResponse
    {
        $validated = $request->validate([
            'run_id' => ['required', 'uuid'],
        ]);

        $runId = $validated['run_id'];
        $status = CatalogReloadStatusPresenter::enrich($coordinator->getStatus($runId));

        $batchPayload = null;
        if (is_array($status) && isset($status['batch_id']) && is_string($status['batch_id'])) {
            $batch = Bus::findBatch($status['batch_id']);
            if ($batch !== null) {
                $batchPayload = [
                    'id' => $batch->id,
                    'name' => $batch->name,
                    'total_jobs' => $batch->totalJobs,
                    'pending_jobs' => $batch->pendingJobs,
                    'failed_jobs' => $batch->failedJobs,
                    'progress' => $batch->progress(),
                    'finished' => $batch->finished(),
                    'cancelled' => $batch->cancelled(),
                    'failed' => $batch->failedJobs > 0,
                ];
            }
        }

        return response()->json([
            'ok' => true,
            'run' => $status,
            'batch' => $batchPayload,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function pageProps(Request $request, CatalogChatAgent $agent): array
    {
        return [
            ...$this->chatPayload($request, $agent),
            'examples' => $agent->examples(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function chatPayload(
        Request $request,
        CatalogChatAgent $agent,
        ?string $forcedRuntimeError = null,
    ): array {
        return [
            'conversation' => $agent->conversation($request->session()),
            'lastResults' => $agent->lastResults($request->session()),
            'runtimeError' => $forcedRuntimeError ?? $agent->runtimeError(),
            'chatEndpoint' => route('catalog-agent.message'),
            'resetEndpoint' => route('catalog-agent.reset'),
            'reloadStartEndpoint' => route('catalog-agent.reload.start'),
            'reloadStatusEndpoint' => route('catalog-agent.reload.status'),
        ];
    }
}
