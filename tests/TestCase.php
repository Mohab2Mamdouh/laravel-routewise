<?php

namespace Routewise\Tests;

use Routewise\Routewise;
use Routewise\RoutewiseServiceProvider;
use Routewise\DefinedTool;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\McpServiceProvider;
use Orchestra\Testbench\TestCase as Testbench;

abstract class TestCase extends Testbench
{
    protected function getPackageProviders($app): array
    {
        return [McpServiceProvider::class, RoutewiseServiceProvider::class];
    }

    protected function tearDown(): void
    {
        Routewise::flush();
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
