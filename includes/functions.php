<?php
require_once __DIR__ . '/db.php';

$GLOBALS['_botora_api_log'] = [
  'started_at' => microtime(true),
  'raw_body' => null,
  'request_data' => null,
  'payload' => null,
  'user_id' => null,
  'error' => null,
];

function api_log_sanitize($value) {
  $sensitive = ['password', 'password_hash', 'token', 'payment_token', 'secret', 'api_key', 'authorization', 'access_token'];
  if (is_array($value)) {
    $clean = [];
    foreach ($value as $key => $item) {
      $clean[$key] = in_array(strtolower((string)$key), $sensitive, true) ? '[redacted]' : api_log_sanitize($item);
    }
    return $clean;
  }
  return is_object($value) ? api_log_sanitize((array)$value) : $value;
}

function api_log_initialize(): void {
  if ($GLOBALS['_botora_api_log']['payload'] !== null) return;
  $raw = file_get_contents('php://input');
  $GLOBALS['_botora_api_log']['raw_body'] = $raw ?: '';
  $decoded = json_decode($raw ?: '', true);
  $requestData = is_array($decoded) ? $decoded : (!empty($_POST) ? $_POST : ($raw !== '' ? $raw : $_GET));
  $GLOBALS['_botora_api_log']['request_data'] = $requestData;
  $GLOBALS['_botora_api_log']['payload'] = api_log_sanitize($requestData);
}

function api_log_set_user(?int $userId): void {
  $GLOBALS['_botora_api_log']['user_id'] = $userId ?: null;
}

function api_log_set_error(?string $error): void {
  $GLOBALS['_botora_api_log']['error'] = $error ?: null;
}

function api_log_write(array $response, int $statusCode): void {
  try {
    $db = db();
    $requestUri = (string)($_SERVER['REQUEST_URI'] ?? '');
    $route = (string)(parse_url($requestUri, PHP_URL_PATH) ?: ($_SERVER['SCRIPT_NAME'] ?? ''));
    $error = $GLOBALS['_botora_api_log']['error'];
    $stmt = $db->prepare('INSERT INTO api_logs (user_id,method,route,ip_address,user_agent,payload,response,error_message,status_code,response_ms) VALUES (?,?,?,?,?,?,?,?,?,?)');
    $stmt->execute([
      $GLOBALS['_botora_api_log']['user_id'],
      (string)($_SERVER['REQUEST_METHOD'] ?? 'GET'),
      $route,
      substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45) ?: null,
      substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500) ?: null,
      json_encode($GLOBALS['_botora_api_log']['payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
      json_encode(api_log_sanitize($response), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
      $error,
      $statusCode,
      (int)round((microtime(true) - $GLOBALS['_botora_api_log']['started_at']) * 1000),
    ]);
  } catch (Throwable $e) {
    error_log('[Botora API log] ' . $e->getMessage());
  }
}

api_log_initialize();

function generate_license(): string {
  return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
    mt_rand(0, 0xffff), mt_rand(0, 0xffff),
    mt_rand(0, 0xffff),
    mt_rand(0, 0x0fff) | 0x4000,
    mt_rand(0, 0x3fff) | 0x8000,
    mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
  );
}

