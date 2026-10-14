<?php
// Platform owner: the audit log of every school (and of the platform itself), filterable by school.
require __DIR__ . '/../../app/bootstrap.php';
$u = require_super(); $platformArea = true; $active = 'p-audit'; $title = 'Platform audit'; $scripts = [];
$q = trim((string)($_GET['q'] ?? '')); $sf = (string)($_GET['school'] ?? ''); $type = trim((string)($_GET['type'] ?? ''));
$w = ['1=1']; $a = [];
if ($q !== '') { $w[] = '(a.action LIKE ? OR u.email LIKE ?)'; array_push($a, "%$q%", "%$q%"); }
if ($sf === 'none') $w[] = 'a.school_id IS NULL'; elseif ((int)$sf) { $w[] = 'a.school_id=?'; $a[] = (int)$sf; }
if ($type !== '') { $w[] = 'a.target_type=?'; $a[] = $type; }
$W = implode(' AND ', $w); $per = per_page(25); $page = page_no();
$total = (int)val("SELECT COUNT(*) FROM audit_log a LEFT JOIN users u ON u.id=a.actor_id WHERE $W", $a);
$list = rows("SELECT a.*,u.full_name,u.role_id,s.short_name,s.name school FROM audit_log a LEFT JOIN users u ON u.id=a.actor_id LEFT JOIN schools s ON s.id=a.school_id WHERE $W ORDER BY a.id DESC LIMIT $per OFFSET " . (($page - 1) * $per), $a);
$schools = array_merge([['id' => 'none', 'n' => 'Platform (no school)']], rows('SELECT id,name n FROM schools WHERE id IN (SELECT DISTINCT school_id FROM audit_log WHERE school_id IS NOT NULL) ORDER BY name'));
$types = rows('SELECT DISTINCT target_type t FROM audit_log WHERE target_type IS NOT NULL AND target_type<>"" ORDER BY t');
include ROOT . '/includes/dashboard_header.php';
echo page_head('Platform audit', number_format($total) . ' recorded actions across every school. Nothing here can be edited.');
?>
<section class="card dt"><?= dt_head('Activity', 'Newest first') ?>
  <form class="toolbar" method="get"><label class="search"><?= ic('search', 'i') ?><span class="vh">Search</span><input name="q" value="<?= e($q) ?>" type="search" placeholder="Action or email"></label>
    <select class="in" name="school" aria-label="School"><?= options($schools, $sf, 'id', 'n', 'Every school') ?></select><select class="in" name="type" aria-label="Target"><?= options($types, $type, 't', 't', 'Any target') ?></select><button class="btn sm">Filter</button></form>
  <div class="dtwrap"><table class="dtable"><thead><tr><th>When</th><th>School</th><th>Action</th><th>By</th><th>Target</th><th>IP address</th></tr></thead><tbody>
  <?php foreach ($list as $l): ?><tr><td class="nw mono"><?= e(fdate($l['created_at'], 'j M Y H:i')) ?></td><td class="nw"><?= e($l['short_name'] ?: ($l['school'] ?: 'Platform')) ?></td><td class="dmain"><?= e($l['action']) ?></td>
    <td><?= e($l['full_name'] ?? 'System') ?><?= (int)$l['role_id'] === Tenant::SUPER ? '<span class="ts">Platform owner</span>' : '' ?></td><td class="nw"><?= $l['target_type'] ? e($l['target_type']) . ' #' . (int)$l['target_id'] : '<span class="muted">&ndash;</span>' ?></td><td class="nw muted"><?= e($l['ip'] ?: '–') ?></td></tr>
  <?php endforeach; if (!$list) echo dt_empty(6, 'Nothing logged yet.'); ?></tbody></table></div>
  <?= dt_foot($total, $per, $page, ['q' => $q, 'school' => $sf, 'type' => $type]) ?></section>
<?php include ROOT . '/includes/dashboard_footer.php'; ?>
