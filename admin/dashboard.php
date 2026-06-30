<?php
$pageTitle = 'Tableau de bord'; $activePage = 'dashboard';
require_once __DIR__ . '/../includes/header.php';

$db = db();
$stats = [
  'total'     => (int)$db->query('SELECT COUNT(*) FROM users')->fetchColumn(),
  'active'    => (int)$db->query("SELECT COUNT(*) FROM users WHERE status='active'")->fetchColumn(),
  'trial'     => (int)$db->query("SELECT COUNT(*) FROM users WHERE status='trial'")->fetchColumn(),
  'suspended' => (int)$db->query("SELECT COUNT(*) FROM users WHERE status='suspended' OR status='expired'")->fetchColumn(),
  'credits_given' => (int)$db->query("SELECT COALESCE(SUM(amount),0) FROM credit_logs WHERE type='add'")->fetchColumn(),
  'credits_used'  => (int)$db->query("SELECT COALESCE(SUM(ABS(amount)),0) FROM credit_logs WHERE type='consume'")->fetchColumn(),
];

$recent_users = $db->query('SELECT u.*, p.name as plan_name FROM users u LEFT JOIN plans p ON p.id=u.plan_id ORDER BY u.created_at DESC LIMIT 8')->fetchAll();

$activity = $db->query("
  SELECT DATE(created_at) as day, COUNT(*) as installs
  FROM users WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
  GROUP BY day ORDER BY day ASC
")->fetchAll();
?>
<div class="page-header">
  <h1>Tableau de bord</h1>
  <a href="<?= APP_URL ?>/admin/user-add.php" class="btn btn-primary">+ Nouveau client</a>
</div>

<div class="stats-grid">
  <div class="stat-card">
    <div class="stat-icon blue"><svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><path d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5c-1.66 0-3 1.34-3 3s1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5C6.34 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5c0-2.33-4.67-3.5-7-3.5zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.97 1.97 3.45V19h6v-2.5c0-2.33-4.67-3.5-7-3.5z"/></svg></div>
    <div class="stat-body"><div class="stat-value"><?= $stats['total'] ?></div><div class="stat-label">Total clients</div></div>
  </div>
  <div class="stat-card">
    <div class="stat-icon green"><svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></svg></div>
    <div class="stat-body"><div class="stat-value"><?= $stats['active'] ?></div><div class="stat-label">Actifs</div></div>
  </div>
  <div class="stat-card">
    <div class="stat-icon orange"><svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><path d="M11.99 2C6.47 2 2 6.48 2 12s4.47 10 9.99 10C17.52 22 22 17.52 22 12S17.52 2 11.99 2zM12 20c-4.42 0-8-3.58-8-8s3.58-8 8-8 8 3.58 8 8-3.58 8-8 8zm.5-13H11v6l5.25 3.15.75-1.23-4.5-2.67V7z"/></svg></div>
    <div class="stat-body"><div class="stat-value"><?= $stats['trial'] ?></div><div class="stat-label">En essai</div></div>
  </div>
  <div class="stat-card">
    <div class="stat-icon purple"><svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1.41 16.09V20h-2.67v-1.93c-1.71-.36-3.16-1.46-3.27-3.4h1.96c.1 1.05.82 1.87 2.65 1.87 1.96 0 2.4-.98 2.4-1.59 0-.83-.44-1.61-2.67-2.14-2.48-.6-4.18-1.62-4.18-3.67 0-1.72 1.39-2.84 3.11-3.21V4h2.67v1.95c1.86.45 2.79 1.86 2.85 3.39H14.3c-.05-1.11-.64-1.87-2.22-1.87-1.5 0-2.4.68-2.4 1.64 0 .84.65 1.39 2.67 1.91s4.18 1.39 4.18 3.91c-.01 1.83-1.38 2.83-3.12 3.16z"/></svg></div>
    <div class="stat-body"><div class="stat-value"><?= number_format($stats['credits_given']) ?></div><div class="stat-label">Crédits attribués</div></div>
  </div>
</div>

<div class="row-2col">
  <div class="card">
    <div class="card-header"><h2>Derniers clients inscrits</h2></div>
    <table class="table">
      <thead><tr><th>Nom</th><th>Plan</th><th>Statut</th><th>Crédits</th><th>Inscrit le</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($recent_users as $u): ?>
        <tr>
          <td><strong><?= h($u['name']) ?></strong><br><small class="text-muted"><?= h($u['email']) ?></small></td>
          <td><?= h($u['plan_name'] ?? '—') ?></td>
          <td><?= status_badge($u['status']) ?></td>
          <td><?= number_format($u['credits_balance']) ?></td>
          <td><?= format_date($u['created_at']) ?></td>
          <td><a href="<?= APP_URL ?>/admin/user-detail.php?id=<?= $u['id'] ?>" class="btn btn-sm btn-outline">Voir</a></td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($recent_users)): ?>
          <tr><td colspan="6" class="text-center text-muted">Aucun client pour l'instant.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <div class="card">
    <div class="card-header"><h2>Répartition par statut</h2></div>
    <div class="donut-chart-wrap">
      <canvas id="statusChart" width="200" height="200"></canvas>
      <div class="chart-legend">
        <div class="legend-item"><span class="dot green"></span> Actifs: <strong><?= $stats['active'] ?></strong></div>
        <div class="legend-item"><span class="dot orange"></span> Essai: <strong><?= $stats['trial'] ?></strong></div>
        <div class="legend-item"><span class="dot red"></span> Suspendus/Expirés: <strong><?= $stats['suspended'] ?></strong></div>
      </div>
    </div>
    <script>
      window._chartData = {active: <?= $stats['active'] ?>, trial: <?= $stats['trial'] ?>, suspended: <?= $stats['suspended'] ?>};
    </script>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
