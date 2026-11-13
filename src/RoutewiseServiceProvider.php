<?php

namespace Routewise;

use Routewise\Console\ScanCommand;
use Routewise\Console\SyncCommand;
use Routewise\Events\ToolCalled;
use Routewise\Platform\Client;
use Routewise\Platform\Guard;
use Routewise\Platform\Reporter;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class RoutewiseServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/routewise.php', 'routewise');
        $this->app->singleton(Client::class);
        $this->app->singleton(Reporter::class);
        $this->app->singleton(Guard::class);
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'routewise');
        $this->publishes([__DIR__.'/../config/routewise.php' => config_path('routewise.php')], 'routewise-config');
        $this->publishes([__DIR__.'/../resources/views' => resource_path('views/vendor/routewise')], 'routewise-views');

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
