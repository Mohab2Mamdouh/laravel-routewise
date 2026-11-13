<?php

namespace Routewise\Behaviour;

/** The model under test. Bound to ClaudeModel; swap the binding to test against something else. */
interface Model
{
    /** A name for reports, e.g. "claude-opus-5-5". */
    public function name(): string;

    /**
     * One request: the system prompt, the tool list and one user message. The tools are only offered, never run.
     *
     * @param  array<int, array{name: string, description: string, inputSchema: array<string, mixed>}>  $tools
     */
    public function ask(string $system, string $prompt, array $tools): Reply;
}
