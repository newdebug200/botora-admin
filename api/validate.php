<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');
verify_api_key();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') api_json(['ok'=>false,'error'=>'Method not allowed'],405);

$body = json_decode(file_get_contents('php://input'), true);
$license_key = trim($body['license_key'] ?? '');
$machine_id = trim($body['machine_id'] ?? '');
$botora_version = trim($body['version'] ?? '');
$ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';

if (!$license_key) api_json(['ok'=>false,'valid'=>false,'error'=>'license_key required'],400);

$db = db();
$stmt = $db->prepare('SELECT u.*, p.name as plan_name, p.slug as plan_slug, p.credits_per_month, p.max_profiles, p.campaigns_enabled, p.ia_enabled FROM users u LEFT JOIN plans p ON p.id=u.plan_id WHERE u.license_key=? LIMIT 1');
$stmt->execute([$license_key]);
$user = $stmt->fetch();

if (!$user) api_json(['ok'=>false,'valid'=>false,'error'=>'Licence inconnue.'],404);
$access = botora_access($db, $user);
if (!$access['access_allowed']) {
  $message = $access['access_type'] === 'banned' ? 'Cette licence a été bannie. Contactez le support.'
    : ($access['access_type'] === 'suspended' ? 'Votre accès est suspendu. Contactez le support.' : "Votre période d'essai ou votre abonnement est terminé. Souscrivez pour continuer.");
  api_json(['ok'=>false,'valid'=>false,'access_allowed'=>false,'access_type'=>$access['access_type'],'error'=>$message],403);
}

$existing = $db->prepare('SELECT id FROM activations WHERE user_id=? AND machine_id=?');
$existing->execute([$user['id'], $machine_id]);
if ($existing->fetchColumn()) {
  $db->prepare('UPDATE activations SET last_seen=NOW(), botora_version=? WHERE user_id=? AND machine_id=?')->execute([$botora_version, $user['id'], $machine_id]);
} else {
  $db->prepare('INSERT INTO activations (user_id,machine_id,ip_address,botora_version) VALUES (?,?,?,?)')->execute([$user['id'], $machine_id, $ip, $botora_version]);
}

api_json(array_merge([
  'ok'=>true, 'valid'=>true, 'access_allowed'=>true, 'user_id'=>(int)$user['id'], 'name'=>$user['name'], 'status'=>$user['status'],
  'credits_balance'=>(float)$user['credits_balance'], 'trial_ends_at'=>$user['trial_ends_at'], 'subscription_ends_at'=>$user['subscription_ends_at'],
  'plan'=>[
    'name'=>$user['plan_name'], 'slug'=>$user['plan_slug'], 'credits_per_month'=>(int)$user['credits_per_month'],
    'max_profiles'=>(int)$user['max_profiles'], 'campaigns_enabled'=>(bool)$user['campaigns_enabled'], 'ia_enabled'=>(bool)$user['ia_enabled']
  ]
], $access));
