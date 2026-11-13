<?php

namespace AgentTools;

/**
 * How well a model can use a tool from its definition alone: can it tell what
 * the tool does, when to call it, and what each argument means? Each tool starts
 * at 100 and loses points per issue.
 */
final class Readiness
{
    /** @return array{score: int, issues: array<int, string>} */
    public static function tool(DefinedTool $tool): array
    {
        $issues = [];
        $score = 100;
        $lose = function (int $points, string $issue) use (&$issues, &$score) {
            $issues[] = $issue;
            $score -= $points;
        };

        $description = trim($tool->description());
        if ($description === '' || $description === 'Confirm with the user first.') {
            $lose(40, 'No description: the model can only guess from the name.');
        } elseif (preg_match('/^(GET|POST|PUT|PATCH|DELETE) \S+( Confirm with the user first\.)?$/', $description)) {
            $lose(30, 'The description is only the route. Say what the tool does and when to use it.');
        } elseif (mb_strlen($description) < 30) {
            $lose(15, 'The description is too short to say when to use the tool.');
        }

        $name = $tool->name();
        if (! preg_match('/^[a-z][a-z0-9_]*$/', $name)) {
            $lose(10, 'The name should be snake_case (a-z, 0-9, _).');
        }
        if (strlen($name) > 64) {
            $lose(10, 'The name is longer than 64 characters; some hosts reject it.');
        }

        $fields = array_map(fn (Field $f) => $f->toArray(), $tool->fields());
        // An enum explains itself by its values; everything else needs a sentence.
        $bare = array_keys(array_filter($fields, fn ($f) => ! isset($f['description']) && ! isset($f['enum'])));
        if ($bare !== []) {
            $lose(min(25, 5 * count($bare)), 'Arguments without a description: '.implode(', ', $bare).'.');
        }
        if (count($fields) > 15) {
            $lose(10, count($fields).' arguments: models fill long argument lists unreliably. Split the tool.');
        }
        if ($tool->kind() === 'delete' && ! collect($fields)->contains(fn ($f) => $f['required'] ?? false)) {
            $lose(15, 'A delete tool with no required argument can be called without saying what to delete.');
        }

        return ['score' => max(0, $score), 'issues' => $issues];
    }

    /**
     * @param  array<int, DefinedTool>  $tools
     * @return array{score: int, issues: array<int, string>, tools: array<string, array{kind: string, score: int, issues: array<int, string>}>}
     */
    public static function server(array $tools): array
    {
        $report = [];
        $issues = [];
        foreach ($tools as $tool) {
            if (isset($report[$tool->name()])) {
                $issues[] = "Two tools are named {$tool->name()}; only one can be listed.";

                continue;
            }
            $report[$tool->name()] = ['kind' => $tool->kind(), ...self::tool($tool)];
        }
        if (count($report) > 100) {
            $issues[] = count($report).' tools: most models choose worse past about 100. List fewer per user or split the server.';
        }

        return [
            'score' => $report === [] ? 0 : (int) round(array_sum(array_column($report, 'score')) / count($report)),
            'issues' => $issues,
            'tools' => $report,
        ];
    }
}
