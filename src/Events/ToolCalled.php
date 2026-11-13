<?php

namespace Routewise\Events;

/** Fired after every tool call, whether it succeeded or not. Listen to it for your own audit log. */
final class ToolCalled
{
    /**
     * @param  array<int, string>  $argumentKeys  names of the arguments passed (never their values)
     */
    public function __construct(
        public readonly string $tool,
        public readonly string $kind,
        public readonly bool $ok,
        public readonly ?string $error,
        public readonly int $durationMs,
        public readonly array $argumentKeys,
        public readonly int|string|null $userId,
        /** ok, failed, blocked (by a rule), pending (waiting for approval) or denied (by an approver) */
        public readonly string $outcome = 'ok',
    ) {}
}
