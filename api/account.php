<?php
require_once __DIR__ . '/../includes/payment.php';
verify_service_key();
$email = strtolower(trim((string)($_GET['email'] ?? '')));
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) api_json(['ok'=>false,'error'=>'Email invalide.'], 400);
try {
  $db = db();
  // SELECT * garde la compatibilité avec les bases historiques avant migration.
  $stmt = $db->prepare('SELECT * FROM users WHERE email=? LIMIT 1');
  $stmt->execute([$email]);
  $user = $stmt->fetch();
  if (!$user) api_json(['ok'=>false,'error'=>'Utilisateur introuvable.'], 404);
  $access = botora_access($db, $user);
  if ($access['access_type'] === 'expired') $user['status'] = 'expired';
  if ($access['access_type'] === 'subscription') $user['status'] = 'active';
  $controlCenterAccess = array_key_exists('control_center_access', $user) && $user['control_center_access'] !== null
    ? (bool)$user['control_center_access'] : null;
  api_json(['ok'=>true,'user'=>[
    'id'=>(int)$user['id'], 'name'=>$user['name'], 'email'=>$user['email'],
    'company'=>$user['company'], 'phone'=>$user['phone'], 'license_key'=>$user['license_key'],
    'status'=>$user['status'], 'control_center_access'=>$controlCenterAccess, 'credits_balance'=>(float)$user['credits_balance'],
    'plan_id'=>$user['plan_id'] ? (int)$user['plan_id'] : null,
    'trial_started_at'=>$user['trial_started_at'], 'trial_ends_at'=>$user['trial_ends_at'],
    'trial_used'=>(bool)$user['trial_used'], 'subscription_started_at'=>$user['subscription_started_at'],
    'subscription_ends_at'=>$user['subscription_ends_at'], 'created_at'=>$user['created_at'], 'updated_at'=>$user['updated_at'],
    ...$access
  ]]);
} catch (Throwable $e) {
  error_log('[Botora Admin] account read: '.$e->getMessage());
  api_log_set_error($e->getMessage());
  api_json(['ok'=>false,'error'=>'Impossible de récupérer le compte central.'], 500);
}
