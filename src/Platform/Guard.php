<?php

namespace Routewise\Platform;

use Illuminate\Support\Facades\Cache;
use Routewise\DefinedTool;
use Throwable;

/**
 * Decides, before a tool runs, whether it may: allow, block, or ask a person first.
 *
 * Rules come from two places. `routewise.policy` in the app's config can block a
 * kind or a tool outright, and nothing overrides that. The control plane's rules
 * (set on its dashboard) can allow, block or require approval per kind and per tool.
 *
 * An approval is for one exact call: the tool, the user and a fingerprint of the
 * arguments. The fingerprint is an HMAC with the app key, so the control plane
 * can match a retry to its approval without being able to recover the values.
 */
final class Guard
{
    public const MODES = ['allow', 'approve', 'block'];

    public function __construct(private Client $client) {}

    /**
     * @param  array<string, mixed>  $args  the validated arguments
     * @return array{outcome: 'blocked'|'pending'|'denied', message: string}|null  null when the tool may run
     */
    public function check(DefinedTool $tool, array $args): ?array
    {
        $name = $tool->name();
        $mode = $this->mode($tool);

        if ($mode === 'block') {
            return ['outcome' => 'blocked', 'message' => "{$name} is blocked by this workspace's rules. Tell the user; do not look for another way to do the same thing."];
        }
        if ($mode !== 'approve') {
            return null;
        }

        try {
            $answer = $this->client->http(5)->post('/api/v1/approvals', [
                'tool' => $name,
                'kind' => $tool->kind(),
                'fingerprint' => self::fingerprint($name, $args),
                'user' => auth()->id() === null ? null : (string) auth()->id(),
                'arguments' => array_keys($args),
                // Off by default: with it on, approvers see what they are approving, and the values leave the app.
                'values' => config('routewise.platform.approval_values') ? self::preview($args) : null,
            ])->throw()->json();
        } catch (Throwable) {
            // A tool that needs approval never runs on a guess.
            return ['outcome' => 'pending', 'message' => "{$name} needs approval, but the approval service could not be reached. Try again later."];
        }

        return match ($answer['status'] ?? null) {
            'approved' => null,
            'denied' => ['outcome' => 'denied', 'message' => "An approver denied this {$name} call. Tell the user; do not retry it."],
            default => ['outcome' => 'pending', 'message' => "{$name} needs approval before it runs. A request was sent to the workspace's approvers"
                .(isset($answer['url']) ? " ({$answer['url']})" : '').'. Tell the user, and call the tool again with exactly the same arguments once it is approved.'],
        };
    }

    public function mode(DefinedTool $tool): string
    {
        $local = (array) config('routewise.policy', []);
        if (($local['tools'][$tool->name()] ?? $local['kinds'][$tool->kind()] ?? 'allow') === 'block') {
            return 'block';
        }

        $remote = $this->rules();
        $mode = $remote['tools'][$tool->name()] ?? $remote['kinds'][$tool->kind()] ?? 'allow';

        return in_array($mode, self::MODES, true) ? $mode : 'allow';
    }

    /**
     * The control plane's rules, fetched at most once per TTL. When it can't be
     * reached, the last rules it gave keep applying.
     *
     * @return array{kinds?: array<string, string>, tools?: array<string, string>}
     */
    private function rules(): array
    {
        if (! $this->client->enabled()) {
            return [];
        }

        $fresh = Cache::get($this->client->key('rules'));
        if (is_array($fresh)) {
            return $fresh;
        }

        try {
            $rules = (array) $this->client->http(3)->get('/api/v1/policy')->throw()->json();
            Cache::forever($this->client->key('rules:last'), $rules);
        } catch (Throwable) {
            $rules = (array) Cache::get($this->client->key('rules:last'), []);
        }
        // Failures are cached too, so a control plane that is down costs one timeout per TTL, not one per call.
        Cache::put($this->client->key('rules'), $rules, (int) config('routewise.platform.policy_ttl', 60));

        return $rules;
    }

    /** @param  array<string, mixed>  $args */
    public static function fingerprint(string $tool, array $args): string
    {
        $sort = function (array $a) use (&$sort) {
            foreach ($a as &$v) {
                if (is_array($v)) {
                    $v = $sort($v);
                }
            }
            if (! array_is_list($a)) {
                ksort($a);
            }

            return $a;
        };

        return hash_hmac('sha256', json_encode([$tool, $sort($args)]), (string) config('app.key'));
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, string>
     */
    private static function preview(array $args): array
    {
        return array_map(fn ($v) => mb_substr(is_scalar($v) || $v === null ? var_export($v, true) : (string) json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 0, 300), $args);
    }
}
