<?php
$pageTitle = 'Plans'; $activePage = 'plans';
require_once __DIR__ . '/../includes/header.php';

$db = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';
  if ($action === 'create') {
    $db->prepare('INSERT INTO plans (name,slug,credits_per_month,max_profiles,campaigns_enabled,ia_enabled,trial_days,price_eur) VALUES (?,?,?,?,?,?,?,?)')
       ->execute([
         trim($_POST['name']), strtolower(trim($_POST['slug'])),
         (int)$_POST['credits_per_month'], (int)$_POST['max_profiles'],
         isset($_POST['campaigns']) ? 1 : 0, isset($_POST['ia']) ? 1 : 0,
         (int)$_POST['trial_days'], (float)str_replace(',','.',$_POST['price_eur'])
       ]);
    flash_set('success', 'Plan créé.');
  } elseif ($action === 'toggle') {
    $pid = (int)$_POST['plan_id'];
    $db->prepare('UPDATE plans SET is_active = NOT is_active WHERE id=?')->execute([$pid]);
    flash_set('success', 'Plan mis à jour.');
  }
  header('Location: ' . APP_URL . '/admin/plans.php'); exit;
}

$plans = $db->query('SELECT p.*, (SELECT COUNT(*) FROM users u WHERE u.plan_id=p.id) as user_count FROM plans p ORDER BY p.price_eur ASC')->fetchAll();
?>
<div class="page-header"><h1>Plans tarifaires</h1></div>

<div class="row-2col" style="align-items:start">
  <div class="card">
    <div class="card-header"><h2>Plans existants</h2></div>
    <table class="table">
      <thead><tr><th>Plan</th><th>Crédits/mois</th><th>Profils</th><th>Essai</th><th>Prix</th><th>Clients</th><th>Statut</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($plans as $p): ?>
        <tr>
          <td><strong><?= h($p['name']) ?></strong><br><small class="text-muted"><?= h($p['slug']) ?></small></td>
          <td><?= number_format($p['credits_per_month']) ?></td>
          <td><?= $p['max_profiles'] ?></td>
          <td><?= $p['trial_days'] ?>j</td>
          <td><?= $p['price_eur'] > 0 ? number_format($p['price_eur'],2).' €' : 'Gratuit' ?></td>
          <td><?= $p['user_count'] ?></td>
          <td><span class="badge <?= $p['is_active']?'badge-success':'badge-secondary' ?>"><?= $p['is_active']?'Actif':'Inactif' ?></span></td>
          <td>
            <form method="POST" style="display:inline">
              <input type="hidden" name="action" value="toggle">
              <input type="hidden" name="plan_id" value="<?= $p['id'] ?>">
              <button type="submit" class="btn btn-sm btn-outline"><?= $p['is_active']?'Désactiver':'Activer' ?></button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <div class="card">
    <div class="card-header"><h2>Créer un plan</h2></div>
    <form method="POST" class="form-body">
      <input type="hidden" name="action" value="create">
      <div class="form-row">
        <div class="form-group"><label>Nom *</label><input type="text" name="name" class="form-control" required></div>
        <div class="form-group"><label>Slug *</label><input type="text" name="slug" class="form-control" required placeholder="ex: pro"></div>
      </div>
      <div class="form-row">
        <div class="form-group"><label>Crédits/mois</label><input type="number" name="credits_per_month" class="form-control" value="500" min="0"></div>
        <div class="form-group"><label>Max profils</label><input type="number" name="max_profiles" class="form-control" value="1" min="1"></div>
      </div>
      <div class="form-row">
        <div class="form-group"><label>Jours d'essai</label><input type="number" name="trial_days" class="form-control" value="14" min="0"></div>
        <div class="form-group"><label>Prix (€)</label><input type="text" name="price_eur" class="form-control" value="0.00"></div>
      </div>
      <div class="form-group">
        <label class="checkbox-label"><input type="checkbox" name="campaigns" checked> Campagnes activées</label>
        <label class="checkbox-label"><input type="checkbox" name="ia" checked> IA activée</label>
      </div>
      <button type="submit" class="btn btn-primary">Créer le plan</button>
    </form>
  </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
