<?php

namespace Routewise;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\AppResource;
use Laravel\Mcp\Server\Attributes\Description;

/**
 * One generic MCP App view: hosts that support MCP Apps (claude.ai) render a
 * tool's JSON result as cards and tables instead of plain text. The model still
 * gets the same JSON text; this only changes what the person sees.
 *
 * Add it to your server's $resources.
 */
#[Description('Renders a tool result as cards and tables.')]
class ResultView extends AppResource
{
    public const URI = 'ui://routewise/result';

    protected string $uri = self::URI;

    public function handle(Request $request): Response
    {
        return Response::view('routewise::result-view', ['title' => config('routewise.name') ?: config('app.name', 'Tools')]);
    }
}
