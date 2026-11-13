<?php

namespace AgentTools\Tests;

use AgentTools\AgentTools;
use AgentTools\DefinedTool;
use AgentTools\Field;
use AgentTools\Platform\Reporter;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

class ReporterTest extends TestCase
{
    private function tool(): DefinedTool
    {
        return DefinedTool::make('notes_add', 'write', 'Add a note to the signed-in user\'s notebook.', ['text' => Field::string('The note')->required()],
            fn ($a) => $a['text'] === 'boom' ? throw new \RuntimeException('Secret 4111 rejected.') : ['id' => 1]);
    }

    public function test_nothing_is_sent_without_a_token(): void
    {
        Http::fake();
        $this->invoke($this->tool(), ['text' => 'hi']);
        app(Reporter::class)->flush();

        Http::assertNothingSent();
        $this->artisan('agent-tools:sync')->assertFailed();
    }

    public function test_calls_are_sent_after_the_request_without_values_or_error_text(): void
    {
        config(['agent-tools.platform' => ['url' => 'https://plane.test/', 'token' => 'tok', 'send_errors' => false]]);
        Http::fake(['*' => Http::response(['ok' => true])]);

        $this->invoke($this->tool(), ['text' => 'private words']);
        $this->invoke($this->tool(), ['text' => 'boom']);
        Http::assertNothingSent();
        app(Reporter::class)->flush();
        app(Reporter::class)->flush();

        Http::assertSentCount(1);
        Http::assertSent(function (Request $r) {
            $calls = $r['calls'];

            return $r->url() === 'https://plane.test/api/v1/calls' && $r->hasHeader('Authorization', 'Bearer tok')
                && count($calls) === 2 && $calls[0]['ok'] && $calls[0]['arguments'] === ['text'] && $calls[0]['tool'] === 'notes_add'
                && ! $calls[1]['ok'] && $calls[1]['error'] === null
                && ! str_contains($r->body(), 'private words') && ! str_contains($r->body(), '4111');
        });
    }

    public function test_a_control_plane_that_is_down_never_breaks_the_app(): void
    {
        config(['agent-tools.platform' => ['url' => 'https://plane.test', 'token' => 'tok']]);
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('down'));

        $this->assertSame([true, ['id' => 1]], $this->invoke($this->tool(), ['text' => 'hi']));
        app(Reporter::class)->flush();
        $this->assertTrue(true);
    }

    public function test_sync_sends_the_catalog_with_schemas_and_scores(): void
    {
        config(['agent-tools.platform' => ['url' => 'https://plane.test', 'token' => 'tok'], 'agent-tools.name' => 'Notes']);
        Http::fake(['*' => Http::response(['ok' => true])]);
        AgentTools::register(fn () => [$this->tool()]);

        $this->artisan('agent-tools:sync')->expectsOutputToContain('1 tools sent.')->assertSuccessful();

        Http::assertSent(fn (Request $r) => $r->url() === 'https://plane.test/api/v1/catalog' && $r['app'] === 'Notes' && $r['score'] === 100
            && $r['tools'][0]['name'] === 'notes_add' && $r['tools'][0]['kind'] === 'write'
            && $r['tools'][0]['input_schema']['properties']['text']['type'] === 'string' && $r['tools'][0]['input_schema']['required'] === ['text']);
    }
}
