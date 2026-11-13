<?php

namespace AgentTools;

use AgentTools\Attributes\AgentTool;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Str;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionMethod;
use ReflectionNamedType;
use RuntimeException;
use Throwable;

/**
 * Turns the app's own routes into tools, so no OpenAPI spec is needed:
 * the HTTP method gives the kind, the route name the tool name, the path
 * parameters and the FormRequest's rules the arguments. A call is dispatched
 * through the router as the signed-in user, so the route's own middleware,
 * validation and policies still decide what happens.
 */
final class RouteTools
{
    public function __construct(private Router $router) {}

    /** @return array<int, DefinedTool> */
    public function all(): array
    {
        $tools = [];
        foreach ($this->router->getRoutes()->getRoutes() as $route) {
            $tool = $this->tool($route);
            if ($tool !== null) {
                $tools[$tool->name()] ??= $tool;
            }
        }

        return array_values($tools);
    }

    private function tool(Route $route): ?DefinedTool
    {
        $uri = ltrim($route->uri(), '/');
        $method = collect($route->methods())->first(fn ($m) => in_array($m, (array) config('agent-tools.routes.methods', ['GET', 'POST', 'PUT', 'PATCH', 'DELETE']), true));
        if ($method === null
            || ! Str::is((array) config('agent-tools.routes.include', ['api/*']), $uri)
            || Str::is((array) config('agent-tools.routes.exclude', []), $uri)) {
            return null;
        }

        $action = $this->reflect($route);
        $meta = $action?->getAttributes(AgentTool::class)[0] ?? null;
        $meta = $meta?->newInstance();
        if ($meta?->hidden) {
            return null;
        }

        $params = $route->parameterNames();
        $fields = [];
        foreach ($params as $name) {
            $field = preg_match('/(^|_)id$|Id$/', $name) ? Field::id() : Field::string();
            $fields[$name] = str_contains($route->uri(), '{'.$name.'?}') ? $field : $field->required();
        }
        foreach ($this->rules($action) as $key => $field) {
            $fields[$key] ??= $field;
        }
        foreach ($meta?->fields ?? [] as $key => $description) {
            if (isset($fields[$key])) {
                $fields[$key]->describedAs($description);
            }
        }

        $kind = $meta?->kind ?? match ($method) {
            'GET' => 'read',
            'DELETE' => 'delete',
            default => 'write',
        };

        return DefinedTool::make(
            $meta?->name ?? self::name($route, $method),
            $kind,
            // Without a description the route is listed as "GET api/…", which the readiness scan flags.
            $meta?->description ?? self::summary($action) ?? $method.' '.$uri,
            $fields,
            fn (array $args) => $this->dispatch($route, $method, $args),
        );
    }

    private static function name(Route $route, string $method): string
    {
        $name = $route->getName()
            ?? strtolower($method).'_'.preg_replace('/\{[^}]+\}/', '', $route->uri());
        $name = preg_replace('/^api_/', '', trim(preg_replace('/[^a-z0-9]+/', '_', strtolower($name)), '_'));

        return substr($name, 0, 64);
    }

