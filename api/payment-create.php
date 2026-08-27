<?php
require_once __DIR__ . '/../includes/payment.php';
verify_service_key();
$data = payment_request_json();
$user = payment_user($data);
$credits = round((float)($data['credits'] ?? 0), 10);
if (!$user) api_json(['ok' => false, 'error' => 'Utilisateur Botora introuvable.'], 404);
if (!is_finite($credits) || $credits < 5) api_json(['ok' => false, 'error' => 'Le minimum est de 5 crédits.'], 400);
$amount = (int)round($credits * CREDIT_VALUE_XOF);
$merchantReference = 'BOTORA-' . $user['id'] . '-' . date('YmdHis') . '-' . bin2hex(random_bytes(4));
$db = db();
try {
  $stmt = $db->prepare('INSERT INTO payment_transactions (user_id,external_id,amount_xof,credits,status,description) VALUES (?,?,?,?,?,?)');
  $stmt->execute([$user['id'], $merchantReference, $amount, $credits, 'pending', 'Recharge de ' . $credits . ' crédit(s)']);
  $paymentId = (int)$db->lastInsertId();
  $created = fedapay_unwrap(fedapay_request('POST', '/transactions', [
    'description' => 'Recharge Botora — ' . $credits . ' crédit(s)',
    'amount' => $amount,
    'currency' => ['iso' => 'XOF'],
    'callback_url' => FEDAPAY_CALLBACK_URL,
    'custom_metadata' => ['botora_payment_id' => (string)$paymentId, 'user_id' => (string)$user['id'], 'credits' => (string)$credits],
    'customer' => ['firstname' => $user['name'] ?: 'Client', 'lastname' => 'Botora', 'email' => $user['email']]
  ]));
  $providerId = (string)($created['id'] ?? '');
  if ($providerId === '') throw new RuntimeException('Identifiant FedaPay absent.');
  $tokenData = fedapay_request('POST', '/transactions/' . rawurlencode($providerId) . '/token', []);
  $token = (string)($tokenData['token'] ?? $tokenData['payment_token'] ?? '');
  $paymentUrl = (string)($tokenData['url'] ?? $tokenData['payment_url'] ?? '');
  if ($paymentUrl === '' && $token !== '') $paymentUrl = 'https://checkout.fedapay.com/' . rawurlencode($token);
  if ($paymentUrl === '') throw new RuntimeException('Lien FedaPay absent.');
  $db->prepare('UPDATE payment_transactions SET external_id=?, metadata=? WHERE id=?')->execute([$providerId, json_encode(['merchant_reference' => $merchantReference, 'provider' => $created, 'token_response' => $tokenData], JSON_UNESCAPED_UNICODE), $paymentId]);
  api_json(['ok' => true, 'paymentId' => $paymentId, 'transactionId' => $providerId, 'paymentUrl' => $paymentUrl, 'amount' => $amount, 'credits' => $credits]);
} catch (Throwable $e) {
  if (!empty($paymentId)) $db->prepare('UPDATE payment_transactions SET status=?, metadata=? WHERE id=?')->execute(['creation_failed', json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE), $paymentId]);
  error_log('[Botora Admin] FedaPay create: ' . $e->getMessage());
  api_json(['ok' => false, 'error' => 'Impossible de créer le paiement FedaPay.'], 502);
}
