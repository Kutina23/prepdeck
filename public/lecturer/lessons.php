<?php
require __DIR__ . '/../../app/bootstrap.php';
$u = lecturer_area_user(); $lecturerArea = true; $active = 'lessons'; $title = 'Lesson notes'; $scripts = ['forms.js'];
$uid = (int)$u['id']; $act = 'lecturer/lessons.php';
$mine = fn(int $id) => row('SELECT n.*,c.code,c.title course,o.title otitle FROM lesson_notes n JOIN courses c ON c.id=n.course_id LEFT JOIN course_outlines o ON o.id=n.outline_id WHERE n.id=? AND n.user_id=? AND ' . in_school('n'), [$id, $uid]);
if (is_post()) {
    form_guard(); $a = $_POST['a'] ?? ''; $id = (int)($_POST['id'] ?? 0);
    if ($a === 'add') {
        $c = (int)($_POST['course_id'] ?? 0); $t = Validator::str($_POST['title'] ?? '', 3, 190); $b = Validator::str($_POST['body'] ?? '', 20, 200000);
        if (!$t || !$b || !Lessons::courseOk($c, staff_course_ids($u)) || !in_my_school('courses', $c)) flash('bad', 'Choose one of your courses and add a title and the note (20+ characters).');
        else { q('INSERT INTO lesson_notes(school_id,user_id,course_id,title,week,body,word_count,status,ai_generated) VALUES (?,?,?,?,?,?,?,\'draft\',0)', [sid(), $uid, $c, $t, mb_substr(trim((string)($_POST['week'] ?? '')), 0, 40) ?: null, $b, str_word_count($b)]);
            $nid = (int)db()->lastInsertId(); Audit::log("wrote lesson note $t", 'lesson', $nid); flash('ok', 'Lesson note saved as a draft.'); redirect_or_ajax('lecturer/lessons.php?id=' . $nid); }
    } elseif (!$n = $mine($id)) flash('bad', 'Lesson note not found.');
    elseif ($a === 'save') {
        $t = Validator::str($_POST['title'] ?? '', 3, 190); $b = Validator::str($_POST['body'] ?? '', 20, 200000);
        if (!$t || !$b) flash('bad', 'Add a title and the note.');
        else { q('UPDATE lesson_notes SET title=?, week=?, body=?, word_count=? WHERE id=?', [$t, mb_substr(trim((string)($_POST['week'] ?? '')), 0, 40) ?: null, $b, str_word_count($b), $id]); flash('ok', 'Saved.'); }
    } elseif ($a === 'status') {
        $s = Validator::in($_POST['status'] ?? '', ['published', 'draft']);
        if ($n['status'] === 'hidden') flash('bad', 'An admin has hidden this note. Ask the admin to review it again.');
        elseif ($s) { Lessons::publish($id, $s); Audit::log("lesson note $id $s", 'lesson', $id); flash('ok', $s === 'published' ? 'Published. Students in ' . $n['code'] . ' can read it now.' : 'Moved back to drafts. Students no longer see it.'); }
    } elseif ($a === 'delete') {
        q('DELETE FROM lesson_notes WHERE id=?', [$id]); if ($n['topic_id']) q("UPDATE outline_topics SET note_status='none' WHERE id=?", [$n['topic_id']]);
        Audit::log("deleted lesson note $id", 'lesson', $id); flash('ok', 'Lesson note deleted.'); redirect_or_ajax('lecturer/lessons.php');
    }
    redirect_self_or_ajax();
}
$id = (int)($_GET['id'] ?? 0);
if ($id && isset($_GET['dl'])) {                                     // download as a Markdown file (opens in Word, Google Docs, any editor)
    $n = $mine($id); if (!$n) { http_response_code(404); exit('Not found'); }
    header('Content-Type: text/markdown; charset=utf-8'); header('Content-Disposition: attachment; filename="' . preg_replace('/[^\w.-]+/', '_', $n['code'] . ' ' . $n['title']) . '.md"');
    echo $n['body']; exit;
}
$courses = staff_courses($u);
$addTpl = function () use ($courses, $act) { if (!$courses) return ''; ob_start(); ?>
<template id="tpl-lesson-add">
<form class="card mform" method="post" data-action="<?= $act ?>"><?= csrf_field() ?><input type="hidden" name="a" value="add"><h2>Write a lesson note</h2>
  <div class="f2"><div class="field"><label for="lc">Course</label><select class="in" id="lc" name="course_id" required><?= options($courses, count($courses) === 1 ? $courses[0]['id'] : null, 'id', 'n', 'Choose course') ?></select></div><div class="field"><label for="lw">Week</label><input class="in" id="lw" name="week" maxlength="40" placeholder="Week 3"></div></div>
  <div class="field"><label for="lt">Title</label><input class="in" id="lt" name="title" required maxlength="190"></div>
  <div class="field"><label for="lb">Note</label><textarea class="in" id="lb" name="body" rows="14" required placeholder="# Title&#10;## Section&#10;- point"></textarea><span class="hint note"># heading, ## section, - list item, **bold**</span></div>
  <button class="btn" type="submit">Save draft</button></form>
</template>
<?php return ob_get_clean(); };

