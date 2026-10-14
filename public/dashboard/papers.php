<?php
require __DIR__ . '/../../app/bootstrap.php';
$u = require_login(); $plus = is_plus($u); $uid = (int)$u['id'];
$active = 'papers'; $scripts = ['forms.js', 'process.js'];
$id = (int)($_GET['id'] ?? 0);

if ($id) {
    $p = row('SELECT p.*,c.code,c.title ctitle,d.name dept FROM papers p JOIN courses c ON c.id=p.course_id JOIN departments d ON d.id=c.department_id WHERE p.id=? AND ' . in_school('p'), [$id]);
    if (!$p || ($p['status'] !== 'published' && !Auth::can('approve') && (int)$p['uploaded_by'] !== $uid && !(Auth::can('review') && ($sc0 = staff_course_ids()) !== [] && ($sc0 === null || in_array((int)$p['course_id'], $sc0, true))))) { http_response_code(404); $title = 'Not found'; include ROOT . '/includes/dashboard_header.php'; echo page_head('Paper not found') . empty_state('This paper is not available.', '<a class="btn sm" href="' . e(url('dashboard/papers.php')) . '">Back to papers</a>'); include ROOT . '/includes/dashboard_footer.php'; exit; }
    $title = $p['title'];
    $staff = Auth::can('approve');
    $qs = rows("SELECT id,kind,number,question,answer,topic,marks FROM exam_questions WHERE source_type='paper' AND source_id=? AND status='published' ORDER BY kind,id", [$id]);
    $decks = rows("SELECT id,title,card_count,contains_answers FROM decks WHERE paper_id=? AND status='active' ORDER BY id", [$id]);
    $guides = rows("SELECT id,title FROM study_guides WHERE paper_id=? AND status='active'", [$id]);
    $job = row("SELECT id,status FROM processing_jobs WHERE source_type='paper' AND source_id=? ORDER BY id DESC LIMIT 1", [$id]);
    include ROOT . '/includes/dashboard_header.php';
    echo page_head($p['title'], $p['code'] . ' · ' . $p['ctitle'] . ' · ' . $p['exam_year'] . ' · Semester ' . $p['semester'],
        '<a class="btn sm line" href="' . e(url('dashboard/papers.php')) . '">All papers</a>');
    if ($p['status'] !== 'published') echo '<div class="banner">This paper is ' . e($p['status']) . ($p['reject_reason'] ? ': ' . e($p['reject_reason']) : '. It will appear for other students once it is approved.') . '</div>';
    if ($job && (in_array($job['status'], ['queued', 'running'], true) || ($job['status'] === 'failed' && ((int)$p['uploaded_by'] === $uid || $staff)))) echo ai_live('paper', $id);
    ?>
    <div class="grid2">
      <section class="card stack"><div class="hd"><div><h2>The paper</h2><p class="sub"><?= e($p['dept']) ?> &middot; <?= fmt_bytes($p['file_size']) ?> &middot; <?= (int)$p['views'] ?> views</p></div>
        <a class="btn sm line" href="<?= e(url('api/papers.php?a=download&dl=1&id=' . $id)) ?>"><?= ic('download') ?>Download</a></div>
        <iframe class="pdfview" title="<?= e($p['title']) ?>" src="<?= e(url('api/papers.php?a=download&id=' . $id)) ?>"></iframe>
      </section>
      <section class="stack">
        <div class="card"><div class="hd"><div><h2>Questions &amp; answers</h2><p class="sub"><?= count($qs) ?> question<?= count($qs) === 1 ? '' : 's' ?> read from this document</p></div></div>
          <?php if (!$plus && !$staff && $qs): echo lock_note('Solved answers'); endif; ?>
          <?php if (!$qs): echo empty_state('No questions have been extracted yet.'); endif; ?>
          <div class="list" style="margin-top:12px">
          <?php foreach ($qs as $i => $q): ?>
            <details class="rowx" style="--i:<?= $i ?>"><summary><b><?= $q['kind'] === 'predicted' ? 'Likely: ' : '' ?><?= e(($q['number'] ? $q['number'] . '. ' : '') . mb_strimwidth($q['question'], 0, 110, '…')) ?></b></summary>
              <p style="margin-top:8px"><?= nl2br(e($q['question'])) ?></p>
              <?php if ($plus || $staff): ?><div class="banner" style="margin-top:8px;background:var(--bg);color:var(--ink);font-weight:500"><b>Answer</b><br><?= nl2br(e($q['answer'])) ?></div>
              <?php else: ?><p class="note" style="margin-top:8px"><?= ic('lock', 'i') ?> Answer is part of Plus.</p><?php endif; ?></details>
          <?php endforeach; ?></div></div>
        <?php if ($decks || $guides): ?><div class="card"><h2>Study this paper</h2><div class="list" style="margin-top:12px">
          <?php foreach ($decks as $d): ?><a class="rowx" href="<?= e(url('dashboard/decks.php?id=' . $d['id'])) ?>"><h3><?= e($d['title']) ?></h3><span class="col"><?= (int)$d['card_count'] ?> cards<?= $d['contains_answers'] && !$plus ? ' · answers need Plus' : '' ?></span></a><?php endforeach; ?>
          <?php foreach ($guides as $g): ?><a class="rowx" href="<?= e(url('dashboard/guides.php?id=' . $g['id'])) ?>"><h3><?= e($g['title']) ?></h3><span class="col">Study guide<?= $plus ? '' : ' · Plus' ?></span></a><?php endforeach; ?></div></div>        <?php endif; ?>
        <?php if ($qs): ?><a class="btn" href="<?= e(url('dashboard/practice.php?type=paper&id=' . $id)) ?>"><?= ic('docchk') ?>Practice this paper as an exam<?= $plus ? '' : ' <b class="pl">Plus</b>' ?></a><?php endif; ?>
        <button type="button" class="btn sm line" data-sheet="tpl-report" data-title="Report a problem">Report a problem</button>
        <template id="tpl-report">
        <form class="card mform" data-api="reports.php" data-done="Thanks, we will check it.">
          <h2>Report a problem</h2><input type="hidden" name="paper_id" value="<?= $id ?>">
          <div class="field"><label for="why">What is wrong?</label><input class="in" id="why" name="reason" maxlength="255" placeholder="Wrong course, unreadable, wrong answer…" required></div>
          <p class="err note" role="alert"></p><button class="btn sm line" type="submit">Send report</button></form>
        </template>
      </section>
    </div>
    <?php include ROOT . '/includes/dashboard_footer.php'; exit;
}

