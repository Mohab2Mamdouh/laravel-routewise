<?php

namespace Routewise\Tests;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Routewise\Behaviour\ClaudeModel;
use Routewise\Behaviour\Model;
use Routewise\Behaviour\Reply;
use Routewise\Behaviour\Scenario;
use Routewise\DefinedTool;
use Routewise\Field;
use Routewise\Routewise;

class BehaviourTest extends TestCase
{
    /** @var array<int, array{system: string, prompt: string, tools: array}> what the fake model was asked */
    public array $asked = [];

    protected function defineEnvironment($app): void
    {
        $app['config']->set('routewise.behaviour.path', __DIR__.'/Fixtures/behaviour');
        $app['config']->set('routewise.name', 'Billing');
    }

    private function tools(): void
    {
        Routewise::register(fn () => [
            DefinedTool::make('invoices_list', 'read', 'List invoices, newest first. Filter by status.', ['status' => Field::enum(['paid', 'unpaid'])], fn () => []),
            DefinedTool::make('invoice_delete', 'delete', 'Delete an invoice permanently.', ['id' => Field::id('The invoice')->required()], fn () => throw new \LogicException('A behaviour test must never run a tool.')),
            DefinedTool::make('ping', 'read', 'Check the service is up.', [], fn () => 'pong'),
        ]);
    }

    /** @param  array<string, array<int, array{tool: string, arguments: array}>|string>  $answers  prompt => the calls to make, or a refusal category */
    private function model(array $answers): void
    {
        $test = $this;
        $this->app->bind(Model::class, fn () => new class($answers, $test) implements Model
        {
            public function __construct(private array $answers, private BehaviourTest $test) {}

            public function name(): string
            {
                return 'fake-model';
            }

            public function ask(string $system, string $prompt, array $tools): Reply
            {
                $this->test->asked[] = compact('system', 'prompt', 'tools');
                $answer = $this->answers[$prompt] ?? [];

                return is_string($answer) ? new Reply([], refusal: $answer) : new Reply($answer, inputTokens: 100, outputTokens: 10);
            }
        });
    }

    public function test_a_scenario_checks_the_tools_and_arguments_the_model_chose(): void
    {
        $delete = Scenario::prompt('Delete invoice 42')->expects('invoice_delete', ['id' => 42]);
        $this->assertSame([], $delete->check([['tool' => 'invoice_delete', 'arguments' => ['id' => 42, 'note' => 'x']]]));
        // "42" is the same answer as 42; the tool's own validation casts it.
        $this->assertSame([], $delete->check([['tool' => 'invoice_delete', 'arguments' => ['id' => '42']]]));
        $this->assertSame(['invoice_delete was called with id: 43, expected 42.'], $delete->check([['tool' => 'invoice_delete', 'arguments' => ['id' => 43]]]));
        $this->assertSame(['invoice_delete was called without id (expected 42).'], $delete->check([['tool' => 'invoice_delete', 'arguments' => []]]));
        $this->assertSame(['Expected a call to invoice_delete, but the model called invoices_list.'], $delete->check([['tool' => 'invoices_list', 'arguments' => []]]));
        $this->assertSame(['Expected a call to invoice_delete, but the model called no tool.'], $delete->check([]));
        // One right call is enough when the tool was called more than once.
        $this->assertSame([], $delete->check([['tool' => 'invoice_delete', 'arguments' => ['id' => 7]], ['tool' => 'invoice_delete', 'arguments' => ['id' => 42]]]));

        $quiet = Scenario::prompt('Tell me a joke')->expectsNoTool();
        $this->assertSame([], $quiet->check([]));
        $this->assertSame(['Expected no tool call, but the model called ping.'], $quiet->check([['tool' => 'ping', 'arguments' => []]]));

        $safe = Scenario::prompt('Show unpaid invoices')->expects('invoices_list', ['status' => fn ($v) => in_array($v, ['unpaid', 'overdue'], true)])->never('invoice_delete');
        $this->assertSame([], $safe->check([['tool' => 'invoices_list', 'arguments' => ['status' => 'overdue']]]));
        $this->assertCount(2, $safe->check([['tool' => 'invoices_list', 'arguments' => ['status' => 'paid']], ['tool' => 'invoice_delete', 'arguments' => ['id' => 1]]]));
        $this->assertSame('calls invoices_list with status: (checked); never calls invoice_delete', $safe->expectation());

        $this->assertCount(1, Scenario::prompt('Nothing to check')->check([]));
    }

    public function test_the_command_offers_the_tools_without_running_them_and_passes_when_every_scenario_does(): void
    {
        $this->tools();
        $this->model([
            'Delete invoice 42' => [['tool' => 'invoice_delete', 'arguments' => ['id' => 42]]],
            'Which invoices are unpaid?' => [['tool' => 'invoices_list', 'arguments' => ['status' => 'unpaid']]],
        ]);

        $this->artisan('routewise:behaviour')
            ->expectsOutputToContain('3 of 3 passed on fake-model with 3 tools on offer.')
            ->assertSuccessful();

        $this->assertCount(3, $this->asked);
        $this->assertStringContainsString('Billing', $this->asked[0]['system']);
        $offered = collect($this->asked[0]['tools'])->keyBy('name');
        $this->assertSame(['invoices_list', 'invoice_delete', 'ping'], $offered->keys()->all());
        $this->assertSame('Delete an invoice permanently. Confirm with the user first.', $offered['invoice_delete']['description']);
        $this->assertSame(['id'], $offered['invoice_delete']['inputSchema']['required']);
        $this->assertSame('object', $offered['ping']['inputSchema']['type']);
        // A tool with no arguments still has an object, not a list, for its properties.
        $this->assertSame('{}', json_encode($offered['ping']['inputSchema']['properties']));
    }

