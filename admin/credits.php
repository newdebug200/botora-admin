<?php
$pageTitle = 'Crédits'; $activePage = 'credits';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_superadmin();
$db = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $userId=(int)($_POST['user_id']??0); $amount=(float)($_POST['amount']??0); $reason=trim((string)($_POST['reason']??'Ajustement admin')) ?: 'Ajustement admin';
  if ($userId && is_finite($amount) && $amount !== 0.0) {
    try {
      record_credit_adjustment($userId, $amount, $reason, (int)$_SESSION['admin_id']);
      $fresh = $db->prepare('SELECT credits_balance FROM users WHERE id=?'); $fresh->execute([$userId]);
      flash_set('success','Solde mis à jour : '.number_format((float)$fresh->fetchColumn(),3,',',' ').' crédits.');
    } catch (Throwable $e) { flash_set('danger', $e instanceof InvalidArgumentException ? $e->getMessage() : 'Impossible de modifier le solde.'); }
  } else flash_set('danger','Sélectionnez un utilisateur et saisissez un montant différent de zéro.');
  header('Location: '.APP_URL.'/admin/credits.php'); exit;
}

$users=$db->query('SELECT id,name,email,status,credits_balance FROM users ORDER BY name ASC')->fetchAll();
$logs=$db->query('SELECT l.*,u.name,u.email FROM credit_logs l JOIN users u ON u.id=l.user_id ORDER BY l.created_at DESC LIMIT 100')->fetchAll();
$totalUsers = count($users);
$totalBalance = array_sum(array_map(static fn($u) => (float)$u['credits_balance'], $users));
$zeroUsers = count(array_filter($users, static fn($u) => (float)$u['credits_balance'] <= 0));
$lowUsers = count(array_filter($users, static fn($u) => (float)$u['credits_balance'] > 0 && (float)$u['credits_balance'] < 20));
require_once __DIR__ . '/../includes/header.php';
?>
<style>
  .credits-intro{display:flex;justify-content:space-between;align-items:flex-end;gap:20px;margin-bottom:24px}.credits-intro h1{margin-bottom:6px}.credits-unit{color:#047857;background:#ecfdf5;border:1px solid #a7f3d0;padding:9px 13px;border-radius:10px;font-size:12px;white-space:nowrap}
  .credit-stat-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin-bottom:22px}.credit-stat{background:#fff;border:1px solid #e5e7eb;border-radius:13px;padding:16px 18px;box-shadow:0 4px 14px rgba(15,23,42,.04)}.credit-stat strong{display:block;font-size:22px;color:#0f172a}.credit-stat span{display:block;color:#64748b;font-size:12px;margin-top:4px}.credit-stat.accent strong{color:#059669}.credit-stat.warn strong{color:#d97706}.credit-stat.danger strong{color:#dc2626}
  .credits-layout{display:grid;grid-template-columns:minmax(280px,360px) minmax(0,1fr);gap:18px;align-items:start}.credits-card{background:#fff;border:1px solid #e5e7eb;border-radius:14px;box-shadow:0 4px 16px rgba(15,23,42,.04);overflow:hidden}.credits-card-header{padding:17px 19px;border-bottom:1px solid #eef2f7}.credits-card-header h2{font-size:16px;margin:0 0 4px}.credits-card-header p{font-size:12px;color:#64748b;margin:0}.credits-card-body{padding:18px 19px}.credits-form{display:grid;gap:14px}.credits-form label{font-size:12px;font-weight:600;color:#334155;margin-bottom:5px}.credits-form .form-control,.credits-form .form-select{min-height:42px}.credit-table-wrap{max-height:535px;overflow:auto}.credit-table-wrap table{margin:0;min-width:590px}.credit-table-wrap thead th{position:sticky;top:0;z-index:2;background:#f8fafc;box-shadow:0 1px 0 #e2e8f0;white-space:nowrap}.credit-table-wrap td,.credit-table-wrap th{padding:12px 14px;font-size:12px}.credit-table-wrap tbody tr{transition:background .15s}.credit-table-wrap tbody tr:hover{background:#f8fafc}.credit-table-wrap .user-email{display:block;color:#64748b;font-size:11px;margin-top:2px}.balance-value{font-weight:700;white-space:nowrap}.balance-zero{color:#dc2626}.balance-low{color:#d97706}.balance-good{color:#059669}.status-cell{white-space:nowrap}
  .table-toolbar{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:14px 18px;border-bottom:1px solid #eef2f7}.table-toolbar .search-box{max-width:320px;flex:1}.table-toolbar input{min-height:38px}.table-meta{font-size:12px;color:#64748b;white-space:nowrap}.pager{display:flex;align-items:center;justify-content:center;gap:10px;padding:13px 18px;border-top:1px solid #eef2f7}.pager button{border:1px solid #dbe3ec;background:#fff;border-radius:8px;padding:6px 11px;font-size:12px;color:#334155}.pager button:disabled{opacity:.45;cursor:not-allowed}.pager span{font-size:12px;color:#64748b;min-width:90px;text-align:center}.logs-card{margin-top:20px}.logs-wrap{max-height:390px;overflow:auto}.logs-wrap table{min-width:720px;margin:0}.logs-wrap thead th{position:sticky;top:0;background:#f8fafc;z-index:1}.logs-wrap td,.logs-wrap th{font-size:12px;padding:11px 14px}
  @media(max-width:1050px){.credit-stat-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.credits-layout{grid-template-columns:1fr}.credits-form{grid-template-columns:repeat(2,minmax(0,1fr));align-items:end}.credits-form .form-heading{grid-column:1/-1}.credits-form button{grid-column:1/-1}}
  @media(max-width:620px){.credits-intro{display:block}.credits-unit{display:inline-block;margin-top:12px;white-space:normal}.credit-stat-grid{grid-template-columns:1fr 1fr;gap:9px}.credit-stat{padding:13px}.credit-stat strong{font-size:18px}.table-toolbar{display:block}.table-toolbar .search-box{max-width:none;margin-bottom:8px}.credits-form{grid-template-columns:1fr}}
</style>

<div class="credits-intro"><div><div class="text-uppercase small fw-bold text-success">Administration centrale</div><h1 class="mb-1">Crédits</h1><p class="text-muted mb-0">Le solde officiel utilisé par la plateforme et les consommations IA.</p></div><span class="credits-unit">1 crédit = 100 000 tokens = 120 F CFA</span></div>

<div class="credit-stat-grid">
  <div class="credit-stat"><strong><?= number_format($totalUsers) ?></strong><span>Comptes suivis</span></div>
  <div class="credit-stat accent"><strong><?= number_format($totalBalance,3,',',' ') ?></strong><span>Solde total disponible</span></div>
  <div class="credit-stat danger"><strong><?= number_format($zeroUsers) ?></strong><span>Comptes sans crédit</span></div>
  <div class="credit-stat warn"><strong><?= number_format($lowUsers) ?></strong><span>Soldes faibles (&lt; 20)</span></div>
</div>

<div class="credits-layout">
  <section class="credits-card">
    <div class="credits-card-header"><h2>Ajuster un solde</h2><p>Une valeur positive crédite le compte ; une valeur négative le débite.</p></div>
    <div class="credits-card-body"><form method="POST" class="credits-form">
      <div class="form-heading"><label for="credit-user">Utilisateur</label><select id="credit-user" name="user_id" class="form-select js-user-select" data-placeholder="Rechercher un utilisateur" required><option value=""></option><?php foreach($users as $u): ?><option value="<?= (int)$u['id'] ?>"><?= h($u['name']) ?> — <?= h($u['email']) ?> (<?= number_format($u['credits_balance'],3,',',' ') ?>)</option><?php endforeach; ?></select></div>
      <div><label for="credit-amount">Montant de l’ajustement</label><input id="credit-amount" name="amount" type="number" step="0.0000000001" class="form-control" placeholder="Ex. 10.000 ou -2.500" required></div>
      <div><label for="credit-reason">Motif</label><input id="credit-reason" name="reason" class="form-control" value="Ajustement administrateur"></div>
      <button class="btn btn-success">Enregistrer l’ajustement</button>
    </form></div>
  </section>

  <section class="credits-card">
    <div class="table-toolbar"><div><h2 class="h5 mb-1">Soldes utilisateurs</h2><div id="balance-meta" class="table-meta">Chargement de la liste…</div></div><div class="search-box"><label class="visually-hidden" for="balance-search">Rechercher un utilisateur</label><input id="balance-search" type="search" class="form-control" placeholder="Rechercher nom, email ou statut…" autocomplete="off"></div></div>
    <div class="credit-table-wrap"><table class="table align-middle" id="balance-table"><thead><tr><th>Utilisateur</th><th>Statut</th><th class="text-end">Solde</th></tr></thead><tbody><?php foreach($users as $u): $balance=(float)$u['credits_balance']; $balanceClass=$balance<=0?'balance-zero':($balance<20?'balance-low':'balance-good'); ?><tr class="balance-row" data-search="<?= h(strtolower($u['name'].' '.$u['email'].' '.$u['status'])) ?>"><td><strong><?= h($u['name']) ?></strong><span class="user-email"><?= h($u['email']) ?></span></td><td class="status-cell"><?= status_badge($u['status']) ?></td><td class="text-end balance-value <?= $balanceClass ?>"><?= number_format($balance,3,',',' ') ?></td></tr><?php endforeach; ?><?php if(!$users): ?><tr><td colspan="3" class="text-center text-muted py-4">Aucun utilisateur synchronisé.</td></tr><?php endif; ?></tbody></table></div>
    <div class="pager"><button type="button" id="balance-prev">Précédent</button><span id="balance-page">Page 1</span><button type="button" id="balance-next">Suivant</button></div>
  </section>
</div>

<section class="credits-card logs-card"><div class="credits-card-header"><h2>Journal récent</h2><p>Les 100 dernières opérations de crédit enregistrées.</p></div><div class="logs-wrap"><table class="table align-middle"><thead><tr><th>Date</th><th>Utilisateur</th><th>Type</th><th>Motif</th><th class="text-end">Montant</th><th class="text-end">Après opération</th></tr></thead><tbody><?php foreach($logs as $l): ?><tr><td><?= format_datetime($l['created_at']) ?></td><td><strong><?= h($l['name']) ?></strong><span class="user-email"><?= h($l['email']) ?></span></td><td><?= h($l['type']) ?></td><td><?= h($l['reason']) ?></td><td class="text-end <?= $l['amount']>=0?'text-success':'text-danger' ?>"><?= number_format($l['amount'],3,',',' ') ?></td><td class="text-end"><?= number_format($l['balance_after'],3,',',' ') ?></td></tr><?php endforeach; ?><?php if(!$logs): ?><tr><td colspan="6" class="text-center text-muted py-4">Aucun mouvement enregistré.</td></tr><?php endif; ?></tbody></table></div></section>

<script>
(function () {
  const rows = Array.from(document.querySelectorAll('#balance-table .balance-row'));
  const input = document.getElementById('balance-search');
  const meta = document.getElementById('balance-meta');
  const pageLabel = document.getElementById('balance-page');
  const prev = document.getElementById('balance-prev');
  const next = document.getElementById('balance-next');
  const pageSize = 25;
  let page = 1;
  function render() {
    const query = (input.value || '').trim().toLowerCase();
    const filtered = rows.filter(row => (row.dataset.search || '').includes(query));
    const pages = Math.max(1, Math.ceil(filtered.length / pageSize));
    page = Math.min(page, pages);
    rows.forEach(row => { row.style.display = 'none'; });
    filtered.slice((page - 1) * pageSize, page * pageSize).forEach(row => { row.style.display = ''; });
    meta.textContent = filtered.length + ' utilisateur(s)' + (query ? ' correspondant(s)' : '') + ' · ' + (filtered.length ? ((page - 1) * pageSize + 1) + '–' + Math.min(page * pageSize, filtered.length) : '0');
    pageLabel.textContent = 'Page ' + page + ' / ' + pages;
    prev.disabled = page <= 1; next.disabled = page >= pages;
  }
  input?.addEventListener('input', () => { page = 1; render(); });
  prev?.addEventListener('click', () => { if (page > 1) { page--; render(); } });
  next?.addEventListener('click', () => { page++; render(); });
  render();
})();
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
