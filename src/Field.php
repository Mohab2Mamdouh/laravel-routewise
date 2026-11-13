<?php

namespace AgentTools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;

/**
 * One tool argument, declared once: it renders the JSON Schema the model sees and
 * the Laravel validation rules the call is checked against.
 */
final class Field
{
    private bool $required = false;

    private bool $nullable = false;

    private mixed $default = null;

    private bool $hasDefault = false;

    private ?array $enum = null;

    private int|float|null $min = null;

    private int|float|null $max = null;

    /** @var array<int, mixed> extra validation rules, checked after the type's own */
    private array $extra = [];

    private function __construct(private string $type, private string $description = '', private ?string $items = null) {}

    public static function string(string $description = ''): self { return new self('string', $description); }

    public static function int(string $description = ''): self { return new self('integer', $description); }

    public static function id(string $description = ''): self { return (new self('integer', $description))->min(1); }

    public static function number(string $description = ''): self { return new self('number', $description); }

    public static function bool(string $description = ''): self { return new self('boolean', $description); }

    public static function date(string $description = ''): self { return new self('date', trim('YYYY-MM-DD. '.$description)); }

    public static function url(string $description = ''): self { return new self('url', $description); }

    /** Hours as 1.5, "1:30", "1h30" or "90m". */
    public static function hours(): self { return new self('hours', 'Hours: 1.5, "1:30", "1h30" or "90m"'); }

    public static function enum(array $values, string $description = ''): self { return (new self('string', $description))->in($values); }

    public static function list(string $items, string $description = ''): self { return new self('array', $description, $items); }

    public function required(): self { $this->required = true; return $this; }

    public function nullable(): self { $this->nullable = true; return $this; }

    public function in(array $values): self { $this->enum = $values; return $this; }

    public function min(int|float $v): self { $this->min = $v; return $this; }

    public function max(int|float $v): self { $this->max = $v; return $this; }

    public function default(mixed $v): self { $this->default = $v; $this->hasDefault = true; return $this; }

    /** Extra Laravel validation rules (strings, Rule objects or closures); the schema is unchanged. */
    public function rule(mixed ...$rules): self { $this->extra = [...$this->extra, ...$rules]; return $this; }

    public function describedAs(string $description): self { $this->description = $description; return $this; }

    public function hasDefault(): bool { return $this->hasDefault; }

    public function defaultValue(): mixed { return $this->default; }

    /** What this field is, for the readiness scan and the tool catalog. */
    public function toArray(): array
    {
        return array_filter([
            'type' => $this->type,
            'items' => $this->items,
            'description' => $this->description,
            'required' => $this->required,
            'nullable' => $this->nullable,
            'enum' => $this->enum,
            'min' => $this->min,
            'max' => $this->max,
        ], fn ($v) => $v !== null && $v !== false && $v !== '');
    }

    /** Coerce a validated value to its declared type ("5" → 5), so handlers can compare strictly. */
    public function cast(mixed $v): mixed
    {
        if ($v === null) {
            return null;
        }

        return match ($this->type) {
            'integer' => (int) $v,
            'number' => (float) $v,
            'boolean' => filter_var($v, FILTER_VALIDATE_BOOLEAN),
            'array' => $this->items === 'integer' ? array_map('intval', $v) : $v,
            default => $v,
        };
    }

    public function schema(JsonSchema $s): Type
    {
        $t = match ($this->type) {
            'integer' => $s->integer(),
            'number' => $s->number(),
            'boolean' => $s->boolean(),
            'array' => $s->array()->items(match ($this->items) {
                'integer' => $s->integer(),
                default => $this->enum ? $s->string()->enum($this->enum) : $s->string(),
            }),
            default => $s->string(),
        };
        if ($this->enum && $this->type !== 'array') {
            $t->enum($this->enum);
        }
        if ($this->type === 'date') {
            $t->pattern('^\d{4}-\d{2}-\d{2}$');
        }
        if ($this->min !== null && in_array($this->type, ['integer', 'number'], true)) {
            $t->min($this->min);
        }
        if ($this->max !== null && in_array($this->type, ['integer', 'number'], true)) {
            $t->max($this->max);
        }
        if ($this->description !== '') {
            $t->description($this->description);
        }
        if ($this->hasDefault && $this->default !== null) {
            $t->default($this->default);
        }
        if ($this->nullable) {
            $t->nullable();
        }
        if ($this->required) {
            $t->required();
        }

        return $t;
    }

    /** @return array<string, array<int, mixed>> rules keyed by field (and field.* for lists) */
    public function rules(string $key): array
    {
        $rules = [$this->required ? 'required' : 'sometimes'];
        if ($this->nullable) {
            $rules[] = 'nullable';
        }
        $rules = [...$rules, ...match ($this->type) {
            'integer' => ['integer'],
            'number' => ['numeric'],
            'boolean' => ['boolean'],
            'date' => ['date_format:Y-m-d'],
            'url' => ['url:http,https'],
            'array' => ['array'],
            'hours' => [fn ($attr, $v, $fail) => is_string($v) || is_int($v) || is_float($v) ? null : $fail("{$attr} must be a number or a string like 1:30")],
            default => ['string'],
        }];
        if ($this->enum && $this->type !== 'array') {
            $rules[] = 'in:'.implode(',', $this->enum);
        }
        if ($this->min !== null) {
            $rules[] = 'min:'.$this->min;
        }
        if ($this->max !== null) {
            $rules[] = 'max:'.$this->max;
        }
        if ($this->required && $this->type === 'string') {
            $rules[] = 'filled';
        }
        $out = [$key => [...$rules, ...$this->extra]];
        if ($this->type === 'array') {
            $out[$key.'.*'] = $this->items === 'integer'
                ? ['integer', 'min:1']
                : array_filter(['string', $this->enum ? 'in:'.implode(',', $this->enum) : null]);
        }

        return $out;
    }
}
