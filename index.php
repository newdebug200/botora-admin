<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
session_init();

// Vérifie/crée la base et applique le schéma de manière idempotente avant toute connexion.
$databaseStatus = db_status(true);
$databaseReady = !empty($databaseStatus[db_active_source()]['ok']);

if (!empty($_SESSION['admin_id'])) {
  header('Location: ' . APP_URL . '/admin/dashboard.php'); exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $databaseReady) {
  $email    = trim($_POST['email'] ?? '');
  $password = trim($_POST['password'] ?? '');
  if (auth_login($email, $password)) {
    $requestedRedirect = (string)($_GET['redirect'] ?? '');
    // Accepte uniquement un chemin local ; toute URL absolue, notamment localhost,
    // est remplacée par l’URL publique configurée dans APP_URL.
    $redirect = (preg_match('#^/[A-Za-z0-9/_?=&.%:-]*$#', $requestedRedirect) && !preg_match('#localhost|127\\.0\\.0\\.1#i', $requestedRedirect))
      ? $requestedRedirect
      : APP_URL . '/admin/dashboard.php';
    header('Location: ' . $redirect); exit;
  }
  $error = 'Email ou mot de passe incorrect.';
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Botora Admin — Connexion</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
  <link rel="stylesheet" href="<?= APP_URL ?>/assets/style.css">
</head>
<body class="login-page bg-light">
  <main class="container min-vh-100 d-flex align-items-center justify-content-center py-5">
    <div class="card shadow-lg border-0 rounded-4 w-100" style="max-width: 440px;">
      <div class="card-body p-4 p-md-5">
        <div class="text-center mb-4">
          <img src="<?= APP_URL ?>/assets/logo-botora.png" alt="Botora" width="92" height="92" class="img-fluid mb-3">
          <h1 class="h3 fw-bold mb-1">Botora Admin</h1>
          <p class="text-secondary mb-0">Centre de gestion de votre plateforme</p>
        </div>
    <?php if (!$databaseReady): ?>
      <div class="alert alert-danger">
        La base <?= h(db_active_source()) ?> est indisponible. Vérifiez la configuration de connexion.
        <?php $dbError = $databaseStatus[db_active_source()]['message'] ?? ''; ?>
        <?php if ($dbError): ?><br><small><?= h($dbError) ?></small><?php endif; ?>
      </div>
    <?php elseif (DB_MODE === 'dual' && !empty($databaseStatus['online']) && !$databaseStatus['online']['ok']): ?>
      <div class="alert alert-warning">La base locale est prête, mais la base en ligne est momentanément indisponible.</div>
    <?php endif; ?>
    <?php if ($error): ?>
      <div class="alert alert-danger"><?= h($error) ?></div>
    <?php endif; ?>
    <form method="POST" autocomplete="on"<?= !$databaseReady ? ' aria-disabled="true"' : '' ?>>
      <div class="form-group">
        <label>Email</label>
        <input type="email" name="email" class="form-control" required autofocus value="<?= h($_POST['email'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label>Mot de passe</label>
        <input type="password" name="password" class="form-control" required>
      </div>
      <button type="submit" class="btn btn-primary btn-block">Se connecter</button>
        </form>
      </div>
    </div>
  </main>
</body>
</html>
