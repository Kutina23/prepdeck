<?php
require __DIR__ . '/../../app/bootstrap.php';
$u = require_perm('approve'); $adminArea = true; $active = 'papers'; $title = 'Past questions'; $scripts = ['forms.js', 'process.js'];
function paper_status(int $id, string $new, ?string $reason = null): void {
    $p = row('SELECT status,course_id FROM papers WHERE id=? AND ' . in_school(), [$id]); if (!$p || $p['status'] === $new) return;
    q('UPDATE papers SET status=?, reject_reason=?, reviewed_by=? WHERE id=?', [$new, $new === 'rejected' ? $reason : null, Auth::user()['id'], $id]);
    if ($new === 'published') q('UPDATE courses SET paper_count=paper_count+1 WHERE id=?', [$p['course_id']]);
    elseif ($p['status'] === 'published') q('UPDATE courses SET paper_count=GREATEST(paper_count-1,0) WHERE id=?', [$p['course_id']]);
    Audit::log("paper $id set to $new", 'paper', $id);
}
if (is_post()) {
    form_guard(); $a = $_POST['a'] ?? ''; $id = (int)($_POST['id'] ?? 0);
    if ($id && !in_my_school('papers', $id)) { flash('bad', 'Paper not found.'); redirect_or_ajax('admin/papers.php'); }
    if ($a === 'status') { $s = Validator::in($_POST['status'] ?? '', ['published', 'rejected', 'unpublished', 'pending']); if ($s) { paper_status($id, $s, Validator::str($_POST['reason'] ?? '', 1, 250)); flash('ok', 'Paper ' . $s . '.'); } }
    elseif ($a === 'bulk') { $ids = array_map('intval', (array)($_POST['ids'] ?? [])); $s = Validator::in($_POST['status'] ?? '', ['published', 'rejected']); if ($ids && $s) { foreach ($ids as $i) paper_status($i, $s, 'Rejected in bulk review'); flash('ok', count($ids) . ' papers updated.'); } else flash('bad', 'Select at least one paper.'); }
    elseif ($a === 'save') {
        $t = Validator::str($_POST['title'] ?? '', 3, 190); $c = (int)($_POST['course_id'] ?? 0); if (!$t || !in_my_school('courses', $c)) flash('bad', 'Title and course are required.');
        else { $old = row('SELECT course_id,status FROM papers WHERE id=?', [$id]);
            q('UPDATE papers SET title=?,course_id=?,exam_year=?,semester=?,doc_kind=?,has_answers=? WHERE id=?', [$t, $c, (int)$_POST['exam_year'], (int)$_POST['semester'], Validator::in($_POST['doc_kind'] ?? '', ['auto', 'past_question', 'lecture_notes']) ?? 'auto', isset($_POST['has_answers']) ? 1 : 0, $id]);
            if ($old && $old['status'] === 'published' && (int)$old['course_id'] !== $c) { q('UPDATE courses SET paper_count=GREATEST(paper_count-1,0) WHERE id=?', [$old['course_id']]); q('UPDATE courses SET paper_count=paper_count+1 WHERE id=?', [$c]); }
            Audit::log("edited paper $id", 'paper', $id); flash('ok', 'Saved.'); }
    } elseif ($a === 'reprocess') { $p = row('SELECT file_path,doc_kind,uploaded_by FROM papers WHERE id=?', [$id]); if ($p) { JobQueue::enqueue('paper', $id, (int)$p['uploaded_by'], $p['file_path'], $p['doc_kind']); flash('ok', 'Sent to PrepDeck AI again.'); } }
    elseif ($a === 'delete' && Auth::can('admin')) { $p = row('SELECT file_path,course_id,status FROM papers WHERE id=?', [$id]);
        if ($p) { if ($p['status'] === 'published') q('UPDATE courses SET paper_count=GREATEST(paper_count-1,0) WHERE id=?', [$p['course_id']]);
            foreach (['DELETE FROM exam_questions WHERE source_type="paper" AND source_id=?', 'DELETE FROM decks WHERE paper_id=?', 'DELETE FROM study_guides WHERE paper_id=?', 'DELETE FROM processing_jobs WHERE source_type="paper" AND source_id=?', 'DELETE FROM papers WHERE id=?'] as $s) q($s, [$id]);
            @unlink(upload_path('papers', $p['file_path'])); Audit::log("deleted paper $id", 'paper', $id); flash('ok', 'Paper deleted.'); } redirect_or_ajax('admin/papers.php'); }
    redirect_self_or_ajax();
}
$courses = rows('SELECT id,CONCAT(code," · ",title) n FROM courses WHERE ' . in_school() . ' ORDER BY code');
$act = 'admin/papers.php';
$id = (int)($_GET['id'] ?? 0);
if ($id) {
    $p = row('SELECT p.*,c.code,u.full_name uploader FROM papers p JOIN courses c ON c.id=p.course_id JOIN users u ON u.id=p.uploaded_by WHERE p.id=? AND ' . in_school('p'), [$id]);
    if (!$p) { flash('bad', 'Paper not found.'); redirect('admin/papers.php'); }
    $title = $p['title']; $qs = rows('SELECT status,COUNT(*) c FROM exam_questions WHERE source_type="paper" AND source_id=? GROUP BY status', [$id]);
    $job = row('SELECT * FROM processing_jobs WHERE source_type="paper" AND source_id=? ORDER BY id DESC LIMIT 1', [$id]);
    $kinds = ['auto' => 'Detect', 'past_question' => 'Past question', 'lecture_notes' => 'Lecture notes'];
    include ROOT . '/includes/dashboard_header.php';
    echo page_head($p['title'], $p['code'] . ' · uploaded by ' . $p['uploader'] . ' · ' . ago($p['created_at']), '<a class="btn sm line" href="' . e(url('admin/papers.php')) . '">All papers</a>');
    echo ai_live('paper', $id);
    ?>
    <div class="grid2">
      <section class="card stack"><div class="hd"><div><h2>File</h2><p class="sub"><?= status_pill($p['status']) ?> &middot; <?= fmt_bytes($p['file_size']) ?> &middot; <?= (int)$p['views'] ?> views</p></div></div>
        <iframe class="pdfview" title="Paper preview" src="<?= e(url('api/papers.php?a=download&id=' . $id)) ?>"></iframe></section>
      <section class="stack">
        <div class="card"><h2>Review</h2><?php if ($p['reject_reason']): ?><p class="note">Rejected: <?= e($p['reject_reason']) ?></p><?php endif; ?>
          <div class="rowact" style="margin-top:12px">
            <?php foreach ([['published', 'Approve & publish', 'btn sm'], ['unpublished', 'Unpublish', 'btn sm line'], ['pending', 'Back to queue', 'btn sm line']] as [$s, $l, $c]): if ($p['status'] === $s) continue; echo row_form($act, ['a' => 'status', 'id' => $id, 'status' => $s], e($l), $c); endforeach; ?>
            <?php if ($p['status'] !== 'rejected') echo modal_btn('tpl-reject', 'Reject this paper', 'Reject', 'btn sm danger'); ?></div></div>
        <section class="card dt"><?= dt_head('Details', '', modal_btn('tpl-details', 'Edit paper details', 'Edit', 'btn sm line', 'edit')) ?>
          <dl class="kv"><dt>Title</dt><dd><?= e($p['title']) ?></dd><dt>Course</dt><dd><?= e($p['code']) ?></dd><dt>Year</dt><dd><?= (int)$p['exam_year'] ?> &middot; Semester <?= (int)$p['semester'] ?></dd><dt>Type</dt><dd><?= e($kinds[$p['doc_kind']] ?? $p['doc_kind']) ?></dd><dt>Solved answers</dt><dd><?= $p['has_answers'] ? 'Available (Plus)' : 'Not yet' ?></dd></dl></section>
        <div class="card"><h2>AI processing</h2><p class="note">Status: <?= status_pill($p['ai_status'] === 'none' ? 'pending' : $p['ai_status']) ?> <?php foreach ($qs as $x) echo e($x['status'] . ' ' . $x['c'] . ' · '); ?></p>
          <?php if ($job): ?><p class="note"><?= e($job['message'] ?: $job['stage']) ?></p><?php endif; ?>
          <div class="rowact" style="margin-top:10px"><?= row_form($act, ['a' => 'reprocess', 'id' => $id], 'Run again', 'btn sm line') ?><a class="btn sm line" href="<?= e(url('admin/processing.php?type=paper&sid=' . $id)) ?>">Review answers</a></div></div>
        <?php if (Auth::can('admin')) echo '<div>' . row_form($act, ['a' => 'delete', 'id' => $id], 'Delete paper', 'btn sm danger', 'Delete this paper, its file and everything made from it?') . '</div>'; ?>
      </section>
    </div>
    <template id="tpl-reject">
    <form class="card mform" method="post" data-action="<?= $act ?>"><?= csrf_field() ?><input type="hidden" name="a" value="status"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="status" value="rejected"><h2>Reject</h2>
      <p class="note">The uploader sees this reason.</p><div class="field"><label for="rj">Reason</label><input class="in" id="rj" name="reason" placeholder="Unreadable scan, wrong course…" required maxlength="250"></div><button class="btn" type="submit">Reject paper</button></form>
    </template>
    <template id="tpl-details">
    <form class="card mform" method="post" data-action="<?= $act ?>"><?= csrf_field() ?><input type="hidden" name="a" value="save"><input type="hidden" name="id" value="<?= $id ?>"><h2>Details</h2>
      <div class="field"><label for="t">Title</label><input class="in" id="t" name="title" value="<?= e($p['title']) ?>" required></div>
      <div class="field"><label for="c">Course</label><select class="in" id="c" name="course_id"><?= options($courses, $p['course_id'], 'id', 'n') ?></select></div>
      <div class="f2"><div class="field"><label for="y">Year</label><input class="in" id="y" type="number" name="exam_year" value="<?= (int)$p['exam_year'] ?>"></div><div class="field"><label for="s">Semester</label><select class="in" id="s" name="semester"><option value="1"<?= $p['semester'] == 1 ? ' selected' : '' ?>>1</option><option value="2"<?= $p['semester'] == 2 ? ' selected' : '' ?>>2</option></select></div></div>
      <div class="field"><label for="k">Type</label><select class="in" id="k" name="doc_kind"><?php foreach ($kinds as $k => $l): ?><option value="<?= $k ?>"<?= $p['doc_kind'] === $k ? ' selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
      <label class="chk"><input type="checkbox" name="has_answers" value="1"<?= $p['has_answers'] ? ' checked' : '' ?>>Solved answers are available (Plus)</label><button class="btn" type="submit">Save</button></form>
    </template>
    <?php include ROOT . '/includes/dashboard_footer.php'; exit;
}
$st = $_GET['status'] ?? 'pending'; $q = trim((string)($_GET['q'] ?? '')); $course = (int)($_GET['course'] ?? 0);
$w = [in_school('p')]; $a = []; if (in_array($st, ['pending', 'published', 'rejected', 'unpublished'], true)) { $w[] = 'p.status=?'; $a[] = $st; }
if ($q !== '') { $w[] = '(p.title LIKE ? OR c.code LIKE ?)'; array_push($a, "%$q%", "%$q%"); } if ($course) { $w[] = 'p.course_id=?'; $a[] = $course; }
$W = implode(' AND ', $w); $per = per_page(15); $page = page_no(); $total = (int)val("SELECT COUNT(*) FROM papers p JOIN courses c ON c.id=p.course_id WHERE $W", $a);
$list = rows("SELECT p.id,p.title,p.status,p.exam_year,p.semester,p.views,p.ai_status,p.created_at,c.code,u.full_name,u.role_id FROM papers p JOIN courses c ON c.id=p.course_id JOIN users u ON u.id=p.uploaded_by WHERE $W ORDER BY p.id DESC LIMIT $per OFFSET " . (($page - 1) * $per), $a);
$counts = array_column(rows('SELECT status,COUNT(*) c FROM papers WHERE ' . in_school() . ' GROUP BY status'), 'c', 'status');
include ROOT . '/includes/dashboard_header.php';
echo page_head('Past questions', number_format($total) . ' in this view', modal_btn('tpl-paperup', 'Upload a paper', 'Upload a paper', 'btn', 'upload'));
?>
<div class="tabs" role="navigation" aria-label="Status"><?php foreach (['pending' => 'Pending', 'published' => 'Published', 'rejected' => 'Rejected', 'unpublished' => 'Unpublished', 'all' => 'All'] as $k => $l): ?><a class="tab" href="?status=<?= $k ?>" aria-selected="<?= $st === $k ? 'true' : 'false' ?>"><?= $l ?><?= isset($counts[$k]) ? ' (' . (int)$counts[$k] . ')' : '' ?></a><?php endforeach; ?></div>
<section class="card dt">
  <?= dt_head('Papers', 'Tick papers to publish or reject them together.') ?>
  <form class="toolbar" method="get"><input type="hidden" name="status" value="<?= e($st) ?>"><label class="search"><?= ic('search', 'i') ?><span class="vh">Search</span><input name="q" value="<?= e($q) ?>" type="search" placeholder="Title or course code"></label><select class="in" name="course" aria-label="Course"><?= options($courses, $course, 'id', 'n', 'All courses') ?></select><button class="btn sm">Filter</button></form>
  <?php if ($list): ?><form class="dtbar bulk" id="bulk" method="post" data-action="<?= $act ?>"><?= csrf_field() ?><input type="hidden" name="a" value="bulk"><span class="note" data-bulkcount></span><button class="btn sm" name="status" value="published" data-bulk>Publish selected</button><button class="btn sm danger" name="status" value="rejected" data-bulk data-confirm="Reject the selected papers?">Reject selected</button></form><?php endif; ?>
  <div class="dtwrap"><table class="dtable"><thead><tr><th class="chkc" data-nosort><input type="checkbox" data-checkall="ids[]" aria-label="Select all on this page"></th><th>Paper</th><th>Course</th><th>Year</th><th>Uploaded by</th><th class="num">Views</th><th>AI</th><th>Status</th><th>Added</th><th data-nosort><span class="vh">Actions</span></th></tr></thead><tbody>
  <?php foreach ($list as $p): ?><tr>
    <td class="chkc"><input type="checkbox" name="ids[]" value="<?= $p['id'] ?>" form="bulk" aria-label="Select <?= e($p['title']) ?>"></td>
    <td class="dmain"><a class="dtt" href="<?= e(url('admin/papers.php?id=' . $p['id'])) ?>"><?= e($p['title']) ?></a></td>
    <td class="nw"><span class="code"><?= e($p['code']) ?></span></td><td class="nw"><?= (int)$p['exam_year'] ?> S<?= (int)$p['semester'] ?></td>
    <td><?= e($p['full_name']) ?><?= (int)$p['role_id'] === 2 ? '<span class="ts">Class rep</span>' : '' ?></td><td class="num"><?= (int)$p['views'] ?></td>
    <td><?= status_pill($p['ai_status'] === 'none' ? 'pending' : $p['ai_status']) ?></td><td><?= status_pill($p['status']) ?></td>
    <td class="nw muted" data-v="<?= e($p['created_at']) ?>"><?= e(ago($p['created_at'])) ?></td>
    <td class="act"><div class="rowact"><?php if ($p['status'] === 'pending') echo row_form($act, ['a' => 'status', 'id' => $p['id'], 'status' => 'published'], 'Approve', 'btn sm'); ?><a class="btn sm line" href="<?= e(url('admin/papers.php?id=' . $p['id'])) ?>">Open</a></div></td></tr>
  <?php endforeach; if (!$list) echo dt_empty(10, 'Nothing here.'); ?></tbody></table></div>
  <?= dt_foot($total, $per, $page, ['status' => $st, 'q' => $q, 'course' => $course]) ?>
