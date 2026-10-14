<?php
require __DIR__ . '/../../app/bootstrap.php';
$u = require_perm('admin'); $adminArea = true; $active = 'audit'; $title = 'Audit log'; $scripts = [];
$q = trim((string)($_GET['q'] ?? '')); $actor = (int)($_GET['actor'] ?? 0); $type = trim((string)($_GET['type'] ?? ''));
$w = [in_school('a')]; $a = []; if ($q !== '') { $w[] = 'a.action LIKE ?'; $a[] = "%$q%"; } if ($actor) { $w[] = 'a.actor_id=?'; $a[] = $actor; } if ($type !== '') { $w[] = 'a.target_type=?'; $a[] = $type; }
$W = implode(' AND ', $w); $per = per_page(25); $page = page_no(); $total = (int)val("SELECT COUNT(*) FROM audit_log a WHERE $W", $a);
$list = rows("SELECT a.*,u.full_name FROM audit_log a LEFT JOIN users u ON u.id=a.actor_id WHERE $W ORDER BY a.id DESC LIMIT $per OFFSET " . (($page - 1) * $per), $a);
$staff = rows('SELECT id,full_name name FROM users WHERE role_id IN (2,3,4,5) AND (' . in_school() . ' OR role_id=5) ORDER BY full_name');
$types = rows('SELECT DISTINCT target_type t FROM audit_log WHERE ' . in_school() . ' AND target_type IS NOT NULL AND target_type<>"" ORDER BY t');
include ROOT . '/includes/dashboard_header.php';
echo page_head('Audit log', number_format($total) . ' recorded actions. Nothing here can be edited.');
?>
<section class="card dt">
  <?= dt_head('Activity', 'Newest first') ?>
  <form class="toolbar" method="get"><label class="search"><?= ic('search', 'i') ?><span class="vh">Search</span><input name="q" value="<?= e($q) ?>" type="search" placeholder="Search actions"></label>
    <select class="in" name="actor" aria-label="Who"><?= options($staff, $actor, 'id', 'name', 'Anyone') ?></select>
    <select class="in" name="type" aria-label="Target"><?= options($types, $type, 't', 't', 'Any target') ?></select><button class="btn sm">Filter</button></form>
  <div class="dtwrap"><table class="dtable"><thead><tr><th>When</th><th>Action</th><th>By</th><th>Target</th><th>IP address</th></tr></thead><tbody>
  <?php foreach ($list as $l): ?><tr>
    <td class="nw mono" data-v="<?= e($l['created_at']) ?>"><?= e(fdate($l['created_at'], 'j M Y H:i')) ?></td>
    <td class="dmain"><?= e($l['action']) ?></td><td><?= e($l['full_name'] ?? 'System') ?></td>
    <td class="nw"><?= $l['target_type'] ? e($l['target_type']) . ' #' . (int)$l['target_id'] : '<span class="muted">&ndash;</span>' ?></td><td class="nw muted"><?= e($l['ip'] ?: '–') ?></td></tr>
  <?php endforeach; if (!$list) echo dt_empty(5, 'Nothing logged yet.'); ?></tbody></table></div>
  <?= dt_foot($total, $per, $page, ['q' => $q, 'actor' => $actor, 'type' => $type]) ?>
</section>
<?php include ROOT . '/includes/dashboard_footer.php'; ?>
