<?php
require_once __DIR__ . '/../includes/aiProxy.php';

function expect_ai_proxy(bool $condition, string $message): void {
  if (!$condition) throw new RuntimeException($message);
}

putenv('BOTORA_AI_TOKEN_SECRET=' . str_repeat('test-secret-', 4));
$now = 2000000000;
$token = botora_ai_issue_token(42, $now);
expect_ai_proxy(is_string($token), 'Token should be issued with a configured secret.');
expect_ai_proxy((botora_ai_verify_token($token, $now)['sub'] ?? null) === 42, 'Valid token should verify.');
expect_ai_proxy(botora_ai_verify_token($token . 'tampered', $now) === null, 'Tampered token should be rejected.');
expect_ai_proxy(botora_ai_verify_token($token, $now + 7 * 24 * 60 * 60) === null, 'Expired token should be rejected.');
expect_ai_proxy(botora_ai_issue_token(0, $now) === null, 'Invalid user IDs should not receive a token.');

expect_ai_proxy(botora_ai_validate_completion_request([
  'messages' => [['role' => 'user', 'content' => 'Bonjour']],
  'max_tokens' => 100,
]) === null, 'Valid completion request should pass.');
expect_ai_proxy(botora_ai_validate_completion_request(['messages' => []]) !== null, 'Empty messages should be rejected.');
expect_ai_proxy(botora_ai_validate_completion_request([
  'messages' => [['role' => 'tool', 'content' => 'invalid']],
]) !== null, 'Unsupported roles should be rejected.');
expect_ai_proxy(botora_ai_validate_completion_request([
  'messages' => [['role' => 'user', 'content' => 'Bonjour']],
  'max_tokens' => 5000,
]) !== null, 'Oversized completion requests should be rejected.');

echo "AI proxy tests passed.\n";
