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
  api_json(['ok'=>true,'user'=>array_merge([
    'id'=>(int)$user['id'], 'name'=>$user['name'], 'email'=>$user['email'],
    'company'=>$user['company'] ?? null, 'phone'=>$user['phone'] ?? null, 'license_key'=>$user['license_key'] ?? null,
    'status'=>$user['status'], 'control_center_access'=>$controlCenterAccess, 'credits_balance'=>(float)($user['credits_balance'] ?? 0),
    'plan_id'=>!empty($user['plan_id']) ? (int)$user['plan_id'] : null,
    'trial_started_at'=>$user['trial_started_at'] ?? null, 'trial_ends_at'=>$user['trial_ends_at'] ?? null,
    'trial_used'=>(bool)($user['trial_used'] ?? true), 'subscription_started_at'=>$user['subscription_started_at'] ?? null,
    'subscription_ends_at'=>$user['subscription_ends_at'] ?? null, 'created_at'=>$user['created_at'] ?? null, 'updated_at'=>$user['updated_at'] ?? null,
  ], $access)]);
} catch (Throwable $e) {
  error_log('[Botora Admin] account read: '.$e->getMessage());
  api_log_set_error($e->getMessage());
  api_json(['ok'=>false,'error'=>'Impossible de récupérer le compte central.'], 500);
}
