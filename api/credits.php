<?php
require_once __DIR__ . '/../includes/payment.php';
verify_service_key();
$data = payment_request_json();
$user = payment_user($data);
if (!$user) api_json(['ok' => false, 'error' => 'Utilisateur Botora introuvable.'], 404);
$db = db();
$limit = min(100, max(1, (int)($data['limit'] ?? 50)));
$stmt = $db->prepare('SELECT id, external_id, amount_xof, credits, status, description, approved_at, created_at FROM payment_transactions WHERE user_id=? ORDER BY created_at DESC LIMIT ' . $limit);
$stmt->execute([$user['id']]);
api_json(['ok' => true, 'user_id' => (int)$user['id'], 'license_key' => $user['license_key'], 'balance' => (float)$user['credits_balance'], 'transactions' => $stmt->fetchAll()]);
