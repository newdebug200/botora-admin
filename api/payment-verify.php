<?php
require_once __DIR__ . '/../includes/payment.php';
verify_service_key();
$data = payment_request_json();
$user = payment_user($data);
$paymentId = (int)($data['payment_id'] ?? 0);
$externalId = trim((string)($data['transaction_id'] ?? ''));
if (!$user || (!$paymentId && $externalId === '')) api_json(['ok' => false, 'error' => 'Utilisateur et transaction requis.'], 400);
$db = db();
$stmt = $db->prepare('SELECT * FROM payment_transactions WHERE user_id=? AND ' . ($paymentId ? 'id=?' : 'external_id=?') . ' LIMIT 1');
$stmt->execute([$user['id'], $paymentId ?: $externalId]);
$payment = $stmt->fetch();
if (!$payment) api_json(['ok' => false, 'error' => 'Paiement introuvable.'], 404);
if ($payment['status'] === 'approved') api_json(['ok' => true, 'status' => 'approved', 'approved' => true, 'alreadyCredited' => true, 'credits' => (float)$payment['credits'], 'balance' => (float)$user['credits_balance']]);
try {
  $transaction = fedapay_unwrap(fedapay_request('GET', '/transactions/' . rawurlencode((string)$payment['external_id'])));
  $status = strtolower((string)($transaction['status'] ?? 'pending'));
  $result = payment_credit_approved((int)$payment['id'], $transaction, 'manual:' . $payment['external_id'] . ':' . $status, 'manual.verify', json_encode($transaction, JSON_UNESCAPED_UNICODE));
  $balanceStmt = $db->prepare('SELECT credits_balance FROM users WHERE id=?');
  $balanceStmt->execute([$user['id']]);
  api_json(['ok' => true, 'status' => $status, 'approved' => $status === 'approved', 'credits' => $result['credits'], 'balance' => (float)$balanceStmt->fetchColumn(), 'message' => $status === 'approved' ? 'Paiement approuvé : crédits ajoutés.' : 'Paiement non approuvé (' . $status . ').']);
} catch (Throwable $e) {
  error_log('[Botora Admin] FedaPay verify: ' . $e->getMessage());
  api_log_set_error($e->getMessage());
  api_json(['ok' => false, 'error' => 'Vérification FedaPay temporairement indisponible.'], 502);
}
