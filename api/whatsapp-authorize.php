<?php
require_once __DIR__ . '/../includes/payment.php';
verify_service_key();
$data = payment_request_json();
$email = strtolower(trim((string)($data['email'] ?? '')));
$phoneNumber = trim((string)($data['phone_number'] ?? ''));
$profileKey = trim((string)($data['profile_id'] ?? ''));
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $phoneNumber === '') {
  api_json(['ok' => false, 'allowed' => false, 'error' => 'Compte et numéro WhatsApp requis.'], 400);
}

$db = db();
$userStmt = $db->prepare('SELECT id,status,subscription_ends_at FROM users WHERE email=? LIMIT 1');
$userStmt->execute([$email]);
$user = $userStmt->fetch();
if (!$user) api_json(['ok' => false, 'allowed' => false, 'error' => 'Compte Botora introuvable.'], 404);

$paidActive = strtolower((string)$user['status']) === 'active'
  && !empty($user['subscription_ends_at'])
  && strtotime((string)$user['subscription_ends_at']) > time();
$existingStmt = $db->prepare('SELECT id,user_id,profile_key FROM whatsapp_accounts WHERE phone_number=? AND user_id<>? LIMIT 1');
$existingStmt->execute([$phoneNumber, (int)$user['id']]);
$existing = $existingStmt->fetch();
$historyStmt = $db->prepare('SELECT id,first_user_id FROM whatsapp_trial_history WHERE phone_number=? AND (first_user_id IS NULL OR first_user_id<>?) LIMIT 1');
$historyStmt->execute([$phoneNumber, (int)$user['id']]);
$trialHistory = $historyStmt->fetch();

if (($existing || $trialHistory) && !$paidActive) {
  api_json([
    'ok' => true,
    'allowed' => false,
    'reason' => 'trial_whatsapp_reuse',
    'error' => 'Ce numéro WhatsApp a déjà été utilisé avec un autre compte Botora. Un abonnement annuel actif est requis pour le connecter ici.'
  ], 403);
}

api_json([
  'ok' => true,
  'allowed' => true,
  'paid_active' => $paidActive,
  'profile_id' => $profileKey !== '' ? $profileKey : null
]);
