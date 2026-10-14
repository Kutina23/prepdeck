<?php
require __DIR__ . '/../../app/bootstrap.php';
$u = require_perm('upload'); $staffArea = true; $active = 'papers'; $title = 'Upload papers'; $scripts = ['forms.js', 'process.js'];
$uid = (int)$u['id'];
if (is_post()) {
    form_guard(); $id = (int)($_POST['id'] ?? 0);
    if (($_POST['a'] ?? '') === 'withdraw') {      // you can withdraw your own upload while it is still waiting
        $p = row("SELECT file_path FROM papers WHERE id=? AND uploaded_by=? AND status IN ('pending','rejected')", [$id, $uid]);
        if ($p) { foreach (['DELETE FROM exam_questions WHERE source_type="paper" AND source_id=?', 'DELETE FROM decks WHERE paper_id=?', 'DELETE FROM study_guides WHERE paper_id=?', 'DELETE FROM processing_jobs WHERE source_type="paper" AND source_id=?', 'DELETE FROM papers WHERE id=?'] as $s) q($s, [$id]);
            @unlink(upload_path('papers', $p['file_path'])); Audit::log("withdrew paper $id", 'paper', $id); flash('ok', 'Upload withdrawn.'); } else flash('bad', 'Only pending or rejected uploads can be withdrawn.');
    }
    redirect_self_or_ajax();
}
$courses = staff_courses($u);
$q = trim((string)($_GET['q'] ?? '')); $st = $_GET['status'] ?? ''; $per = per_page(15); $page = page_no();
$w = ['p.uploaded_by=?', in_school('p')]; $a = [$uid]; if (in_array($st, ['pending', 'published', 'rejected', 'unpublished'], true)) { $w[] = 'p.status=?'; $a[] = $st; } if ($q !== '') { $w[] = '(p.title LIKE ? OR c.code LIKE ?)'; array_push($a, "%$q%", "%$q%"); }
$W = implode(' AND ', $w); $total = (int)val("SELECT COUNT(*) FROM papers p JOIN courses c ON c.id=p.course_id WHERE $W", $a);
$list = rows("SELECT p.id,p.title,p.status,p.exam_year,p.semester,p.views,p.reject_reason,p.ai_status,p.created_at,c.code FROM papers p JOIN courses c ON c.id=p.course_id WHERE $W ORDER BY p.id DESC LIMIT $per OFFSET " . (($page - 1) * $per), $a);
$isRep = (int)$u['role_id'] === 2;
$auto = Auth::can('approve') || (!$isRep && setting('lecturer_autopublish', '1') === '1') || ($isRep && setting('rep_autopublish', '0') === '1');
include ROOT . '/includes/dashboard_header.php';
echo page_head('Upload papers', $auto ? 'Uploads go live straight away.' : 'Uploads are reviewed before students see them.', $courses ? modal_btn('tpl-paperup', 'Upload a paper', 'Upload a paper', 'btn', 'upload') : '');
if (!$courses) echo '<div class="banner warn">No courses are assigned to your account yet. ' . (picks_own_courses($u) ? '<a href="' . e(url('staff/courses.php')) . '">Pick your courses</a>.' : 'Ask an admin to assign them.') . '</div>'; else { ?>
<template id="tpl-paperup">
<form class="card mform" id="paperup" data-api="papers.php?a=upload" data-job="1" enctype="multipart/form-data">
  <h2>New upload</h2>
  <div class="field"><label for="pt">Title</label><input class="in" id="pt" name="title" required minlength="3" placeholder="CSC 201 End of semester 2025"></div>
  <div class="f2"><div class="field"><label for="pc">Course</label><select class="in" id="pc" name="course_id" required><?= options($courses, count($courses) === 1 ? $courses[0]['id'] : null, 'id', 'n', 'Choose course') ?></select></div>
  <div class="field"><label for="pk">Document type</label><select class="in" id="pk" name="kind"><option value="auto">Detect automatically</option><option value="past_question">Past question</option><option value="lecture_notes">Lecture notes</option></select></div></div>
  <div class="f2"><div class="field"><label for="py">Exam year</label><input class="in" id="py" name="exam_year" type="number" value="<?= gmdate('Y') ?>"></div><div class="field"><label for="ps">Semester</label><select class="in" id="ps" name="semester"><option>1</option><option>2</option></select></div></div>
  <div class="field"><label for="pf">PDF file</label><input class="in" id="pf" name="file" type="file" accept="application/pdf" required style="padding-top:11px"></div>
  <div class="progressbar" hidden><i style="width:0"></i></div><p class="note" data-jobmsg></p><p class="err note" role="alert"></p>
  <button class="btn" type="submit"><?= ic('upload') ?>Upload</button></form>
</template>
<?php } ?>
<section class="card dt">
  <?= dt_head('Your uploads', number_format($total) . ' in this view') ?>
  <form class="toolbar" method="get"><label class="search"><?= ic('search', 'i') ?><span class="vh">Search</span><input name="q" value="<?= e($q) ?>" type="search" placeholder="Title or course code"></label>
    <select class="in" name="status" aria-label="Status"><option value="">Any status</option><?php foreach (['pending' => 'Pending', 'published' => 'Published', 'rejected' => 'Rejected', 'unpublished' => 'Unpublished'] as $k => $l): ?><option value="<?= $k ?>"<?= $st === $k ? ' selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select><button class="btn sm">Filter</button></form>
  <div class="dtwrap"><table class="dtable"><thead><tr><th>Paper</th><th>Course</th><th>Year</th><th class="num">Views</th><th>AI</th><th>Status</th><th>Uploaded</th><th data-nosort><span class="vh">Actions</span></th></tr></thead><tbody>
  <?php foreach ($list as $p): $href = url('dashboard/papers.php?id=' . $p['id']); ?><tr>
    <td class="dmain"><a class="dtt" href="<?= e($href) ?>"><?= e($p['title']) ?></a><?= $p['reject_reason'] ? '<span class="ts" style="color:var(--bad)">Rejected: ' . e($p['reject_reason']) . '</span>' : '' ?></td>
    <td class="nw"><span class="code"><?= e($p['code']) ?></span></td><td class="nw"><?= (int)$p['exam_year'] ?> S<?= (int)$p['semester'] ?></td><td class="num"><?= (int)$p['views'] ?></td>
    <td><?= status_pill($p['ai_status'] === 'none' ? 'pending' : $p['ai_status']) ?></td><td><?= status_pill($p['status']) ?></td><td class="nw muted" data-v="<?= e($p['created_at']) ?>"><?= e(ago($p['created_at'])) ?></td>
    <td class="act"><div class="rowact"><a class="btn sm line" href="<?= e($href) ?>">Open</a>
      <?php if (in_array($p['status'], ['pending', 'rejected'], true)) echo row_form('staff/papers.php', ['a' => 'withdraw', 'id' => $p['id']], 'Withdraw', 'btn sm danger', 'Withdraw this upload? The file and anything made from it are deleted.'); ?></div></td></tr>
  <?php endforeach; if (!$list) echo dt_empty(8, 'Nothing uploaded yet.'); ?></tbody></table></div>
  <?= dt_foot($total, $per, $page, ['q' => $q, 'status' => $st]) ?>
</section>
<?php include ROOT . '/includes/dashboard_footer.php'; ?>