    private function reflect(Route $route): ?ReflectionFunctionAbstract
    {
        try {
            $uses = $route->getAction('uses');
            if ($uses instanceof Closure) {
                return new ReflectionFunction($uses);
            }
            $class = $route->getControllerClass();

            return $class ? new ReflectionMethod($class, $route->getActionMethod() === $class ? '__invoke' : $route->getActionMethod()) : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** First line of the action's docblock, when it has one. */
    private static function summary(?ReflectionFunctionAbstract $action): ?string
    {
        $doc = $action?->getDocComment();
        if (! $doc) {
            return null;
        }
        foreach (preg_split('/\R/', $doc) as $line) {
            $line = trim($line, " \t/*");
            if ($line !== '' && $line[0] !== '@') {
                return $line;
            }
        }

        return null;
    }

    /** @return array<string, Field> the FormRequest's top-level rules as fields */
    private function rules(?ReflectionFunctionAbstract $action): array
    {
        $class = null;
        foreach ($action?->getParameters() ?? [] as $parameter) {
            $type = $parameter->getType();
            if ($type instanceof ReflectionNamedType && is_subclass_of($type->getName(), FormRequest::class)) {
                $class = $type->getName();
                break;
            }
        }
        if ($class === null || ! method_exists($class, 'rules')) {
            return [];
        }

        try {
            // Built by hand: resolving it from the container would validate the current request.
            $request = (new $class)->setContainer(app());
            $rules = (array) app()->call([$request, 'rules']);
        } catch (Throwable) {
            // rules() that depend on the live request (route model, user) can't be read ahead of a call.
            return [];
        }

        $fields = [];
        foreach ($rules as $key => $rule) {
            if (str_contains($key, '.') || str_contains($key, '*')) {
                continue;
            }
            $fields[$key] = self::field(self::flat($rule), isset($rules[$key.'.*']) ? self::flat($rules[$key.'.*']) : []);
        }

        return $fields;
    }

    /** @return array<int, string> rule names with their parameters, closures and unknown objects dropped */
    private static function flat(mixed $rule): array
    {
        $out = [];
        foreach (is_string($rule) ? explode('|', $rule) : (array) $rule as $r) {
            if (is_string($r)) {
                $out[] = $r;
            } elseif (is_object($r) && method_exists($r, '__toString')) {
                $out[] = (string) $r;
            }
        }

        return $out;
    }

    /**
     * @param  array<int, string>  $rules
     * @param  array<int, string>  $itemRules
     */
    private static function field(array $rules, array $itemRules): Field
    {
        $has = fn (string ...$names) => collect($rules)->contains(fn ($r) => in_array(strtolower(Str::before($r, ':')), $names, true));
        $arg = fn (string $name) => ($r = collect($rules)->first(fn ($r) => strtolower(Str::before($r, ':')) === $name)) && str_contains($r, ':') ? Str::after($r, ':') : null;

        $numeric = $has('integer', 'int', 'numeric', 'decimal');
        $field = match (true) {
            $has('integer', 'int') => Field::int(),
            $has('numeric', 'decimal') => Field::number(),
            $has('boolean', 'bool', 'accepted', 'declined') => Field::bool(),
            $has('array', 'list') => Field::list(collect($itemRules)->contains(fn ($r) => in_array(strtolower($r), ['integer', 'int'], true)) ? 'integer' : 'string'),
            $arg('date_format') === 'Y-m-d' => Field::date(),
            $has('date', 'date_format') => Field::string('A date'.($arg('date_format') ? ' in the format '.$arg('date_format') : '')),
            $has('url', 'active_url') => Field::url(),
            $has('email') => Field::string('An email address'),
            default => Field::string(),
        };

        if ($has('required')) {
            $field->required();
        }
        if ($has('nullable')) {
            $field->nullable();
        }
        if (($in = $arg('in')) !== null) {
            $field->in(array_map(fn ($v) => trim($v, '"'), str_getcsv($in, ',', '"', '')));
        }
        // min/max mean length for strings and size for arrays; only numbers have a schema equivalent.
        if ($numeric && is_numeric($arg('min'))) {
            $field->min($arg('min') + 0);
        }
        if ($numeric && is_numeric($arg('max'))) {
            $field->max($arg('max') + 0);
        }

        return $field;
    }

    /** Runs the route as the signed-in user and returns its decoded response. */
    private function dispatch(Route $route, string $method, array $args): mixed
    {
        $path = $route->uri();
        foreach ($route->parameterNames() as $name) {
            $value = $args[$name] ?? null;
            unset($args[$name]);
            $path = str_replace(['{'.$name.'}', '{'.$name.'?}'], $value === null ? '' : rawurlencode((string) $value), $path);
        }

        $original = app('request');
        $sub = Request::create(
            $original->getSchemeAndHttpHost().'/'.trim(preg_replace('#/+#', '/', $path), '/'),
            $method,
            $args,
            server: ['HTTP_ACCEPT' => 'application/json'],
        );

        // The MCP endpoint authenticated the caller on its own guard; the route may check another one.
        $user = Auth::user();
        if ($user !== null) {
            $sub->setUserResolver(fn () => $user);
            foreach ($route->gatherMiddleware() as $middleware) {
                if (is_string($middleware) && preg_match('/(^|\\\\)(auth|Authenticate)(:(.+))?$/', $middleware, $m)) {
                    foreach (explode(',', $m[4] ?? '') as $guard) {
                        Auth::guard($guard !== '' ? $guard : null)->setUser($user);
                    }
                }
            }
        }

        try {
            app()->instance('request', $sub);
            Facade::clearResolvedInstance('request');
            $response = $this->router->dispatch($sub);
        } catch (AuthorizationException|AuthenticationException $e) {
            throw new RuntimeException($e->getMessage() ?: 'This action is unauthorized.');
        } finally {
            app()->instance('request', $original);
            Facade::clearResolvedInstance('request');
        }

        $body = (string) $response->getContent();
        $data = json_decode($body, true);
        $status = $response->getStatusCode();
        if ($status >= 400) {
            throw new RuntimeException(is_array($data) && isset($data['message']) ? (string) $data['message'] : "The route answered {$status}.");
        }
        if ($status >= 300) {
            // A redirect is what a web route does on success or on a failed form; it carries no data to return.
            throw new RuntimeException("The route redirected ({$status}) instead of returning data; only JSON routes work as tools.");
        }

        return $body === '' ? ['ok' => true, 'status' => $status] : ($data ?? $body);
    }
}
