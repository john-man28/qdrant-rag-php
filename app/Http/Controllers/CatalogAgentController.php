<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\CatalogAgent\CatalogAgentMessageRequest;
use App\Http\Requests\CatalogAgent\CatalogReloadStatusRequest;
use App\Services\Catalog\CatalogReloadCoordinator;
use App\Services\Catalog\CatalogReloadStatusPresenter;
use App\Services\CatalogAgent\AgentRuntimeException;
use App\Services\CatalogAgent\CatalogChatAgent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Inertia\Inertia;
use Inertia\Response;

class CatalogAgentController extends Controller
{
    public function home(Request $request, CatalogChatAgent $agent): Response
    {
        return Inertia::render('CatalogAgentApp', $this->pageProps($request, $agent));
    }

    public function message(CatalogAgentMessageRequest $request, CatalogChatAgent $agent): JsonResponse
    {
        try {
            $reply = $agent->chat($request->session(), $request->message());

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
        $result = $coordinator->startCatalogReload();

        if (! $result['started']) {
            return response()->json([
                'ok' => false,
                'error' => 'A catalog reload is already in progress.',
                'run_id' => $result['run_id'],
            ], 409);
        }

        return response()->json([
            'ok' => true,
            'run_id' => $result['run_id'],
        ], 202);
    }

    public function catalogReloadStatus(
        CatalogReloadStatusRequest $request,
        CatalogReloadCoordinator $coordinator,
        CatalogChatAgent $agent,
    ): JsonResponse {
        $status = CatalogReloadStatusPresenter::enrich($coordinator->getStatus($request->runId()));

        return response()->json([
            'ok' => true,
            'run' => $status,
            'batch' => $this->batchPayload($status),
            'runtimeError' => $agent->runtimeError(),
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $status
     * @return array<string, mixed>|null
     */
    private function batchPayload(?array $status): ?array
    {
        if (! is_array($status) || ! isset($status['batch_id']) || ! is_string($status['batch_id'])) {
            return null;
        }

        $batch = Bus::findBatch($status['batch_id']);
        if ($batch === null) {
            return null;
        }

        return [
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
