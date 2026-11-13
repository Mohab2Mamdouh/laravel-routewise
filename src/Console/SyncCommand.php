<?php

namespace AgentTools\Console;

use AgentTools\AgentTools;
use AgentTools\Platform\Reporter;
use Illuminate\Console\Command;
use Throwable;

class SyncCommand extends Command
{
    protected $signature = 'agent-tools:sync';

    protected $description = 'Send the tool catalog and its readiness scores to the control plane (run on deploy)';

    public function handle(Reporter $reporter): int
    {
        if (! $reporter->enabled()) {
            $this->error('Set AGENT_TOOLS_URL and AGENT_TOOLS_TOKEN first.');

            return self::FAILURE;
        }

        try {
            $tools = AgentTools::tools();
            $reporter->sync($tools);
        } catch (Throwable $e) {
            $this->error('Sync failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info(count($tools).' tools sent.');

        return self::SUCCESS;
    }
}
