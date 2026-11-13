<?php

namespace Routewise\Console;

use Routewise\Routewise;
use Routewise\Readiness;
use Illuminate\Console\Command;

class ScanCommand extends Command
{
    protected $signature = 'routewise:scan {--json : Print the report as JSON} {--min= : Fail (exit 1) when the overall score is below this, for CI}';

    protected $description = 'Score how ready the app\'s tools are for an AI agent to use';

    public function handle(): int
    {
        $report = Readiness::server(Routewise::tools());

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } elseif ($report['tools'] === []) {
            $this->warn('No tools found. Register tool sets with Routewise::register() or enable routewise.routes.');
        } else {
            $this->table(['Tool', 'Kind', 'Score', 'Issues'], collect($report['tools'])
                ->sortBy('score')
                ->map(fn ($t, $name) => [$name, $t['kind'], $t['score'], implode("\n", $t['issues']) ?: '-'])
                ->values()->all());
            foreach ($report['issues'] as $issue) {
                $this->warn($issue);
            }
            $this->info(count($report['tools'])." tools, overall score {$report['score']}/100.");
        }

        $min = $this->option('min');

        return $min !== null && $report['score'] < (int) $min ? self::FAILURE : self::SUCCESS;
    }
}
