<?php
$pageTitle = 'Utilisateurs'; $activePage = 'users';
require_once __DIR__ . '/../includes/header.php';

$db = db();
$filter = $_GET['filter'] ?? 'all';
$search = trim($_GET['q'] ?? '');

$where = '1=1';
$params = [];
if ($filter === 'trial')    { $where .= " AND u.status='trial'"; }
if ($filter === 'active')   { $where .= " AND u.status='active'"; }
if ($filter === 'suspended'){ $where .= " AND (u.status='suspended' OR u.status='expired')"; }
if ($filter === 'expiring') { $where .= " AND u.status='trial' AND u.trial_ends_at BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 3 DAY)"; }
if ($search) { $where .= " AND (u.name LIKE ? OR u.email LIKE ? OR u.license_key LIKE ?)"; $params = array_merge($params, ["%$search%","%$search%","%$search%"]); }

$stmt = $db->prepare("SELECT u.*, p.name as plan_name FROM users u LEFT JOIN plans p ON p.id=u.plan_id WHERE $where ORDER BY u.created_at DESC");
$stmt->execute($params);
$users = $stmt->fetchAll();
?>
<div class="page-header">
  <h1>Utilisateurs <span class="text-muted">(<?= count($users) ?>)</span></h1>
  <a href="<?= APP_URL ?>/admin/user-add.php" class="btn btn-primary">+ Nouveau client</a>
</div>

<div class="filters-bar">
  <div class="filter-tabs">
    <?php foreach (['all'=>'Tous','trial'=>'Essai','active'=>'Actifs','suspended'=>'Suspendus','expiring'=>'Expirent bientôt'] as $k=>$v): ?>
    <a href="?filter=<?= $k ?>" class="filter-tab <?= $filter===$k?'active':'' ?>"><?= $v ?></a>
    <?php endforeach; ?>
  </div>
  <form method="GET" class="search-form">
    <input type="hidden" name="filter" value="<?= h($filter) ?>">
    <input type="text" name="q" placeholder="Rechercher nom, email, clé…" class="form-control" value="<?= h($search) ?>">
    <button type="submit" class="btn btn-outline">Rechercher</button>
  </form>
</div>

<div class="card">
  <table class="table table-hover">
    <thead>
      <tr>
        <th>Client</th>
        <th>Plan</th>
        <th>Statut</th>
        <th>Crédits</th>
        <th>Essai expire</th>
        <th>Inscrit le</th>
        <th>Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($users as $u): ?>
      <tr>
        <td>
          <strong><?= h($u['name']) ?></strong><br>
          <small class="text-muted"><?= h($u['email']) ?></small>
        </td>
        <td><?= h($u['plan_name'] ?? '—') ?></td>
        <td><?= status_badge($u['status']) ?></td>
        <td>
          <span class="credits-display <?= $u['credits_balance'] <= 0 ? 'zero' : ($u['credits_balance'] < 20 ? 'low' : '') ?>">
            <?= number_format($u['credits_balance']) ?>
          </span>
        </td>
        <td>
          <?php if ($u['trial_ends_at']): ?>
            <?php $days = (int)ceil((strtotime($u['trial_ends_at']) - time()) / 86400); ?>
            <span class="<?= $days <= 3 ? 'text-danger' : ($days <= 7 ? 'text-warning' : '') ?>">
              <?= format_date($u['trial_ends_at']) ?>
              <?php if ($days <= 7 && $days >= 0): ?>(<?= $days ?>j)<?php endif; ?>
              <?php if ($days < 0): ?><span class="badge badge-danger">Expiré</span><?php endif; ?>
            </span>
          <?php else: ?>—<?php endif; ?>
        </td>
        <td><?= format_date($u['created_at']) ?></td>
        <td class="actions">
          <a href="<?= APP_URL ?>/admin/user-detail.php?id=<?= $u['id'] ?>" class="btn btn-sm btn-outline">Détail</a>
          <a href="<?= APP_URL ?>/admin/user-detail.php?id=<?= $u['id'] ?>&action=credits" class="btn btn-sm btn-primary">+ Crédits</a>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php if (empty($users)): ?>
        <tr><td colspan="7" class="text-center text-muted py-4">Aucun utilisateur trouvé.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
