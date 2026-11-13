<?php

namespace Routewise\Behaviour;

use Routewise\DefinedTool;

/** Puts each scenario's prompt to the model with the app's tools on offer, and grades what it chose. */
final class Runner
{
    public function __construct(private Model $model) {}

    /**
     * @param  array<int, Scenario>  $scenarios
     * @param  array<int, DefinedTool>  $tools
     * @param  callable(array<string, mixed>): void|null  $progress  called with each result as it comes in
     * @return array{model: string, passed: int, failed: int, input_tokens: int, output_tokens: int, scenarios: array<int, array<string, mixed>>}
     */
    public function run(array $scenarios, array $tools, ?callable $progress = null): array
    {
        $offered = array_map(fn (DefinedTool $tool) => [
            'name' => $tool->name(),
            'description' => $tool->description(),
            'inputSchema' => self::schema($tool),
        ], $tools);
        $system = (string) (config('routewise.behaviour.system') ?: 'You are an assistant connected to '
            .(config('routewise.name') ?: config('app.name', 'an app')).'. Use its tools to do what the user asks. '
            .'When no tool fits, or the request lacks something a tool needs, say so or ask instead of guessing.');

        $run = ['model' => $this->model->name(), 'passed' => 0, 'failed' => 0, 'input_tokens' => 0, 'output_tokens' => 0, 'scenarios' => []];

        foreach ($scenarios as $scenario) {
            $reply = $this->model->ask($system, $scenario->text(), $offered);
            $failures = $reply->refusal !== null
                ? ["The model declined to answer ({$reply->refusal}), so there was nothing to check."]
                : $scenario->check($reply->calls);

            $result = [
                'name' => $scenario->name(),
                'prompt' => $scenario->text(),
                'passed' => $failures === [],
                'expected' => $scenario->expectation(),
                'called' => $reply->calls,
                'failures' => $failures,
            ];
            $run['scenarios'][] = $result;
            $run[$failures === [] ? 'passed' : 'failed']++;
            $run['input_tokens'] += $reply->inputTokens;
            $run['output_tokens'] += $reply->outputTokens;
            if ($progress) {
                $progress($result);
            }
        }

        return $run;
    }

    /** @return array<string, mixed> the tool's JSON Schema, with an object (not a list) for a tool that takes nothing */
    private static function schema(DefinedTool $tool): array
    {
        $schema = (array) $tool->toArray()['inputSchema'];
        if (empty((array) ($schema['properties'] ?? []))) {
            $schema['properties'] = new \stdClass;
        }

        return ['type' => 'object', ...$schema];
    }
}
