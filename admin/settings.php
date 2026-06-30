<?php
$pageTitle = 'Paramètres'; $activePage = 'settings';
require_once __DIR__ . '/../includes/header.php';
require_superadmin();

$db = db();
$admins = $db->query('SELECT id, name, email, role, created_at, last_login FROM admins ORDER BY id ASC')->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';
  if ($action === 'create_admin') {
    $n = trim($_POST['name']); $e = trim($_POST['email']); $p = $_POST['password']; $r = $_POST['role'];
    if ($n && filter_var($e, FILTER_VALIDATE_EMAIL) && strlen($p) >= 6 && in_array($r,['superadmin','admin','viewer'])) {
      $db->prepare('INSERT INTO admins (name,email,password_hash,role) VALUES (?,?,?,?)')->execute([$n,$e,password_hash($p,PASSWORD_DEFAULT),$r]);
      flash_set('success', 'Admin créé.');
    } else { flash_set('danger','Données invalides (mot de passe ≥ 6 caractères).'); }
  } elseif ($action === 'delete_admin') {
    $aid = (int)$_POST['admin_id'];
    if ($aid !== $_SESSION['admin_id']) {
      $db->prepare('DELETE FROM admins WHERE id=?')->execute([$aid]);
      flash_set('success', 'Admin supprimé.');
    } else { flash_set('danger','Vous ne pouvez pas vous supprimer vous-même.'); }
  } elseif ($action === 'change_password') {
    $p = $_POST['new_password'] ?? '';
    if (strlen($p) >= 6) {
      $db->prepare('UPDATE admins SET password_hash=? WHERE id=?')->execute([password_hash($p,PASSWORD_DEFAULT),$_SESSION['admin_id']]);
      flash_set('success','Mot de passe modifié.');
    } else { flash_set('danger','Mot de passe trop court.'); }
  }
  header('Location: ' . APP_URL . '/admin/settings.php'); exit;
}
?>
<div class="page-header"><h1>Paramètres</h1></div>

<div class="row-2col" style="align-items:start">
  <div class="card">
    <div class="card-header"><h2>Équipe admin</h2></div>
    <table class="table">
      <thead><tr><th>Nom</th><th>Email</th><th>Rôle</th><th>Dernière connexion</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($admins as $a): ?>
        <tr>
          <td><?= h($a['name']) ?></td>
          <td><?= h($a['email']) ?></td>
          <td><span class="badge <?= $a['role']==='superadmin'?'badge-danger':($a['role']==='admin'?'badge-primary':'badge-secondary') ?>"><?= h($a['role']) ?></span></td>
          <td><?= format_datetime($a['last_login']) ?></td>
          <td>
            <?php if ($a['id'] !== $_SESSION['admin_id']): ?>
            <form method="POST" onsubmit="return confirm('Supprimer cet admin ?')">
              <input type="hidden" name="action" value="delete_admin">
              <input type="hidden" name="admin_id" value="<?= $a['id'] ?>">
              <button type="submit" class="btn btn-sm btn-danger">Supprimer</button>
            </form>
            <?php else: ?><span class="text-muted">Vous</span><?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <div>
    <div class="card" style="margin-bottom:16px">
      <div class="card-header"><h2>Créer un admin</h2></div>
      <form method="POST" class="form-body">
        <input type="hidden" name="action" value="create_admin">
        <div class="form-group"><label>Nom</label><input type="text" name="name" class="form-control" required></div>
        <div class="form-group"><label>Email</label><input type="email" name="email" class="form-control" required></div>
        <div class="form-group"><label>Mot de passe</label><input type="password" name="password" class="form-control" required minlength="6"></div>
        <div class="form-group">
          <label>Rôle</label>
          <select name="role" class="form-control">
            <option value="viewer">Viewer (lecture seule)</option>
            <option value="admin">Admin</option>
            <option value="superadmin">Superadmin</option>
          </select>
        </div>
        <button type="submit" class="btn btn-primary">Créer</button>
      </form>
    </div>

    <div class="card">
      <div class="card-header"><h2>Changer mon mot de passe</h2></div>
      <form method="POST" class="form-body">
        <input type="hidden" name="action" value="change_password">
        <div class="form-group"><label>Nouveau mot de passe</label><input type="password" name="new_password" class="form-control" required minlength="6"></div>
        <button type="submit" class="btn btn-outline">Modifier</button>
      </form>
    </div>
  </div>
</div>

<div class="card" style="margin-top:16px">
  <div class="card-header"><h2>Configuration API</h2></div>
  <div class="form-body">
    <div class="info-row"><span>Clé API Botora</span><code><?= h(API_KEY) ?></code></div>
    <div class="info-row"><span>Endpoint validate</span><code><?= h(APP_URL) ?>/api/validate.php</code></div>
    <div class="info-row"><span>Endpoint consume</span><code><?= h(APP_URL) ?>/api/consume.php</code></div>
    <div class="info-row"><span>Endpoint features</span><code><?= h(APP_URL) ?>/api/features.php</code></div>
    <p class="text-muted" style="margin-top:12px;font-size:.85rem">Configurez ces valeurs dans le fichier <code>config.php</code> sur votre serveur ou via variables d'environnement.</p>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
