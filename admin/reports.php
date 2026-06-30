<?php
$pageTitle = 'Rapports'; $activePage = 'reports';
require_once __DIR__ . '/../includes/header.php';

$db = db();

$top_users = $db->query("
  SELECT u.name, u.email, u.status, u.credits_balance,
    (SELECT COUNT(*) FROM usage_logs ul WHERE ul.user_id=u.id) as total_events,
    (SELECT COALESCE(SUM(credits_used),0) FROM usage_logs ul WHERE ul.user_id=u.id) as total_credits_used
  FROM users u ORDER BY total_credits_used DESC LIMIT 10
")->fetchAll();

$daily_installs = $db->query("
  SELECT DATE(created_at) as day, COUNT(*) as installs
  FROM users WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
  GROUP BY day ORDER BY day ASC
")->fetchAll();

$plan_dist = $db->query("
  SELECT p.name, COUNT(u.id) as cnt
  FROM users u LEFT JOIN plans p ON p.id=u.plan_id
  GROUP BY u.plan_id ORDER BY cnt DESC
")->fetchAll();

$credit_summary = $db->query("
  SELECT
    COALESCE(SUM(CASE WHEN type='add' THEN amount ELSE 0 END),0) as total_added,
    COALESCE(SUM(CASE WHEN type='consume' THEN ABS(amount) ELSE 0 END),0) as total_consumed
  FROM credit_logs
")->fetch();

$conversion = $db->query("
  SELECT
    COUNT(*) as total,
    SUM(CASE WHEN status='active' THEN 1 ELSE 0 END) as converted,
    SUM(CASE WHEN status='trial' THEN 1 ELSE 0 END) as in_trial,
    SUM(CASE WHEN status IN('suspended','expired','banned') THEN 1 ELSE 0 END) as churned
  FROM users
")->fetch();
?>
<div class="page-header"><h1>Rapports</h1></div>

<div class="stats-grid">
  <div class="stat-card">
    <div class="stat-icon blue"><svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1.41 16.09V20h-2.67v-1.93c-1.71-.36-3.16-1.46-3.27-3.4h1.96c.1 1.05.82 1.87 2.65 1.87 1.96 0 2.4-.98 2.4-1.59 0-.83-.44-1.61-2.67-2.14-2.48-.6-4.18-1.62-4.18-3.67 0-1.72 1.39-2.84 3.11-3.21V4h2.67v1.95c1.86.45 2.79 1.86 2.85 3.39H14.3c-.05-1.11-.64-1.87-2.22-1.87-1.5 0-2.4.68-2.4 1.64 0 .84.65 1.39 2.67 1.91s4.18 1.39 4.18 3.91c-.01 1.83-1.38 2.83-3.12 3.16z"/></svg></div>
    <div class="stat-body"><div class="stat-value"><?= number_format($credit_summary['total_added']) ?></div><div class="stat-label">Crédits distribués</div></div>
  </div>
  <div class="stat-card">
    <div class="stat-icon orange"><svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><path d="M13 2.05v2.02c3.95.49 7 3.85 7 7.93 0 3.21-1.81 6-4.72 7.28L13 17v5h5l-1.22-1.22C19.91 19.07 22 15.76 22 12c0-5.18-3.95-9.45-9-9.95zM11 2.05C5.95 2.55 2 6.82 2 12c0 3.76 2.09 7.07 5.22 8.78L6 22h5V2.05z"/></svg></div>
    <div class="stat-body"><div class="stat-value"><?= number_format($credit_summary['total_consumed']) ?></div><div class="stat-label">Crédits consommés</div></div>
  </div>
  <div class="stat-card">
    <div class="stat-icon green"><svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><path d="M16 6l2.29 2.29-4.88 4.88-4-4L2 16.59 3.41 18l6-6 4 4 6.3-6.29L22 12V6z"/></svg></div>
    <div class="stat-body"><div class="stat-value"><?= $conversion['total'] > 0 ? round($conversion['converted']/$conversion['total']*100) : 0 ?>%</div><div class="stat-label">Taux de conversion</div></div>
  </div>
  <div class="stat-card">
    <div class="stat-icon red"><svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><path d="M20 3H4v10c0 2.21 1.79 4 4 4h6c2.21 0 4-1.79 4-4v-3h2c1.11 0 2-.89 2-2V5c0-1.11-.89-2-2-2zm0 5h-2V5h2v3zM4 19h16v2H4z"/></svg></div>
    <div class="stat-body"><div class="stat-value"><?= $conversion['churned'] ?></div><div class="stat-label">Churned</div></div>
  </div>
</div>

<div class="row-2col">
  <div class="card">
    <div class="card-header"><h2>Top 10 utilisateurs (crédits consommés)</h2></div>
    <table class="table table-sm">
      <thead><tr><th>Client</th><th>Statut</th><th>Crédits utilisés</th><th>Solde</th></tr></thead>
      <tbody>
        <?php foreach ($top_users as $u): ?>
        <tr>
          <td><strong><?= h($u['name']) ?></strong><br><small class="text-muted"><?= h($u['email']) ?></small></td>
          <td><?= status_badge($u['status']) ?></td>
          <td><?= number_format($u['total_credits_used']) ?></td>
          <td><?= number_format($u['credits_balance']) ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($top_users)): ?><tr><td colspan="4" class="text-center text-muted">Aucune donnée.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>

  <div class="card">
    <div class="card-header"><h2>Répartition par plan</h2></div>
    <table class="table table-sm">
      <thead><tr><th>Plan</th><th>Clients</th><th>%</th></tr></thead>
      <tbody>
        <?php $total_c = array_sum(array_column($plan_dist,'cnt')); ?>
        <?php foreach ($plan_dist as $p): ?>
        <tr>
          <td><?= h($p['name'] ?? 'Sans plan') ?></td>
          <td><?= $p['cnt'] ?></td>
          <td>
            <div class="progress-bar-wrap">
              <div class="progress-bar" style="width:<?= $total_c>0?round($p['cnt']/$total_c*100):0 ?>%"></div>
              <span><?= $total_c>0?round($p['cnt']/$total_c*100):0 ?>%</span>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
