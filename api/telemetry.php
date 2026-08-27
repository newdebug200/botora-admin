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
api_json(['ok'=>true,'recorded'=>true]);
