<?php
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/db.php';

// Les erreurs serveur doivent rester visibles sous forme JSON pour le client
// Node.js, tout en conservant le détail uniquement dans le journal PHP.
set_exception_handler(function (Throwable $e): void {
  error_log('[Botora API] ' . $e->getMessage());
  $message = defined('APP_DEBUG') && APP_DEBUG ? $e->getMessage() : 'Erreur interne de configuration du serveur.';
  api_json(['ok' => false, 'error' => $message], 500);
});

function payment_request_json(): array {
  $raw = file_get_contents('php://input');
  $data = json_decode($raw ?: '{}', true);
  return is_array($data) ? $data : [];
}

function verify_service_key(): void {
  // Contrôle interservices désactivé temporairement en développement.
  // Les échanges seront protégés par clé après stabilisation des contrats HTTP.
}

function payment_user(array $data): array {
  $db = db();
  if (!empty($data['license_key'])) {
    $stmt = $db->prepare('SELECT * FROM users WHERE license_key = ? LIMIT 1');
    $stmt->execute([trim((string)$data['license_key'])]);
  } elseif (!empty($data['email'])) {
    $email = strtolower(trim((string)$data['email']));
    $stmt = $db->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $existing = $stmt->fetch();
    if ($existing) return $existing;
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return [];
    $name = trim((string)($data['name'] ?? 'Client Botora')) ?: 'Client Botora';
    $insert = $db->prepare('INSERT INTO users (name,email,license_key,status) VALUES (?,?,?,?)');
    $insert->execute([$name, $email, generate_license(), 'active']);
    $newId = (int)$db->lastInsertId();
    $stmt = $db->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$newId]);
  } else {
    return [];
  }
  return $stmt->fetch() ?: [];
}

function fedapay_request(string $method, string $path, ?array $payload = null): array {
  if (FEDAPAY_SECRET_KEY === '') throw new RuntimeException('FEDAPAY_SECRET_KEY non configurée.');
  $ch = curl_init(FEDAPAY_API_URL . '/' . ltrim($path, '/'));
  $headers = ['Authorization: Bearer ' . FEDAPAY_SECRET_KEY, 'Content-Type: application/json', 'Accept: application/json'];
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CUSTOMREQUEST => strtoupper($method),
    CURLOPT_HTTPHEADER => $headers,
    CURLOPT_TIMEOUT => 25,
  ]);
  if ($payload !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE));
  $body = curl_exec($ch);
  $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $error = curl_error($ch);
  curl_close($ch);
  if ($body === false || $error) throw new RuntimeException('FedaPay inaccessible: ' . ($error ?: 'réponse vide'));
  $decoded = json_decode($body, true);
  if ($status < 200 || $status >= 300) throw new RuntimeException('FedaPay HTTP ' . $status . ': ' . substr($body, 0, 500));
  return is_array($decoded) ? $decoded : ['raw' => $body];
}

function fedapay_unwrap(array $data): array {
  if (isset($data['v1']) && is_array($data['v1'])) return $data['v1'];
  if (isset($data['transaction']) && is_array($data['transaction'])) return $data['transaction'];
  return $data;
}

function payment_credit_approved(int $paymentId, array $transaction, string $eventId, string $eventType, string $rawPayload): array {
  $db = db();
  $db->beginTransaction();
  try {
    $stmt = $db->prepare('SELECT * FROM payment_transactions WHERE id = ? FOR UPDATE');
    $stmt->execute([$paymentId]);
    $payment = $stmt->fetch();
    if (!$payment) throw new RuntimeException('Paiement introuvable.');
    try {
      $db->prepare('INSERT INTO payment_webhook_events (payment_id,event_id,event_type,payload) VALUES (?,?,?,?)')
        ->execute([$paymentId, $eventId, $eventType, $rawPayload]);
    } catch (PDOException $e) {
      if ((string)$e->getCode() !== '23000') throw $e;
      $db->commit();
      return ['status' => $payment['status'], 'already_processed' => true, 'credits' => (float)$payment['credits']];
    }
    $status = strtolower((string)($transaction['status'] ?? 'pending'));
    if ($payment['status'] === 'approved') {
      $db->commit();
      return ['status' => 'approved', 'already_processed' => true, 'credits' => (float)$payment['credits']];
    }
    $db->prepare('UPDATE payment_transactions SET status=?, approved_at=CASE WHEN ?="approved" THEN NOW() ELSE approved_at END, updated_at=NOW() WHERE id=?')
      ->execute([$status, $status, $paymentId]);
    if ($status === 'approved') {
      $db->prepare('UPDATE users SET credits_balance = credits_balance + ?, updated_at = NOW() WHERE id = ?')->execute([$payment['credits'], $payment['user_id']]);
      $balanceStmt = $db->prepare('SELECT credits_balance FROM users WHERE id=?');
      $balanceStmt->execute([$payment['user_id']]);
      $balance = $balanceStmt->fetchColumn();
      $db->prepare('INSERT INTO credit_logs (user_id,amount,type,reason,balance_after) VALUES (?,?,?,?,?)')
        ->execute([$payment['user_id'], $payment['credits'], 'add', 'Recharge FedaPay ' . $payment['external_id'], $balance]);
    }
    $db->commit();
    return ['status' => $status, 'already_processed' => false, 'credits' => $status === 'approved' ? (float)$payment['credits'] : 0];
  } catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    throw $e;
  }
}
