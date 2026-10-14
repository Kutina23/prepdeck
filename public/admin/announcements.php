<?php
require __DIR__ . '/../../app/bootstrap.php';
$u = require_perm('announce'); $adminArea = true; $active = 'announcements'; $title = 'Announcements'; $scripts = ['forms.js'];
$deps = rows('SELECT id,name FROM departments WHERE is_active=1 AND ' . in_school() . ' ORDER BY name');
if (is_post()) {
    form_guard(); $a = $_POST['a'] ?? ''; $id = (int)($_POST['id'] ?? 0);
    if (($a === 'delete' && !in_my_school('announcements', $id)) || ($a === 'sub_delete' && !in_my_school('alert_subscribers', $id))) { flash('bad', 'Not found in this school.'); redirect_self_or_ajax(); }
    if ($a === 'add') {
        $t = Validator::str($_POST['title'] ?? '', 3, 160); $b = Validator::str($_POST['body'] ?? '', 3, 5000); $aud = Validator::in($_POST['audience'] ?? '', ['all', 'free', 'plus', 'department']); $dep = (int)($_POST['department_id'] ?? 0) ?: null;
        if (!$t || !$b || !$aud || ($aud === 'department' && (!$dep || !in_my_school('departments', $dep)))) flash('bad', 'Add a title, a message and who it is for.');
        else { $at = !empty($_POST['publish_at']) ? gmdate('Y-m-d H:i:s', strtotime($_POST['publish_at'] . ' UTC')) : gmdate('Y-m-d H:i:s');
            q('INSERT INTO announcements(school_id,author_id,title,body,audience,department_id,publish_at) VALUES (?,?,?,?,?,?,?)', [sid(), $u['id'], $t, $b, $aud, $aud === 'department' ? $dep : null, $at]); Audit::log("announced: $t", 'announcement', (int)db()->lastInsertId()); flash('ok', 'Announcement published.'); }
    } elseif ($a === 'delete') { q('DELETE FROM announcements WHERE id=?', [$id]); Audit::log("deleted announcement $id", 'announcement', $id); flash('ok', 'Deleted.'); }
    elseif ($a === 'sub_delete') { q('DELETE FROM alert_subscribers WHERE id=?', [$id]); flash('ok', 'Removed.'); }
    redirect_self_or_ajax();
}
$tab = ($_GET['tab'] ?? '') === 'subscribers' ? 'subscribers' : 'published'; $q = trim((string)($_GET['q'] ?? '')); $aud = $_GET['aud'] ?? ''; $per = per_page(15); $page = page_no();
$act = 'admin/announcements.php';
$annN = (int)val('SELECT COUNT(*) FROM announcements WHERE ' . in_school()); $subN = (int)val('SELECT COUNT(*) FROM alert_subscribers WHERE ' . in_school());
include ROOT . '/includes/dashboard_header.php';
echo page_head('Announcements', 'Messages students see in the bell and on their dashboard.', modal_btn('tpl-new-ann', 'New announcement', 'New announcement', 'btn', 'plus'));
?>
<div class="tabs" role="navigation"><a class="tab" href="?tab=published" aria-selected="<?= $tab === 'published' ? 'true' : 'false' ?>">Published (<?= $annN ?>)</a><a class="tab" href="?tab=subscribers" aria-selected="<?= $tab === 'subscribers' ? 'true' : 'false' ?>">Alert subscribers (<?= $subN ?>)</a></div>
<?php if ($tab === 'published'):
    $w = [in_school('a')]; $a = []; if ($q !== '') { $w[] = '(a.title LIKE ? OR a.body LIKE ?)'; array_push($a, "%$q%", "%$q%"); } if (in_array($aud, ['all', 'free', 'plus', 'department'], true)) { $w[] = 'a.audience=?'; $a[] = $aud; }
    $W = implode(' AND ', $w); $total = (int)val("SELECT COUNT(*) FROM announcements a WHERE $W", $a);
    $list = rows("SELECT a.*,u.full_name,d.name dept FROM announcements a JOIN users u ON u.id=a.author_id LEFT JOIN departments d ON d.id=a.department_id WHERE $W ORDER BY a.publish_at DESC LIMIT $per OFFSET " . (($page - 1) * $per), $a); ?>
