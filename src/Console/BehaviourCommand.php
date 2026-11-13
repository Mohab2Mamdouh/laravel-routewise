<?php

namespace Routewise\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Routewise\Behaviour\Runner;
use Routewise\Behaviour\Scenario;
use Routewise\Platform\Reporter;
use Routewise\Routewise;
use Throwable;

class BehaviourCommand extends Command
{
    protected $signature = 'routewise:behaviour
        {--filter= : Only scenarios whose name or prompt contains this}
        {--no-report : Do not send the result to the control plane}
        {--json : Print the result as JSON}';

    protected $description = 'Ask a model each scenario\'s prompt and check which tools it calls (no tool is run)';

    public function handle(Runner $runner, Reporter $reporter): int
    {
        $path = (string) config('routewise.behaviour.path', base_path('routewise'));
        try {
            $scenarios = $this->scenarios($path);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        if ($scenarios === []) {
            $this->warn("No scenarios found in {$path}. Add a PHP file there that returns Routewise\\Behaviour\\Scenario objects.");

            return self::FAILURE;
        }

        $tools = Routewise::tools();
        if ($tools === []) {
            $this->error('The app has no tools to offer. Register tool sets or enable routewise.routes first.');

            return self::FAILURE;
        }

        try {
            $run = $runner->run($scenarios, $tools, $this->option('json') ? null : function (array $result) {
                $this->line(($result['passed'] ? '<info>  pass</info>  ' : '<error>  FAIL</error>  ').$result['name']);
                foreach ($result['failures'] as $failure) {
                    $this->line("          {$failure}");
                }
            });
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line(json_encode($run, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $total = $run['passed'] + $run['failed'];
            $this->newLine();
            $this->line("{$run['passed']} of {$total} passed on {$run['model']} with ".count($tools).' tools on offer. '
                .number_format($run['input_tokens']).' tokens in, '.number_format($run['output_tokens']).' out.');
        }

        if (! $this->option('no-report') && $reporter->enabled()) {
            try {
                $reporter->behaviour($run);
            } catch (Throwable $e) {
                $this->warn('The result was not sent to the control plane: '.$e->getMessage());
            }
        }

        return $run['failed'] === 0 ? self::SUCCESS : self::FAILURE;
    }

    /** @return array<int, Scenario> */
    private function scenarios(string $path): array
    {
        if (! is_dir($path)) {
            return [];
        }
        $scenarios = [];
        foreach (File::allFiles($path) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            foreach ((array) require $file->getPathname() as $scenario) {
                if (! $scenario instanceof Scenario) {
                    throw new \RuntimeException($file->getRelativePathname().' must return an array of '.Scenario::class.' objects.');
                }
                $scenarios[] = $scenario;
            }
        }
        $filter = (string) $this->option('filter');

        return $filter === '' ? $scenarios : array_values(array_filter($scenarios,
            fn (Scenario $s) => Str::contains($s->name().' '.$s->text(), $filter, ignoreCase: true)));
    }
}
