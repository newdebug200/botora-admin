<?php
require_once __DIR__ . '/../includes/payment.php';
require_once __DIR__ . '/../includes/account-deletion.php';
verify_password_reset_service_key();
$data = payment_request_json();
$email = strtolower(trim((string)($data['email'] ?? '')));
$reasonCode = trim((string)($data['reason_code'] ?? ''));
$reasonText = trim((string)($data['reason_text'] ?? ''));
[$reasonValid, $reasonError] = validate_account_deletion_reason($reasonCode, $reasonText);
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !$reasonValid) {
  api_json(['ok' => false, 'error' => !filter_var($email, FILTER_VALIDATE_EMAIL) ? 'Adresse e-mail ou motif invalide.' : $reasonError], 400);
}
$db = db();
$userStmt = $db->prepare('SELECT id,name,email FROM users WHERE email=? LIMIT 1');
$userStmt->execute([$email]);
$user = $userStmt->fetch();
if (!$user) api_json(['ok' => false, 'error' => 'Compte Botora introuvable.'], 404);
$pending = $db->prepare("SELECT id FROM account_deletion_requests WHERE user_id=? AND status='pending' LIMIT 1");
$pending->execute([(int)$user['id']]);
if ($pending->fetch()) api_json(['ok' => true, 'already_pending' => true, 'message' => 'Une demande de suppression est déjà en cours d’examen.']);
$insert = $db->prepare('INSERT INTO account_deletion_requests (user_id,name,email,reason_code,reason_text) VALUES (?,?,?,?,?)');
$insert->execute([(int)$user['id'], (string)$user['name'], (string)$user['email'], $reasonCode, $reasonText !== '' ? $reasonText : null]);
api_json(['ok' => true, 'request_id' => (int)$db->lastInsertId(), 'message' => 'Votre demande a été enregistrée et sera examinée par Botora Admin.']);
