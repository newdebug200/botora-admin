<?php
require_once __DIR__ . '/db.php';

$GLOBALS['_botora_api_log'] = [
  'started_at' => microtime(true),
  'request_data' => null,
  'payload' => null,
  'user_id' => null,
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
  $decoded = json_decode($raw ?: '', true);
  $requestData = is_array($decoded) ? $decoded : (!empty($_POST) ? $_POST : ($raw !== '' ? $raw : $_GET));
  $GLOBALS['_botora_api_log']['request_data'] = $requestData;
  $GLOBALS['_botora_api_log']['payload'] = api_log_sanitize($requestData);
}

function api_log_set_user(?int $userId): void {
  $GLOBALS['_botora_api_log']['user_id'] = $userId ?: null;
}

function api_log_write(array $response, int $statusCode): void {
  try {
    $db = db();
    $requestUri = (string)($_SERVER['REQUEST_URI'] ?? '');
    $route = (string)(parse_url($requestUri, PHP_URL_PATH) ?: ($_SERVER['SCRIPT_NAME'] ?? ''));
    $stmt = $db->prepare('INSERT INTO api_logs (user_id,method,route,ip_address,user_agent,payload,response,status_code,response_ms) VALUES (?,?,?,?,?,?,?,?,?)');
    $stmt->execute([
      $GLOBALS['_botora_api_log']['user_id'],
      (string)($_SERVER['REQUEST_METHOD'] ?? 'GET'),
      $route,
      substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45) ?: null,
      substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500) ?: null,
      json_encode($GLOBALS['_botora_api_log']['payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
      json_encode(api_log_sanitize($response), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
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

function add_credits(int $user_id, int $amount, string $reason, ?int $admin_id = null): void {
  $db = db();
  $db->beginTransaction();
  try {
    $db->prepare('UPDATE users SET credits_balance = credits_balance + ?, updated_at = NOW() WHERE id = ?')
       ->execute([$amount, $user_id]);
    $row = $db->prepare('SELECT credits_balance FROM users WHERE id = ?');
    $row->execute([$user_id]);
    $balance = (int)$row->fetchColumn();
    $db->prepare('INSERT INTO credit_logs (user_id, amount, type, reason, admin_id, balance_after) VALUES (?, ?, ?, ?, ?, ?)')
       ->execute([$user_id, $amount, 'add', $reason, $admin_id, $balance]);
    $db->commit();
  } catch (Exception $e) {
    $db->rollBack();
    throw $e;
  }
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
