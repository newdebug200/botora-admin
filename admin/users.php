<?php
$pageTitle = 'Utilisateurs'; $activePage = 'users';
require_once __DIR__ . '/../includes/header.php';

$db = db();
$filter = $_GET['filter'] ?? 'all';
$where = '1=1';
if ($filter === 'trial') $where .= " AND u.status='trial'";
if ($filter === 'active') $where .= " AND u.status='active'";
if ($filter === 'suspended') $where .= " AND u.status='suspended'";
if ($filter === 'expired') $where .= " AND u.status='expired'";
if ($filter === 'banned') $where .= " AND u.status='banned'";
if ($filter === 'expiring') $where .= " AND u.status='trial' AND u.trial_ends_at BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 3 DAY)";
$stmt = $db->query("SELECT u.* FROM users u WHERE $where ORDER BY u.created_at DESC LIMIT 500");
$users = $stmt->fetchAll();
?>
<div class="page-header">
  <div><h1>Utilisateurs <span id="users-count" class="text-muted">(<?= count($users) ?>)</span></h1><p class="text-muted mb-0">Les 500 derniers comptes centralisés dans Botora.</p></div>
  <a href="<?= APP_URL ?>/admin/user-add.php" class="btn btn-primary">+ Nouveau client</a>
</div>

<div class="filters-bar">
  <div class="filter-tabs">
    <?php foreach (['all'=>'Tous','trial'=>'Essai','active'=>'Actifs','suspended'=>'Suspendus','expired'=>'Expirés','banned'=>'Bannis','expiring'=>'Expirent bientôt'] as $k=>$v): ?>
    <a href="?filter=<?= $k ?>" class="filter-tab <?= $filter===$k?'active':'' ?>"><?= $v ?></a>
    <?php endforeach; ?>
  </div>
  <div class="search-form" role="search">
    <label class="visually-hidden" for="user-live-search">Rechercher un utilisateur</label>
    <input id="user-live-search" type="search" placeholder="Rechercher nom, email, téléphone, entreprise, licence…" class="form-control" autocomplete="off">
    <span id="user-search-result" class="small text-muted" aria-live="polite"></span>
  </div>
</div>

<div class="card">
  <div class="table-responsive">
    <table class="table table-hover align-middle" id="users-table">
      <thead><tr><th>Client</th><th>Statut</th><th>Crédits</th><th>Essai expire</th><th>Abonnement annuel</th><th>Inscrit le</th><th>Options</th></tr></thead>
      <tbody>
      <?php foreach ($users as $u):
        $searchText = strtolower(implode(' ', array_filter([$u['name'],$u['email'],$u['company'],$u['phone'],$u['license_key'],$u['status']])));
      ?>
        <tr class="user-row" data-user-search="<?= h($searchText) ?>">
          <td><strong><?= h($u['name']) ?></strong><br><small class="text-muted"><?= h($u['email']) ?></small><?php if (!empty($u['company'])): ?><br><small class="text-muted"><?= h($u['company']) ?></small><?php endif; ?></td>
          <td><?= status_badge($u['status']) ?></td>
          <td><span class="credits-display <?= $u['credits_balance'] <= 0 ? 'zero' : ($u['credits_balance'] < 20 ? 'low' : '') ?>"><?= number_format($u['credits_balance']) ?></span></td>
          <td><?php if ($u['trial_ends_at']): $days=(int)ceil((strtotime($u['trial_ends_at'])-time())/86400); ?><span class="<?= $days<=3?'text-danger':($days<=7?'text-warning':'') ?>"><?= format_date($u['trial_ends_at']) ?><?php if ($days<=7 && $days>=0): ?> (<?= $days ?>j)<?php endif; ?><?php if ($days<0): ?> <span class="badge badge-danger">Expiré</span><?php endif; ?></span><?php else: ?>—<?php endif; ?></td>
          <td><?php if (!empty($u['subscription_ends_at'])): $subDays=(int)ceil((strtotime($u['subscription_ends_at'])-time())/86400); ?><span class="<?= $subDays<0?'text-danger':'' ?>"><?= format_date($u['subscription_ends_at']) ?><?php if ($subDays<0): ?> <span class="badge badge-danger">Expiré</span><?php endif; ?></span><?php else: ?>—<?php endif; ?></td>
          <td><?= format_date($u['created_at']) ?></td>
          <td><a href="<?= APP_URL ?>/admin/user-detail.php?id=<?= (int)$u['id'] ?>" class="btn btn-sm btn-primary">Options</a></td>
        </tr>
      <?php endforeach; ?>
      <tr id="no-user-result" <?= $users ? 'style="display:none"' : '' ?>><td colspan="7" class="text-center text-muted py-4">Aucun utilisateur trouvé.</td></tr>
      </tbody>
    </table>
  </div>
</div>

<script>
$(function () {
  const $input = $('#user-live-search');
  const $rows = $('#users-table .user-row');
  const $empty = $('#no-user-result');
  const $count = $('#users-count');
  const $result = $('#user-search-result');
  function filterUsers() {
    const query = $.trim($input.val()).toLowerCase();
    let visible = 0;
    $rows.each(function () {
      const match = !query || String($(this).data('user-search') || '').indexOf(query) !== -1;
      $(this).toggle(match);
      if (match) visible++;
    });
    $empty.toggle(visible === 0);
    $count.text('(' + visible + ')');
    $result.text(query ? visible + ' résultat(s)' : 'Recherche instantanée sur les 500 derniers utilisateurs');
  }
  $input.on('input', filterUsers);
  filterUsers();
});
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
