<?php

namespace Routewise\Platform;

use Routewise\DefinedTool;
use Routewise\Events\ToolCalled;
use Routewise\Readiness;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Sends the tool catalog and the call log to the control plane, when a project
 * token is configured. Only metadata leaves the app: tool definitions, and per
 * call the tool, outcome, duration, argument names and user id. Never argument
 * values or results.
 */
final class Reporter
{
    /** @var array<int, array<string, mixed>> */
    private array $calls = [];

    public function enabled(): bool
    {
        return (string) config('routewise.platform.token') !== '' && (string) config('routewise.platform.url') !== '';
    }

    public function record(ToolCalled $call): void
    {
        $this->calls[] = [
            'tool' => $call->tool,
            'kind' => $call->kind,
            'ok' => $call->ok,
            // An error message can quote input; it is sent only when the app opts in.
            'error' => config('routewise.platform.send_errors') ? mb_substr((string) $call->error, 0, 500) : null,
            'duration_ms' => $call->durationMs,
            'arguments' => $call->argumentKeys,
            'user' => $call->userId === null ? null : (string) $call->userId,
            'at' => now()->toIso8601String(),
        ];
    }

    /** Called when the request ends. A failed send drops the batch: the log must never break the app. */
    public function flush(): void
    {
        if ($this->calls === [] || ! $this->enabled()) {
            return;
        }
        $calls = $this->calls;
        $this->calls = [];

        try {
            $this->http(3)->post('/api/v1/calls', ['calls' => $calls]);
        } catch (Throwable) {
            //
        }
    }

    /**
     * @param  array<int, DefinedTool>  $tools
     * @return array<string, mixed> the platform's answer
     */
    public function sync(array $tools): array
    {
        $report = Readiness::server($tools);

        return $this->http(15)->post('/api/v1/catalog', [
            'app' => config('routewise.name') ?: config('app.name'),
            'score' => $report['score'],
            'issues' => $report['issues'],
            'tools' => collect($tools)->unique(fn (DefinedTool $t) => $t->name())->map(fn (DefinedTool $t) => [
                'name' => $t->name(),
                'kind' => $t->kind(),
                'description' => $t->description(),
                'input_schema' => $t->toArray()['inputSchema'],
                'score' => $report['tools'][$t->name()]['score'],
                'issues' => $report['tools'][$t->name()]['issues'],
            ])->values()->all(),
        ])->throw()->json() ?? [];
    }

    private function http(int $timeout): PendingRequest
    {
        // The token is a credential: never follow a redirect with it.
        return Http::baseUrl(rtrim((string) config('routewise.platform.url'), '/'))
            ->withToken((string) config('routewise.platform.token'))
            ->acceptJson()
            ->withoutRedirecting()
            ->timeout($timeout);
    }
}
