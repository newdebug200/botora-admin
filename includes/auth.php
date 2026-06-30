<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/db.php';

function session_init(): void {
  if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params(['lifetime' => SESSION_LIFETIME, 'httponly' => true, 'samesite' => 'Lax']);
    session_start();
  }
}

function auth_check(): void {
  session_init();
  if (empty($_SESSION['admin_id'])) {
    header('Location: ' . APP_URL . '/index.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
    exit;
  }
}

function auth_login(string $email, string $password): bool {
  $stmt = db()->prepare('SELECT id, password_hash, role FROM admins WHERE email = ? LIMIT 1');
  $stmt->execute([$email]);
  $admin = $stmt->fetch();
  if (!$admin || !password_verify($password, $admin['password_hash'])) return false;
  $_SESSION['admin_id']   = $admin['id'];
  $_SESSION['admin_role'] = $admin['role'];
  db()->prepare('UPDATE admins SET last_login = NOW() WHERE id = ?')->execute([$admin['id']]);
  return true;
}

function current_admin(): array {
  session_init();
  if (empty($_SESSION['admin_id'])) return [];
  static $admin = null;
  if ($admin === null) {
    $stmt = db()->prepare('SELECT id, name, email, role FROM admins WHERE id = ? LIMIT 1');
    $stmt->execute([$_SESSION['admin_id']]);
    $admin = $stmt->fetch() ?: [];
  }
  return $admin;
}

function is_superadmin(): bool {
  return ($_SESSION['admin_role'] ?? '') === 'superadmin';
}

function require_superadmin(): void {
  auth_check();
  if (!is_superadmin()) { http_response_code(403); die('Accès refusé.'); }
}
