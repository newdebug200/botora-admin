<?php
require_once __DIR__ . '/../includes/payment.php';
verify_service_key();

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$data = $method === 'GET' ? $_GET : payment_request_json();
$email = strtolower(trim((string)($data['email'] ?? '')));
$profileKey = trim((string)($data['profile_key'] ?? 'default'));
if ($profileKey === '') $profileKey = 'default';

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
  api_json(['ok' => false, 'error' => 'Adresse e-mail invalide.'], 400);
}
if (strlen($profileKey) > 191) {
  api_json(['ok' => false, 'error' => 'Profil WhatsApp invalide.'], 400);
}

function keyword_reply_normalize(string $value): string {
  $value = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
  return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
}

function keyword_reply_payload(array $row): array {
  return [
    'id' => (int)$row['id'],
    'profile_key' => $row['profile_key'],
    'keyword' => $row['keyword'],
    'response_text' => $row['response_text'],
    'is_active' => (bool)$row['is_active'],
    'created_at' => $row['created_at'],
    'updated_at' => $row['updated_at'],
  ];
}

$db = db();
$userStmt = $db->prepare('SELECT id FROM users WHERE email=? LIMIT 1');
$userStmt->execute([$email]);
$userId = (int)($userStmt->fetchColumn() ?: 0);
if (!$userId) api_json(['ok' => false, 'error' => 'Compte utilisateur introuvable.'], 404);
api_log_set_user($userId);

try {
  if ($method === 'GET') {
    $stmt = $db->prepare('SELECT id,profile_key,keyword,response_text,is_active,created_at,updated_at FROM keyword_auto_replies WHERE user_id=? AND profile_key=? ORDER BY created_at DESC,id DESC');
    $stmt->execute([$userId, $profileKey]);
    $rules = array_map('keyword_reply_payload', $stmt->fetchAll());
    api_json(['ok' => true, 'rules' => $rules]);
  }

  if ($method === 'POST') {
    $keyword = trim((string)($data['keyword'] ?? ''));
    $responseText = trim((string)($data['response_text'] ?? ''));
    $normalized = keyword_reply_normalize($keyword);
    if ($normalized === '' || strlen($keyword) > 255) api_json(['ok' => false, 'error' => 'Le mot-clé est requis et ne peut pas dépasser 255 caractères.'], 400);
    if ($responseText === '' || strlen($responseText) > 4000) api_json(['ok' => false, 'error' => 'La réponse est requise et ne peut pas dépasser 4 000 caractères.'], 400);

    $countStmt = $db->prepare('SELECT COUNT(*) FROM keyword_auto_replies WHERE user_id=?');
    $countStmt->execute([$userId]);
    if ((int)$countStmt->fetchColumn() >= 200) api_json(['ok' => false, 'error' => 'La limite de 200 réponses automatiques est atteinte.'], 409);

    try {
      $stmt = $db->prepare('INSERT INTO keyword_auto_replies (user_id,profile_key,keyword,keyword_normalized,response_text,is_active) VALUES (?,?,?,?,?,?)');
      $stmt->execute([$userId, $profileKey, $keyword, $normalized, $responseText, isset($data['is_active']) ? (int)(bool)$data['is_active'] : 1]);
    } catch (PDOException $error) {
      if ((string)$error->getCode() === '23000') api_json(['ok' => false, 'error' => 'Ce mot-clé existe déjà pour ce profil.'], 409);
      throw $error;
    }

    $id = (int)$db->lastInsertId();
    $stmt = $db->prepare('SELECT id,profile_key,keyword,response_text,is_active,created_at,updated_at FROM keyword_auto_replies WHERE id=? AND user_id=?');
    $stmt->execute([$id, $userId]);
    api_json(['ok' => true, 'rule' => keyword_reply_payload($stmt->fetch())], 201);
  }

  if ($method === 'PATCH' || $method === 'PUT') {
    $id = (int)($data['id'] ?? 0);
    $stmt = $db->prepare('SELECT * FROM keyword_auto_replies WHERE id=? AND user_id=? LIMIT 1');
    $stmt->execute([$id, $userId]);
    $existing = $stmt->fetch();
    if (!$existing) api_json(['ok' => false, 'error' => 'Réponse automatique introuvable.'], 404);

    $keyword = array_key_exists('keyword', $data) ? trim((string)$data['keyword']) : (string)$existing['keyword'];
    $responseText = array_key_exists('response_text', $data) ? trim((string)$data['response_text']) : (string)$existing['response_text'];
    $normalized = keyword_reply_normalize($keyword);
    $isActive = array_key_exists('is_active', $data) ? (int)(bool)$data['is_active'] : (int)$existing['is_active'];
    if ($normalized === '' || strlen($keyword) > 255) api_json(['ok' => false, 'error' => 'Le mot-clé est requis et ne peut pas dépasser 255 caractères.'], 400);
    if ($responseText === '' || strlen($responseText) > 4000) api_json(['ok' => false, 'error' => 'La réponse est requise et ne peut pas dépasser 4 000 caractères.'], 400);

    try {
      $update = $db->prepare('UPDATE keyword_auto_replies SET keyword=?,keyword_normalized=?,response_text=?,is_active=? WHERE id=? AND user_id=?');
      $update->execute([$keyword, $normalized, $responseText, $isActive, $id, $userId]);
    } catch (PDOException $error) {
      if ((string)$error->getCode() === '23000') api_json(['ok' => false, 'error' => 'Ce mot-clé existe déjà pour ce profil.'], 409);
      throw $error;
    }

    $stmt = $db->prepare('SELECT id,profile_key,keyword,response_text,is_active,created_at,updated_at FROM keyword_auto_replies WHERE id=? AND user_id=?');
    $stmt->execute([$id, $userId]);
    api_json(['ok' => true, 'rule' => keyword_reply_payload($stmt->fetch())]);
  }

  if ($method === 'DELETE') {
    $id = (int)($data['id'] ?? 0);
    if (!$id) api_json(['ok' => false, 'error' => 'Identifiant requis.'], 400);
    $stmt = $db->prepare('DELETE FROM keyword_auto_replies WHERE id=? AND user_id=?');
    $stmt->execute([$id, $userId]);
    if ($stmt->rowCount() < 1) api_json(['ok' => false, 'error' => 'Réponse automatique introuvable.'], 404);
    api_json(['ok' => true, 'deleted' => true, 'id' => $id]);
  }

  api_json(['ok' => false, 'error' => 'Méthode non autorisée.'], 405);
} catch (Throwable $error) {
  error_log('[keyword-replies] ' . $error->getMessage());
  api_log_set_error($error->getMessage());
  api_json(['ok' => false, 'error' => 'Impossible de gérer les réponses automatiques.'], 500);
}
