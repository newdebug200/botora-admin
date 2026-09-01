<?php
require_once __DIR__ . '/../includes/payment.php';
verify_service_key();
$data = payment_request_json();
$email = strtolower(trim((string)($data['email'] ?? '')));
$password = (string)($data['password'] ?? '');
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '') api_json(['ok'=>false,'error'=>'Identifiants invalides.'],400);
$db = db();
$stmt = $db->prepare('SELECT * FROM users WHERE email=? LIMIT 1');
$stmt->execute([$email]);
$user = $stmt->fetch();
if (!$user || empty($user['password_hash']) || !password_verify($password, $user['password_hash'])) api_json(['ok'=>false,'error'=>'Email ou mot de passe incorrect.'],401);
$access = botora_access($db, $user);
$controlCenterAccess = array_key_exists('control_center_access', $user) && $user['control_center_access'] !== null
  ? (bool)$user['control_center_access'] : null;
if (!$access['access_allowed']) {
  $message = $access['access_type'] === 'suspended' ? 'Votre accès est suspendu. Contactez le support.'
    : ($access['access_type'] === 'banned' ? 'Cette licence a été bannie. Contactez le support.' : "Votre période d'essai ou votre abonnement est terminé. Souscrivez pour continuer.");
  if (in_array($access['access_type'], ['suspended', 'banned'], true)) api_json(['ok'=>false,'access_allowed'=>false,'access_type'=>$access['access_type'],'error'=>$message], 403);
  api_json(['ok'=>true,'requires_subscription'=>true,'access_allowed'=>false,'access_type'=>$access['access_type'],'message'=>$message,'user'=>array_merge([
    'id'=>(int)$user['id'], 'name'=>$user['name'], 'email'=>$user['email'], 'password_hash'=>$user['password_hash'],
    'license_key'=>$user['license_key'], 'status'=>'expired', 'control_center_access'=>$controlCenterAccess, 'credits_balance'=>(float)($user['credits_balance'] ?? 0),
    'plan_id'=>!empty($user['plan_id']) ? (int)$user['plan_id'] : null,
  ], $access)]);
}
if ($access['access_type'] === 'subscription') $user['status'] = 'active';
api_json(['ok'=>true,'user'=>array_merge([
  'id'=>(int)$user['id'], 'name'=>$user['name'], 'email'=>$user['email'],
  'password_hash'=>$user['password_hash'], 'license_key'=>$user['license_key'],
  'status'=>$user['status'], 'control_center_access'=>$controlCenterAccess, 'credits_balance'=>(float)($user['credits_balance'] ?? 0),
  'plan_id'=>!empty($user['plan_id']) ? (int)$user['plan_id'] : null,
], $access)]);
