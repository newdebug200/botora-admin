<?php
require_once __DIR__ . '/../../includes/payment.php';
$raw = file_get_contents('php://input') ?: '{}';
$event = json_decode($raw, true);
if (!is_array($event)) api_json(['ok' => false, 'error' => 'Payload JSON invalide.'], 400);
$payload = $event['object'] ?? $event['data'] ?? $event['transaction'] ?? $event;
if (isset($payload['transaction']) && is_array($payload['transaction'])) $payload = $payload['transaction'];
$eventType = strtolower((string)($event['name'] ?? $event['type'] ?? $event['event'] ?? ''));
$externalId = (string)($payload['id'] ?? '');
$eventId = (string)($event['id'] ?? ($eventType . ':' . $externalId . ':' . ($payload['updated_at'] ?? $payload['status'] ?? hash('sha256', $raw))));
if ($externalId === '') api_json(['ok' => true, 'received' => true]);
try {
  // Le payload webhook n’est jamais considéré comme preuve suffisante :
  // la transaction est relue directement depuis FedaPay.
  $transaction = fedapay_unwrap(fedapay_request('GET', '/transactions/' . rawurlencode($externalId)));
  $stmt = db()->prepare('SELECT id FROM payment_transactions WHERE external_id=? LIMIT 1');
  $stmt->execute([$externalId]);
  $paymentId = (int)$stmt->fetchColumn();
  if ($paymentId) payment_credit_approved($paymentId, $transaction, $eventId, $eventType, $raw);
  api_json(['ok' => true, 'received' => true]);
} catch (Throwable $e) {
  error_log('[Botora Admin] FedaPay webhook: ' . $e->getMessage());
  api_json(['ok' => false, 'error' => 'Webhook temporairement indisponible.'], 500);
}
