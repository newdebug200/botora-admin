<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');
verify_api_key();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') api_json(['ok'=>false,'error'=>'Method not allowed'],405);

$body        = payment_request_json();
$license_key = trim($body['license_key'] ?? '');
$tokens_used = max(0, (int)($body['tokens_used'] ?? 0));
$legacy_amount = max(0, (float)($body['amount'] ?? 0));
$event_type  = trim($body['event_type'] ?? 'unknown');
$meta        = $body['meta'] ?? null;

if (!$license_key) api_json(['ok'=>false,'error'=>'license_key required'],400);

$db = db();
$conversion = credit_conversion($db);
$amount = $tokens_used > 0 ? round(($tokens_used / $conversion['tokens_per_unit']) * $conversion['credits_per_unit'], 10) : $legacy_amount;
if ($amount <= 0) api_json(['ok'=>false,'error'=>'Tokens ou montant de crédits invalide.'],400);
$stmt = $db->prepare('SELECT id, credits_balance, status FROM users WHERE license_key=? LIMIT 1');
$stmt->execute([$license_key]);
$user = $stmt->fetch();

if (!$user) api_json(['ok'=>false,'error'=>'Licence inconnue.'],404);
api_log_set_user((int)$user['id']);
if (!in_array($user['status'],['trial','active'])) api_json(['ok'=>false,'error'=>'Compte non actif.']);
if ($user['credits_balance'] < $amount) api_json(['ok'=>false,'error'=>'Crédits insuffisants.','credits_balance'=>(int)$user['credits_balance']]);

$db->beginTransaction();
try {
  $db->prepare('UPDATE users SET credits_balance = credits_balance - ?, updated_at=NOW() WHERE id=?')->execute([$amount, $user['id']]);
  $new_balance = $user['credits_balance'] - $amount;
  $db->prepare('INSERT INTO credit_logs (user_id,amount,type,reason,balance_after) VALUES (?,?,?,?,?)')->execute([$user['id'],-$amount,'consume',$event_type,$new_balance]);
  $db->prepare('INSERT INTO usage_logs (user_id,event_type,credits_used,meta) VALUES (?,?,?,?)')->execute([$user['id'],$event_type,$amount,json_encode(['tokens_used'=>$tokens_used,'conversion'=>$conversion,'payload'=>$meta],JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
  $db->commit();
} catch (Exception $e) {
  $db->rollBack();
  api_json(['ok'=>false,'error'=>'Erreur interne.'],500);
}

api_json(['ok'=>true,'credits_balance'=>$new_balance,'consumed'=>$amount,'tokens_used'=>$tokens_used,'conversion'=>$conversion]);
