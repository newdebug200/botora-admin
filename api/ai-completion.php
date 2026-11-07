<?php
require_once __DIR__ . '/../includes/payment.php';
require_once __DIR__ . '/../includes/aiProxy.php';

$GLOBALS['_botora_api_log']['suppress_response'] = true;
$data = payment_request_json();
$token = trim((string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? ''));
if (!preg_match('/^Bearer\s+(.+)$/i', $token, $matches)) {
  api_json(['ok' => false, 'error' => 'Authentification IA requise.'], 401);
}
$claims = botora_ai_verify_token(trim($matches[1]));
if (!$claims) api_json(['ok' => false, 'error' => 'Session IA invalide ou expirée. Reconnectez-vous.'], 401);

$userId = (int)$claims['sub'];
api_log_set_user($userId);
$GLOBALS['_botora_api_log']['payload'] = [
  'message_count' => is_array($data['messages'] ?? null) ? count($data['messages']) : 0,
];

$validationError = botora_ai_validate_completion_request($data);
if ($validationError) api_json(['ok' => false, 'error' => $validationError], 400);

$groqKey = botora_setting('GROQ_API_KEY');
if ($groqKey === '') {
  api_log_set_error('GROQ_API_KEY is not configured.');
  api_json(['ok' => false, 'error' => 'Le service IA est momentanément indisponible.'], 503);
}
if (!function_exists('curl_init')) {
  api_log_set_error('PHP cURL extension is not available.');
  api_json(['ok' => false, 'error' => 'Le service IA est momentanément indisponible.'], 503);
}

$maxTokens = (int)($data['max_tokens'] ?? 600);
$estimatedTokens = (int)ceil(array_sum(array_map(
  static fn(array $message): int => strlen($message['content']),
  $data['messages']
)) / 3) + $maxTokens;
$GLOBALS['_botora_api_log']['payload']['estimated_tokens'] = $estimatedTokens;

$db = db();
$conversion = credit_conversion($db);
$creditsSetting = $db->query("SELECT enabled FROM platform_features WHERE feature_key='credits_enabled' LIMIT 1")->fetchColumn();
$creditsEnabled = $creditsSetting === false ? true : (bool)$creditsSetting;
$reservedCredits = $creditsEnabled
  ? round(($estimatedTokens / $conversion['tokens_per_unit']) * $conversion['credits_per_unit'], 10)
  : 0;
$db->beginTransaction();
try {
  $stmt = $db->prepare('SELECT * FROM users WHERE id=? FOR UPDATE');
  $stmt->execute([$userId]);
  $user = $stmt->fetch();
  if (!$user) {
    $db->rollBack();
    api_json(['ok' => false, 'error' => 'Compte introuvable. Reconnectez-vous.'], 401);
  }
  $access = botora_access($db, $user, false);
  if (!$access['access_allowed']) {
    $db->rollBack();
    api_json(['ok' => false, 'error' => 'Votre accès Botora ne permet pas d’utiliser le service IA.'], 403);
  }
  if ((float)$user['credits_balance'] < $reservedCredits) {
    $db->rollBack();
    api_json(['ok' => false, 'error' => 'Crédits insuffisants.', 'credits_balance' => (float)$user['credits_balance']], 402);
  }

  $rateError = botora_ai_reserve_rate($db, $userId, $estimatedTokens);
  if ($rateError) {
    $db->rollBack();
    api_json(['ok' => false, 'error' => $rateError, 'retry_after' => 60], 429);
  }

  $reservedBalance = (float)$user['credits_balance'] - $reservedCredits;
  $db->prepare('UPDATE users SET credits_balance=?,updated_at=NOW() WHERE id=?')->execute([$reservedBalance, $userId]);
  if ($reservedCredits > 0) {
    $db->prepare('INSERT INTO credit_logs (user_id,amount,type,reason,balance_after) VALUES (?,?,?,?,?)')
      ->execute([$userId, -$reservedCredits, 'consume', 'Réservation consommation IA', $reservedBalance]);
  }
  $db->commit();
} catch (Throwable $error) {
  if ($db->inTransaction()) $db->rollBack();
  error_log('[Botora AI proxy] Quota reservation failed: ' . $error->getMessage());
  api_log_set_error('Quota reservation failed.');
  api_json(['ok' => false, 'error' => 'Le service IA est momentanément indisponible.'], 503);
}

$request = [
  'model' => botora_setting('BOTORA_GROQ_MODEL', 'openai/gpt-oss-20b'),
  'messages' => $data['messages'],
  'max_tokens' => $maxTokens,
];
if (isset($data['temperature'])) $request['temperature'] = (float)$data['temperature'];
if (isset($data['response_format'])) $request['response_format'] = ['type' => 'json_object'];