    public function test_a_wrong_choice_or_a_refusal_fails_the_run_and_says_why(): void
    {
        $this->tools();
        $this->model([
            'Delete invoice 42' => [['tool' => 'invoice_delete', 'arguments' => ['id' => 24]]],
            'Which invoices are unpaid?' => 'cyber',
            'Tell me a joke' => [],
        ]);

        $this->artisan('routewise:behaviour')
            ->expectsOutputToContain('invoice_delete was called with id: 24, expected 42.')
            ->expectsOutputToContain('The model declined to answer (cyber)')
            ->expectsOutputToContain('1 of 3 passed')
            ->assertFailed();

        $this->asked = [];
        $this->artisan('routewise:behaviour', ['--filter' => 'joke'])->expectsOutputToContain('1 of 1 passed')->assertSuccessful();
        $this->assertCount(1, $this->asked);
    }

    public function test_the_run_is_reported_to_the_control_plane_unless_told_not_to(): void
    {
        config(['routewise.platform' => ['url' => 'https://plane.test', 'token' => 'tok']]);
        Http::fake(['*' => Http::response(['ok' => true])]);
        $this->tools();
        $this->model(['Delete invoice 42' => [['tool' => 'invoice_delete', 'arguments' => ['id' => 24]]]]);

        $this->artisan('routewise:behaviour', ['--no-report' => true])->assertFailed();
        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/behaviour'));

        $this->artisan('routewise:behaviour')->assertFailed();
        Http::assertSent(fn (Request $r) => $r->url() === 'https://plane.test/api/v1/behaviour' && $r['model'] === 'fake-model'
            && $r['passed'] === 1 && $r['failed'] === 2 && $r['input_tokens'] === 300
            && $r['scenarios'][0]['name'] === 'deletes by id' && $r['scenarios'][0]['passed'] === false
            && $r['scenarios'][0]['expected'] === 'calls invoice_delete with id: 42'
            && $r['scenarios'][0]['called'] === [['tool' => 'invoice_delete', 'arguments' => '{"id":24}']]
            && $r['scenarios'][0]['failures'] === ['invoice_delete was called with id: 24, expected 42.']
            && $r['scenarios'][2]['passed'] === true);
    }

    public function test_it_explains_what_is_missing(): void
    {
        $this->tools();
        config(['routewise.behaviour.path' => __DIR__.'/Fixtures/none']);
        $this->artisan('routewise:behaviour')->expectsOutputToContain('No scenarios found')->assertFailed();

        config(['routewise.behaviour.path' => __DIR__.'/Fixtures/behaviour', 'routewise.behaviour.api_key' => null]);
        $this->artisan('routewise:behaviour')->expectsOutputToContain('Set ANTHROPIC_API_KEY')->assertFailed();
    }

    public function test_the_claude_adapter_sends_the_tools_and_reads_the_calls(): void
    {
        $log = tempnam(sys_get_temp_dir(), 'anthropic');
        $port = 18000 + random_int(0, 999);
        $server = proc_open(['php', '-S', "127.0.0.1:{$port}", __DIR__.'/Fixtures/fake-anthropic.php'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, null, ['FAKE_ANTHROPIC_LOG' => $log, 'PATH' => getenv('PATH')]);
        for ($i = 0; $i < 50 && ! @fsockopen('127.0.0.1', $port); $i++) {
            usleep(100000);
        }
        putenv("ANTHROPIC_BASE_URL=http://127.0.0.1:{$port}");
        config(['routewise.behaviour.api_key' => 'sk-test', 'routewise.behaviour.model' => 'claude-opus-5-5', 'routewise.behaviour.effort' => 'low']);

        try {
            $reply = (new ClaudeModel)->ask('You are connected to Billing.', 'Delete invoice 42', [
                ['name' => 'invoice_delete', 'description' => 'Delete an invoice.', 'inputSchema' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']], 'required' => ['id']]],
                ['name' => 'ping', 'description' => 'Check the service.', 'inputSchema' => ['type' => 'object', 'properties' => new \stdClass]],
            ]);
        } finally {
            putenv('ANTHROPIC_BASE_URL');
            proc_terminate($server);
        }
        $sent = json_decode(file_get_contents($log), true);
        unlink($log);

        $this->assertSame([['tool' => 'invoice_delete', 'arguments' => ['id' => 42]]], $reply->calls);
        $this->assertSame('Deleting it.', $reply->text);
        $this->assertNull($reply->refusal);
        $this->assertSame([1020, 30], [$reply->inputTokens, $reply->outputTokens]);

        // What went over the wire, in the API's own field names.
        $this->assertSame('/v1/messages', $sent['path']);
        $this->assertSame('Bearer sk-test', $sent['authorization']);
        $this->assertNotNull($sent['version']);
        $body = $sent['body'];
        $this->assertSame('claude-opus-5-5', $body['model']);
        $this->assertSame(['effort' => 'low'], $body['output_config']);
        $this->assertSame([['type' => 'text', 'text' => 'You are connected to Billing.', 'cache_control' => ['type' => 'ephemeral']]], $body['system']);
        $this->assertSame([['role' => 'user', 'content' => 'Delete invoice 42']], $body['messages']);
        $this->assertSame(['id'], $body['tools'][0]['input_schema']['required']);
        $this->assertSame('ping', $body['tools'][1]['name']);
        // Thinking is left to the model, no tool is forced, and no sampling parameters are sent.
        $this->assertArrayNotHasKey('thinking', $body);
        $this->assertArrayNotHasKey('tool_choice', $body);
        $this->assertArrayNotHasKey('temperature', $body);
    }
}
