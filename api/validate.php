<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');
verify_api_key();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') api_json(['ok'=>false,'error'=>'Method not allowed'],405);

$body = json_decode(file_get_contents('php://input'), true);
$license_key   = trim($body['license_key'] ?? '');
$machine_id    = trim($body['machine_id'] ?? '');
$botora_version= trim($body['version'] ?? '');
$ip            = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';

if (!$license_key) api_json(['ok'=>false,'error'=>'license_key required'],400);

$db = db();
$stmt = $db->prepare('SELECT u.*, p.name as plan_name, p.slug as plan_slug, p.credits_per_month, p.max_profiles, p.campaigns_enabled, p.ia_enabled FROM users u LEFT JOIN plans p ON p.id=u.plan_id WHERE u.license_key=? LIMIT 1');
$stmt->execute([$license_key]);
$user = $stmt->fetch();

if (!$user) api_json(['ok'=>false,'valid'=>false,'error'=>'Licence inconnue.'],404);

// Check status
if ($user['status'] === 'banned')    api_json(['ok'=>false,'valid'=>false,'error'=>'Cette licence a été bannie. Contactez le support.']);
if ($user['status'] === 'suspended') api_json(['ok'=>false,'valid'=>false,'error'=>'Votre accès est suspendu. Contactez le support.']);

// Check trial expiry
if ($user['status'] === 'trial' && $user['trial_ends_at']) {
  if (strtotime($user['trial_ends_at']) < time()) {
    $db->prepare("UPDATE users SET status='expired', updated_at=NOW() WHERE id=?")->execute([$user['id']]);
    api_json(['ok'=>false,'valid'=>false,'error'=>"Votre période d'essai a expiré. Contactez-nous pour continuer."]);
  }
}

if ($user['status'] === 'expired') api_json(['ok'=>false,'valid'=>false,'error'=>"Votre période d'essai a expiré. Contactez-nous pour continuer."]);

// Register/update activation
$existing = $db->prepare('SELECT id FROM activations WHERE user_id=? AND machine_id=?');
$existing->execute([$user['id'], $machine_id]);
if ($existing->fetchColumn()) {
  $db->prepare('UPDATE activations SET last_seen=NOW(), botora_version=? WHERE user_id=? AND machine_id=?')
     ->execute([$botora_version, $user['id'], $machine_id]);
} else {
  $db->prepare('INSERT INTO activations (user_id,machine_id,ip_address,botora_version) VALUES (?,?,?,?)')
     ->execute([$user['id'], $machine_id, $ip, $botora_version]);
}

// Calculate trial days left
$trial_days_left = null;
if ($user['trial_ends_at']) {
  $trial_days_left = max(0, (int)ceil((strtotime($user['trial_ends_at']) - time()) / 86400));
}

api_json([
  'ok'              => true,
  'valid'           => true,
  'user_id'         => $user['id'],
  'name'            => $user['name'],
  'status'          => $user['status'],
  'credits_balance' => (int)$user['credits_balance'],
  'trial_ends_at'   => $user['trial_ends_at'],
  'trial_days_left' => $trial_days_left,
  'plan'            => [
    'name'               => $user['plan_name'],
    'slug'               => $user['plan_slug'],
    'credits_per_month'  => (int)$user['credits_per_month'],
    'max_profiles'       => (int)$user['max_profiles'],
    'campaigns_enabled'  => (bool)$user['campaigns_enabled'],
    'ia_enabled'         => (bool)$user['ia_enabled'],
  ],
]);
