<?php

namespace AgentTools\Tests;

use AgentTools\AgentTools;
use AgentTools\DefinedTool;
use AgentTools\Field;
use AgentTools\Http\RestrictOAuthRedirects;
use AgentTools\ResultView;
use Laravel\Mcp\Facades\Mcp;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Contracts\Transport;

class DemoServer extends Server
{
    protected array $resources = [ResultView::class];

    public function __construct(Transport $transport)
    {
        parent::__construct($transport);
        $this->tools = AgentTools::tools();
    }
}

class ServerTest extends TestCase
{
    protected function defineRoutes($router): void
    {
        Mcp::web('/mcp', DemoServer::class);
    }

    private function rpc(string $method, array $params = []): array
    {
        return $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params])->assertOk()->json('result');
    }

    public function test_a_server_lists_and_runs_the_tools_and_serves_the_result_view(): void
    {
        AgentTools::register(fn () => [
            DefinedTool::make('sum', 'read', 'Add two whole numbers and return the total.', ['a' => Field::int('First')->required(), 'b' => Field::int('Second')->required()], fn ($x) => ['total' => $x['a'] + $x['b']]),
        ]);

        $tool = $this->rpc('tools/list')['tools'][0];
        $this->assertSame('sum', $tool['name']);
        $this->assertSame(ResultView::URI, $tool['_meta']['ui']['resourceUri']);

        $result = $this->rpc('tools/call', ['name' => 'sum', 'arguments' => ['a' => 2, 'b' => 3]]);
        $this->assertSame(['total' => 5], json_decode($result['content'][0]['text'], true));

        $view = $this->rpc('resources/read', ['uri' => ResultView::URI])['contents'][0];
        $this->assertSame('text/html;profile=mcp-app', $view['mimeType']);
        $this->assertStringContainsString('onToolResult', $view['text']);
    }

    public function test_only_exact_callbacks_and_loopback_addresses_may_register(): void
    {
        $this->assertTrue(RestrictOAuthRedirects::allowed('https://claude.ai/api/mcp/auth_callback'));
        $this->assertTrue(RestrictOAuthRedirects::allowed('http://localhost:5555/callback'));
        $this->assertFalse(RestrictOAuthRedirects::allowed('https://claude.ai/redirect?to=https://evil.test'));
        $this->assertFalse(RestrictOAuthRedirects::allowed('http://user:pw@localhost/callback'));
        $this->assertFalse(RestrictOAuthRedirects::allowed('https://localhost/callback'));
    }
}
