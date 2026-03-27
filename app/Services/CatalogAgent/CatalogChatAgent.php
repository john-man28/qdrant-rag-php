<?php

declare(strict_types=1);

namespace App\Services\CatalogAgent;

use App\Services\Catalog\CatalogEmbeddingService;
use App\Services\Catalog\CatalogLateInteractionEmbeddingService;
use App\Services\Catalog\CatalogSearchMode;
use App\Services\Catalog\CatalogSparseEmbeddingService;
use App\Services\Catalog\CatalogVectorEncodingOrchestrator;
use App\Services\Catalog\QdrantCatalogCollectionService;
use GuzzleHttp\Client as GuzzleClient;
use Illuminate\Contracts\Session\Session;
use OpenAI\Client as OpenAIClient;
use OpenAI\Responses\Chat\CreateResponse;
use OpenAI\Responses\Chat\CreateResponseMessage;
use OpenAI\Responses\Chat\CreateResponseToolCall;
use Qdrant\Models\FieldCondition;
use Qdrant\Models\Filter;
use Qdrant\Models\Fusion;
use Qdrant\Models\FusionQuery;
use Qdrant\Models\NearestQuery;
use Qdrant\Models\PointStruct;
use Qdrant\Models\Prefetch;
use Qdrant\Models\QueryResponse;
use Qdrant\Models\RecommendInput;
use Qdrant\Models\RecommendQuery;
use Qdrant\Models\Record;
use Qdrant\Models\ScoredPoint;
use Qdrant\Models\SparseVector;
use Qdrant\QdrantClient;
use Throwable;

class CatalogChatAgent
{
    private const SESSION_KEY = 'catalog_agent.chat';

    private const SEARCH_PREFIX = 'Represent this sentence for searching relevant passages: ';

    private const MAX_TOOL_CALL_ROUNDS = 4;

    private const MAX_PRODUCT_CHUNKS = 3;

    private const MAX_SNIPPET_LENGTH = 320;

    private const RECOMMEND_KEYWORDS = [
        'similar',
        'alternative',
        'alternatives',
        'comparable',
        'compare',
        'replacement',
        'replace',
        'recommend like',
        'like this',
        'like that',
        'equivalent',
    ];

    private const DEMONSTRATIVE_ANCHOR_HINTS = [
        'this',
        'that',
        'these',
        'those',
        'previous',
        'above',
        'earlier',
    ];

    private const SYSTEM_PROMPT = <<<'PROMPT'
You are a product catalog assistant for a browser chat app.

Use tools whenever you need catalog facts. Follow these rules:
- Use nearestQuery for unanchored semantic product search.
- Use recommendQuery only for anchored "similar to / alternative to / replacement for" requests.
- Never invent SKU seeds or result indexes.
- If the user wants recommendations but does not provide a valid SKU or result number, ask for that anchor instead of guessing.
- When you return products, keep them concise and reference the provided result indexes like #1, #2.
- Mention when no matches were found and suggest how the user can refine the request.
- Do not reveal chain-of-thought or output <think> tags.
PROMPT;

    private readonly CatalogAgentConfig $config;

    private readonly OpenAIClient $openai;

    private readonly CatalogVectorEncodingOrchestrator $vectorEncodings;

    private readonly QdrantCatalogCollectionService $collectionService;

    private readonly QdrantClient $qdrant;

    public function __construct(
        ?CatalogAgentConfig $config = null,
        ?CatalogVectorEncodingOrchestrator $vectorEncodings = null,
        ?QdrantCatalogCollectionService $collectionService = null,
        ?QdrantClient $qdrant = null,
    ) {
        $this->config = $config ?? CatalogAgentConfig::fromConfig();
        $this->vectorEncodings = $vectorEncodings ?? new CatalogVectorEncodingOrchestrator(
            CatalogEmbeddingService::fromConfig(),
            CatalogSparseEmbeddingService::fromConfig(),
            CatalogLateInteractionEmbeddingService::fromConfig(),
            $this->config->searchMode,
        );
        $this->collectionService = $collectionService ?? QdrantCatalogCollectionService::fromConfig();
        $this->openai = \OpenAI::factory()
            ->withApiKey($this->config->openaiApiKey)
            ->withBaseUri($this->config->openaiBaseUrl)
            ->withHttpClient(new GuzzleClient([
                'timeout' => $this->config->openaiTimeout,
                'connect_timeout' => $this->config->openaiTimeout,
            ]))
            ->make();

        $this->qdrant = $qdrant ?? $this->collectionService->makeClient();
    }

