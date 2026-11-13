<?php

namespace Routewise\Behaviour;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\RateLimitException;
use Anthropic\Messages\ToolUseBlock;
use RuntimeException;

/**
 * Claude through the official Anthropic SDK (`composer require --dev anthropic-ai/sdk`).
 *
 * No fallback model is configured on purpose: a behaviour test is about the named model, and a silent
 * switch to another one would make the result say something about a model nobody chose.
 */
final class ClaudeModel implements Model
{
    private ?Client $client = null;

    public function name(): string
    {
        return (string) config('routewise.behaviour.model', 'claude-opus-5-5');
    }

    public function ask(string $system, string $prompt, array $tools): Reply
    {
        try {
            $message = $this->client()->messages->create(
                model: $this->name(),
                maxTokens: 16000,
                // The system prompt and the tool list are the same for every scenario: the breakpoint after the
                // system prompt caches both, so only the first scenario pays full price for a large catalog.
                system: [['type' => 'text', 'text' => $system, 'cacheControl' => ['type' => 'ephemeral']]],
                tools: $tools,
                messages: [['role' => 'user', 'content' => $prompt]],
                outputConfig: ['effort' => (string) config('routewise.behaviour.effort', 'medium')],
            );
        } catch (AuthenticationException) {
            throw new RuntimeException('The Anthropic API key was rejected. Check ANTHROPIC_API_KEY.');
        } catch (RateLimitException) {
            throw new RuntimeException('The Anthropic API rate limit was reached. Run fewer scenarios at once (--filter) or try again in a minute.');
        } catch (APIStatusException $e) {
            throw new RuntimeException('The Anthropic API answered '.$e->getMessage());
        } catch (APIConnectionException $e) {
            throw new RuntimeException('The Anthropic API could not be reached: '.$e->getMessage());
        }

        $calls = [];
        $text = '';
        foreach ($message->content as $block) {
            if ($block instanceof ToolUseBlock) {
                $calls[] = ['tool' => $block->name, 'arguments' => (array) $block->input];
            } elseif ($block->type === 'text') {
                $text .= $block->text;
            }
        }

        return new Reply(
            calls: $calls,
            text: $text,
            // Check the stop reason before trusting the content: a refusal has nothing to grade.
            refusal: $message->stopReason === 'refusal' ? ($message->stopDetails?->category ?? 'refused') : null,
            inputTokens: (int) ($message->usage->inputTokens ?? 0) + (int) ($message->usage->cacheReadInputTokens ?? 0) + (int) ($message->usage->cacheCreationInputTokens ?? 0),
            outputTokens: (int) ($message->usage->outputTokens ?? 0),
        );
    }

    private function client(): Client
    {
        if (! class_exists(Client::class)) {
            throw new RuntimeException('Behaviour tests use the Anthropic SDK. Run: composer require --dev anthropic-ai/sdk');
        }
        $key = (string) config('routewise.behaviour.api_key');
        if ($key === '') {
            throw new RuntimeException('Set ANTHROPIC_API_KEY to run behaviour tests.');
        }

        // Passed explicitly: with Laravel's config cached, .env is not in the process environment.
        return $this->client ??= new Client(apiKey: $key);
    }
}
