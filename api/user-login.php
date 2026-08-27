<?php
require_once __DIR__ . '/../includes/payment.php';
verify_service_key();
$data = payment_request_json();
$email = strtolower(trim((string)($data['email'] ?? '')));
$password = (string)($data['password'] ?? '');
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '') api_json(['ok'=>false,'error'=>'Identifiants invalides.'],400);
$db = db();
$stmt = $db->prepare('SELECT id,name,email,password_hash,license_key,status,credits_balance,plan_id FROM users WHERE email=? LIMIT 1');
$stmt->execute([$email]);
$user = $stmt->fetch();
if (!$user || empty($user['password_hash']) || !password_verify($password, $user['password_hash']) || in_array($user['status'], ['suspended','expired','banned'], true)) api_json(['ok'=>false,'error'=>'Email ou mot de passe incorrect.'],401);
api_json(['ok'=>true,'user'=>[
  'id'=>(int)$user['id'], 'name'=>$user['name'], 'email'=>$user['email'],
  'password_hash'=>$user['password_hash'], 'license_key'=>$user['license_key'],
  'status'=>$user['status'], 'credits_balance'=>(float)$user['credits_balance'],
  'plan_id'=>$user['plan_id'] ? (int)$user['plan_id'] : null
]]);
?>

La route est interne : elle exige `X-Botora-Service-Key` et ne doit jamais être appelée directement par le navigateur.
