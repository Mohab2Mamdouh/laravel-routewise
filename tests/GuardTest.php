<?php

namespace Routewise\Tests;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Routewise\DefinedTool;
use Routewise\Events\ToolCalled;
use Routewise\Field;
use Routewise\Platform\Guard;

class GuardTest extends TestCase
{
    private int $ran = 0;

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
    }

    private function tool(string $name = 'invoice_delete', string $kind = 'delete'): DefinedTool
    {
        return DefinedTool::make($name, $kind, 'Delete an invoice permanently.', ['id' => Field::id('The invoice')->required(), 'note' => Field::string('Why')], function ($a) {
            $this->ran++;

            return ['done' => $a['id']];
        });
    }

    private function connect(array $extra = []): void
    {
        config(['routewise.platform' => ['url' => 'https://plane.test', 'token' => 'tok', 'policy_ttl' => 60, ...$extra]]);
    }

    public function test_without_rules_every_tool_runs(): void
    {
        Http::fake();

        $this->assertSame([true, ['done' => 5]], $this->invoke($this->tool(), ['id' => 5]));
        Http::assertNothingSent();
    }

    public function test_a_block_in_the_apps_config_cannot_be_overridden(): void
    {
        $this->connect();
        Http::fake(['*/policy' => Http::response(['kinds' => ['delete' => 'allow'], 'tools' => ['invoice_delete' => 'allow']])]);
        config(['routewise.policy.kinds' => ['delete' => 'block']]);
        Event::fake([ToolCalled::class]);

        [$ok, $message] = $this->invoke($this->tool(), ['id' => 5]);

        $this->assertFalse($ok);
        $this->assertStringContainsString('blocked', $message);
        $this->assertSame(0, $this->ran);
        Event::assertDispatched(ToolCalled::class, fn (ToolCalled $e) => $e->outcome === 'blocked' && ! $e->ok);
    }

    public function test_the_control_planes_rules_apply_per_tool_then_per_kind(): void
    {
        $this->connect();
        Http::fake(['*/policy' => Http::response(['kinds' => ['delete' => 'block', 'write' => 'allow'], 'tools' => ['note_delete' => 'allow']])]);

        $this->assertFalse($this->invoke($this->tool(), ['id' => 5])[0]);
        $this->assertTrue($this->invoke($this->tool('note_delete'), ['id' => 5])[0]);
        $this->assertTrue($this->invoke($this->tool('invoice_update', 'write'), ['id' => 5])[0]);
        // Fetched once, then cached.
        $this->assertCount(1, Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/policy')));
    }

    public function test_a_call_that_needs_approval_waits_and_runs_once_approved(): void
    {
        $this->connect();
        $status = 'pending';
        Http::fake([
            '*/policy' => Http::response(['kinds' => ['delete' => 'approve']]),
            '*/approvals' => function () use (&$status) {
                return Http::response(['status' => $status, 'id' => 9, 'url' => 'https://plane.test/projects/1']);
            },
        ]);
        Event::fake([ToolCalled::class]);

        [$ok, $message] = $this->invoke($this->tool(), ['id' => 5, 'note' => 'private reason']);
        $this->assertFalse($ok);
        $this->assertStringContainsString('needs approval', $message);
        $this->assertStringContainsString('https://plane.test/projects/1', $message);
        $this->assertSame(0, $this->ran);
        Event::assertDispatched(ToolCalled::class, fn (ToolCalled $e) => $e->outcome === 'pending');

        $status = 'approved';
        $this->assertSame([true, ['done' => 5]], $this->invoke($this->tool(), ['note' => 'private reason', 'id' => 5]));

        $status = 'denied';
        [$ok, $message] = $this->invoke($this->tool(), ['id' => 5, 'note' => 'private reason']);
        $this->assertFalse($ok);
        $this->assertStringContainsString('denied', $message);
        $this->assertSame(1, $this->ran);

        $sent = Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/approvals'))->map(fn ($pair) => $pair[0])->values();
        // The same call gives the same fingerprint whatever the argument order, and the values stay home.
        $this->assertSame($sent[0]['fingerprint'], $sent[1]['fingerprint']);
        $this->assertSame(64, strlen($sent[0]['fingerprint']));
        $this->assertNull($sent[0]['values']);
        $this->assertEqualsCanonicalizing(['id', 'note'], $sent[0]['arguments']);
        $this->assertStringNotContainsString('private reason', $sent[0]->body());
        $this->assertNotSame($sent[0]['fingerprint'], Guard::fingerprint('invoice_delete', ['id' => 6, 'note' => 'private reason']));
    }

    public function test_values_are_shown_to_approvers_only_when_the_app_opts_in(): void
    {
        $this->connect(['approval_values' => true]);
        Http::fake(['*/policy' => Http::response(['tools' => ['invoice_delete' => 'approve']]), '*/approvals' => Http::response(['status' => 'pending'])]);

        $this->invoke($this->tool(), ['id' => 5, 'note' => 'duplicate']);

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/approvals') && $r['values'] === ['id' => '5', 'note' => "'duplicate'"]);
    }

    public function test_when_the_control_plane_is_down_the_last_rules_hold_and_approvals_fail_closed(): void
    {
        $this->connect();
        Http::fake(['*/policy' => Http::response(['kinds' => ['delete' => 'approve']]), '*/approvals' => Http::response(['status' => 'approved'])]);
        $this->assertTrue($this->invoke($this->tool(), ['id' => 5])[0]);

        Cache::forget(app(\Routewise\Platform\Client::class)->key('rules'));
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('down'));

        [$ok, $message] = $this->invoke($this->tool(), ['id' => 5]);
        $this->assertFalse($ok);
        $this->assertStringContainsString('could not be reached', $message);
        $this->assertSame(1, $this->ran);
    }
}
