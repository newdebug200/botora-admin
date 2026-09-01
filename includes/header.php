<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
auth_check();
$admin = current_admin();
$flash = flash_get();

// Stats for sidebar badges
$total_users   = (int) db()->query('SELECT COUNT(*) FROM users')->fetchColumn();
$trial_users   = (int) db()->query("SELECT COUNT(*) FROM users WHERE status='trial'")->fetchColumn();
$expiring_soon = (int) db()->query("SELECT COUNT(*) FROM users WHERE status='trial' AND trial_ends_at BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 3 DAY)")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= h($pageTitle ?? 'Dashboard') ?> — Botora Admin</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
  <link rel="stylesheet" href="<?= APP_URL ?>/assets/style.css">
</head>
<body>
<div class="layout">
  <nav class="sidebar">
    <div class="sidebar-brand">
      <img src="<?= APP_URL ?>/assets/logo-botora.png" alt="Botora" width="32" height="32" class="rounded-3">
      <span>Botora Admin</span>
    </div>
    <ul class="sidebar-menu">
      <li class="menu-section">Navigation</li>
      <li><a href="<?= APP_URL ?>/admin/dashboard.php" class="<?= ($activePage??'')==='dashboard'?'active':'' ?>">
        <svg viewBox="0 0 24 24" fill="currentColor" width="18" height="18"><path d="M3 13h8V3H3v10zm0 8h8v-6H3v6zm10 0h8V11h-8v10zm0-18v6h8V3h-8z"/></svg>
        Tableau de bord
      </a></li>
      <li><a href="<?= APP_URL ?>/admin/users.php" class="<?= ($activePage??'')==='users'?'active':'' ?>">
        <svg viewBox="0 0 24 24" fill="currentColor" width="18" height="18"><path d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5c-1.66 0-3 1.34-3 3s1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5C6.34 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5c0-2.33-4.67-3.5-7-3.5zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.97 1.97 3.45V19h6v-2.5c0-2.33-4.67-3.5-7-3.5z"/></svg>
        Utilisateurs
        <?php if ($trial_users > 0): ?><span class="badge-pill"><?= $trial_users ?></span><?php endif; ?>
      </a></li>
      <li><a href="<?= APP_URL ?>/admin/transactions.php" class="<?= ($activePage??'')==='transactions'?'active':'' ?>">
        <svg viewBox="0 0 24 24" fill="currentColor" width="18" height="18"><path d="M3 7h18v10H3V7zm2 2v6h14V9H5zm2 2h4v2H7v-2zm6 0h4v2h-4v-2z"/></svg>
        Transactions
      </a></li>
      <li><a href="<?= APP_URL ?>/admin/api-keys.php" class="<?= ($activePage??'')==='api-keys'?'active':'' ?>">
        <svg viewBox="0 0 24 24" fill="currentColor" width="18" height="18"><path d="M7 14a5 5 0 1 1 3.9-8.13l8.23 8.23-2.12 2.12-1.41-1.41-1.42 1.42-1.41-1.42-1.42 1.42-1.41-1.41A5 5 0 0 1 7 14zm0-3a2 2 0 1 0 0 4 2 2 0 0 0 0-4z"/></svg>
        Clés API
      </a></li>
      <li><a href="<?= APP_URL ?>/admin/api-logs.php" class="<?= ($activePage??'')==='api-logs'?'active':'' ?>">
        <svg viewBox="0 0 24 24" fill="currentColor" width="18" height="18"><path d="M4 3h16v18H4V3zm2 2v14h12V5H6zm2 3h8v2H8V8zm0 4h8v2H8v-2zm0 4h5v2H8v-2z"/></svg>
        Logs API
      </a></li>
      <li><a href="<?= APP_URL ?>/admin/reports.php" class="<?= ($activePage??'')==='reports'?'active':'' ?>">
        <svg viewBox="0 0 24 24" fill="currentColor" width="18" height="18"><path d="M5 9.2h3V19H5V9.2zM10.6 5h2.8v14h-2.8V5zm5.6 8H19v6h-2.8v-6z"/></svg>
        Rapports
      </a></li>
      <?php if (is_superadmin()): ?>
      <li class="menu-section">Superadmin</li>
      <li><a href="<?= APP_URL ?>/admin/credits.php" class="<?= ($activePage??'')==='credits'?'active':'' ?>">
        <svg viewBox="0 0 24 24" fill="currentColor" width="18" height="18"><path d="M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20Zm1 16h-2v-2h2v2Zm2.07-7.25-.9.92C13.45 12.4 13 13 13 14h-2v-.5c0-.8.45-1.55 1.17-2.28l1.24-1.26A1.5 1.5 0 0 0 12.35 7.4c-.76 0-1.4.47-1.66 1.15l-1.85-.77A3.5 3.5 0 0 1 12.35 5a3.5 3.5 0 0 1 2.72 5.75Z"/></svg>
        Crédits
      </a></li>
      <li><a href="<?= APP_URL ?>/admin/features.php" class="<?= ($activePage??'')==='features'?'active':'' ?>">
        <svg viewBox="0 0 24 24" fill="currentColor" width="18" height="18"><path d="M19.43 12.98c.04-.32.07-.65.07-.98s-.02-.66-.07-.98l2.11-1.65-2-3.46-2.49 1a7.06 7.06 0 0 0-1.69-.98L15 3h-4l-.36 2.93c-.6.25-1.16.58-1.69.98l-2.49-1-2 3.46 2.11 1.65a7.8 7.8 0 0 0 0 1.96l-2.11 1.65 2 3.46 2.49-1c.53.4 1.09.73 1.69.98L11 21h4l.36-2.93c.6-.25 1.16-.58 1.69-.98l2.49 1 2-3.46-2.11-1.65ZM13 15.5A3.5 3.5 0 1 1 13 8a3.5 3.5 0 0 1 0 7.5Z"/></svg>
        Fonctionnalités
      </a></li>
      <li><a href="<?= APP_URL ?>/admin/settings.php" class="<?= ($activePage??'')==='settings'?'active':'' ?>">
        <svg viewBox="0 0 24 24" fill="currentColor" width="18" height="18"><path d="M12 15.5A3.5 3.5 0 018.5 12 3.5 3.5 0 0112 8.5a3.5 3.5 0 013.5 3.5 3.5 3.5 0 01-3.5 3.5m7.43-2.92c.04-.3.07-.62.07-.95s-.03-.66-.07-1l2.16-1.65c.19-.15.24-.42.12-.64l-2.05-3.55c-.12-.22-.39-.3-.61-.22l-2.55 1.03c-.52-.4-1.08-.73-1.7-.98l-.38-2.71C14.46 2.18 14.25 2 14 2h-4c-.25 0-.46.18-.49.42l-.38 2.71c-.62.25-1.18.58-1.7.98L4.88 5.08c-.22-.08-.49 0-.61.22L2.22 8.85c-.13.22-.07.49.12.64l2.16 1.65c-.04.34-.07.67-.07 1s.03.65.07.97l-2.16 1.66c-.19.15-.24.42-.12.64l2.05 3.55c.12.22.39.3.61.22l2.55-1.02c.52.4 1.08.73 1.7.98l.38 2.71c.03.24.24.42.49.42h4c.25 0 .46-.18.49-.42l.38-2.71c.62-.25 1.18-.58 1.7-.98l2.55 1.02c.22.08.49 0 .61-.22l2.05-3.55c.12-.22.07-.49-.12-.64l-2.16-1.66z"/></svg>
        Paramètres
      </a></li>
      <li><a href="<?= APP_URL ?>/admin/admin-team.php" class="<?= ($activePage??'')==='admin-team'?'active':'' ?>">
        <svg viewBox="0 0 24 24" fill="currentColor" width="18" height="18"><path d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5s-3 1.34-3 3 1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5s-3 1.34-3 3 1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5C15 14.17 10.33 13 8 13zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.97 1.97 3.45V19h6v-2.5c0-2.33-4.67-3.5-7-3.5z"/></svg>
        Équipe admin
      </a></li>
      <?php endif; ?>
    </ul>
    <div class="sidebar-footer">
      <div class="sidebar-admin">
        <div class="admin-avatar"><?= strtoupper(substr($admin['name'],0,1)) ?></div>
        <div>
          <div class="admin-name"><?= h($admin['name']) ?></div>
          <div class="admin-role"><?= h($admin['role']) ?></div>
        </div>
        <a href="<?= APP_URL ?>/logout.php" class="logout-btn" title="Déconnexion">
          <svg viewBox="0 0 24 24" fill="currentColor" width="18" height="18"><path d="M17 7l-1.41 1.41L18.17 11H8v2h10.17l-2.58 2.58L17 17l5-5zM4 5h8V3H4c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h8v-2H4V5z"/></svg>
        </a>
      </div>
    </div>
  </nav>
  <main class="content">
    <?php if ($flash): ?>
      <div class="alert alert-<?= h($flash['type']) ?> alert-dismissible"><?= h($flash['msg']) ?> <button onclick="this.parentElement.remove()">×</button></div>
    <?php endif; ?>
    <?php if ($expiring_soon > 0 && ($activePage??'')==='dashboard'): ?>
      <div class="alert alert-warning">⚠️ <?= $expiring_soon ?> essai(s) expirent dans moins de 3 jours. <a href="<?= APP_URL ?>/admin/users.php?filter=expiring">Voir</a></div>
    <?php endif; ?>
