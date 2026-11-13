<?php

namespace Routewise\Tests;

use Routewise\DefinedTool;
use Routewise\Field;
use Routewise\Readiness;

class ReadinessTest extends TestCase
{
    public function test_a_well_described_tool_scores_full_marks(): void
    {
        $tool = DefinedTool::make('invoices_list', 'read', 'List invoices, newest first. Filter by status or client.', [
            'status' => Field::enum(['draft', 'sent']), 'client_id' => Field::id('Only this client\'s invoices'),
        ], fn () => []);

        $this->assertSame(['score' => 100, 'issues' => []], Readiness::tool($tool));
    }

    public function test_issues_cost_points_and_say_what_to_fix(): void
    {
        $route = Readiness::tool(DefinedTool::make('invoices_show', 'read', 'GET api/invoices/{id}', ['id' => Field::id()->required()], fn () => []));
        $this->assertSame(65, $route['score']);
        $this->assertCount(2, $route['issues']);

        $delete = Readiness::tool(DefinedTool::make('Wipe-All', 'delete', '', [], fn () => []));
        $this->assertSame(35, $delete['score']);
        $this->assertCount(3, $delete['issues']);
    }

    public function test_the_server_report_averages_tools_and_flags_duplicates(): void
    {
        $good = DefinedTool::make('a_list', 'read', 'List every a there is, newest first.', [], fn () => []);
        $bad = DefinedTool::make('b_list', 'read', 'List.', [], fn () => []);

        $report = Readiness::server([$good, $bad, $good]);
        $this->assertSame(93, $report['score']);
        $this->assertSame(['a_list', 'b_list'], array_keys($report['tools']));
        $this->assertCount(1, $report['issues']);
    }

    public function test_the_scan_command_can_gate_ci(): void
    {
        \Routewise\Routewise::register(fn () => [DefinedTool::make('b_list', 'read', 'List.', [], fn () => [])]);

        $this->artisan('routewise:scan')->expectsOutputToContain('overall score 85/100')->assertSuccessful();
        $this->artisan('routewise:scan', ['--min' => 90])->assertFailed();
    }
}
