<?php

namespace Routewise\Behaviour;

/** What a model did with one prompt: the tools it called, or why it called none. */
final class Reply
{
    /**
     * @param  array<int, array{tool: string, arguments: array<string, mixed>}>  $calls
     * @param  string|null  $refusal  set when the model declined the request (a safety refusal), with the category if given
     */
    public function __construct(
        public readonly array $calls,
        public readonly string $text = '',
        public readonly ?string $refusal = null,
        public readonly int $inputTokens = 0,
        public readonly int $outputTokens = 0,
    ) {}
}
