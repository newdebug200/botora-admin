<?php

function botora_ai_token_secret(): string {
  $secret = function_exists('botora_setting')
    ? botora_setting('BOTORA_AI_TOKEN_SECRET')
    : trim((string)getenv('BOTORA_AI_TOKEN_SECRET'));
  return strlen($secret) >= 32 ? $secret : '';
}

function botora_ai_base64url_encode(string $value): string {
  return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function botora_ai_base64url_decode(string $value): string|false {
  $padding = (4 - strlen($value) % 4) % 4;
  return base64_decode(strtr($value . str_repeat('=', $padding), '-_', '+/'), true);
}

function botora_ai_issue_token(int $userId, ?int $now = null): ?string {
  $secret = botora_ai_token_secret();
  if ($secret === '' || $userId <= 0) return null;

  $issuedAt = $now ?? time();
  $payload = botora_ai_base64url_encode(json_encode([
    'sub' => $userId,
    'aud' => 'botora-ai',
    'iat' => $issuedAt,
    'exp' => $issuedAt + 7 * 24 * 60 * 60,
  ], JSON_THROW_ON_ERROR));
  $signature = botora_ai_base64url_encode(hash_hmac('sha256', $payload, $secret, true));
  return $payload . '.' . $signature;
}

function botora_ai_verify_token(string $token, ?int $now = null): ?array {
  $secret = botora_ai_token_secret();
  if ($secret === '' || strlen($token) > 2048 || substr_count($token, '.') !== 1) return null;

  [$payload, $signature] = explode('.', $token, 2);
  $expected = botora_ai_base64url_encode(hash_hmac('sha256', $payload, $secret, true));
  if (!hash_equals($expected, $signature)) return null;

  $decoded = botora_ai_base64url_decode($payload);
  if ($decoded === false) return null;
  $claims = json_decode($decoded, true);
  if (!is_array($claims)
    || ($claims['aud'] ?? null) !== 'botora-ai'
    || (int)($claims['sub'] ?? 0) <= 0
    || (int)($claims['exp'] ?? 0) <= ($now ?? time())
    || (int)($claims['iat'] ?? 0) > ($now ?? time()) + 60) {
    return null;
  }
  return $claims;
}

function botora_ai_validate_completion_request(array $data): ?string {
  $messages = $data['messages'] ?? null;
  if (!is_array($messages) || count($messages) < 1 || count($messages) > 30) {
    return 'La conversation ne contient pas un nombre valide de messages.';
  }

  $contentLength = 0;
  foreach ($messages as $message) {
    if (!is_array($message)
      || !in_array($message['role'] ?? null, ['system', 'user', 'assistant'], true)
      || !is_string($message['content'] ?? null)) {
      return 'Format de conversation non pris en charge.';
    }
    $contentLength += strlen($message['content']);
  }
  if ($contentLength > 80000) return 'La conversation dépasse la taille maximale autorisée.';

  $maxTokens = filter_var($data['max_tokens'] ?? 600, FILTER_VALIDATE_INT);
  if ($maxTokens === false || $maxTokens < 1 || $maxTokens > 2048) {
    return 'La limite de réponse demandée n’est pas valide.';
  }
  if (isset($data['temperature']) && (!is_numeric($data['temperature']) || (float)$data['temperature'] < 0 || (float)$data['temperature'] > 2)) {
    return 'La température demandée n’est pas valide.';
  }
  if (isset($data['response_format'])
    && (!is_array($data['response_format']) || ($data['response_format']['type'] ?? null) !== 'json_object')) {
    return 'Le format de réponse demandé n’est pas pris en charge.';
  }
  return null;
}

function botora_ai_rate_limits(): array {
  $limits = [
    'global_rpm' => [getenv('BOTORA_GROQ_RPM_LIMIT'), 25, 1, 1000],
    'global_rpd' => [getenv('BOTORA_GROQ_RPD_LIMIT'), 900, 1, 100000],
    'global_tpm' => [getenv('BOTORA_GROQ_TPM_LIMIT'), 7500, 100, 1000000],
    'global_tpd' => [getenv('BOTORA_GROQ_TPD_LIMIT'), 180000, 100, 100000000],
    'user_rpm' => [getenv('BOTORA_USER_AI_RPM_LIMIT'), 10, 1, 1000],
    'user_rpd' => [getenv('BOTORA_USER_AI_RPD_LIMIT'), 300, 1, 100000],
  ];
  $result = [];
  foreach ($limits as $name => [$raw, $fallback, $minimum, $maximum]) {
    $value = filter_var($raw, FILTER_VALIDATE_INT);
    $result[$name] = $value === false
      ? $fallback
      : max($minimum, min($maximum, $value));
  }
  return $result;
}

function botora_ai_reserve_rate(PDO $db, int $userId, int $estimatedTokens): ?string {
  $limits = botora_ai_rate_limits();
  $minute = intdiv(time(), 60);
  $day = intdiv(time(), 86400);
  $buckets = [
    ['global', $limits['global_rpm'], $limits['global_rpd'], $limits['global_tpm'], $limits['global_tpd']],
    ['user:' . $userId, $limits['user_rpm'], $limits['user_rpd'], $limits['global_tpm'], $limits['global_tpd']],
  ];

  foreach ($buckets as [$key, $rpmLimit, $rpdLimit, $tpmLimit, $tpdLimit]) {
    $db->prepare('INSERT IGNORE INTO ai_proxy_usage_windows (bucket_key, rpm_window, rpd_window, tpm_window, tpd_window) VALUES (?, ?, ?, ?, ?)')
      ->execute([$key, $minute, $day, $minute, $day]);
    $stmt = $db->prepare('SELECT * FROM ai_proxy_usage_windows WHERE bucket_key=? FOR UPDATE');
    $stmt->execute([$key]);
    $window = $stmt->fetch();
    if (!$window) return 'Le contrôle de quota IA est indisponible.';

    $rpmCount = (int)$window['rpm_window'] === $minute ? (int)$window['rpm_count'] : 0;
    $rpdCount = (int)$window['rpd_window'] === $day ? (int)$window['rpd_count'] : 0;
    $tpmTokens = (int)$window['tpm_window'] === $minute ? (int)$window['tpm_tokens'] : 0;
    $tpdTokens = (int)$window['tpd_window'] === $day ? (int)$window['tpd_tokens'] : 0;
    if ($rpmCount >= $rpmLimit || $rpdCount >= $rpdLimit
      || $tpmTokens + $estimatedTokens > $tpmLimit
      || $tpdTokens + $estimatedTokens > $tpdLimit) {
      return 'La limite d’utilisation IA est atteinte. Réessayez plus tard.';
    }

    $db->prepare('UPDATE ai_proxy_usage_windows SET rpm_window=?, rpm_count=?, rpd_window=?, rpd_count=?, tpm_window=?, tpm_tokens=?, tpd_window=?, tpd_tokens=?, updated_at=NOW() WHERE bucket_key=?')
      ->execute([
        $minute, $rpmCount + 1,
        $day, $rpdCount + 1,
        $minute, $tpmTokens + $estimatedTokens,
        $day, $tpdTokens + $estimatedTokens,
        $key,
      ]);
  }
  return null;
}
