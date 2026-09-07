<?php
require_once __DIR__ . '/../includes/payment.php';
verify_service_key();
$data = payment_request_json();
$user = payment_user($data);
$eventType = trim((string)($data['event_type'] ?? ''));
if (!$eventType || !preg_match('/^[a-z0-9_.-]{2,80}$/i', $eventType)) api_json(['ok'=>false,'error'=>'event_type invalide.'],400);
$db = db();
$payload = $data['payload'] ?? [];
if (!is_array($payload)) $payload = ['value' => (string)$payload];
$db->prepare('INSERT INTO activity_logs (user_id,event_type,tokens_used,credits_used,payload) VALUES (?,?,?,?,?)')->execute([$user['id'] ?? null, $eventType, isset($data['tokens_used']) ? max(0,(int)$data['tokens_used']) : null, isset($data['credits_used']) ? (float)$data['credits_used'] : null, json_encode($payload, JSON_UNESCAPED_UNICODE)]);

// Keep a durable, queryable registry of each WhatsApp number connected by a user.
$phoneNumber = trim((string)($payload['phone_number'] ?? ''));
$profileKey = trim((string)($payload['profile_id'] ?? ''));
if ($user['id'] && $eventType === 'whatsapp.profile_connected' && $phoneNumber !== '') {
  $db->prepare('INSERT INTO whatsapp_accounts (user_id,profile_key,phone_number,is_connected,last_connected_at,disconnected_at) VALUES (?,?,?,1,NOW(),NULL) ON DUPLICATE KEY UPDATE profile_key=VALUES(profile_key),is_connected=1,last_connected_at=NOW(),disconnected_at=NULL')
    ->execute([$user['id'], $profileKey !== '' ? $profileKey : null, $phoneNumber]);
} elseif ($user['id'] && $eventType === 'whatsapp.profile_disconnected') {
  if ($phoneNumber !== '') {
    $db->prepare('UPDATE whatsapp_accounts SET is_connected=0,disconnected_at=NOW() WHERE user_id=? AND phone_number=?')->execute([$user['id'], $phoneNumber]);
  } elseif ($profileKey !== '') {
    $db->prepare('UPDATE whatsapp_accounts SET is_connected=0,disconnected_at=NOW() WHERE user_id=? AND profile_key=?')->execute([$user['id'], $profileKey]);
  }
}
api_json(['ok'=>true,'recorded'=>true]);
