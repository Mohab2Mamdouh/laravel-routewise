<?php

namespace AgentTools;

use AgentTools\Console\ScanCommand;
use AgentTools\Console\SyncCommand;
use AgentTools\Events\ToolCalled;
use AgentTools\Platform\Reporter;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AgentToolsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/agent-tools.php', 'agent-tools');
        $this->app->singleton(Reporter::class);
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'agent-tools');
        $this->publishes([__DIR__.'/../config/agent-tools.php' => config_path('agent-tools.php')], 'agent-tools-config');
        $this->publishes([__DIR__.'/../resources/views' => resource_path('views/vendor/agent-tools')], 'agent-tools-views');

        if ($this->app->runningInConsole()) {
            $this->commands([ScanCommand::class, SyncCommand::class]);
        }

        // The call log is buffered and sent after the response, so a slow control plane never slows a tool call.
        Event::listen(ToolCalled::class, function (ToolCalled $call) {
            $reporter = $this->app->make(Reporter::class);
            if ($reporter->enabled()) {
                $reporter->record($call);
            }
        });
        $this->app->terminating(fn () => $this->app->make(Reporter::class)->flush());
    }
}
