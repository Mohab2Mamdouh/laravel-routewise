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

        // Approval rules set on the control plane are re-read this often (seconds).
        'policy_ttl' => 60,

        // Show approvers the argument values of a call that waits for approval.
        // Off: they see the tool, the user and the argument names only.
        'approval_values' => env('ROUTEWISE_APPROVAL_VALUES', false),
    ],

    /*
     | Behaviour tests (`php artisan routewise:behaviour`): each scenario's prompt is put to a model with the
     | app's tools on offer, and the tools it calls are checked. No tool is run. Needs the Anthropic SDK
     | (`composer require --dev anthropic-ai/sdk`) and an API key; every run costs tokens.
     */
    'behaviour' => [
        // PHP files here return arrays of Routewise\Behaviour\Scenario objects.
        'path' => base_path('routewise'),
        'api_key' => env('ANTHROPIC_API_KEY'),
        'model' => env('ROUTEWISE_BEHAVIOUR_MODEL', 'claude-opus-5-5'),
        // low, medium, high, xhigh or max: how hard the model thinks before choosing.
        'effort' => env('ROUTEWISE_BEHAVIOUR_EFFORT', 'medium'),
        // The system prompt the model gets. Null uses a short default naming the app; set it to what your
        // MCP server's own instructions say, so the test matches what real clients see.
        'system' => null,
    ],

    /*
     | Hard rules, checked before the control plane's: 'block' a kind or a tool
     | here and no dashboard setting can allow it. Anything else is 'allow'.
     | Requiring approval is set on the control plane, where approvers decide.
     */
    'policy' => [
        'kinds' => [
            // 'delete' => 'block',
        ],
        'tools' => [
            // 'invoice_delete' => 'block',
        ],
    ],

];