function h(string $s): string {
  return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function status_badge(string $status): string {
  $map = [
    'trial'     => ['badge-warning', 'Essai'],
    'active'    => ['badge-success', 'Actif'],
    'suspended' => ['badge-danger',  'Suspendu'],
    'expired'   => ['badge-secondary','Expiré'],
    'banned'    => ['badge-dark',    'Banni'],
  ];
  [$cls, $label] = $map[$status] ?? ['badge-secondary', $status];
  return "<span class=\"badge {$cls}\">{$label}</span>";
}

function credit_transaction_type_label(string $type): string {
  return match (strtolower(trim($type))) {
    'admin_grant' => 'Crédit accordé',
    'admin_debit' => 'Retrait administrateur',
    default => 'Paiement réel',
  };
}

function credit_transaction_type_badge(string $type): string {
  $key = strtolower(trim($type));
  $map = [
    'payment' => ['badge-primary', 'Paiement réel'],
    'admin_grant' => ['badge-success', 'Crédit accordé'],
    'admin_debit' => ['badge-warning', 'Retrait admin'],
  ];
  [$class, $label] = $map[$key] ?? ['badge-secondary', ucfirst(str_replace('_', ' ', $key))];
  return '<span class="badge ' . $class . '">' . h($label) . '</span>';
}

function payment_status_badge(string $status): string {
  $labelMap = [
    'pending' => 'En attente',
    'approved' => 'Approuvée',
    'failed' => 'Échouée',
    'creation_failed' => 'Création échouée',
    'canceled' => 'Annulée',
    'expired' => 'Expirée',
    'refunded' => 'Remboursée',
  ];

  $classMap = [
    'pending' => 'badge-warning',
    'approved' => 'badge-success',
    'failed' => 'badge-danger',
    'creation_failed' => 'badge-danger',
    'canceled' => 'badge-secondary',
    'expired' => 'badge-secondary',
    'refunded' => 'badge-dark',
  ];

  $key = strtolower(trim((string)$status));
  $label = $labelMap[$key] ?? ucfirst(str_replace('_', ' ', $key));
  $cls = $classMap[$key] ?? 'badge-secondary';

  return "<span class=\"badge {$cls}\">{$label}</span>";
}

function format_date(?string $d): string {
  if (!$d) return '—';
  return date('d/m/Y', strtotime($d));
}

function format_datetime(?string $d): string {
  if (!$d) return '—';
  return date('d/m/Y H:i', strtotime($d));
}

function record_credit_adjustment(int $user_id, float $amount, string $reason, ?int $admin_id = null): void {
  if (!is_finite($amount) || $amount == 0.0) throw new InvalidArgumentException('Le montant doit être différent de zéro.');
  $db = db();
  $db->beginTransaction();
  try {
    $lock = $db->prepare('SELECT credits_balance FROM users WHERE id=? FOR UPDATE');
    $lock->execute([$user_id]);
    $current = $lock->fetchColumn();
    if ($current === false) throw new RuntimeException('Utilisateur introuvable.');
    if ($amount < 0 && abs($amount) > (float)$current) throw new InvalidArgumentException('Le retrait dépasse le solde disponible.');
    $newBalance = (float)$current + $amount;
    $effective = $newBalance - (float)$current;
    if ($effective == 0.0) throw new InvalidArgumentException('Le retrait dépasse le solde disponible.');
    $creditType = $effective > 0 ? 'add' : 'consume';
    $transactionType = $effective > 0 ? 'admin_grant' : 'admin_debit';
    $conversion = credit_conversion($db);
    $description = trim($reason) !== '' ? trim($reason) : ($effective > 0 ? 'Crédit ajouté par un administrateur' : 'Crédit retiré par un administrateur');
    $db->prepare('UPDATE users SET credits_balance=?, updated_at=NOW() WHERE id=?')->execute([$newBalance, $user_id]);
    $db->prepare('INSERT INTO credit_logs (user_id,amount,type,reason,admin_id,balance_after) VALUES (?,?,?,?,?,?)')->execute([$user_id, $effective, $creditType, $description, $admin_id, $newBalance]);
    $db->prepare("INSERT INTO payment_transactions (user_id,admin_id,external_id,amount_xof,credits,transaction_type,status,description,metadata,approved_at) VALUES (?,?,NULL,?,?,?,'approved',?,?,NOW())")
      ->execute([$user_id, $admin_id, round(abs($effective) * $conversion['xof_per_credit'], 2), abs($effective), $transactionType, $description, json_encode(['source'=>'admin_credit_adjustment','requested_amount'=>$amount,'effective_amount'=>$effective,'conversion'=>$conversion], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
    $db->commit();
  } catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    throw $e;
  }
}

function add_credits(int $user_id, int $amount, string $reason, ?int $admin_id = null): void {
  record_credit_adjustment($user_id, (float)$amount, $reason, $admin_id);
}

function credit_conversion(PDO $db): array {
  $row = $db->query('SELECT tokens_per_unit,credits_per_unit,xof_per_unit,updated_at FROM credit_config WHERE id=1 LIMIT 1')->fetch() ?: [];
  $tokens = 100000;
  $credits = (float)($row['credits_per_unit'] ?? 1);
  $xof = (float)($row['xof_per_unit'] ?? 120);
  if (!is_finite($credits) || $credits <= 0) $credits = 1;
  if (!is_finite($xof) || $xof <= 0) $xof = 120;
  return [
    'tokens_per_unit' => $tokens,
    'credits_per_unit' => $credits,
    'xof_per_unit' => $xof,
    'xof_per_credit' => $xof / $credits,
    'updated_at' => $row['updated_at'] ?? null,
  ];
}

function botora_server_now(PDO $db): DateTimeImmutable {
  $raw = (string)$db->query('SELECT UTC_TIMESTAMP()')->fetchColumn();
  try { return new DateTimeImmutable($raw !== '' ? $raw : 'now', new DateTimeZone('UTC')); }
  catch (Throwable $e) { return new DateTimeImmutable('now', new DateTimeZone('UTC')); }
}

function botora_access(PDO $db, array $user, bool $persist = true): array {
  $now = botora_server_now($db);
  $status = strtolower((string)($user['status'] ?? 'expired'));
  $subscriptionEnd = !empty($user['subscription_ends_at']) ? new DateTimeImmutable((string)$user['subscription_ends_at'], new DateTimeZone('UTC')) : null;
  $trialEnd = !empty($user['trial_ends_at']) ? new DateTimeImmutable((string)$user['trial_ends_at'], new DateTimeZone('UTC')) : null;
  $access = false;
  $accessType = 'none';
  $accessEnd = null;
  if (in_array($status, ['suspended', 'banned'], true)) {
    $accessType = $status;
  } elseif ($subscriptionEnd && $subscriptionEnd > $now) {
    $access = true;
    $accessType = 'subscription';
    $accessEnd = $subscriptionEnd;
    if ($persist && $status !== 'active') $db->prepare("UPDATE users SET status='active', updated_at=NOW() WHERE id=?")->execute([(int)$user['id']]);
  } elseif ($status === 'trial' && $trialEnd && $trialEnd > $now) {
    $access = true;
    $accessType = 'trial';
    $accessEnd = $trialEnd;
  } else {
    $accessType = 'expired';
    if ($persist && !in_array($status, ['expired', 'suspended', 'banned'], true)) $db->prepare("UPDATE users SET status='expired', updated_at=NOW() WHERE id=?")->execute([(int)$user['id']]);
  }
  $secondsLeft = $accessEnd ? max(0, $accessEnd->getTimestamp() - $now->getTimestamp()) : 0;
  return [
    'access_allowed' => $access,
    'access_type' => $accessType,
    'access_ends_at' => $accessEnd?->format('Y-m-d H:i:s'),
    'trial_ends_at' => $trialEnd?->format('Y-m-d H:i:s'),
    'trial_days_left' => $accessType === 'trial' ? (int)ceil($secondsLeft / 86400) : null,
    'subscription_ends_at' => $subscriptionEnd?->format('Y-m-d H:i:s'),
    'subscription_days_left' => $accessType === 'subscription' ? (int)ceil($secondsLeft / 86400) : null,
    'server_time' => $now->format('Y-m-d H:i:s'),
  ];
}

function api_json(array $data, int $code = 200): void {
  api_log_write($data, $code);
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($data, JSON_UNESCAPED_UNICODE);
  exit;
}

function verify_api_key(): void {
  // Contrôle désactivé pendant la phase de développement.
}

function flash_set(string $type, string $msg): void {
  session_init();
  $_SESSION['flash'] = ['type' => $type, 'msg' => $msg];
}

function flash_get(): ?array {
  session_init();
  $f = $_SESSION['flash'] ?? null;
  unset($_SESSION['flash']);
  return $f;
}
