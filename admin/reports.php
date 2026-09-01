<?php
$pageTitle = 'Rapports'; $activePage = 'reports';
require_once __DIR__ . '/../includes/header.php';

$db = db();
$now = botora_server_now($db);

$access = $db->query("SELECT
  COUNT(*) AS total,
  SUM(status='trial' AND trial_ends_at >= NOW()) AS trial_active,
  SUM(status='active' AND subscription_ends_at >= NOW()) AS subscriptions_active,
  SUM(status='suspended') AS suspended,
  SUM(status='banned') AS banned,
  SUM((status='expired' OR (subscription_ends_at IS NOT NULL AND subscription_ends_at < NOW())) AND status NOT IN ('suspended','banned')) AS expired,
  SUM(subscription_started_at IS NOT NULL) AS subscribed_accounts,
  SUM(subscription_ends_at BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 30 DAY)) AS subscriptions_expiring
FROM users")->fetch();

$creditSummary = $db->query("SELECT
  COALESCE(SUM(CASE WHEN type='add' THEN amount ELSE 0 END),0) AS total_added,
  COALESCE(SUM(CASE WHEN type='consume' OR (type='remove') THEN ABS(amount) ELSE 0 END),0) AS total_consumed,
  COALESCE(SUM(CASE WHEN type='consume' THEN 1 ELSE 0 END),0) AS consumption_events
FROM credit_logs")->fetch();

$subscriptionSummary = $db->query("SELECT
  COUNT(*) AS total_payments,
  SUM(status='approved') AS approved_payments,
  SUM(status='pending') AS pending_payments,
  COALESCE(SUM(CASE WHEN status='approved' THEN amount_xof ELSE 0 END),0) AS revenue_xof
FROM subscription_payments")->fetch();

$topUsers = $db->query("SELECT u.name, u.email, u.status, u.credits_balance,
  COALESCE((SELECT SUM(credits_used) FROM usage_logs ul WHERE ul.user_id=u.id),0) AS total_credits_used,
  COALESCE((SELECT COUNT(*) FROM usage_logs ul WHERE ul.user_id=u.id),0) AS total_events
FROM users u
ORDER BY total_credits_used DESC, total_events DESC
LIMIT 10")->fetchAll();

$statusRows = $db->query("SELECT status, COUNT(*) AS total FROM users GROUP BY status ORDER BY total DESC")->fetchAll();
$statusLabels = ['trial'=>'En essai','active'=>'Accès actif','suspended'=>'Suspendus','expired'=>'Expirés','banned'=>'Bannis'];
$statusColors = ['trial'=>'#f59e0b','active'=>'#10b981','suspended'=>'#ef4444','expired'=>'#94a3b8','banned'=>'#7f1d1d'];
$totalUsers = max(1, (int)$access['total']);

$recentSubscriptions = $db->query("SELECT u.name, u.email, sp.amount_xof, sp.status, sp.approved_at, sp.created_at
FROM subscription_payments sp JOIN users u ON u.id=sp.user_id
ORDER BY sp.created_at DESC LIMIT 8")->fetchAll();

$dailyInstalls = $db->query("SELECT DATE(created_at) AS day, COUNT(*) AS total FROM users WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) GROUP BY DATE(created_at) ORDER BY day ASC")->fetchAll();
$maxInstalls = max(1, (int)max(array_column($dailyInstalls, 'total') ?: [1]));
$subRate = (int)$access['total'] > 0 ? round(((int)$access['subscribed_accounts'] / (int)$access['total']) * 100) : 0;
$accessGranted = (int)$access['trial_active'] + (int)$access['subscriptions_active'];
?>
<style>
  .reports-intro{display:flex;justify-content:space-between;align-items:flex-end;gap:20px;margin-bottom:24px}.reports-intro h1{margin-bottom:6px}.reports-period{color:#64748b;font-size:13px;background:#f8fafc;border:1px solid #e2e8f0;padding:9px 13px;border-radius:10px;white-space:nowrap}
  .report-section{margin-top:24px}.report-section-title{display:flex;justify-content:space-between;align-items:center;margin-bottom:12px}.report-section-title h2{font-size:17px;margin:0}.report-section-title span{font-size:12px;color:#64748b}
  .metric-card{min-height:118px}.metric-card .stat-value{font-size:25px}.metric-card .stat-label{line-height:1.35}.metric-helper{font-size:11px;color:#64748b;margin-top:4px}
  .report-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px}.report-card{background:#fff;border:1px solid #e5e7eb;border-radius:14px;overflow:hidden;box-shadow:0 4px 16px rgba(15,23,42,.04)}.report-card-header{display:flex;justify-content:space-between;align-items:center;padding:17px 19px;border-bottom:1px solid #eef2f7}.report-card-header h3{font-size:15px;margin:0}.report-card-header small{color:#64748b}.report-card-body{padding:18px 19px}
  .status-list{display:grid;gap:13px}.status-item{display:grid;grid-template-columns:112px 1fr 45px;align-items:center;gap:10px;font-size:12px}.status-name{color:#475569}.status-track{height:8px;background:#f1f5f9;border-radius:99px;overflow:hidden}.status-fill{height:100%;border-radius:99px}.status-value{text-align:right;font-weight:700;color:#0f172a}
  .mini-kpis{display:grid;grid-template-columns:repeat(2,1fr);gap:12px}.mini-kpi{padding:14px;background:#f8fafc;border-radius:11px}.mini-kpi strong{display:block;font-size:20px;color:#0f172a}.mini-kpi span{display:block;color:#64748b;font-size:11px;margin-top:3px}
  .report-table{width:100%;border-collapse:collapse;font-size:12px}.report-table th{font-size:10px;text-transform:uppercase;letter-spacing:.04em;color:#64748b;text-align:left;padding:9px 12px;background:#f8fafc}.report-table td{padding:11px 12px;border-top:1px solid #f1f5f9;vertical-align:middle}.report-table td:last-child,.report-table th:last-child{text-align:right}.report-table .muted{color:#64748b;font-size:11px}.report-table .amount{font-weight:700;white-space:nowrap}
  .activity-chart{display:flex;align-items:flex-end;gap:7px;height:142px;padding:12px 4px 0;border-bottom:1px solid #e2e8f0}.activity-bar{flex:1;display:flex;flex-direction:column;align-items:center;justify-content:flex-end;height:100%;gap:5px}.activity-bar strong{font-size:10px;color:#475569}.activity-bar i{display:block;width:100%;max-width:24px;min-height:3px;background:linear-gradient(180deg,#20c997,#0d9488);border-radius:5px 5px 0 0}.activity-bar small{font-size:9px;color:#94a3b8}.empty-state{padding:22px;text-align:center;color:#94a3b8;font-size:13px}
  @media(max-width:900px){.report-grid{grid-template-columns:1fr}.reports-intro{align-items:flex-start;flex-direction:column}.reports-period{white-space:normal}.status-item{grid-template-columns:95px 1fr 35px}}
</style>

<div class="reports-intro">
  <div><h1>Rapports</h1><p class="text-muted mb-0">Vue opérationnelle des accès, abonnements annuels et consommations de la plateforme.</p></div>
  <div class="reports-period">Données actualisées avec l’heure serveur · <?= h($now->format('d/m/Y H:i')) ?></div>
</div>

<div class="stats-grid">
  <div class="stat-card metric-card"><div class="stat-icon blue"><svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><path d="M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20Zm1 15h-2v-2h2v2Zm1.1-5.6-.8.8c-.5.5-.8 1-.8 1.8h-2c0-1.4.5-2.2 1.4-3.1l.9-.9c.4-.4.6-.8.6-1.3a1.4 1.4 0 0 0-2.8 0H8.6a3.4 3.4 0 0 1 6.8 0c0 1-.4 1.8-1.3 2.7Z"/></svg></div><div class="stat-body"><div class="stat-value"><?= number_format($access['total']) ?></div><div class="stat-label">Comptes utilisateurs</div><div class="metric-helper"><?= number_format($accessGranted) ?> avec accès autorisé</div></div></div>
  <div class="stat-card metric-card"><div class="stat-icon orange"><svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><path d="M12 2a10 10 0 1 0 0 20A10 10 0 0 0 12 2Zm1 15h-2v-2h2v2Zm2-6.2-1.1 1.1c-.6.6-.9 1.2-.9 2.1h-2c0-1.5.6-2.6 1.6-3.6l1-1a1.7 1.7 0 1 0-2.9-1.2H8.8a3.2 3.2 0 1 1 6.2 1.6Z"/></svg></div><div class="stat-body"><div class="stat-value"><?= number_format($access['trial_active']) ?></div><div class="stat-label">Essais actifs</div><div class="metric-helper"><?= number_format($access['subscriptions_expiring']) ?> abonnement(s) finissent sous 30 jours</div></div></div>
  <div class="stat-card metric-card"><div class="stat-icon green"><svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><path d="M12 2a10 10 0 1 0 0 20A10 10 0 0 0 12 2Zm-1 15H9v-2h2V9h-2V7h4v8h2v2h-4v0Z"/></svg></div><div class="stat-body"><div class="stat-value"><?= number_format($access['subscriptions_active']) ?></div><div class="stat-label">Abonnements annuels actifs</div><div class="metric-helper">Taux d’abonnement : <?= $subRate ?>%</div></div></div>
  <div class="stat-card metric-card"><div class="stat-icon red"><svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><path d="M12 2 2 20h20L12 2Zm1 14h-2v-2h2v2Zm0-4h-2V8h2v4Z"/></svg></div><div class="stat-body"><div class="stat-value"><?= number_format((int)$access['suspended'] + (int)$access['banned']) ?></div><div class="stat-label">Accès suspendus ou bannis</div><div class="metric-helper"><?= number_format($access['expired']) ?> accès expiré(s)</div></div></div>
</div>

<div class="report-section"><div class="report-section-title"><h2>Accès et monétisation</h2><span>Indicateurs calculés côté serveur</span></div><div class="report-grid">
  <div class="report-card"><div class="report-card-header"><h3>Répartition des accès</h3><small><?= number_format($access['total']) ?> comptes</small></div><div class="report-card-body"><div class="status-list">
    <?php foreach ($statusLabels as $status=>$label): $count = 0; foreach ($statusRows as $row) if ($row['status'] === $status) $count = (int)$row['total']; $width = min(100, round($count/$totalUsers*100)); ?>
      <div class="status-item"><span class="status-name"><?= h($label) ?></span><div class="status-track"><div class="status-fill" style="width:<?= $width ?>%;background:<?= $statusColors[$status] ?>"></div></div><span class="status-value"><?= $count ?></span></div>
    <?php endforeach; ?>
  </div></div></div>
  <div class="report-card"><div class="report-card-header"><h3>Abonnements annuels</h3><small>Offre fixe d’un an</small></div><div class="report-card-body"><div class="mini-kpis"><div class="mini-kpi"><strong><?= number_format($subscriptionSummary['approved_payments']) ?></strong><span>Paiements approuvés</span></div><div class="mini-kpi"><strong><?= number_format($subscriptionSummary['pending_payments']) ?></strong><span>En attente</span></div><div class="mini-kpi"><strong><?= number_format($subscriptionSummary['revenue_xof'], 0, ',', ' ') ?> F</strong><span>Revenus encaissés</span></div><div class="mini-kpi"><strong><?= number_format($access['subscriptions_expiring']) ?></strong><span>Échéances sous 30 jours</span></div></div></div></div>
</div></div>

<div class="report-section"><div class="report-section-title"><h2>Activité et crédits</h2><span>Suivi de la consommation</span></div><div class="report-grid">
  <div class="report-card"><div class="report-card-header"><h3>Nouveaux comptes — 30 derniers jours</h3><small><?= number_format(array_sum(array_column($dailyInstalls,'total'))) ?> créations</small></div><div class="report-card-body">
    <?php if ($dailyInstalls): ?><div class="activity-chart"><?php foreach ($dailyInstalls as $day): $height = max(3, round(((int)$day['total']/$maxInstalls)*100)); ?><div class="activity-bar"><strong><?= (int)$day['total'] ?></strong><i style="height:<?= $height ?>%" title="<?= h($day['day']) ?> : <?= (int)$day['total'] ?> compte(s)"></i><small><?= h(date('d/m', strtotime($day['day']))) ?></small></div><?php endforeach; ?></div><?php else: ?><div class="empty-state">Aucune création de compte sur la période.</div><?php endif; ?>
  </div></div>
  <div class="report-card"><div class="report-card-header"><h3>Crédits</h3><small>Indépendants de l’abonnement</small></div><div class="report-card-body"><div class="mini-kpis"><div class="mini-kpi"><strong><?= number_format($creditSummary['total_added'], 0, ',', ' ') ?></strong><span>Crédits distribués</span></div><div class="mini-kpi"><strong><?= number_format($creditSummary['total_consumed'], 0, ',', ' ') ?></strong><span>Crédits consommés</span></div><div class="mini-kpi"><strong><?= number_format($creditSummary['consumption_events']) ?></strong><span>Événements de consommation</span></div><div class="mini-kpi"><strong><?= $creditSummary['total_added'] > 0 ? round(($creditSummary['total_consumed'] / $creditSummary['total_added']) * 100) : 0 ?>%</strong><span>Taux d’utilisation</span></div></div></div></div>
</div></div>

<div class="report-section"><div class="report-section-title"><h2>Détails opérationnels</h2><span>Dernières données disponibles</span></div><div class="report-grid">
  <div class="report-card"><div class="report-card-header"><h3>Top utilisateurs par consommation</h3><small>10 maximum</small></div><div class="report-card-body" style="padding:0"><table class="report-table"><thead><tr><th>Utilisateur</th><th>Statut</th><th>Crédits</th><th>Solde</th></tr></thead><tbody><?php foreach ($topUsers as $u): ?><tr><td><strong><?= h($u['name']) ?></strong><br><span class="muted"><?= h($u['email']) ?></span></td><td><?= status_badge($u['status']) ?></td><td><?= number_format($u['total_credits_used'], 0, ',', ' ') ?></td><td><?= number_format($u['credits_balance'], 0, ',', ' ') ?></td></tr><?php endforeach; ?><?php if (!$topUsers): ?><tr><td colspan="4" class="empty-state">Aucune consommation enregistrée.</td></tr><?php endif; ?></tbody></table></div></div>
  <div class="report-card"><div class="report-card-header"><h3>Derniers paiements annuels</h3><small>Abonnement uniquement</small></div><div class="report-card-body" style="padding:0"><table class="report-table"><thead><tr><th>Compte</th><th>Montant</th><th>Statut</th></tr></thead><tbody><?php foreach ($recentSubscriptions as $payment): ?><tr><td><strong><?= h($payment['name']) ?></strong><br><span class="muted"><?= h($payment['email']) ?> · <?= h(format_datetime($payment['created_at'])) ?></span></td><td class="amount"><?= number_format($payment['amount_xof'], 0, ',', ' ') ?> F</td><td><?= h(ucfirst($payment['status'])) ?></td></tr><?php endforeach; ?><?php if (!$recentSubscriptions): ?><tr><td colspan="3" class="empty-state">Aucun paiement d’abonnement enregistré.</td></tr><?php endif; ?></tbody></table></div></div>
</div></div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