if ($id) {
    $n = $mine($id);
    if (!$n) { include ROOT . '/includes/dashboard_header.php'; echo page_head('Not found') . empty_state('That lesson note is not in your account.', '<a class="btn sm" href="' . e(url($act)) . '">All lesson notes</a>'); include ROOT . '/includes/dashboard_footer.php'; exit; }
    $title = $n['title'];
    include ROOT . '/includes/dashboard_header.php';
    $pub = $n['status'] === 'published';
    echo page_head($n['title'], $n['code'] . ($n['week'] ? ' · ' . $n['week'] : '') . ' · ' . number_format((int)$n['word_count']) . ' words' . ($n['ai_generated'] ? ' · written by PrepDeck AI' : ''),
        '<a class="btn sm line" href="' . e(url($act)) . '">All notes</a>'
        . modal_btn('tpl-edit', 'Edit lesson note', 'Edit', 'btn sm line', 'edit', 'wide')
        . '<a class="btn sm line" href="' . e(url($act . '?id=' . $id . '&dl=1')) . '">' . ic('download') . 'Download</a>'
        . ($n['status'] === 'hidden' ? '' : row_form($act, ['a' => 'status', 'id' => $id, 'status' => $pub ? 'draft' : 'published'], $pub ? 'Unpublish' : 'Publish to students', $pub ? 'btn sm line' : 'btn sm', $pub ? 'Hide this note from students?' : 'Share this lesson note with students in ' . $n['code'] . '?')));
    if ($n['status'] === 'hidden') echo '<div class="banner warn">An admin has hidden this note from students. You can still edit it; ask the admin to make it visible again.</div>';
    if ($n['ai_generated'] && !$pub) echo '<div class="banner">Written by PrepDeck AI. Read it through, fix anything in [square brackets] and correct any mistakes before you publish it.</div>';
    ?>
    <section class="kpis"><div class="card kpi"><b><?= status_pill($n['status']) ?></b><small>Status</small></div><div class="card kpi"><b><?= number_format((int)$n['views']) ?></b><small>Student views</small></div><div class="card kpi"><b><?= number_format((int)$n['word_count']) ?></b><small>Words</small></div>
      <div class="card kpi"><b style="font-size:16px"><?= $n['otitle'] ? '<a href="' . e(url('lecturer/outlines.php?id=' . $n['outline_id'])) . '">' . e(mb_strimwidth($n['otitle'], 0, 40, '…')) . '</a>' : 'Written by hand' ?></b><small>From outline</small></div></section>
    <article class="card guide"><?= guide_html($n['body']) ?></article>
    <section class="card"><h2>Delete</h2><p class="note" style="margin:6px 0 12px">Students lose access straight away. If it came from an outline you can write it again there.</p><?= row_form($act, ['a' => 'delete', 'id' => $id], 'Delete lesson note', 'btn sm danger', 'Delete this lesson note for good?') ?></section>
    <template id="tpl-edit">
    <form class="card mform" method="post" data-action="<?= $act ?>"><?= csrf_field() ?><input type="hidden" name="a" value="save"><input type="hidden" name="id" value="<?= $id ?>"><h2>Edit</h2>
      <div class="f2"><div class="field"><label for="et">Title</label><input class="in" id="et" name="title" required maxlength="190" value="<?= e($n['title']) ?>"></div><div class="field"><label for="ew">Week</label><input class="in" id="ew" name="week" maxlength="40" value="<?= e($n['week']) ?>"></div></div>
      <div class="field"><label for="eb">Note</label><textarea class="in" id="eb" name="body" rows="20" required><?= e($n['body']) ?></textarea><span class="hint note"># heading, ## section, - list item, **bold**</span></div>
      <button class="btn" type="submit">Save changes</button></form>
    </template>
    <?php include ROOT . '/includes/dashboard_footer.php'; exit;
}

