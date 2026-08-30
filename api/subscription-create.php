<?php
require_once __DIR__ . '/../includes/payment.php';
verify_service_key();
$data = payment_request_json();
$user = payment_user($data);
if (!$user) api_json(['ok'=>false,'error'=>'Utilisateur Botora introuvable.'],404);
$db = db();
$config = $db->query('SELECT price_xof,duration_days,is_active FROM subscription_config WHERE id=1 LIMIT 1')->fetch() ?: [];
$amount = (int)round((float)($config['price_xof'] ?? 0));
$durationDays = 365;
if (!(bool)($config['is_active'] ?? false) || $amount <= 0) api_json(['ok'=>false,'error'=>'L’abonnement annuel n’est pas disponible pour le moment.'],503);
$merchantReference = 'BOTORA-SUB-' . $user['id'] . '-' . date('YmdHis') . '-' . bin2hex(random_bytes(4));
$paymentId = 0;
try {
  $stmt = $db->prepare('INSERT INTO subscription_payments (user_id,external_id,amount_xof,duration_days,status,description) VALUES (?,?,?,?,?,?)');
  $stmt->execute([$user['id'], $merchantReference, $amount, $durationDays, 'pending', 'Abonnement Botora annuel']);
  $paymentId = (int)$db->lastInsertId();
  $created = fedapay_unwrap(fedapay_request('POST', '/transactions', [
    'description' => 'Abonnement Botora — 1 an',
    'amount' => $amount,
    'currency' => ['iso' => 'XOF'],
    'custom_metadata' => ['botora_subscription_payment_id' => (string)$paymentId, 'user_id' => (string)$user['id'], 'duration_days' => (string)$durationDays],
    'customer' => ['firstname' => $user['name'] ?: 'Client', 'lastname' => 'Botora', 'email' => $user['email']]
  ]));
  $providerId = (string)($created['id'] ?? '');
  if ($providerId === '') throw new RuntimeException('Identifiant FedaPay absent.');
  $tokenData = fedapay_request('POST', '/transactions/' . rawurlencode($providerId) . '/token', []);
  $token = (string)($tokenData['token'] ?? $tokenData['payment_token'] ?? '');
  $paymentUrl = (string)($tokenData['url'] ?? $tokenData['payment_url'] ?? '');
  if ($paymentUrl === '' && $token !== '') $paymentUrl = 'https://checkout.fedapay.com/' . rawurlencode($token);
  if ($paymentUrl === '') throw new RuntimeException('Lien FedaPay absent.');
  $db->prepare('UPDATE subscription_payments SET external_id=?, metadata=? WHERE id=?')->execute([$providerId, json_encode(['merchant_reference'=>$merchantReference,'provider'=>$created,'token_response'=>$tokenData], JSON_UNESCAPED_UNICODE), $paymentId]);
  api_json(['ok'=>true,'paymentId'=>$paymentId,'transactionId'=>$providerId,'paymentUrl'=>$paymentUrl,'amount'=>$amount,'duration_days'=>$durationDays]);
} catch (Throwable $e) {
  if ($paymentId) $db->prepare('UPDATE subscription_payments SET status=?, metadata=? WHERE id=?')->execute(['creation_failed', json_encode(['error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE), $paymentId]);
  error_log('[Botora Admin] subscription create: ' . $e->getMessage());
  api_log_set_error($e->getMessage());
  api_json(['ok'=>false,'error'=>'Impossible de créer le paiement de l’abonnement.'],502);
}
