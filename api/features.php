<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');
verify_api_key();

$license_key = trim($_GET['license_key'] ?? '');
if (!$license_key) api_json(['ok'=>false,'error'=>'license_key required'],400);

$db = db();
$stmt = $db->prepare('SELECT u.status, u.credits_balance, p.campaigns_enabled, p.ia_enabled, p.max_profiles FROM users u LEFT JOIN plans p ON p.id=u.plan_id WHERE u.license_key=? LIMIT 1');
$stmt->execute([$license_key]);
$user = $stmt->fetch();

if (!$user) api_json(['ok'=>false,'error'=>'Licence inconnue.'],404);

api_json([
  'ok'                => true,
  'status'            => $user['status'],
  'credits_balance'   => (int)$user['credits_balance'],
  'campaigns_enabled' => (bool)$user['campaigns_enabled'],
  'ia_enabled'        => (bool)$user['ia_enabled'],
  'max_profiles'      => (int)$user['max_profiles'],
]);