</section>
<template id="tpl-paperup">
<form class="card mform" id="paperup" data-api="papers.php?a=upload" data-job="1" enctype="multipart/form-data">
  <h2>Upload a paper</h2>
  <div class="field"><label for="pt">Title</label><input class="in" id="pt" name="title" required minlength="3"></div>
  <div class="f2"><div class="field"><label for="pc">Course</label><select class="in" id="pc" name="course_id" required><?= options($courses, null, 'id', 'n', 'Choose') ?></select></div><div class="field"><label for="pk">Type</label><select class="in" id="pk" name="kind"><option value="auto">Detect automatically</option><option value="past_question">Past question</option><option value="lecture_notes">Lecture notes</option></select></div></div>
  <div class="f2"><div class="field"><label for="py">Year</label><input class="in" id="py" name="exam_year" type="number" value="<?= gmdate('Y') ?>"></div><div class="field"><label for="ps">Semester</label><select class="in" id="ps" name="semester"><option>1</option><option>2</option></select></div></div>
  <div class="field"><label for="pf">PDF</label><input class="in" id="pf" name="file" type="file" accept="application/pdf" required style="padding-top:11px"></div>
  <div class="progressbar" hidden><i style="width:0"></i></div><p class="note" data-jobmsg></p><p class="err note" role="alert"></p><button class="btn" type="submit"><?= ic('upload') ?>Upload</button></form>
</template>
<?php include ROOT . '/includes/dashboard_footer.php'; ?>
