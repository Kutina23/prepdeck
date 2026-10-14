<?php
require __DIR__ . '/../../app/bootstrap.php';
$u = require_perm('announce'); $staffArea = true; $active = 'announcements'; $title = 'Announcements'; $scripts = ['forms.js'];
$ids = staff_course_ids();
$deps = $ids === null ? rows('SELECT id,name FROM departments WHERE is_active=1 AND ' . in_school() . ' ORDER BY name')
  : ($ids ? rows('SELECT DISTINCT d.id,d.name FROM departments d JOIN courses c ON c.department_id=d.id WHERE c.id IN (' . implode(',', $ids) . ') ORDER BY d.name') : []);
$depIds = array_map('intval', array_column($deps, 'id'));
if (is_post()) {
    form_guard(); $a = $_POST['a'] ?? ''; $id = (int)($_POST['id'] ?? 0);
    if ($a === 'add') {
        $t = Validator::str($_POST['title'] ?? '', 3, 160); $b = Validator::str($_POST['body'] ?? '', 3, 3000); $d = (int)($_POST['department_id'] ?? 0);
        if (!$t || !$b || !in_array($d, $depIds, true)) flash('bad', 'Add a title, a message and pick one of your departments.');
        else { q("INSERT INTO announcements(school_id,author_id,title,body,audience,department_id,publish_at) VALUES (?,?,?,?,'department',?,NOW())", [sid(), $u['id'], $t, $b, $d]); Audit::log("staff announcement: $t", 'announcement'); flash('ok', 'Announcement posted to your department.'); }
    } elseif ($a === 'delete') { q('DELETE FROM announcements WHERE id=? AND author_id=?', [$id, $u['id']]); flash('ok', 'Deleted.'); }
    redirect_self_or_ajax();
}
$q = trim((string)($_GET['q'] ?? '')); $per = per_page(15); $page = page_no();
$w = 'a.author_id=? AND ' . in_school('a'); $a = [$u['id']]; if ($q !== '') { $w .= ' AND (a.title LIKE ? OR a.body LIKE ?)'; array_push($a, "%$q%", "%$q%"); }
$total = (int)val("SELECT COUNT(*) FROM announcements a WHERE $w", $a);
$list = rows("SELECT a.*,d.name dept FROM announcements a LEFT JOIN departments d ON d.id=a.department_id WHERE $w ORDER BY a.id DESC LIMIT $per OFFSET " . (($page - 1) * $per), $a);
$act = 'staff/announcements.php';
include ROOT . '/includes/dashboard_header.php';
echo page_head('Announcements', 'Messages go to students in the department you pick.', $deps ? modal_btn('tpl-new-ann', 'New announcement', 'New announcement', 'btn', 'plus') : '');
if (!$deps) echo '<div class="banner warn">No courses are assigned to your account, so there is no department to post to.</div>'; else { ?>
<template id="tpl-new-ann">
<form class="card mform" method="post" data-action="<?= $act ?>"><?= csrf_field() ?><input type="hidden" name="a" value="add"><h2>New announcement</h2>
  <div class="field"><label for="at">Title</label><input class="in" id="at" name="title" required maxlength="160"></div>
  <div class="field"><label for="ab">Message</label><textarea class="in" id="ab" name="body" rows="5" required maxlength="3000"></textarea></div>
  <div class="field"><label for="ad">Department</label><select class="in" id="ad" name="department_id"><?= options($deps, count($deps) === 1 ? $deps[0]['id'] : null, 'id', 'name', 'Choose') ?></select></div><button class="btn">Post</button></form>
</template>
<?php } ?>
<section class="card dt">
  <?= dt_head('Your announcements', number_format($total) . ' posted') ?>
  <form class="toolbar" method="get"><label class="search"><?= ic('search', 'i') ?><span class="vh">Search</span><input name="q" value="<?= e($q) ?>" type="search" placeholder="Title or message"></label><button class="btn sm">Search</button></form>
  <div class="dtwrap"><table class="dtable"><thead><tr><th>Announcement</th><th>Department</th><th>Posted</th><th data-nosort><span class="vh">Actions</span></th></tr></thead><tbody>
  <?php foreach ($list as $x): ?><tr>
    <td class="dmain"><span class="dtt"><?= e($x['title']) ?></span><span class="ts clip"><?= e(mb_strimwidth($x['body'], 0, 140, '…')) ?></span></td>
    <td><span class="pill free"><?= e($x['dept'] ?: $x['audience']) ?></span></td><td class="nw muted" data-v="<?= e($x['publish_at']) ?>"><?= e(ago($x['publish_at'])) ?></td>
    <td class="act"><div class="rowact"><?= modal_btn('tpl-v' . $x['id'], $x['title'], 'View', 'btn sm line') ?><?= row_form($act, ['a' => 'delete', 'id' => $x['id']], 'Delete', 'btn sm danger', 'Delete this announcement?') ?></div>
      <template id="tpl-v<?= $x['id'] ?>"><p class="note"><?= e($x['dept'] ?: $x['audience']) ?> &middot; <?= e(fdate($x['publish_at'], 'j M Y H:i')) ?></p><div class="mbody"><?= e($x['body']) ?></div></template></td></tr>
  <?php endforeach; if (!$list) echo dt_empty(4, 'None yet.'); ?></tbody></table></div>
  <?= dt_foot($total, $per, $page, ['q' => $q]) ?>
</section>
<?php include ROOT . '/includes/dashboard_footer.php'; ?>
