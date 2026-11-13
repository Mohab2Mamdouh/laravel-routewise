<?php

namespace Routewise\Behaviour;

use Closure;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * One thing a person might ask, and what a model should do with the tools when they do:
 *
 *     Scenario::prompt('Delete invoice 42')->expects('invoice_delete', ['id' => 42]);
 *     Scenario::prompt('Which invoices are unpaid?')->expects('invoices_list', ['status' => 'unpaid'])->never('invoice_delete');
 *     Scenario::prompt('Tell me a joke')->expectsNoTool();
 *
 * Only the model's choice is checked: which tools it calls and with which arguments. No tool is run.
 */
final class Scenario
{
    private ?string $name = null;

    /** @var array<string, array<string, mixed>> tool => the arguments it must be called with */
    private array $expected = [];

    /** @var array<int, string> */
    private array $never = [];

    private bool $noTool = false;

    private function __construct(private string $prompt) {}

    public static function prompt(string $prompt): self
    {
        if (trim($prompt) === '') {
            throw new InvalidArgumentException('A scenario needs a prompt.');
        }

        return new self($prompt);
    }

    public function named(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    /**
     * The model must call this tool. Each listed argument must have that value; other arguments are free.
     * A value can be a closure that takes what the model passed and returns whether it is acceptable.
     *
     * @param  array<string, mixed>  $arguments
     */
    public function expects(string $tool, array $arguments = []): self
    {
        $this->expected[$tool] = $arguments;

        return $this;
    }

    /** The model must not call any of these tools. */
    public function never(string ...$tools): self
    {
        $this->never = [...$this->never, ...$tools];

        return $this;
    }

    /** The model must answer in words, or ask a question, without calling a tool. */
    public function expectsNoTool(): self
    {
        $this->noTool = true;

        return $this;
    }

    public function name(): string
    {
        return $this->name ?? Str::limit($this->prompt, 70);
    }

    public function text(): string
    {
        return $this->prompt;
    }

    /** What the scenario asks for, in words, for reports. */
    public function expectation(): string
    {
        $parts = [];
        foreach ($this->expected as $tool => $arguments) {
            $shown = implode(', ', array_map(
                fn ($key, $value) => $key.': '.($value instanceof Closure ? '(checked)' : self::show($value)),
                array_keys($arguments), $arguments,
            ));
            $parts[] = "calls {$tool}".($shown !== '' ? " with {$shown}" : '');
        }
        if ($this->noTool) {
            $parts[] = 'calls no tool';
        }
        if ($this->never !== []) {
            $parts[] = 'never calls '.implode(', ', $this->never);
        }

        return implode('; ', $parts);
    }

    /**
     * @param  array<int, array{tool: string, arguments: array<string, mixed>}>  $calls  the tool calls the model made
     * @return array<int, string> what was wrong; empty when the scenario passed
     */
    public function check(array $calls): array
    {
        if ($this->expected === [] && $this->never === [] && ! $this->noTool) {
            return ['The scenario checks nothing: add expects(), never() or expectsNoTool().'];
        }

        $failures = [];
        $called = array_column($calls, 'tool');

        if ($this->noTool && $calls !== []) {
            $failures[] = 'Expected no tool call, but the model called '.implode(', ', array_unique($called)).'.';
        }
        foreach (array_intersect($this->never, $called) as $tool) {
            $failures[] = "The model called {$tool}, which it must never call here.";
        }

        foreach ($this->expected as $tool => $arguments) {
            $matching = array_values(array_filter($calls, fn (array $call) => $call['tool'] === $tool));
            if ($matching === []) {
                $failures[] = "Expected a call to {$tool}, but the model ".($calls === [] ? 'called no tool.' : 'called '.implode(', ', array_unique($called)).'.');

                continue;
            }
            // Passing means one call to the tool gets every listed argument right.
            $problems = array_map(fn (array $call) => self::mismatches($tool, $arguments, $call['arguments']), $matching);
            if (! in_array([], $problems, true)) {
                array_push($failures, ...$problems[0]);
            }
        }

        return $failures;
    }

    /**
     * @param  array<string, mixed>  $expected
     * @param  array<string, mixed>  $actual
     * @return array<int, string>
     */
    private static function mismatches(string $tool, array $expected, array $actual): array
    {
        $problems = [];
        foreach ($expected as $key => $want) {
            $given = array_key_exists($key, $actual);
            $got = $actual[$key] ?? null;

            $ok = match (true) {
                $want instanceof Closure => (bool) $want($got),
                ! $given => false,
                // "42" and 42 are the same answer; the tool's own validation casts it.
                is_numeric($want) && is_numeric($got) => $want == $got,
                default => $want === $got,
            };
            if (! $ok) {
                $problems[] = $given
                    ? "{$tool} was called with {$key}: ".self::show($got).($want instanceof Closure ? ', which the scenario does not accept.' : ', expected '.self::show($want).'.')
                    : "{$tool} was called without {$key}".($want instanceof Closure ? '.' : ' (expected '.self::show($want).').');
            }
        }

        return $problems;
    }

    private static function show(mixed $value): string
    {
        return Str::limit(is_string($value) ? '"'.$value.'"' : (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 120);
    }
}
