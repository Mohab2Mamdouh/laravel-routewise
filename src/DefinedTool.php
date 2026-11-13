<?php

namespace Routewise;

use Routewise\Events\ToolCalled;
use Routewise\Platform\Guard;
use Closure;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Throwable;

/**
 * A tool defined as data (name, kind, description, fields, handler) instead of
 * one class per tool. Results are JSON text; any exception becomes a tool error
 * carrying the message, so the model sees what went wrong and can correct itself.
 *
 * The kind drives everything a host needs to know about safety:
 * read (no side effects), write, delete (asks for confirmation), external (reads the open web).
 */
class DefinedTool extends Tool
{
    public const KINDS = ['read', 'write', 'delete', 'external'];

    /**
     * @param  'read'|'write'|'delete'|'external'  $kind
     * @param  array<string, Field>  $fields
     * @param  Closure(array<string, mixed>): mixed  $handler
     */
    public function __construct(string $name, private string $kind, string $description, private array $fields, private Closure $handler)
    {
        if (! in_array($kind, self::KINDS, true)) {
            throw new InvalidArgumentException("Tool {$name}: kind must be one of ".implode(', ', self::KINDS).'.');
        }

        $this->name = $name;
        $this->title = Str::headline($name);
        // Every delete tool asks for the same thing, so none reads as safe to run unasked.
        $this->description = $kind === 'delete' && ! preg_match('/confirm|ask the user/i', $description)
            ? rtrim($description).' Confirm with the user first.'
            : $description;

        // MCP Apps hosts (claude.ai) draw read results with ResultView; others ignore this.
        if (in_array($kind, ['read', 'external'], true) && config('routewise.result_view', true)) {
            $this->setMeta('ui', ['resourceUri' => ResultView::URI, 'visibility' => ['model', 'app']]);
        }
    }

    public static function make(string $name, string $kind, string $description, array $fields, Closure $handler): self
    {
        return new self($name, $kind, $description, $fields, $handler);
    }

    public function kind(): string
    {
        return $this->kind;
    }

    /** @return array<string, Field> */
    public function fields(): array
    {
        return $this->fields;
    }

    public function schema(JsonSchema $schema): array
    {
        return array_map(fn (Field $f) => $f->schema($schema), $this->fields);
    }

    public function annotations(): array
    {
        return [
            'readOnlyHint' => in_array($this->kind, ['read', 'external'], true),
            'destructiveHint' => $this->kind === 'delete',
            'idempotentHint' => $this->kind === 'read',
            'openWorldHint' => $this->kind === 'external',
        ];
    }

    public function handle(Request $request): Response
    {
        $started = hrtime(true);
        $error = null;
        $outcome = 'ok';

        try {
            $rules = [];
            foreach ($this->fields as $key => $field) {
                $rules += $field->rules($key);
            }
            $args = Validator::validate($request->all(), $rules);
            foreach ($this->fields as $key => $field) {
                if (array_key_exists($key, $args)) {
                    $args[$key] = $field->cast($args[$key]);
                } elseif ($field->hasDefault()) {
                    $args[$key] = $field->defaultValue();
                }
            }

            // The workspace's rules: blocked tools stop here, and tools that need approval wait for it.
            if ($stop = app(Guard::class)->check($this, $args)) {
                $outcome = $stop['outcome'];

                return Response::error($error = $stop['message']);
            }

            return self::result(($this->handler)($args));
        } catch (ValidationException $e) {
            $outcome = 'failed';

            return Response::error($error = collect($e->errors())->flatten()->implode(' '));
        } catch (Throwable $e) {
            // Remote/API and input errors are expected; only report real bugs.
            if (! $e instanceof \RuntimeException && ! $e instanceof \InvalidArgumentException) {
                report($e);
            }

            $outcome = 'failed';

            return Response::error($error = $e->getMessage());
        } finally {
            // Argument names only: values can hold personal data and never leave the app.
            event(new ToolCalled(
                $this->name,
                $this->kind,
                $error === null,
                $error,
                (int) round((hrtime(true) - $started) / 1e6),
                array_keys($request->all()),
                auth()->id(),
                $outcome,
            ));
        }
    }

    public static function result(mixed $data): Response
    {
        return Response::text(is_string($data) ? $data : json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
    }
}
