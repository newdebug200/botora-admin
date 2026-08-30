<?php
$pageTitle = 'Paramètres'; $activePage = 'settings';
require_once __DIR__ . '/../includes/header.php';
require_superadmin();

$db = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';
  if ($action === 'change_password') {
    $currentPassword = (string)($_POST['current_password'] ?? '');
    $newPassword = (string)($_POST['new_password'] ?? '');
    $confirmPassword = (string)($_POST['confirm_password'] ?? '');
    $stmt = $db->prepare('SELECT password_hash FROM admins WHERE id=? LIMIT 1');
    $stmt->execute([$_SESSION['admin_id']]);
    $adminPasswordHash = (string)$stmt->fetchColumn();
    if (!password_verify($currentPassword, $adminPasswordHash)) {
      flash_set('danger','L’ancien mot de passe est incorrect.');
    } elseif (strlen($newPassword) < 6) {
      flash_set('danger','Le nouveau mot de passe doit contenir au moins 6 caractères.');
    } elseif ($newPassword !== $confirmPassword) {
      flash_set('danger','La confirmation du nouveau mot de passe ne correspond pas.');
    } else {
      $db->prepare('UPDATE admins SET password_hash=? WHERE id=?')->execute([password_hash($newPassword,PASSWORD_DEFAULT),$_SESSION['admin_id']]);
      flash_set('success','Mot de passe modifié.');
    }
  }
  header('Location: ' . APP_URL . '/admin/settings.php'); exit;
}
?>
<div class="page-header"><h1>Paramètres</h1></div>

<div class="row-2col" style="align-items:start">
  <div>
    <div class="card">
      <div class="card-header"><h2>Changer mon mot de passe</h2></div>
      <form method="POST" class="form-body">
        <input type="hidden" name="action" value="change_password">
        <div class="form-group"><label for="current-password">Ancien mot de passe</label><input id="current-password" type="password" name="current_password" class="form-control" required autocomplete="current-password"></div>
        <div class="form-group"><label for="new-password">Nouveau mot de passe</label><input id="new-password" type="password" name="new_password" class="form-control" required minlength="6" autocomplete="new-password"></div>
        <div class="form-group"><label for="confirm-password">Confirmer le nouveau mot de passe</label><input id="confirm-password" type="password" name="confirm_password" class="form-control" required minlength="6" autocomplete="new-password"></div>
        <button type="submit" class="btn btn-outline">Modifier</button>
      </form>
    </div>
  </div>
</div>

<div class="card" style="margin-top:16px">
  <div class="card-header"><h2>Configuration API</h2></div>
  <div class="form-body">
    <div class="info-row"><span>Clé API Botora</span><code><?= h(API_KEY) ?></code></div>
    <div class="info-row"><span>Endpoint validate</span><code><?= h(BOTORA_API_URL) ?>/api/validate.php</code></div>
    <div class="info-row"><span>Endpoint consume</span><code><?= h(BOTORA_API_URL) ?>/api/consume.php</code></div>
    <div class="info-row"><span>Endpoint features</span><code><?= h(BOTORA_API_URL) ?>/api/features.php</code></div>
    <p class="text-muted" style="margin-top:12px;font-size:.85rem">Configurez ces valeurs dans le fichier <code>config.php</code> sur votre serveur ou via variables d'environnement.</p>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