$q = trim((string)($_GET['q'] ?? '')); $st = $_GET['status'] ?? ''; $cf = (int)($_GET['course'] ?? 0); $per = per_page(15); $page = page_no();
$w = ['n.user_id=?', in_school('n')]; $args = [$uid];
if (in_array($st, ['draft', 'published', 'hidden'], true)) { $w[] = 'n.status=?'; $args[] = $st; }
if ($cf) { $w[] = 'n.course_id=?'; $args[] = $cf; }
if ($q !== '') { $w[] = '(n.title LIKE ? OR n.week LIKE ?)'; array_push($args, "%$q%", "%$q%"); }
$W = implode(' AND ', $w);
$total = (int)val("SELECT COUNT(*) FROM lesson_notes n WHERE $W", $args);
$list = rows("SELECT n.id,n.title,n.week,n.word_count,n.status,n.views,n.ai_generated,n.updated_at,c.code FROM lesson_notes n JOIN courses c ON c.id=n.course_id WHERE $W ORDER BY n.course_id, n.outline_id IS NULL, n.outline_id DESC, (SELECT t.position FROM outline_topics t WHERE t.id=n.topic_id), n.id DESC LIMIT $per OFFSET " . (($page - 1) * $per), $args);
$sum = row("SELECT COUNT(*) n, COALESCE(SUM(status='published'),0) pub, COALESCE(SUM(status='draft'),0) dr, COALESCE(SUM(views),0) v FROM lesson_notes WHERE user_id=? AND " . in_school(), [$uid]);
$myCourses = rows('SELECT DISTINCT c.id,CONCAT(c.code," · ",c.title) n FROM lesson_notes l JOIN courses c ON c.id=l.course_id WHERE l.user_id=? AND ' . in_school('l') . ' ORDER BY c.code', [$uid]);
include ROOT . '/includes/dashboard_header.php';
echo page_head('Lesson notes', 'Check, edit and publish your lesson notes. Published notes appear for students in that course.',
    ($courses ? modal_btn('tpl-lesson-add', 'Write a lesson note', 'Write a note', 'btn line', 'edit', 'wide') : '') . '<a class="btn" href="' . e(url('lecturer/outlines.php')) . '">' . ic('spark') . 'From an outline</a>');
echo $addTpl();
?>
<section class="kpis"><div class="card kpi"><b><?= (int)$sum['n'] ?></b><small>Lesson notes</small></div><div class="card kpi"><b><?= (int)$sum['pub'] ?></b><small>Published</small></div><div class="card kpi"><b><?= (int)$sum['dr'] ?></b><small>Drafts to check</small></div><div class="card kpi"><b><?= number_format((int)$sum['v']) ?></b><small>Student views</small></div></section>
<section class="card dt">
  <?= dt_head('Your lesson notes', number_format($total) . ' in this view') ?>
  <form class="toolbar" method="get"><label class="search"><?= ic('search', 'i') ?><span class="vh">Search</span><input name="q" value="<?= e($q) ?>" type="search" placeholder="Title or week"></label>
    <select class="in" name="course" aria-label="Course"><?= options($myCourses, $cf ?: null, 'id', 'n', 'All courses') ?></select>
    <select class="in" name="status" aria-label="Status"><option value="">Any status</option><?php foreach (['draft' => 'Drafts', 'published' => 'Published', 'hidden' => 'Hidden by admin'] as $k => $l): ?><option value="<?= $k ?>"<?= $st === $k ? ' selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select><button class="btn sm">Filter</button></form>
  <div class="dtwrap"><table class="dtable"><thead><tr><th>Lesson</th><th>Course</th><th>Week</th><th class="num">Words</th><th class="num">Views</th><th>Status</th><th>Updated</th><th data-nosort><span class="vh">Actions</span></th></tr></thead><tbody>
  <?php foreach ($list as $r): $href = url('lecturer/lessons.php?id=' . $r['id']); ?><tr data-href="<?= e($href) ?>">
    <td class="dmain"><a class="dtt" href="<?= e($href) ?>"><?= e($r['title']) ?></a><span class="ts"><?= $r['ai_generated'] ? 'PrepDeck AI' : 'Written by you' ?></span></td><td class="nw"><span class="code"><?= e($r['code']) ?></span></td>
    <td class="nw muted"><?= e($r['week'] ?: '–') ?></td><td class="num"><?= number_format((int)$r['word_count']) ?></td><td class="num"><?= number_format((int)$r['views']) ?></td>
    <td><?= status_pill($r['status']) ?></td><td class="nw muted" data-v="<?= e($r['updated_at']) ?>"><?= e(ago($r['updated_at'])) ?></td>
    <td class="act"><div class="rowact"><a class="btn sm line" href="<?= e($href) ?>">Open</a><?= $r['status'] === 'draft' ? row_form($act, ['a' => 'status', 'id' => $r['id'], 'status' => 'published'], 'Publish', 'btn sm', 'Share this lesson note with students in ' . $r['code'] . '?') : '' ?></div></td></tr>
  <?php endforeach; if (!$list) echo dt_empty(8, 'No lesson notes yet. Upload a course outline to have them written for you.'); ?></tbody></table></div>
  <?= dt_foot($total, $per, $page, ['q' => $q, 'status' => $st, 'course' => $cf ?: '']) ?>
</section>
<?php include ROOT . '/includes/dashboard_footer.php'; ?>
