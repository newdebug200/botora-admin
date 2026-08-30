<?php
require_once __DIR__ . '/../includes/payment.php';
verify_service_key();
$data = payment_request_json();
$user = payment_user($data);
$paymentId = (int)($data['payment_id'] ?? 0);
$externalId = trim((string)($data['transaction_id'] ?? ''));
if (!$user || (!$paymentId && $externalId === '')) api_json(['ok'=>false,'error'=>'Utilisateur et transaction requis.'],400);
$db = db();
$stmt = $db->prepare('SELECT * FROM subscription_payments WHERE user_id=? AND ' . ($paymentId ? 'id=?' : 'external_id=?') . ' LIMIT 1');
$stmt->execute([$user['id'], $paymentId ?: $externalId]);
$payment = $stmt->fetch();
if (!$payment) api_json(['ok'=>false,'error'=>'Paiement abonnement introuvable.'],404);
if ($payment['status'] === 'approved') {
  $fresh = $db->prepare('SELECT subscription_ends_at FROM users WHERE id=?'); $fresh->execute([$user['id']]);
  api_json(['ok'=>true,'status'=>'approved','approved'=>true,'alreadyActivated'=>true,'subscription_ends_at'=>$fresh->fetchColumn()]);
}
try {
  $transaction = fedapay_unwrap(fedapay_request('GET', '/transactions/' . rawurlencode((string)$payment['external_id'])));
  $status = strtolower((string)($transaction['status'] ?? 'pending'));
  $result = subscription_approved((int)$payment['id'], $transaction, 'manual:' . $payment['external_id'] . ':' . $status, 'manual.subscription.verify', json_encode($transaction, JSON_UNESCAPED_UNICODE));
  $fresh = $db->prepare('SELECT status,subscription_ends_at FROM users WHERE id=?'); $fresh->execute([$user['id']]); $account = $fresh->fetch() ?: [];
  api_json(['ok'=>true,'status'=>$status,'approved'=>$status==='approved','activated'=>$status==='approved','subscription_ends_at'=>$account['subscription_ends_at'] ?? ($result['subscription_ends_at'] ?? null),'message'=>$status==='approved' ? 'Abonnement activé pour un an.' : 'Paiement non approuvé (' . $status . ').']);
} catch (Throwable $e) {
  error_log('[Botora Admin] subscription verify: ' . $e->getMessage());
  api_log_set_error($e->getMessage());
  api_json(['ok'=>false,'error'=>'Vérification du paiement d’abonnement temporairement indisponible.'],502);
}