<section class="card dt">
  <?= dt_head('Published', number_format($total) . ' in this view') ?>
  <form class="toolbar" method="get"><input type="hidden" name="tab" value="published"><label class="search"><?= ic('search', 'i') ?><span class="vh">Search</span><input name="q" value="<?= e($q) ?>" type="search" placeholder="Title or message"></label>
    <select class="in" name="aud" aria-label="Audience"><option value="">Any audience</option><?php foreach (['all' => 'Everyone', 'free' => 'Free members', 'plus' => 'Plus members', 'department' => 'One department'] as $k => $l): ?><option value="<?= $k ?>"<?= $aud === $k ? ' selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select><button class="btn sm">Filter</button></form>
  <div class="dtwrap"><table class="dtable"><thead><tr><th>Announcement</th><th>Audience</th><th>Publish at</th><th>Author</th><th>Status</th><th data-nosort><span class="vh">Actions</span></th></tr></thead><tbody>
  <?php foreach ($list as $x): $later = strtotime($x['publish_at'] . ' UTC') > time(); ?><tr>
    <td class="dmain"><span class="dtt"><?= e($x['title']) ?></span><span class="ts clip"><?= e(mb_strimwidth($x['body'], 0, 140, '…')) ?></span></td>
    <td><span class="pill free"><?= $x['audience'] === 'department' ? e($x['dept'] ?: 'Department') : e(ucfirst($x['audience'])) ?></span></td>
    <td class="nw" data-v="<?= e($x['publish_at']) ?>"><?= e(fdate($x['publish_at'], 'j M Y H:i')) ?></td><td><?= e($x['full_name']) ?></td>
    <td><?= $later ? '<span class="pill wait">Scheduled</span>' : '<span class="pill ok">Live</span>' ?></td>
    <td class="act"><div class="rowact"><?= modal_btn('tpl-v' . $x['id'], $x['title'], 'View', 'btn sm line') ?><?= row_form($act, ['a' => 'delete', 'id' => $x['id']], 'Delete', 'btn sm danger', 'Delete this announcement?') ?></div>
      <template id="tpl-v<?= $x['id'] ?>"><p class="note"><?= $x['audience'] === 'department' ? e($x['dept']) : e(ucfirst($x['audience'])) ?> &middot; <?= e(fdate($x['publish_at'], 'j M Y H:i')) ?> UTC &middot; by <?= e($x['full_name']) ?></p><div class="mbody"><?= e($x['body']) ?></div>
        <?= row_form($act, ['a' => 'delete', 'id' => $x['id']], 'Delete announcement', 'btn sm danger', 'Delete this announcement?') ?></template></td></tr>
  <?php endforeach; if (!$list) echo dt_empty(6, 'No announcements yet.'); ?></tbody></table></div>
  <?= dt_foot($total, $per, $page, ['tab' => 'published', 'q' => $q, 'aud' => $aud]) ?>
</section>
<?php else:
    $w = in_school('s'); $a = []; if ($q !== '') { $w .= ' AND s.email LIKE ?'; $a[] = "%$q%"; }
    $total = (int)val("SELECT COUNT(*) FROM alert_subscribers s WHERE $w", $a);
    $list = rows("SELECT s.*,d.name dept FROM alert_subscribers s LEFT JOIN departments d ON d.id=s.department_id WHERE $w ORDER BY s.id DESC LIMIT $per OFFSET " . (($page - 1) * $per), $a); ?>
<section class="card dt">
  <?= dt_head('Alert subscribers', 'People who asked on the home page to hear about new papers. Copy these emails into your mailing tool.') ?>
  <form class="toolbar" method="get"><input type="hidden" name="tab" value="subscribers"><label class="search"><?= ic('search', 'i') ?><span class="vh">Search</span><input name="q" value="<?= e($q) ?>" type="search" placeholder="Email"></label><button class="btn sm">Search</button></form>
  <div class="dtwrap"><table class="dtable"><thead><tr><th>Email</th><th>Department</th><th>Joined</th><th data-nosort><span class="vh">Actions</span></th></tr></thead><tbody>
  <?php foreach ($list as $s): ?><tr><td class="dmain"><span class="dtt"><?= e($s['email']) ?></span></td><td><?= e($s['dept'] ?: 'Any') ?></td><td class="nw" data-v="<?= e($s['created_at']) ?>"><?= e(fdate($s['created_at'])) ?></td>
    <td class="act"><div class="rowact"><?= row_form($act, ['a' => 'sub_delete', 'id' => $s['id']], 'Remove', 'btn sm line', 'Remove this subscriber?') ?></div></td></tr>
  <?php endforeach; if (!$list) echo dt_empty(4, 'No subscribers yet.'); ?></tbody></table></div>
  <?= dt_foot($total, $per, $page, ['tab' => 'subscribers', 'q' => $q]) ?>
</section>
<?php endif; ?>
<template id="tpl-new-ann">
<form class="card mform" method="post" data-action="<?= $act ?>"><?= csrf_field() ?><input type="hidden" name="a" value="add"><h2>New announcement</h2>
  <div class="field"><label for="at">Title</label><input class="in" id="at" name="title" required maxlength="160"></div>
  <div class="field"><label for="ab">Message</label><textarea class="in" id="ab" name="body" rows="5" required maxlength="5000"></textarea></div>
  <div class="f2"><div class="field"><label for="au">Audience</label><select class="in" id="au" name="audience"><option value="all">Everyone</option><option value="free">Free members</option><option value="plus">Plus members</option><option value="department">One department</option></select></div>
  <div class="field"><label for="ad">Department</label><select class="in" id="ad" name="department_id"><?= options($deps, null, 'id', 'name', 'Not needed') ?></select></div></div>
  <div class="field"><label for="ap">Publish at (UTC, blank = now)</label><input class="in" id="ap" type="datetime-local" name="publish_at"></div><button class="btn">Publish</button></form>
</template>
<?php include ROOT . '/includes/dashboard_footer.php'; ?>