    public function runtimeError(): ?string
    {
        try {
            $this->validateRuntime();

            return null;
        } catch (AgentRuntimeException $exception) {
            return $exception->getMessage();
        }
    }

    /**
     * @return list<array{role:string,content:string}>
     */
    public function conversation(Session $session): array
    {
        return $this->state($session)->conversation();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function lastResults(Session $session): array
    {
        return $this->state($session)->lastResults;
    }

    public function reset(Session $session): void
    {
        $session->forget(self::SESSION_KEY);
    }

    /**
     * @return list<string>
     */
    public function examples(): array
    {
        return [
            'I need a high bay occupancy sensor for a warehouse',
            'Show alternatives to OSFHU-ITW',
            'Show similar to #2',
        ];
    }

    public function chat(Session $session, string $userText): string
    {
        $this->validateRuntime();

        $userText = trim($userText);
        if ($userText === '') {
            throw new AgentRuntimeException('Please enter a message before sending.');
        }

        $state = $this->state($session);
        $route = $this->routeUserMessage($userText, $state);
        $state->messages[] = ['role' => 'user', 'content' => $userText];
        $this->persistState($session, $state);

        $tools = $this->toolDefinitions($route->mode);
        $latestToolResult = null;

        try {
            for ($roundIndex = 0; $roundIndex < self::MAX_TOOL_CALL_ROUNDS; $roundIndex++) {
                $response = $this->createCompletion(
                    messages: $state->messages,
                    tools: $tools,
                    routeMode: $route->mode,
                    routeHint: $this->buildRouteHint($route),
                    forceTool: $roundIndex === 0,
                );

                $message = $response->choices[0]->message;

                if ($message->toolCalls === []) {
                    if ($roundIndex === 0 && in_array($route->mode, ['nearest_only', 'recommend_only'], true)) {
                        $finalText = $this->runDeterministicToolFallback(
                            state: $state,
                            route: $route,
                            userText: $userText,
                        );
                        $this->persistState($session, $state);

                        return $finalText;
                    }

                    $finalText = $this->cleanResponseText($message->content ?? '');
                    if ($finalText === '') {
                        $finalText = "I couldn't produce a response for that request.";
                    }

                    $state->messages[] = ['role' => 'assistant', 'content' => $finalText];
                    $this->persistState($session, $state);

                    return $finalText;
                }

                $state->messages[] = $this->assistantToolMessage($message);

                foreach ($message->toolCalls as $toolCall) {
                    $toolResult = $this->executeToolCall($toolCall, $state);
                    $latestToolResult = $toolResult;
                    $state->messages[] = [
                        'role' => 'tool',
                        'tool_call_id' => $toolCall->id,
                        'content' => json_encode($toolResult, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    ];
                }
            }
        } catch (Throwable $exception) {
            $this->persistState($session, $state);

            if ($exception instanceof AgentRuntimeException) {
                throw $exception;
            }

            throw new AgentRuntimeException($exception->getMessage(), previous: $exception);
        }

        $finalText = $this->finalizeToolLoop($state, $route, $latestToolResult);
        $this->persistState($session, $state);

        if ($finalText !== null) {
            return $finalText;
        }

        throw new AgentRuntimeException(
            'The model kept requesting tools without producing a final answer.'
        );
    }

    public function validateRuntime(): void
    {
        try {
            $exists = $this->qdrant->collectionExists($this->config->qdrantCollection);
        } catch (Throwable $exception) {
            throw new AgentRuntimeException(
                sprintf(
                    'Unable to reach Qdrant at %s: %s',
                    $this->config->qdrantUrl,
                    $exception->getMessage(),
                ),
                previous: $exception,
            );
        }

        if (! $exists) {
            throw new AgentRuntimeException(
                sprintf(
                    "Qdrant collection '%s' was not found at %s.",
                    $this->config->qdrantCollection,
                    $this->config->qdrantUrl,
                ),
            );
        }

        if (! $this->config->searchMode->usesNamedVectors()) {
            return;
        }

        try {
            $this->vectorEncodings->assertSearchEncodersConfigured();
            $this->collectionService->assertSearchCollectionSchema();
        } catch (Throwable $exception) {
            throw new AgentRuntimeException($exception->getMessage(), previous: $exception);
        }
    }

    private function state(Session $session): ChatSessionState
    {
        return ChatSessionState::fromArray($session->get(self::SESSION_KEY, []));
    }

    private function persistState(Session $session, ChatSessionState $state): void
    {
        $session->put(self::SESSION_KEY, $state->toArray());
    }

    private function routeUserMessage(string $text, ChatSessionState $state): RouteDecision
    {
        $normalized = mb_strtolower($text);
        $seedSkus = $this->parseSkuTokens($text);
        $seedResultIndexes = $this->parseResultIndexes($text);
        $hasRecommendIntent = false;

        foreach (self::RECOMMEND_KEYWORDS as $keyword) {
            if (str_contains($normalized, $keyword)) {
                $hasRecommendIntent = true;
                break;
            }
        }

        $hasDemonstrativeAnchor = $state->lastResults !== [];
        if ($hasDemonstrativeAnchor) {
            $hasDemonstrativeAnchor = false;

            foreach (self::DEMONSTRATIVE_ANCHOR_HINTS as $hint) {
                if (str_contains($normalized, $hint)) {
                    $hasDemonstrativeAnchor = true;
                    break;
                }
            }
        }

        if ($hasRecommendIntent && ($seedSkus !== [] || $seedResultIndexes !== [])) {
            return new RouteDecision(
                mode: 'recommend_only',
                seedSkus: $seedSkus,
                seedResultIndexes: $seedResultIndexes,
                reason: 'recommendation intent with an explicit anchor',
            );
        }

        if ($hasRecommendIntent && $hasDemonstrativeAnchor) {
            return new RouteDecision(
                mode: 'auto',
                seedSkus: $seedSkus,
                seedResultIndexes: $seedResultIndexes,
                reason: 'recommendation intent with ambiguous prior-context anchor',
            );
        }

        if ($hasRecommendIntent) {
            return new RouteDecision(
                mode: 'auto',
                seedSkus: $seedSkus,
                seedResultIndexes: $seedResultIndexes,
                reason: 'recommendation intent without a usable anchor',
            );
        }

        return new RouteDecision(
            mode: 'nearest_only',
            seedSkus: $seedSkus,
            seedResultIndexes: $seedResultIndexes,
            reason: 'defaulting to semantic catalog search',
        );
    }

    /**
     * @return list<int>
     */
    private function parseResultIndexes(string $text): array
    {
        preg_match_all('/(?<!\w)#(\d+)\b/u', $text, $matches);

        $seen = [];
        $resultIndexes = [];

        foreach ($matches[1] ?? [] as $match) {
            $index = (int) $match;
            if (in_array($index, $seen, true)) {
                continue;
            }

            $seen[] = $index;
            $resultIndexes[] = $index;
        }

        return $resultIndexes;
    }

    /**
     * @return list<string>
     */
    private function parseSkuTokens(string $text): array
    {
        $seen = [];
        $skus = [];

        preg_match_all('/(?i)\bsku\b[\s:=#-]*([A-Za-z0-9][A-Za-z0-9._\/-]{2,})\b/u', $text, $labelMatches);
        foreach ($labelMatches[1] ?? [] as $match) {
            $sku = strtoupper(trim($match, ".,;:()[]{} \t\n\r\0\x0B"));
            if ($sku === '' || in_array($sku, $seen, true)) {
                continue;
            }

            $seen[] = $sku;
            $skus[] = $sku;
        }

        preg_match_all('/\b[A-Za-z0-9][A-Za-z0-9._\/-]{2,}\b/u', $text, $tokenMatches);
        foreach ($tokenMatches[0] ?? [] as $token) {
            $cleaned = trim($token, ".,;:()[]{} \t\n\r\0\x0B");
            if (! $this->looksLikeSku($cleaned)) {
                continue;
            }

            $sku = strtoupper($cleaned);
            if (in_array($sku, $seen, true)) {
                continue;
            }

            $seen[] = $sku;
            $skus[] = $sku;
        }

        return $skus;
    }

    private function looksLikeSku(string $token): bool
    {
        if (mb_strlen($token) < 4) {
            return false;
        }

        if (! preg_match('/[A-Za-z]/', $token)) {
            return false;
        }

        if (preg_match('/\d/', $token)) {
            return true;
        }

        return strtoupper($token) === $token && preg_match('/[-_\/]/', $token) === 1;
    }

    /**
     * @param  list<array<string, mixed>>  $messages
     * @param  list<array<string, mixed>>  $tools
     */
    protected function createCompletion(
        array $messages,
        array $tools,
        string $routeMode,
        string $routeHint,
        bool $forceTool,
    ): CreateResponse {
        $toolChoice = $this->selectToolChoice($routeMode, $forceTool);

        try {
            return $this->openai->chat()->create([
                'model' => $this->config->openaiModel,
                'messages' => [
                    ['role' => 'system', 'content' => self::SYSTEM_PROMPT."\n\n".$routeHint],
                    ...$messages,
                ],
                'tools' => $tools,
                'tool_choice' => $toolChoice,
                'parallel_tool_calls' => false,
                'temperature' => 0.1,
            ]);
        } catch (Throwable $exception) {
            throw new AgentRuntimeException(
                sprintf(
                    "Unable to reach the OpenAI-compatible endpoint at %s with model '%s': %s",
                    $this->config->openaiBaseUrl,
                    $this->config->openaiModel,
                    $exception->getMessage(),
                ),
                previous: $exception,
            );
        }
    }

    /**
     * @param  list<array<string, mixed>>  $messages
     * @param  array<string, mixed>  $toolResult
     */
    protected function createGroundedCompletion(array $messages, string $routeHint, array $toolResult): string
    {
        $groundingPrompt = self::SYSTEM_PROMPT."\n\n"
            .$routeHint."\n\n"
            .'You already have the retrieval result. Do not call tools.'."\n"
            .'Answer using the tool result JSON below.'."\n"
            .'If the tool result contains an error, ask the user for the missing anchor or explain the issue briefly.'."\n"
            .'If there are hits, summarize the best options and mention their result indexes.'."\n\n"
            .'tool_result_json='.json_encode($toolResult, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        try {
            $response = $this->openai->chat()->create([
                'model' => $this->config->openaiModel,
                'messages' => [
                    ['role' => 'system', 'content' => $groundingPrompt],
                    ...$messages,
                ],
                'temperature' => 0.1,
            ]);
        } catch (Throwable $exception) {
            throw new AgentRuntimeException(
                sprintf(
                    "Unable to reach the OpenAI-compatible endpoint at %s with model '%s': %s",
                    $this->config->openaiBaseUrl,
                    $this->config->openaiModel,
                    $exception->getMessage(),
                ),
                previous: $exception,
            );
        }

        return $this->cleanResponseText($response->choices[0]->message->content ?? '');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function toolDefinitions(string $routeMode): array
    {
        $toolCatalog = [
            'nearestQuery' => [
                'type' => 'function',
                'function' => [
                    'name' => 'nearestQuery',
                    'description' => 'Run semantic nearest-neighbor search over catalog products.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'query_text' => [
                                'type' => 'string',
                                'description' => 'The product need or search text to embed.',
                            ],
                            'limit' => [
                                'type' => 'integer',
                                'description' => 'How many results to return.',
                                'minimum' => 1,
                                'maximum' => 10,
                            ],
                            'reason' => [
                                'type' => 'string',
                                'description' => 'Why this tool fits the current user request.',
                            ],
                        ],
                        'required' => ['query_text', 'limit', 'reason'],
                    ],
                ],
            ],
            'recommendQuery' => [
                'type' => 'function',
                'function' => [
                    'name' => 'recommendQuery',
                    'description' => 'Recommend similar catalog items from seed SKUs or previous result indexes.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'seed_skus' => [
                                'type' => 'array',
                                'items' => ['type' => 'string'],
                                'description' => 'Explicit SKU seeds.',
                            ],
                            'seed_result_indexes' => [
                                'type' => 'array',
                                'items' => ['type' => 'integer'],
                                'description' => 'One-based result numbers from the previous tool output.',
                            ],
                            'limit' => [
                                'type' => 'integer',
                                'description' => 'How many results to return.',
                                'minimum' => 1,
                                'maximum' => 10,
                            ],
                            'reason' => [
                                'type' => 'string',
                                'description' => 'Why this tool fits the current user request.',
                            ],
                        ],
                        'required' => ['seed_skus', 'seed_result_indexes', 'limit', 'reason'],
                    ],
                ],
            ],
        ];

        if ($routeMode === 'nearest_only') {
            return [$toolCatalog['nearestQuery']];
        }

        if ($routeMode === 'recommend_only') {
            return [$toolCatalog['recommendQuery']];
        }

        return [$toolCatalog['nearestQuery'], $toolCatalog['recommendQuery']];
    }

    /**
     * @return array<string, mixed>
     */
    protected function executeToolCall(CreateResponseToolCall $toolCall, ChatSessionState $state): array
    {
        $arguments = json_decode($toolCall->function->arguments, true);
        if (! is_array($arguments)) {
            return ['error' => 'Tool arguments were not valid JSON.'];
        }

        return match ($toolCall->function->name) {
            'nearestQuery' => $this->nearestQuery($arguments, $state),
            'recommendQuery' => $this->recommendQuery($arguments, $state),
            default => ['error' => sprintf("Unsupported tool '%s'.", $toolCall->function->name)],
        };
    }

    /**
     * @param  array<string, mixed>|null  $latestToolResult
     */
    private function finalizeToolLoop(
        ChatSessionState $state,
        RouteDecision $route,
        ?array $latestToolResult,
    ): ?string {
        if ($latestToolResult === null) {
            return null;
        }

        try {
            $finalText = $this->createGroundedCompletion(
                messages: $state->messages,
                routeHint: $this->buildRouteHint($route),
                toolResult: $latestToolResult,
            );
        } catch (AgentRuntimeException) {
            $finalText = '';
        }

        if ($finalText === '') {
            $finalText = $this->fallbackTextFromToolResult($latestToolResult);
        }

        if ($finalText === '') {
            return null;
        }

        $state->messages[] = ['role' => 'assistant', 'content' => $finalText];

        return $finalText;
    }

    private function runDeterministicToolFallback(
        ChatSessionState $state,
        RouteDecision $route,
        string $userText,
    ): string {
        $toolResult = $route->mode === 'nearest_only'
            ? $this->nearestQuery(
                [
                    'query_text' => $userText,
                    'limit' => $this->config->chatTopK,
                    'reason' => $route->reason,
                ],
                $state,
            )
            : $this->recommendQuery(
                [
                    'seed_skus' => $route->seedSkus,
                    'seed_result_indexes' => $route->seedResultIndexes,
                    'limit' => $this->config->chatTopK,
                    'reason' => $route->reason,
                ],
                $state,
            );

        try {
            $finalText = $this->createGroundedCompletion(
                messages: $state->messages,
                routeHint: $this->buildRouteHint($route),
                toolResult: $toolResult,
            );
        } catch (AgentRuntimeException) {
            $finalText = '';
        }

        if ($finalText === '') {
            $finalText = $this->fallbackTextFromToolResult($toolResult);
        }

        $state->messages[] = ['role' => 'assistant', 'content' => $finalText];

        return $finalText;
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    protected function nearestQuery(array $arguments, ChatSessionState $state): array
    {
        $queryText = trim((string) ($arguments['query_text'] ?? ''));
        $limit = $this->coerceLimit($arguments['limit'] ?? null, $this->config->chatTopK);

        if ($queryText === '') {
            return ['error' => 'nearestQuery requires a non-empty query_text.'];
        }

        try {
            $response = match ($this->config->searchMode) {
                CatalogSearchMode::Dense => $this->runDenseNearestQuery($queryText, $limit),
                CatalogSearchMode::Hybrid => $this->runHybridNearestQuery($queryText, $limit),
                CatalogSearchMode::HybridRerank => $this->runHybridRerankNearestQuery($queryText, $limit),
            };
        } catch (Throwable $exception) {
            return ['error' => sprintf('Nearest search failed: %s', $exception->getMessage())];
        }

        $collapsedPoints = $this->collapsePointHitsBySku($response->points, $limit);
        $hits = $this->formatToolHits($collapsedPoints);
        $state->lastResults = $this->formatSessionHits($collapsedPoints);
        $state->lastToolName = 'nearestQuery';

        return [
            'tool' => 'nearestQuery',
            'hits' => $hits,
            'message' => $hits === [] ? 'No matches found.' : null,
        ];
    }

    private function runDenseNearestQuery(string $queryText, int $limit): QueryResponse
    {
        $embedding = $this->vectorEncodings->encodeSearchQuery(self::SEARCH_PREFIX.$queryText);

        return $this->qdrant->queryPoints(
            collectionName: $this->config->qdrantCollection,
            query: new NearestQuery($embedding['dense'] ?? []),
            withPayload: true,
            limit: $this->expandedResultLimit($limit),
        );
    }

    private function runHybridNearestQuery(string $queryText, int $limit): QueryResponse
    {
        $queryVectors = $this->vectorEncodings->encodeSearchQuery(
            queryText: $queryText,
            denseQueryText: self::SEARCH_PREFIX.$queryText,
        );

        return $this->qdrant->queryPoints(
            collectionName: $this->config->qdrantCollection,
            query: new FusionQuery(Fusion::RRF),
            withPayload: true,
            limit: $this->expandedResultLimit($limit),
            prefetch: $this->hybridPrefetches($queryVectors),
        );
    }

    private function runHybridRerankNearestQuery(string $queryText, int $limit): QueryResponse
    {
        $queryVectors = $this->vectorEncodings->encodeSearchQuery(
            queryText: $queryText,
            denseQueryText: self::SEARCH_PREFIX.$queryText,
        );

        return $this->qdrant->queryPoints(
            collectionName: $this->config->qdrantCollection,
            query: $queryVectors['late'] ?? [],
            using: $this->collectionService->lateVectorName(),
            withPayload: true,
            limit: $this->expandedResultLimit($limit),
            prefetch: $this->hybridPrefetches($queryVectors),
        );
    }

    /**
     * @param  array{dense:list<float>,sparse:SparseVector,late?:list<list<float>>}  $queryVectors
     * @return list<Prefetch>
     */
    private function hybridPrefetches(array $queryVectors): array
    {
        return [
            new Prefetch(
                query: $queryVectors['dense'] ?? [],
                using: $this->collectionService->denseVectorName(),
                limit: $this->config->hybridPrefetchLimit,
            ),
            new Prefetch(
                query: $queryVectors['sparse'] ?? null,
                using: $this->collectionService->sparseVectorName(),
                limit: $this->config->hybridPrefetchLimit,
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    protected function recommendQuery(array $arguments, ChatSessionState $state): array
    {
        $limit = $this->coerceLimit($arguments['limit'] ?? null, $this->config->chatTopK);
        [$seedResultIndexes, $indexErrors] = $this->coerceIntList($arguments['seed_result_indexes'] ?? null);
        $seedSkus = $this->coerceStringList($arguments['seed_skus'] ?? null);

        [$resolvedIndexSeeds, $errors] = $this->resolveResultReferences(
            $state->lastResults,
            $seedResultIndexes,
        );
        $errors = [...$indexErrors, ...$errors];
        $seedSkusToExclude = $this->coerceStringList(array_merge(
            $seedSkus,
            array_values(array_filter(
                array_map(
                    static fn (array $seed): ?string => is_string($seed['sku'] ?? null) ? $seed['sku'] : null,
                    $resolvedIndexSeeds,
                ),
                static fn (?string $sku): bool => is_string($sku) && trim($sku) !== '',
            )),
        ));

        $positiveIds = array_map(
            static fn (array $seed): string => (string) $seed['point_id'],
            $resolvedIndexSeeds,
        );

        foreach ($seedSkus as $sku) {
            $resolvedId = $this->resolvePointIdForSku($sku);
            if ($resolvedId === null) {
                $errors[] = sprintf("Could not resolve SKU '%s'.", $sku);

                continue;
            }

            if (! in_array($resolvedId, $positiveIds, true)) {
                $positiveIds[] = $resolvedId;
            }
        }

        if ($positiveIds === []) {
            return [
                'error' => 'No valid recommendation seeds were found. Provide a SKU or a previous result number like #2.',
                'details' => $errors,
            ];
        }

        try {
            $response = $this->qdrant->queryPoints(
                collectionName: $this->config->qdrantCollection,
                query: new RecommendQuery(new RecommendInput($positiveIds)),
                withPayload: true,
                limit: $this->expandedResultLimit($limit + count($seedSkusToExclude)),
            );
        } catch (Throwable $exception) {
            return ['error' => sprintf('Recommendation search failed: %s', $exception->getMessage())];
        }

        $seedSet = array_values(array_unique($positiveIds));
        $collapsedPoints = $this->collapsePointHitsBySku($response->points, $limit, $seedSkusToExclude);
        $hits = $this->formatToolHits($collapsedPoints);
        $state->lastResults = $this->formatSessionHits($collapsedPoints);
        $state->lastToolName = 'recommendQuery';

        return [
            'tool' => 'recommendQuery',
            'hits' => $hits,
            'seed_point_ids' => $seedSet,
            'message' => $hits === [] ? 'No matches found.' : null,
            'details' => $errors !== [] ? $errors : null,
        ];
    }

    private function resolvePointIdForSku(string $sku): ?string
    {
        $sku = strtoupper(trim($sku));
        if ($sku === '') {
            return null;
        }

        try {
            $pointId = CatalogIds::catalogPointIdForSku($sku);
        } catch (Throwable) {
            return null;
        }

        try {
            $records = $this->qdrant->retrieve(
                collectionName: $this->config->qdrantCollection,
                ids: [$pointId],
                withPayload: true,
            );

            if ($records !== []) {
                return (string) $records[0]->id;
            }

            $scrollResponse = $this->qdrant->scroll(
                collectionName: $this->config->qdrantCollection,
                scrollFilter: new Filter(
                    must: [
                        FieldCondition::matchValue('sku', $sku),
                        FieldCondition::matchValue('is_primary', true),
                    ],
                ),
                withPayload: true,
                limit: 1,
            );
        } catch (Throwable) {
            return null;
        }

        if ($scrollResponse->points !== []) {
            return (string) $scrollResponse->points[0]->id;
        }

        try {
            $fallbackScrollResponse = $this->qdrant->scroll(
                collectionName: $this->config->qdrantCollection,
                scrollFilter: new Filter(
                    must: [FieldCondition::matchValue('sku', $sku)],
                ),
                withPayload: true,
                limit: 1,
            );
        } catch (Throwable) {
            return null;
        }

        if ($fallbackScrollResponse->points === []) {
            return null;
        }

        return (string) $fallbackScrollResponse->points[0]->id;
    }

    /**
     * @param  list<array<string, mixed>>  $lastResults
     * @param  list<int>  $indexes
     * @return array{0:list<array<string, mixed>>,1:list<string>}
     */
    private function resolveResultReferences(array $lastResults, array $indexes): array
    {
        $resolved = [];
        $errors = [];
        $seen = [];

        foreach ($indexes as $index) {
            if ($index < 1 || $index > count($lastResults)) {
                $errors[] = sprintf('Result index #%d is out of range.', $index);

                continue;
            }

            $item = $lastResults[$index - 1];
            $pointId = trim((string) ($item['point_id'] ?? ''));
            if ($pointId === '') {
                $errors[] = sprintf('Result index #%d is missing a point ID.', $index);

                continue;
            }

            if (in_array($pointId, $seen, true)) {
                continue;
            }

            $seen[] = $pointId;
            $resolved[] = [
                'point_id' => $pointId,
                'sku' => $item['sku'] ?? null,
                'name' => $item['name'] ?? null,
            ];
        }

        return [$resolved, $errors];
    }

    protected function expandedResultLimit(int $limit): int
    {
        return max(1, $limit) * self::MAX_PRODUCT_CHUNKS;
    }

    /**
     * @param  list<PointStruct|ScoredPoint|Record>  $points
     * @param  list<string>  $excludedSkus
     * @return list<PointStruct|ScoredPoint|Record>
     */
    protected function collapsePointHitsBySku(array $points, int $limit, array $excludedSkus = []): array
    {
        $collapsed = [];
        $seenSkuKeys = [];
        $excludedSkuKeys = [];

        foreach ($excludedSkus as $excludedSku) {
            $skuKey = $this->normalizeSkuKey($excludedSku);
            if ($skuKey !== null) {
                $excludedSkuKeys[$skuKey] = true;
            }
        }

        foreach ($points as $point) {
            $payload = is_array($point->payload ?? null) ? $point->payload : [];
            $sku = is_string($payload['sku'] ?? null) ? (string) $payload['sku'] : '';
            $skuKey = $this->normalizeSkuKey($sku);
            if ($skuKey === null || isset($excludedSkuKeys[$skuKey]) || isset($seenSkuKeys[$skuKey])) {
                continue;
            }

            $seenSkuKeys[$skuKey] = true;
            $collapsed[] = $point;

            if (count($collapsed) >= $limit) {
                break;
            }
        }

        return $collapsed;
    }

    /**
     * @param  list<PointStruct|ScoredPoint|Record>  $points
     * @return list<array<string, mixed>>
     */
    protected function formatToolHits(array $points): array
    {
        $hits = [];

        foreach ($points as $index => $point) {
            $hits[] = $this->formatHit($point, $index + 1, includeText: true, includeSnippet: true);
        }

        return $hits;
    }

    /**
     * @param  list<PointStruct|ScoredPoint|Record>  $points
     * @return list<array<string, mixed>>
     */
    protected function formatSessionHits(array $points): array
    {
        $hits = [];

        foreach ($points as $index => $point) {
            $hits[] = $this->formatHit($point, $index + 1, includeText: false, includeSnippet: false);
        }

        return $hits;
    }

    /**
     * @return array<string, mixed>
     */
    protected function formatHit(
        PointStruct|ScoredPoint|Record $point,
        int $resultIndex,
        bool $includeText,
        bool $includeSnippet,
    ): array {
        $payload = is_array($point->payload ?? null) ? $point->payload : [];
        $text = trim((string) ($payload['text'] ?? ''));

        $hit = [
            'result_index' => $resultIndex,
            'point_id' => (string) $point->id,
            'sku' => $payload['sku'] ?? null,
            'name' => $payload['name'] ?? null,
            'brand' => $payload['brand'] ?? null,
            'categories' => is_array($payload['categories'] ?? null) ? $payload['categories'] : [],
            'score' => property_exists($point, 'score') ? $point->score : null,
            'text_snippet' => $includeSnippet ? $this->truncateText($text) : '',
        ];

        if ($includeText) {
            $hit['text'] = $text;
        }

        return $hit;
    }

    private function normalizeSkuKey(string $sku): ?string
    {
        try {
            return CatalogIds::normalizeSku($sku);
        } catch (Throwable) {
            return null;
        }
    }

    private function truncateText(string $text): string
    {
        $compact = trim((string) preg_replace('/\s+/u', ' ', $text));

        if (mb_strlen($compact) <= self::MAX_SNIPPET_LENGTH) {
            return $compact;
        }

        return rtrim(mb_substr($compact, 0, self::MAX_SNIPPET_LENGTH - 3)).'...';
    }

    private function coerceLimit(mixed $value, int $default): int
    {
        if (! is_numeric($value)) {
            return $default;
        }

        $limit = (int) $value;

        return max(1, min($limit, 10));
    }

    private function buildRouteHint(RouteDecision $route): string
    {
        return "Routing hint:\n"
            ."- mode: {$route->mode}\n"
            ."- reason: {$route->reason}\n"
            .'- detected_seed_skus: '.json_encode($route->seedSkus, JSON_UNESCAPED_SLASHES)."\n"
            .'- detected_seed_result_indexes: '.json_encode($route->seedResultIndexes, JSON_UNESCAPED_SLASHES)."\n"
            ."- If mode is recommend_only, call recommendQuery unless you need clarification.\n"
            ."- If mode is nearest_only, call nearestQuery.\n"
            .'- If mode is auto and no usable anchor exists, ask for a SKU or result number.';
    }

    private function selectToolChoice(string $routeMode, bool $forceTool): string
    {
        if ($forceTool && in_array($routeMode, ['nearest_only', 'recommend_only'], true)) {
            return 'required';
        }

        return 'auto';
    }

    private function cleanResponseText(string $text): string
    {
        $cleaned = preg_replace('/<think>.*?<\/think>/su', '', $text) ?? $text;
        $cleaned = str_replace('<|im_end|>', '', $cleaned);
        $cleaned = str_replace('<|endoftext|>', '', $cleaned);

        return trim($cleaned);
    }

    /**
     * @param  array<string, mixed>  $toolResult
     */
    private function fallbackTextFromToolResult(array $toolResult): string
    {
        if (isset($toolResult['error']) && is_string($toolResult['error'])) {
            return $toolResult['error'];
        }

        $hits = $toolResult['hits'] ?? [];
        if (! is_array($hits) || $hits === []) {
            return "I couldn't find matches for that request.";
        }

        $lines = [];
        foreach (array_slice($hits, 0, 3) as $hit) {
            if (! is_array($hit)) {
                continue;
            }

            $lines[] = sprintf(
                '#%d: %s (SKU: %s)',
                (int) ($hit['result_index'] ?? 0),
                (string) ($hit['name'] ?? 'Unknown product'),
                (string) ($hit['sku'] ?? 'n/a'),
            );
        }

        return implode("\n", $lines);
    }

    /**
     * @return list<string>
     */
    private function coerceStringList(mixed $value): array
    {
        $items = is_array($value) ? $value : ($value === null ? [] : [$value]);
        $result = [];
        $seen = [];

        foreach ($items as $item) {
            $text = strtoupper(trim((string) $item));
            if ($text === '' || in_array($text, $seen, true)) {
                continue;
            }

            $seen[] = $text;
            $result[] = $text;
        }

        return $result;
    }

    /**
     * @return array{0:list<int>,1:list<string>}
     */
    private function coerceIntList(mixed $value): array
    {
        $items = is_array($value) ? $value : ($value === null ? [] : [$value]);
        $values = [];
        $errors = [];
        $seen = [];

        foreach ($items as $item) {
            if (! is_numeric($item)) {
                $errors[] = sprintf("Invalid result index '%s'.", (string) $item);

                continue;
            }

            $parsed = (int) $item;
            if (in_array($parsed, $seen, true)) {
                continue;
            }

            $seen[] = $parsed;
            $values[] = $parsed;
        }

        return [$values, $errors];
    }

    /**
     * @return array<string, mixed>
     */
    private function assistantToolMessage(CreateResponseMessage $message): array
    {
        return [
            'role' => 'assistant',
            'content' => $message->content ?? '',
            'tool_calls' => array_map(
                static fn (CreateResponseToolCall $toolCall): array => $toolCall->toArray(),
                $message->toolCalls,
            ),
        ];
    }
}
