<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/payment.php';

header('Content-Type: application/json; charset=utf-8');
verify_service_key();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') api_json(['ok'=>false,'error'=>'Méthode non autorisée.'],405);
$data = payment_request_json();
$email = strtolower(trim((string)($data['account_email'] ?? $data['email'] ?? '')));
$keyUid = trim((string)($data['key_uid'] ?? ''));
$name = trim((string)($data['name'] ?? ''));
$prefix = trim((string)($data['prefix'] ?? ''));
$event = strtolower(trim((string)($data['event'] ?? '')));
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $keyUid === '' || !in_array($event, ['created','used','revoked'], true)) api_json(['ok'=>false,'error'=>'Données d’historique de clé invalides.'],400);
$db = db();
try {
  if ($event === 'created') {
    if ($name === '' || $prefix === '') api_json(['ok'=>false,'error'=>'Nom et préfixe de clé requis.'],400);
    $stmt = $db->prepare('INSERT INTO api_key_history (account_email,platform_key_uid,key_name,key_prefix,status) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE account_email=VALUES(account_email),key_name=VALUES(key_name),key_prefix=VALUES(key_prefix),status=IF(revoked_at IS NULL,\'active\',status)');
    $stmt->execute([$email,$keyUid,substr($name,0,80),substr($prefix,0,32),'active']);
  } elseif ($event === 'revoked') {
    $stmt = $db->prepare('UPDATE api_key_history SET status=\'revoked\',revoked_at=COALESCE(revoked_at,NOW()) WHERE platform_key_uid=? AND account_email=?');
    $stmt->execute([$keyUid,$email]);
  } else {
    $stmt = $db->prepare('UPDATE api_key_history SET last_used_at=NOW() WHERE platform_key_uid=? AND account_email=? AND revoked_at IS NULL');
    $stmt->execute([$keyUid,$email]);
  }
  api_json(['ok'=>true,'event'=>$event]);
} catch (Throwable $e) {
  error_log('[api-key-history] ' . $e->getMessage());
  api_json(['ok'=>false,'error'=>'Impossible d’enregistrer l’historique de la clé.'],500);
}
