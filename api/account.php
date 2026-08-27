<?php
require_once __DIR__ . '/../includes/payment.php';
verify_service_key();
$email = strtolower(trim((string)($_GET['email'] ?? '')));
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) api_json(['ok'=>false,'error'=>'Email invalide.'], 400);
try {
  $db = db();
  $stmt = $db->prepare('SELECT id,name,email,company,phone,license_key,status,credits_balance,plan_id,trial_ends_at,created_at,updated_at FROM users WHERE email=? LIMIT 1');
  $stmt->execute([$email]);
  $user = $stmt->fetch();
  if (!$user) api_json(['ok'=>false,'error'=>'Utilisateur introuvable.'], 404);
  api_json(['ok'=>true,'user'=>[
    'id'=>(int)$user['id'], 'name'=>$user['name'], 'email'=>$user['email'],
    'company'=>$user['company'], 'phone'=>$user['phone'], 'license_key'=>$user['license_key'],
    'status'=>$user['status'], 'credits_balance'=>(float)$user['credits_balance'],
    'plan_id'=>$user['plan_id'] ? (int)$user['plan_id'] : null,
    'trial_ends_at'=>$user['trial_ends_at'], 'created_at'=>$user['created_at'], 'updated_at'=>$user['updated_at']
  ]]);
} catch (Throwable $e) {
  error_log('[Botora Admin] account read: '.$e->getMessage());
  api_json(['ok'=>false,'error'=>'Impossible de récupérer le compte central.'], 500);
}