$title = 'Past questions';
$q = trim((string)($_GET['q'] ?? '')); $course = (int)($_GET['course'] ?? 0); $year = (int)($_GET['year'] ?? 0); $sem = (int)($_GET['sem'] ?? 0); $scope = $_GET['scope'] ?? 'mine';
$w = ["p.status='published'", in_school('p')]; $a = [];
if ($scope === 'mine' && $u['department_id']) { $w[] = 'c.department_id=?'; $a[] = $u['department_id']; }
if ($q !== '') { $w[] = '(p.title LIKE ? OR c.code LIKE ? OR c.title LIKE ?)'; array_push($a, "%$q%", "%$q%", "%$q%"); }
if ($course) { $w[] = 'p.course_id=?'; $a[] = $course; } if ($year) { $w[] = 'p.exam_year=?'; $a[] = $year; } if ($sem) { $w[] = 'p.semester=?'; $a[] = $sem; }
$W = implode(' AND ', $w); $per = per_page(12); $page = page_no();
$total = (int)val("SELECT COUNT(*) FROM papers p JOIN courses c ON c.id=p.course_id WHERE $W", $a);
$list = rows("SELECT p.id,p.title,p.exam_year,p.semester,p.has_answers,p.views,c.code,c.title ctitle FROM papers p JOIN courses c ON c.id=p.course_id WHERE $W ORDER BY p.exam_year DESC,p.id DESC LIMIT $per OFFSET " . (($page - 1) * $per), $a);
$courses = rows('SELECT id,code,title FROM courses WHERE ' . in_school() . ($scope === 'mine' && $u['department_id'] ? ' AND department_id=' . (int)$u['department_id'] : '') . ' ORDER BY code');
foreach ($courses as &$c) $c['label'] = $c['code'] . ' · ' . $c['title']; unset($c);
$years = rows("SELECT DISTINCT exam_year y FROM papers WHERE status='published' AND " . in_school() . " ORDER BY y DESC");
$mine = rows("SELECT p.id,p.title,p.status,p.reject_reason,p.created_at,c.code FROM papers p JOIN courses c ON c.id=p.course_id WHERE p.uploaded_by=? AND p.status<>'published' ORDER BY p.id DESC LIMIT 5", [$uid]);
$seen = array_column(rows('SELECT DISTINCT paper_id FROM paper_views WHERE user_id=?', [$uid]), 'paper_id');
$canUp = Auth::can('upload'); $upCourses = $canUp ? staff_courses($u) : [];
include ROOT . '/includes/dashboard_header.php';
echo page_head('Past questions', $total . ' paper' . ($total === 1 ? '' : 's') . ' found', $canUp ? modal_btn('tpl-paperup', 'Upload a past question', 'Upload a paper', 'btn', 'upload') : '');
?>
<section class="card dt">
  <?= dt_head('Papers', $scope === 'mine' ? 'Your department' : 'All departments') ?>
  <form class="toolbar" method="get">
    <label class="search"><?= ic('search', 'i') ?><span class="vh">Search</span><input name="q" value="<?= e($q) ?>" type="search" placeholder="Search title or course code"></label>
    <select class="in" name="course" aria-label="Course"><?= options($courses, $course, 'id', 'label', 'All courses') ?></select>
    <select class="in" name="year" aria-label="Year"><?= options($years, $year, 'y', 'y', 'Any year') ?></select>
    <select class="in" name="sem" aria-label="Semester"><?= options([['id' => 1, 'n' => 'Semester 1'], ['id' => 2, 'n' => 'Semester 2']], $sem, 'id', 'n', 'Any semester') ?></select>
    <select class="in" name="scope" aria-label="Scope"><option value="mine"<?= $scope === 'mine' ? ' selected' : '' ?>>My department</option><option value="all"<?= $scope === 'all' ? ' selected' : '' ?>>All departments</option></select>
    <button class="btn sm" type="submit">Filter</button>
  </form>
  <div class="dtwrap"><table class="dtable"><thead><tr><th>Course</th><th>Paper</th><th>Year</th><th>Semester</th><th class="num">Views</th><th>Answers</th><th data-nosort><span class="vh">Actions</span></th></tr></thead><tbody>
  <?php foreach ($list as $p): $href = url('dashboard/papers.php?id=' . $p['id']); ?><tr data-href="<?= e($href) ?>">
    <td class="nw"><span class="code"><?= e($p['code']) ?></span></td>
    <td class="dmain"><a class="dtt" href="<?= e($href) ?>"><?= e($p['title']) ?></a><span class="ts"><?= e($p['ctitle']) ?></span></td>
    <td class="nw"><?= (int)$p['exam_year'] ?></td><td class="nw">Semester <?= (int)$p['semester'] ?></td><td class="num"><?= (int)$p['views'] ?></td>
    <td><?= $p['has_answers'] ? '<span class="pill plus">Solved</span>' : '<span class="pill new">Questions</span>' ?></td>
    <td class="act"><a class="btn sm<?= in_array($p['id'], $seen) ? ' line' : '' ?>" href="<?= e($href) ?>"><?= in_array($p['id'], $seen) ? 'Open again' : 'Open' ?></a></td></tr>
  <?php endforeach; if (!$list) echo dt_empty(7, 'No papers match. Try another course or year, or show all departments.'); ?></tbody></table></div>
  <?= dt_foot($total, $per, $page, compact('q', 'course', 'year', 'sem', 'scope')) ?>
