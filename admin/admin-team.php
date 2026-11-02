<?php
$pageTitle = 'Équipe admin'; $activePage = 'admin-team';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_superadmin();

$db = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';
  if ($action === 'create_admin') {
    $name = trim((string)($_POST['name'] ?? ''));
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    $password = (string)($_POST['password'] ?? '');
    $role = (string)($_POST['role'] ?? 'viewer');
    if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 6 || !in_array($role, ['superadmin', 'admin', 'viewer'], true)) {
      flash_set('danger', 'Données invalides. Le mot de passe doit contenir au moins 6 caractères.');
    } else {
      try {
        $db->prepare('INSERT INTO admins (name,email,password_hash,role) VALUES (?,?,?,?)')->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT), $role]);
        flash_set('success', 'Administrateur créé.');
      } catch (PDOException $e) {
        flash_set('danger', $e->getCode() === '23000' ? 'Cette adresse email est déjà utilisée.' : 'Impossible de créer cet administrateur.');
      }
    }
  } elseif ($action === 'delete_admin') {
    $adminId = (int)($_POST['admin_id'] ?? 0);
    if (!$adminId || $adminId === (int)$_SESSION['admin_id']) {
      flash_set('danger', 'Vous ne pouvez pas vous supprimer vous-même.');
    } else {
      $stmt = $db->prepare('DELETE FROM admins WHERE id=?');
      $stmt->execute([$adminId]);
      flash_set($stmt->rowCount() ? 'success' : 'danger', $stmt->rowCount() ? 'Admin supprimé.' : 'Administrateur introuvable.');
    }
  }
  header('Location: ' . APP_URL . '/admin/admin-team.php');
  exit;
}

$admins = $db->query('SELECT id, name, email, role, created_at, last_login FROM admins ORDER BY id ASC')->fetchAll();
require_once __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
  <div>
    <h1>Équipe admin</h1>
    <p class="text-muted mb-0">Gérez les comptes ayant accès à l’administration.</p>
  </div>
</div>

<div class="card">
  <div class="card-header"><h2>Créer un admin</h2></div>
  <form method="POST" class="form-body">
    <input type="hidden" name="action" value="create_admin">
    <div class="form-row">
      <div class="form-group"><label for="admin-name">Nom</label><input id="admin-name" type="text" name="name" class="form-control" required autocomplete="name"></div>
      <div class="form-group"><label for="admin-email">Email</label><input id="admin-email" type="email" name="email" class="form-control" required autocomplete="email"></div>
    </div>
    <div class="form-row">
      <div class="form-group"><label for="admin-password">Mot de passe</label><input id="admin-password" type="password" name="password" class="form-control" required minlength="6" autocomplete="new-password"></div>
      <div class="form-group"><label for="admin-role">Rôle</label><select id="admin-role" name="role" class="form-control"><option value="viewer">Viewer (lecture seule)</option><option value="admin">Admin</option><option value="superadmin">Superadmin</option></select></div>
    </div>
    <button type="submit" class="btn btn-primary">Créer l’administrateur</button>
  </form>
</div>

<div class="card">
  <div class="card-header"><h2>Liste des administrateurs</h2></div>
  <div class="table-responsive">
    <table class="table">
      <thead><tr><th>ID</th><th>Nom</th><th>Email</th><th>Rôle</th><th>Créé le</th><th>Dernière connexion</th><th>Action</th></tr></thead>
      <tbody>
      <?php foreach ($admins as $admin): ?>
        <tr>
          <td>#<?= (int)$admin['id'] ?></td>
          <td><strong><?= h($admin['name']) ?></strong><?php if ((int)$admin['id'] === (int)$_SESSION['admin_id']): ?><br><small class="text-muted">Vous</small><?php endif; ?></td>
          <td><?= h($admin['email']) ?></td>
          <td><span class="badge <?= $admin['role'] === 'superadmin' ? 'badge-danger' : ($admin['role'] === 'admin' ? 'badge-primary' : 'badge-secondary') ?>"><?= h($admin['role']) ?></span></td>
          <td><?= format_datetime($admin['created_at']) ?></td>
          <td><?= format_datetime($admin['last_login']) ?></td>
          <td>
            <?php if ((int)$admin['id'] !== (int)$_SESSION['admin_id']): ?>
            <form method="POST" onsubmit="return confirm('Supprimer définitivement cet administrateur ?');">
              <input type="hidden" name="action" value="delete_admin">
              <input type="hidden" name="admin_id" value="<?= (int)$admin['id'] ?>">
              <button type="submit" class="btn btn-sm btn-danger">Supprimer</button>
            </form>
            <?php else: ?><span class="text-muted">Compte actuel</span><?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$admins): ?><tr><td colspan="7" class="text-center text-muted py-4">Aucun administrateur.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
