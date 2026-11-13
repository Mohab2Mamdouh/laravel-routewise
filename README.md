# Routewise for Laravel

Turn a Laravel app into a safe MCP server for AI clients (claude.ai, Claude Code, any MCP host),
on top of [`laravel/mcp`](https://github.com/laravel/mcp). No OpenAPI spec needed: the app's own
code is the source of truth.

- **Tools as data.** One `Field` declaration gives the JSON Schema the model sees *and* the validation rules the call is checked against.
- **Kinds.** Every tool is `read`, `write`, `delete` or `external`. The kind sets the MCP annotations; delete tools always ask for confirmation.
- **Routes as tools.** Existing routes become tools: arguments come from the path and the FormRequest, and a call runs through the route's own middleware, validation and policies as the signed-in user.
- **Readiness scan.** `php artisan routewise:scan` scores how well a model can use each tool and says what to fix. `--min=80` gates CI.
- **Call log.** Every call fires `ToolCalled` (tool, outcome, duration, argument *names*, user). Listen to it, or send it to the control plane.
- **Rules and approvals.** Block a kind or a tool in config, or let the control plane decide per kind and per tool: allow, ask a person first, or block.
- **Result view.** Read results render as cards and tables in hosts that support MCP Apps.

## Install

```bash
composer require routewise/laravel
php artisan vendor:publish --tag=routewise-config
```

## Define tools

```php
use Routewise\DefinedTool as T;
use Routewise\Field as F;

class InvoiceTools
{
    public function all(): array
    {
        return [
            T::make('invoices_list', 'read', 'List invoices, newest first. Filter by status.', [
                'status' => F::enum(['draft', 'sent', 'paid']),
                'limit' => F::int('How many to return')->min(1)->max(100)->default(20),
            ], fn (array $a) => Invoice::when($a['status'] ?? null, fn ($q, $s) => $q->where('status', $s))->latest()->limit($a['limit'])->get()),

            T::make('invoice_delete', 'delete', 'Delete an invoice permanently.', ['id' => F::id('The invoice')->required()],
                fn (array $a) => ['deleted' => Invoice::findOrFail($a['id'])->delete()]),
        ];
    }
}
```

Register the set (a service provider's `boot`) and hand the list to your server:

```php
Routewise::register(InvoiceTools::class);

class AppServer extends \Laravel\Mcp\Server
{
    protected array $resources = [\Routewise\ResultView::class];

    public function __construct(\Laravel\Mcp\Server\Contracts\Transport $transport)
    {
        parent::__construct($transport);
        $this->tools = \Routewise\Routewise::tools();
    }
}
```

A handler returns anything JSON-encodable. Throw a `RuntimeException` to tell the model what went wrong.

## Routes as tools

```php
// config/routewise.php
'routes' => ['enabled' => true, 'include' => ['api/*'], 'exclude' => ['api/admin/*']],
```

| From | Becomes |
|---|---|
| HTTP method | kind: GET = read, DELETE = delete, the rest = write |
| Route name `invoices.index` | tool name `invoices_index` |
| Path parameters | required arguments |
| The action's FormRequest `rules()` | arguments (type, required, enum, min/max) |
| `#[AgentTool(description: ...)]` or the docblock's first line | description |

```php
#[AgentTool(description: 'Publish a draft post so readers can see it.', fields: ['post' => 'The post id'])]
public function publish(Post $post) { ... }

#[AgentTool(hidden: true)]   // never listed
public function export() { ... }
```

Limits: only routes that answer JSON work; nested rules (`address.city`) are not listed as arguments
(they are still validated by the route); `rules()` that need the live request are skipped.

Turning this on gives the signed-in user's AI client every matching route, with that user's permissions.
Start with a narrow `include`.

## OAuth registration

`laravel/mcp` checks redirect URIs by prefix. `Routewise\Http\RestrictOAuthRedirects` accepts only the
exact callbacks in `routewise.exact_redirect_uris`, plus http loopback addresses:

```php
Route::middleware(RestrictOAuthRedirects::class)->group(fn () => Mcp::oauthRoutes());
```

## Control plane (optional)

```env
ROUTEWISE_URL=https://your-control-plane.example
ROUTEWISE_TOKEN=project-token
```

`php artisan routewise:sync` (run on deploy) sends the tool catalog with its scores; calls are sent after each
response. Only metadata leaves the app: results are never sent, error messages only with
`ROUTEWISE_SEND_ERRORS=true`, and argument values only for approvals with `ROUTEWISE_APPROVAL_VALUES=true`.

## Rules and approvals

Hard rules live in the app and can't be overridden from anywhere:

```php
// config/routewise.php
'policy' => ['kinds' => ['delete' => 'block'], 'tools' => ['payroll_run' => 'block']],
```

With the control plane connected, its dashboard sets a rule per kind and per tool: **Allow**, **Ask first** or **Block**.
A call to an "Ask first" tool does not run. The model is told it needs approval, an approver decides on the
dashboard, and the same call (same tool, user and arguments) then runs once.

- Approvers see the tool, the user id and the argument names. Set `ROUTEWISE_APPROVAL_VALUES=true` to show them the values too; that is the one case where argument values leave the app.
- A retry is matched to its approval by an HMAC of the arguments made with your `APP_KEY`, so the control plane can't recover the values from it.
- Rules are re-read every 60 seconds (`platform.policy_ttl`). If the control plane is down, the last rules keep applying and "Ask first" tools don't run.

Every call's outcome (`ok`, `failed`, `blocked`, `pending`, `denied`) is on the `ToolCalled` event and in the call log.

## Testing

```bash
composer install && composer test
```
