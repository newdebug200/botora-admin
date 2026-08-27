<?php
require_once __DIR__ . '/../includes/payment.php';
verify_service_key();
$data = payment_request_json();
$email = strtolower(trim((string)($data['email'] ?? '')));
$name = trim((string)($data['name'] ?? 'Client Botora')) ?: 'Client Botora';
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) api_json(['ok'=>false,'error'=>'Email invalide.'],400);
$db = db();
$stmt = $db->prepare('SELECT * FROM users WHERE email=? LIMIT 1'); $stmt->execute([$email]); $user = $stmt->fetch();
if ($user) {
  $db->prepare('UPDATE users SET name=?, phone=COALESCE(?,phone), updated_at=NOW() WHERE id=?')->execute([$name, $data['phone'] ?? null, $user['id']]);
  $stmt = $db->prepare('SELECT * FROM users WHERE id=?'); $stmt->execute([$user['id']]); $user = $stmt->fetch();
} else {
  $db->prepare('INSERT INTO users (name,email,phone,license_key,status) VALUES (?,?,?,?,?)')->execute([$name,$email,$data['phone'] ?? null,generate_license(),'active']);
  $stmt = $db->prepare('SELECT * FROM users WHERE id=?'); $stmt->execute([(int)$db->lastInsertId()]); $user = $stmt->fetch();
}
api_json(['ok'=>true,'user'=>['id'=>(int)$user['id'],'email'=>$user['email'],'name'=>$user['name'],'license_key'=>$user['license_key'],'status'=>$user['status'],'credits_balance'=>(float)$user['credits_balance']]]);
