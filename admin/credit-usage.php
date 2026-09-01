<?php
$pageTitle = 'Utilisation des crédits'; $activePage = 'credit-usage';
require_once __DIR__ . '/../includes/header.php';
require_superadmin();

$db = db();
$page = max(1, (int)($_GET['page'] ?? 1));
$allowedPerPage = [50, 100, 500, 1000];
$perPage = (int)($_GET['per_page'] ?? 50);
if (!in_array($perPage, $allowedPerPage, true)) $perPage = 50;
$email = strtolower(trim((string)($_GET['email'] ?? '')));
$eventType = trim((string)($_GET['event_type'] ?? ''));
$where = [];
$params = [];
if ($email !== '') { $where[] = 'u.email LIKE ?'; $params[] = '%' . $email . '%'; }
if ($eventType !== '') { $where[] = 'ul.event_type = ?'; $params[] = $eventType; }
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
$count = $db->prepare("SELECT COUNT(*) FROM usage_logs ul LEFT JOIN users u ON u.id=ul.user_id{$whereSql}"); $count->execute($params); $total = (int)$count->fetchColumn();
$pages = max(1, (int)ceil($total / $perPage)); $page = min($page, $pages); $offset = ($page - 1) * $perPage;
$summaryStmt = $db->prepare("SELECT COUNT(*) occurrences, COALESCE(SUM(ul.tokens_used),0) tokens, COALESCE(SUM(ul.credits_used),0) credits FROM usage_logs ul LEFT JOIN users u ON u.id=ul.user_id{$whereSql}"); $summaryStmt->execute($params); $summary = $summaryStmt->fetch() ?: ['occurrences'=>0,'tokens'=>0,'credits'=>0];
$eventTypes = $db->query('SELECT DISTINCT event_type FROM usage_logs ORDER BY event_type')->fetchAll(PDO::FETCH_COLUMN);
$stmt = $db->prepare("SELECT ul.*, u.name AS user_name, u.email AS user_email FROM usage_logs ul LEFT JOIN users u ON u.id=ul.user_id{$whereSql} ORDER BY ul.logged_at DESC, ul.id DESC LIMIT {$perPage} OFFSET {$offset}"); $stmt->execute($params); $usage = $stmt->fetchAll();
function usage_meta(array $row): array { $meta = json_decode((string)($row['meta'] ?? ''), true); return is_array($meta) ? $meta : []; }
function page_url(int $page, int $perPage, string $email, string $eventType): string { return '?' . http_build_query(['page'=>$page,'per_page'=>$perPage,'email'=>$email,'event_type'=>$eventType]); }
?>
<div class="page-header"><div><h1>Utilisation des crédits</h1><p class="text-muted mb-0">Suivi paginé des consommations enregistrées par la plateforme.</p></div></div>
<div class="stats-grid">
  <div class="stat-card"><div class="stat-icon blue"><span style="font-weight:700">#</span></div><div class="stat-body"><div class="stat-value"><?= number_format((int)$summary['occurrences']) ?></div><div class="stat-label">Occurrences</div></div></div>
  <div class="stat-card"><div class="stat-icon orange"><span style="font-weight:700">T</span></div><div class="stat-body"><div class="stat-value"><?= number_format((int)$summary['tokens']) ?></div><div class="stat-label">Tokens utilisés</div></div></div>
  <div class="stat-card"><div class="stat-icon green"><span style="font-weight:700">C</span></div><div class="stat-body"><div class="stat-value"><?= number_format((float)$summary['credits'], 3, ',', ' ') ?></div><div class="stat-label">Crédits consommés</div></div></div>
</div>
<div class="card">
  <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap"><div><h2>Journal des consommations</h2><span class="text-muted small"><?= number_format($total) ?> occurrence(s) au total</span></div><form method="GET" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center"><input type="search" name="email" value="<?= h($email) ?>" class="form-control" placeholder="Email utilisateur" style="min-width:220px"><select name="event_type" class="form-control"><option value="">Tous les événements</option><?php foreach ($eventTypes as $type): ?><option value="<?= h($type) ?>" <?= $eventType===$type?'selected':'' ?>><?= h($type) ?></option><?php endforeach; ?></select><select name="per_page" class="form-control"><?php foreach ($allowedPerPage as $size): ?><option value="<?= $size ?>" <?= $perPage===$size?'selected':'' ?>><?= $size ?> / page</option><?php endforeach; ?></select><button class="btn btn-primary" type="submit">Filtrer</button></form></div>
  <div class="table-responsive"><table class="table table-hover align-middle"><thead><tr><th>Date</th><th>Utilisateur</th><th>Événement</th><th>Tokens</th><th>Crédits</th><th>Conversion appliquée</th><th>Détails</th></tr></thead><tbody>
  <?php foreach ($usage as $row): $meta=usage_meta($row); $conversion=$meta['conversion'] ?? []; ?>
    <tr><td><?= format_datetime($row['logged_at']) ?></td><td><strong><?= h($row['user_name'] ?? '—') ?></strong><br><small class="text-muted"><?= h($row['user_email'] ?? '—') ?></small></td><td><span class="badge badge-secondary"><?= h($row['event_type']) ?></span></td><td><?= number_format((int)$row['tokens_used']) ?></td><td><strong><?= number_format((float)$row['credits_used'], 6, ',', ' ') ?></strong></td><td><small>100k tokens = <?= number_format((float)($row['credits_per_unit'] ?? $conversion['credits_per_unit'] ?? 1), 6, ',', ' ') ?> crédit(s)<br><?= number_format((float)($row['xof_per_unit'] ?? $conversion['xof_per_unit'] ?? 120), 2, ',', ' ') ?> XOF</small></td><td><details><summary>Voir</summary><pre style="max-width:320px;white-space:pre-wrap;font-size:.75rem;margin:8px 0 0"><?= h(json_encode($meta['payload'] ?? $meta, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)) ?></pre></details></td></tr>
  <?php endforeach; ?>
  <?php if (!$usage): ?><tr><td colspan="7" class="text-center text-muted py-4">Aucune consommation trouvée.</td></tr><?php endif; ?>
  </tbody></table></div>
  <div class="pagination-bar"><span>Page <?= $page ?> sur <?= $pages ?></span><div><?php if ($page>1): ?><a class="btn btn-sm btn-outline" href="<?= h(page_url($page-1,$perPage,$email,$eventType)) ?>">← Précédente</a><?php endif; ?> <?php if ($page<$pages): ?><a class="btn btn-sm btn-outline" href="<?= h(page_url($page+1,$perPage,$email,$eventType)) ?>">Suivante →</a><?php endif; ?></div></div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
