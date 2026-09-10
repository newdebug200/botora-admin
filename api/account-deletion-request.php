<?php
require_once __DIR__ . '/../includes/payment.php';
verify_password_reset_service_key();
$data = payment_request_json();
$email = strtolower(trim((string)($data['email'] ?? '')));
$reasonCode = trim((string)($data['reason_code'] ?? ''));
$reasonText = trim((string)($data['reason_text'] ?? ''));
$allowedReasons = [
  'no_longer_needed',
  'too_expensive',
  'difficult_to_use',
  'missing_features',
  'privacy_concern',
  'other'
];
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !in_array($reasonCode, $allowedReasons, true)) {
  api_json(['ok' => false, 'error' => 'Adresse e-mail ou motif invalide.'], 400);
}
if ($reasonCode === 'other' && mb_strlen($reasonText) < 20) {
  api_json(['ok' => false, 'error' => 'Pour le motif Autre, la précision doit contenir au moins 20 caractères.'], 400);
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
