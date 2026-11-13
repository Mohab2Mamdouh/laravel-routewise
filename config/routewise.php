<?php

return [

    // Shown as the title of the result view and as the app's name in the control plane. Defaults to app.name.
    'name' => env('ROUTEWISE_NAME'),

    // Render read results as cards and tables in hosts that support MCP Apps (claude.ai).
    // Needs Routewise\ResultView in your server's $resources.
    'result_view' => true,

    /*
     | Routes as tools. Off by default: turning it on exposes every matching
     | route to the signed-in user's AI client, with that user's permissions.
     | A call runs through the route's own middleware, validation and policies.
     */
    'routes' => [
        'enabled' => false,
        'include' => ['api/*'],
        'exclude' => [],
        'methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'],
    ],

    /*
     | OAuth client registration accepts exactly these redirect URIs, plus http
     | loopback addresses (Routewise\Http\RestrictOAuthRedirects). laravel/mcp's
     | own redirect_domains is a looser prefix check.
     */
    'exact_redirect_uris' => [
        'https://claude.ai/api/mcp/auth_callback',
        'https://claude.com/api/mcp/auth_callback',
    ],

    /*
     | The control plane (optional). With a project token set, `routewise:sync`
     | sends the tool catalog and every call is logged there: tool, outcome,
     | duration, argument names and user id. Argument values and results are
     | never sent; error messages only when send_errors is on.
     */
    'platform' => [
        'url' => env('ROUTEWISE_URL'),
        'token' => env('ROUTEWISE_TOKEN'),
        'send_errors' => env('ROUTEWISE_SEND_ERRORS', false),
    ],

];
