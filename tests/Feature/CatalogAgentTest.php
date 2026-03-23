<?php

namespace Tests\Feature;

use App\CatalogAgent\AgentRuntimeException;
use App\CatalogAgent\CatalogChatAgent;
use Inertia\Testing\AssertableInertia;
use Mockery;
use Tests\TestCase;

class CatalogAgentTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_home_page_renders_the_catalog_agent_interface(): void
    {
        $agent = Mockery::mock(CatalogChatAgent::class);
        $agent->shouldReceive('conversation')->once()->andReturn([]);
        $agent->shouldReceive('lastResults')->once()->andReturn([]);
        $agent->shouldReceive('runtimeError')->once()->andReturn(null);
        $agent->shouldReceive('examples')->once()->andReturn([
            'I need a high bay occupancy sensor for a warehouse',
        ]);

        $this->app->instance(CatalogChatAgent::class, $agent);

        $response = $this->get(route('home'));

        $response
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('CatalogAgentApp')
                ->where('chatEndpoint', route('catalog-agent.message'))
                ->where('resetEndpoint', route('catalog-agent.reset'))
                ->where('conversation', [])
                ->where('lastResults', [])
                ->where('examples', [
                    'I need a high bay occupancy sensor for a warehouse',
                ]));
    }

    public function test_message_endpoint_returns_the_updated_chat_payload(): void
    {
        $agent = Mockery::mock(CatalogChatAgent::class);
        $agent->shouldReceive('chat')->once()->andReturn('Here are a few good matches.');
        $agent->shouldReceive('conversation')->once()->andReturn([
            ['role' => 'user', 'content' => 'warehouse sensor'],
            ['role' => 'assistant', 'content' => 'Here are a few good matches.'],
        ]);
        $agent->shouldReceive('lastResults')->once()->andReturn([
            [
                'result_index' => 1,
                'point_id' => 'id-1',
                'sku' => 'OSFHU-ITW',
                'name' => 'Fixture Sensor',
                'brand' => 'Acme',
                'categories' => ['Sensors'],
                'score' => 0.98,
                'text_snippet' => 'High bay occupancy sensor',
            ],
        ]);
        $agent->shouldReceive('runtimeError')->once()->andReturn(null);

        $this->app->instance(CatalogChatAgent::class, $agent);

        $response = $this->postJson(route('catalog-agent.message'), [
            'message' => 'warehouse sensor',
        ]);

        $response
            ->assertOk()
            ->assertJson([
                'ok' => true,
                'reply' => 'Here are a few good matches.',
                'conversation' => [
                    ['role' => 'user', 'content' => 'warehouse sensor'],
                    ['role' => 'assistant', 'content' => 'Here are a few good matches.'],
                ],
                'runtimeError' => null,
            ]);
    }

    public function test_message_endpoint_returns_a_validation_style_error_payload_when_the_agent_fails(): void
    {
        $agent = Mockery::mock(CatalogChatAgent::class);
        $agent->shouldReceive('chat')->once()->andThrow(new AgentRuntimeException('Qdrant is unavailable.'));
        $agent->shouldReceive('conversation')->once()->andReturn([
            ['role' => 'user', 'content' => 'show similar to #2'],
        ]);
        $agent->shouldReceive('lastResults')->once()->andReturn([]);
        $agent->shouldReceive('runtimeError')->never();

        $this->app->instance(CatalogChatAgent::class, $agent);

        $response = $this->postJson(route('catalog-agent.message'), [
            'message' => 'show similar to #2',
        ]);

        $response
            ->assertStatus(422)
            ->assertJson([
                'ok' => false,
                'error' => 'Qdrant is unavailable.',
                'runtimeError' => 'Qdrant is unavailable.',
            ]);
    }

    public function test_reset_endpoint_clears_the_browser_chat_session(): void
    {
        $agent = Mockery::mock(CatalogChatAgent::class);
        $agent->shouldReceive('reset')->once();
        $agent->shouldReceive('conversation')->once()->andReturn([]);
        $agent->shouldReceive('lastResults')->once()->andReturn([]);
        $agent->shouldReceive('runtimeError')->once()->andReturn(null);

        $this->app->instance(CatalogChatAgent::class, $agent);

        $response = $this->postJson(route('catalog-agent.reset'));

        $response
            ->assertOk()
            ->assertJson([
                'ok' => true,
                'conversation' => [],
                'lastResults' => [],
                'runtimeError' => null,
            ]);
    }
}