$curl = curl_init('https://api.groq.com/openai/v1/chat/completions');
curl_setopt_array($curl, [
  CURLOPT_POST => true,
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_CONNECTTIMEOUT => 10,
  CURLOPT_TIMEOUT => 45,
  CURLOPT_HTTPHEADER => [
    'Authorization: Bearer ' . $groqKey,
    'Content-Type: application/json',
  ],
  CURLOPT_POSTFIELDS => json_encode($request, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
]);
$rawResponse = curl_exec($curl);
$curlError = curl_error($curl);
$providerStatus = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
curl_close($curl);

$providerResponse = is_string($rawResponse) ? json_decode($rawResponse, true) : null;
if (!is_array($providerResponse) || $providerStatus < 200 || $providerStatus >= 300
  || !isset($providerResponse['choices'][0]['message']['content'])) {
  $db->beginTransaction();
  try {
    $stmt = $db->prepare('SELECT credits_balance FROM users WHERE id=? FOR UPDATE');
    $stmt->execute([$userId]);
    $balance = (float)$stmt->fetchColumn() + $reservedCredits;
    $db->prepare('UPDATE users SET credits_balance=?,updated_at=NOW() WHERE id=?')->execute([$balance, $userId]);
    if ($reservedCredits > 0) {
      $db->prepare('INSERT INTO credit_logs (user_id,amount,type,reason,balance_after) VALUES (?,?,?,?,?)')
        ->execute([$userId, $reservedCredits, 'add', 'Annulation consommation IA', $balance]);
    }
    $db->commit();
  } catch (Throwable $error) {
    if ($db->inTransaction()) $db->rollBack();
    error_log('[Botora AI proxy] Credit reservation release failed: ' . $error->getMessage());
  }

  $status = $providerStatus === 429 ? 429 : 503;
  api_log_set_error('Groq completion failed with HTTP ' . $providerStatus . ($curlError ? ': ' . $curlError : ''));
  api_json([
    'ok' => false,
    'error' => $providerStatus === 429
      ? 'La limite du service IA est atteinte. Réessayez plus tard.'
      : 'Le service IA est momentanément indisponible.',
  ], $status);
}

$usage = is_array($providerResponse['usage'] ?? null) ? $providerResponse['usage'] : [];
$actualTokens = (int)($usage['total_tokens'] ?? 0);
if ($actualTokens <= 0) $actualTokens = $estimatedTokens;
$actualCredits = $creditsEnabled
  ? round(($actualTokens / $conversion['tokens_per_unit']) * $conversion['credits_per_unit'], 10)
  : 0;

$db->beginTransaction();
try {
  $stmt = $db->prepare('SELECT credits_balance FROM users WHERE id=? FOR UPDATE');
  $stmt->execute([$userId]);
  $balance = (float)$stmt->fetchColumn();
  $settlement = round($reservedCredits - $actualCredits, 10);
  if ($settlement > 0) {
    $balance += $settlement;
    $db->prepare('INSERT INTO credit_logs (user_id,amount,type,reason,balance_after) VALUES (?,?,?,?,?)')
      ->execute([$userId, $settlement, 'add', 'Ajustement consommation IA', $balance]);
  } elseif ($settlement < 0 && $balance >= abs($settlement)) {
    $balance += $settlement;
    $db->prepare('INSERT INTO credit_logs (user_id,amount,type,reason,balance_after) VALUES (?,?,?,?,?)')
      ->execute([$userId, $settlement, 'consume', 'Ajustement consommation IA', $balance]);
  } elseif ($settlement < 0) {
    $actualCredits = $reservedCredits;
  }
  $db->prepare('UPDATE users SET credits_balance=?,updated_at=NOW() WHERE id=?')->execute([$balance, $userId]);
  $meta = [
    'model' => $request['model'],
    'tokens_used' => $actualTokens,
    'prompt_tokens' => (int)($usage['prompt_tokens'] ?? 0),
    'completion_tokens' => (int)($usage['completion_tokens'] ?? 0),
    'conversion' => $conversion,
  ];
  $db->prepare('INSERT INTO usage_logs (user_id,event_type,credits_used,meta) VALUES (?,?,?,?)')
    ->execute([$userId, 'ai.proxy', $actualCredits, json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
  $usageId = (int)$db->lastInsertId();
  $db->commit();
} catch (Throwable $error) {
  if ($db->inTransaction()) $db->rollBack();
  error_log('[Botora AI proxy] Usage finalization failed: ' . $error->getMessage());
  api_log_set_error('Usage finalization failed.');
  api_json(['ok' => false, 'error' => 'La réponse IA n’a pas pu être finalisée. Réessayez.'], 503);
}

$providerResponse['botora'] = [
  'credits_balance' => $balance,
  'credits_used' => $actualCredits,
  'usage_id' => $usageId,
];
api_json($providerResponse);
