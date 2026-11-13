<?php

// A stand-in for api.anthropic.com for one test: records the request and answers with a fixed tool call.
file_put_contents(getenv('FAKE_ANTHROPIC_LOG'), json_encode([
    'path' => $_SERVER['REQUEST_URI'],
    'authorization' => $_SERVER['HTTP_AUTHORIZATION'] ?? null,
    'version' => $_SERVER['HTTP_ANTHROPIC_VERSION'] ?? null,
    'body' => json_decode(file_get_contents('php://input'), true),
]));

header('Content-Type: application/json');
echo json_encode([
    'id' => 'msg_test', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-opus-5-5',
    'content' => [
        ['type' => 'text', 'text' => 'Deleting it.'],
        ['type' => 'tool_use', 'id' => 'toolu_test', 'name' => 'invoice_delete', 'input' => ['id' => 42], 'caller' => ['type' => 'direct']],
    ],
    'stop_reason' => 'tool_use', 'stop_sequence' => null,
    'usage' => ['input_tokens' => 120, 'output_tokens' => 30, 'cache_read_input_tokens' => 900, 'cache_creation_input_tokens' => 0],
]);