</section>
<?php if ($mine): ?><section class="card dt"><?= dt_head('Your uploads', 'Waiting for review or not live') ?>
  <div class="dtwrap"><table class="dtable"><thead><tr><th>Paper</th><th>Course</th><th>Uploaded</th><th>Status</th></tr></thead><tbody>
  <?php foreach ($mine as $m): $href = url('dashboard/papers.php?id=' . $m['id']); ?><tr data-href="<?= e($href) ?>"><td class="dmain"><a class="dtt" href="<?= e($href) ?>"><?= e($m['title']) ?></a><?= $m['reject_reason'] ? '<span class="ts" style="color:var(--bad)">' . e($m['reject_reason']) . '</span>' : '' ?></td><td class="nw"><span class="code"><?= e($m['code']) ?></span></td><td class="nw muted" data-v="<?= e($m['created_at']) ?>"><?= e(ago($m['created_at'])) ?></td><td><?= status_pill($m['status']) ?></td></tr><?php endforeach; ?>
  </tbody></table></div></section><?php endif; ?>
<?php if ($canUp): ?>
<template id="tpl-paperup">
<form class="card mform" id="paperup" data-api="papers.php?a=upload" data-job="1" enctype="multipart/form-data">
  <h2>Upload a past question</h2><p class="sub note">Class reps and lecturers can add papers. Every upload is read by PrepDeck AI and checked before students see it.</p>
  <div class="field"><label for="pt">Title</label><input class="in" id="pt" name="title" required minlength="3" placeholder="CSC 201 End of semester 2025"></div>
  <div class="f2"><div class="field"><label for="pc">Course</label><select class="in" id="pc" name="course_id" required><?= options($upCourses, null, 'id', 'n', 'Choose course') ?></select></div>
  <div class="field"><label for="pk">Document type</label><select class="in" id="pk" name="kind"><option value="auto">Detect automatically</option><option value="past_question">Past question</option><option value="lecture_notes">Lecture notes</option></select></div></div>
  <div class="f2"><div class="field"><label for="py">Exam year</label><input class="in" id="py" name="exam_year" type="number" value="<?= gmdate('Y') ?>" min="1990" max="<?= gmdate('Y') + 1 ?>"></div>
  <div class="field"><label for="ps">Semester</label><select class="in" id="ps" name="semester"><option value="1">1</option><option value="2">2</option></select></div></div>
  <div class="field"><label for="pf">PDF file</label><input class="in" id="pf" name="file" type="file" accept="application/pdf" required style="padding-top:11px"></div>
  <div class="progressbar" hidden><i style="width:0"></i></div><p class="note" data-jobmsg></p><p class="err note" role="alert"></p>
  <button class="btn" type="submit"><?= ic('upload') ?>Upload paper</button>
</form>
</template>
<?php endif; ?>
<?php include ROOT . '/includes/dashboard_footer.php'; ?>
