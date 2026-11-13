<?php

namespace Routewise\Console;

use Routewise\Routewise;
use Routewise\Platform\Reporter;
use Illuminate\Console\Command;
use Throwable;

class SyncCommand extends Command
{
    protected $signature = 'routewise:sync';

    protected $description = 'Send the tool catalog and its readiness scores to the control plane (run on deploy)';

    public function handle(Reporter $reporter): int
    {
        if (! $reporter->enabled()) {
            $this->error('Set ROUTEWISE_URL and ROUTEWISE_TOKEN first.');

            return self::FAILURE;
        }

        try {
            $tools = Routewise::tools();
            $reporter->sync($tools);
        } catch (Throwable $e) {
            $this->error('Sync failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info(count($tools).' tools sent.');

        return self::SUCCESS;
    }
}
