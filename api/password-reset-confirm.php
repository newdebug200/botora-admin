<?php
require_once __DIR__ . '/../includes/payment.php';
verify_password_reset_service_key();
$data = payment_request_json();
$email = strtolower(trim((string)($data['email'] ?? '')));
$code = trim((string)($data['code'] ?? ''));
$newPassword = (string)($data['new_password'] ?? '');
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !preg_match('/^\d{6}$/', $code) || strlen($newPassword) < 8) {
  api_json(['ok' => false, 'error' => 'Email, code à 6 chiffres et nouveau mot de passe de 8 caractères minimum requis.'], 400);
}

$db = db();
$userStmt = $db->prepare('SELECT id,email FROM users WHERE email=? LIMIT 1');
$userStmt->execute([$email]);
$user = $userStmt->fetch();
if (!$user) api_json(['ok' => false, 'error' => 'Code invalide ou expiré.'], 400);

$requestStmt = $db->prepare('SELECT * FROM password_reset_requests WHERE user_id=? AND used_at IS NULL AND expires_at > NOW() ORDER BY created_at DESC, id DESC LIMIT 1');
$requestStmt->execute([(int)$user['id']]);
$request = $requestStmt->fetch();
if (!$request || (int)$request['attempts'] >= 5) api_json(['ok' => false, 'error' => 'Code invalide ou expiré.'], 400);
if (!password_verify($code, $request['code_hash'])) {
  $db->prepare('UPDATE password_reset_requests SET attempts=attempts+1 WHERE id=?')->execute([(int)$request['id']]);
  api_json(['ok' => false, 'error' => 'Code invalide ou expiré.'], 400);
}

$db->beginTransaction();
try {
  $db->prepare('UPDATE users SET password_hash=?, updated_at=NOW() WHERE id=?')->execute([password_hash($newPassword, PASSWORD_DEFAULT), (int)$user['id']]);
  $db->prepare('UPDATE password_reset_requests SET used_at=NOW() WHERE id=?')->execute([(int)$request['id']]);
  $db->commit();
  api_json(['ok' => true, 'message' => 'Mot de passe réinitialisé avec succès.']);
} catch (Throwable $e) {
  if ($db->inTransaction()) $db->rollBack();
  throw $e;
}
