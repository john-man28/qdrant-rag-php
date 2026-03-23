<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\CatalogAgent\AgentRuntimeException;
use App\CatalogAgent\CatalogChatAgent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
        ];
    }
}
