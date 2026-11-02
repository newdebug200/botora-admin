<?php
require_once __DIR__ . '/../includes/payment.php';
verify_service_key();
$data = payment_request_json();
$email = strtolower(trim((string)($data['email'] ?? '')));
$name = trim((string)($data['name'] ?? 'Client Botora')) ?: 'Client Botora';
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) api_json(['ok'=>false,'error'=>'Email invalide.'],400);
$db = db();
$stmt = $db->prepare('SELECT * FROM users WHERE email=? LIMIT 1'); $stmt->execute([$email]); $user = $stmt->fetch();
$password = (string)($data['password'] ?? '');
$passwordHash = trim((string)($data['password_hash'] ?? ''));
if ($password !== '' && strlen($password) < 8) api_json(['ok'=>false,'error'=>'Le mot de passe doit contenir au moins 8 caractères.'],400);
if ($password !== '') $passwordHash = password_hash($password, PASSWORD_DEFAULT);
if ($user) {
  if ($passwordHash !== '' && preg_match('/^\$2[ayb]\$\d{2}\$/', $passwordHash)) {
    $db->prepare('UPDATE users SET name=?, phone=COALESCE(?,phone), password_hash=?, updated_at=NOW() WHERE id=?')->execute([$name, $data['phone'] ?? null, $passwordHash, $user['id']]);
  } else {
    $db->prepare('UPDATE users SET name=?, phone=COALESCE(?,phone), updated_at=NOW() WHERE id=?')->execute([$name, $data['phone'] ?? null, $user['id']]);
  }
  $stmt = $db->prepare('SELECT * FROM users WHERE id=?'); $stmt->execute([$user['id']]); $user = $stmt->fetch();
} else {
  $now = botora_server_now($db);
  $trialStart = $now->format('Y-m-d H:i:s');
  $trialEnd = $now->modify('+14 days')->format('Y-m-d H:i:s');
  $freePlanId = $db->query("SELECT id FROM plans WHERE slug='free' AND is_active=1 LIMIT 1")->fetchColumn() ?: null;
  $db->prepare('INSERT INTO users (name,email,password_hash,phone,plan_id,license_key,status,credits_balance,trial_started_at,trial_ends_at,trial_used) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
    ->execute([$name, $email, ($passwordHash !== '' && preg_match('/^\$2[ayb]\$\d{2}\$/', $passwordHash)) ? $passwordHash : null, $data['phone'] ?? null, $freePlanId, generate_license(), 'trial', 0, $trialStart, $trialEnd, 1]);
  $stmt = $db->prepare('SELECT * FROM users WHERE id=?'); $stmt->execute([(int)$db->lastInsertId()]); $user = $stmt->fetch();
}
$access = botora_access($db, $user);
api_json(['ok'=>true,'confirmed'=>true,'user'=>array_merge([
  'id'=>(int)$user['id'], 'email'=>$user['email'], 'name'=>$user['name'], 'license_key'=>$user['license_key'],
  'status'=>$user['status'], 'credits_balance'=>(float)$user['credits_balance'], 'password_hash'=>$user['password_hash'] ?? null,
  'trial_started_at'=>$user['trial_started_at'] ?? null, 'trial_ends_at'=>$user['trial_ends_at'] ?? null,
  'trial_used'=>(bool)($user['trial_used'] ?? true), 'subscription_started_at'=>$user['subscription_started_at'] ?? null,
  'subscription_ends_at'=>$user['subscription_ends_at'] ?? null,
], $access)]);
