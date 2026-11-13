<?php

namespace AgentTools\Tests;

use AgentTools\AgentTools;
use AgentTools\AgentToolsServiceProvider;
use AgentTools\DefinedTool;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\McpServiceProvider;
use Orchestra\Testbench\TestCase as Testbench;

abstract class TestCase extends Testbench
{
    protected function getPackageProviders($app): array
    {
        return [McpServiceProvider::class, AgentToolsServiceProvider::class];
    }

    protected function tearDown(): void
    {
        AgentTools::flush();
        parent::tearDown();
    }

    /** @return array{0: bool, 1: mixed} whether the call succeeded, and its decoded result (or the error text) */
    protected function invoke(DefinedTool $tool, array $args = []): array
    {
        $response = $tool->handle(new Request($args));
        $text = (string) $response->content();

        return [! $response->isError(), json_decode($text, true) ?? $text];
    }
}
