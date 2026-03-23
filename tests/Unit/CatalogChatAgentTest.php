<?php

namespace Tests\Unit;

use App\CatalogAgent\CatalogChatAgent;
use App\CatalogAgent\ChatSessionState;
use OpenAI\Responses\Chat\CreateResponse;
use OpenAI\Responses\Chat\CreateResponseToolCall;
use OpenAI\Responses\Meta\MetaInformation;
use Tests\TestCase;

class CatalogChatAgentTest extends TestCase
{
    public function test_chat_returns_a_grounded_reply_when_the_model_keeps_requesting_tools(): void
    {
        $agent = new LoopingCatalogChatAgent([
            $this->toolResponse('call-1'),
            $this->toolResponse('call-2'),
            $this->toolResponse('call-3'),
            $this->toolResponse('call-4'),
        ]);
        $agent->groundedReply = 'Here are the best matches from the latest retrieval.';

        $reply = $agent->chat($this->app['session']->driver(), 'warehouse sensor');

        $this->assertSame('Here are the best matches from the latest retrieval.', $reply);
        $this->assertSame([
            ['role' => 'user', 'content' => 'warehouse sensor'],
            ['role' => 'assistant', 'content' => 'Here are the best matches from the latest retrieval.'],
        ], $agent->conversation($this->app['session']->driver()));
    }

    public function test_chat_falls_back_to_tool_result_text_when_grounded_reply_is_empty(): void
    {
        $agent = new LoopingCatalogChatAgent([
            $this->toolResponse('call-1'),
            $this->toolResponse('call-2'),
            $this->toolResponse('call-3'),
            $this->toolResponse('call-4'),
        ]);
        $agent->groundedReply = '';

        $reply = $agent->chat($this->app['session']->driver(), 'warehouse sensor');

        $this->assertSame("#1: Fixture Sensor (SKU: OSFHU-ITW)", $reply);
    }

    private function toolResponse(string $toolCallId): CreateResponse
    {
        return CreateResponse::from([
            'id' => 'chatcmpl-test',
            'object' => 'chat.completion',
            'created' => 1,
            'model' => 'test-model',
            'choices' => [[
                'index' => 0,
                'message' => [
                    'role' => 'assistant',
                    'content' => null,
                    'tool_calls' => [[
                        'id' => $toolCallId,
                        'type' => 'function',
                        'function' => [
                            'name' => 'nearestQuery',
                            'arguments' => json_encode([
                                'query_text' => 'warehouse sensor',
                                'limit' => 3,
                                'reason' => 'semantic catalog search',
                            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                        ],
                    ]],
                ],
                'finish_reason' => 'tool_calls',
                'logprobs' => null,
            ]],
            'usage' => [
                'prompt_tokens' => 1,
                'completion_tokens' => 1,
                'total_tokens' => 2,
            ],
        ], MetaInformation::from([]));
    }
}

class LoopingCatalogChatAgent extends CatalogChatAgent
{
    /**
     * @param list<CreateResponse> $responses
     */
    public function __construct(private array $responses)
    {
    }

    public string $groundedReply = '';

    public function validateRuntime(): void
    {
    }

    protected function createCompletion(
        array $messages,
        array $tools,
        string $routeMode,
        string $routeHint,
        bool $forceTool,
    ): CreateResponse {
        return array_shift($this->responses)
            ?? throw new \RuntimeException('No queued response available.');
    }

    protected function createGroundedCompletion(array $messages, string $routeHint, array $toolResult): string
    {
        return $this->groundedReply;
    }

    protected function executeToolCall(CreateResponseToolCall $toolCall, ChatSessionState $state): array
    {
        return [
            'tool' => 'nearestQuery',
            'hits' => [[
                'result_index' => 1,
                'point_id' => 'id-1',
                'sku' => 'OSFHU-ITW',
                'name' => 'Fixture Sensor',
                'brand' => 'Acme',
                'categories' => ['Sensors'],
                'score' => 0.98,
                'text_snippet' => 'High bay occupancy sensor',
            ]],
            'message' => null,
        ];
    }
}
