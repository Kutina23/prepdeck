<?php
require __DIR__ . '/../../app/bootstrap.php';
$u = lecturer_area_user(); $lecturerArea = true; $active = 'overview'; $title = 'Lecturer dashboard'; $scripts = ['process.js'];
$uid = (int)$u['id']; $plus = is_plus($u); $ids = staff_course_ids($u);
$inC = in_school('c') . ' AND ' . staff_course_sql('c.id');
$inP = in_school('p') . ' AND ' . staff_course_sql('p.course_id');
$courses = rows("SELECT c.id,c.code,c.title,d.name dept,
  (SELECT COUNT(*) FROM papers p WHERE p.course_id=c.id AND p.status='published') pub,
  (SELECT COUNT(*) FROM papers p WHERE p.course_id=c.id AND p.status='pending') pend,
  (SELECT COUNT(*) FROM lesson_notes n WHERE n.course_id=c.id AND n.status='published') lessons,
  (SELECT COUNT(*) FROM paper_views v JOIN papers p ON p.id=v.paper_id WHERE p.course_id=c.id AND v.viewed_at>=NOW()-INTERVAL 30 DAY) views30,
  (SELECT COUNT(*) FROM practice_attempts a JOIN papers p ON a.source_type='paper' AND p.id=a.source_id WHERE p.course_id=c.id AND a.created_at>=NOW()-INTERVAL 30 DAY) tries30,
  (SELECT ROUND(100*SUM(a.correct)/NULLIF(SUM(a.total),0)) FROM practice_attempts a JOIN papers p ON a.source_type='paper' AND p.id=a.source_id WHERE p.course_id=c.id) score
  FROM courses c JOIN departments d ON d.id=c.department_id WHERE $inC ORDER BY c.code" . ($ids === null ? ' LIMIT 200' : ''));
$deptIds = array_values(array_unique(array_map('intval', array_column(rows("SELECT DISTINCT c.department_id FROM courses c WHERE $inC"), 'department_id'))));
$students = $deptIds ? (int)val('SELECT COUNT(*) FROM users WHERE role_id=1 AND status=\'active\' AND ' . in_school() . ' AND department_id IN (' . implode(',', $deptIds) . ')') : 0;
$reach = (int)val("SELECT COUNT(DISTINCT v.user_id) FROM paper_views v JOIN papers p ON p.id=v.paper_id WHERE $inP AND v.viewed_at>=NOW()-INTERVAL 30 DAY");
$views = (int)val("SELECT COUNT(*) FROM paper_views v JOIN papers p ON p.id=v.paper_id WHERE $inP AND v.viewed_at>=NOW()-INTERVAL 30 DAY");
$ls = row("SELECT COUNT(*) n, COALESCE(SUM(status='published'),0) pub, COALESCE(SUM(status='draft'),0) dr, COALESCE(SUM(views),0) v FROM lesson_notes WHERE user_id=? AND " . in_school(), [$uid]);
$queue = Auth::can('review') || Auth::can('approve') ? staff_queue_count() : 0;
$exams = rows("SELECT x.exam_date,x.exam_time,x.venue,c.code,c.title FROM exam_timetable x JOIN courses c ON c.id=x.course_id WHERE $inC AND x.exam_date>=CURDATE() ORDER BY x.exam_date,x.exam_time LIMIT 5");
$outlines = rows('SELECT o.id,o.title,o.ai_status,o.created_at,c.code,(SELECT COUNT(*) FROM outline_topics t WHERE t.outline_id=o.id) topics,(SELECT COUNT(*) FROM lesson_notes n WHERE n.outline_id=o.id) notes FROM course_outlines o JOIN courses c ON c.id=o.course_id WHERE o.user_id=? AND ' . in_school('o') . ' ORDER BY o.id DESC LIMIT 5', [$uid]);
$running = val("SELECT j.id FROM processing_jobs j WHERE j.owner_id=? AND j.source_type='outline' AND j.status IN ('queued','running') ORDER BY j.id DESC LIMIT 1", [$uid]);
[$canGen, $why] = Lessons::canGenerate($u); $left = Lessons::left($uid);
$benefit = setting('lecturer_plus', '1') === '1' && (int)$u['role_id'] === 3;
$paidTill = val('SELECT plus_expires FROM users WHERE id=? AND plus=1', [$uid]);
$per = per_page(10); $page = page_no(); [$cList, $cTotal] = page_slice($courses, $per, $page);
include ROOT . '/includes/dashboard_header.php';
echo page_head('Hello ' . first_name($u['full_name']), 'Plan your lessons, share material and see how your students are doing.', $canGen && $ids !== [] ? '<a class="btn" href="' . e(url('lecturer/outlines.php')) . '">' . ic('spark') . 'Outline to lesson notes</a>' : '');
if ($ids === []) echo '<div class="banner warn">You have no courses yet, so you cannot upload or generate notes. <a href="' . e(url('staff/courses.php')) . '">Pick the courses you teach</a>.</div>';
if ($queue) echo '<a class="banner" style="display:block;color:inherit;text-decoration:none" href="' . e(url('staff/review.php')) . '"><b>' . $queue . ' paper' . ($queue === 1 ? '' : 's') . ' waiting for your review</b> &rsaquo;</a>';
if ((int)$ls['dr']) echo '<a class="banner" style="display:block;color:inherit;text-decoration:none" href="' . e(url('lecturer/lessons.php?status=draft')) . '"><b>' . (int)$ls['dr'] . ' lesson note' . ((int)$ls['dr'] === 1 ? '' : 's') . ' waiting for you to check and publish</b> &rsaquo;</a>';
if ($running) echo '<div data-live-job="' . (int)$running . '"></div>';
?>
<section class="kpis">
  <div class="card kpi"><span class="ic" style="background:var(--lemon)"><?= ic('cap') ?></span><div><b><?= $ids === null ? 'All' : count($ids) ?></b><small>Courses you teach</small></div></div>
  <div class="card kpi"><span class="ic" style="background:var(--pop)"><?= ic('users') ?></span><div><b><?= number_format($reach) ?></b><small>Students studied your courses (30 days) of <?= number_format($students) ?> in your departments</small></div></div>
  <div class="card kpi"><span class="ic" style="background:var(--brand);color:var(--brand-ink)"><?= ic('lesson') ?></span><div><b><?= (int)$ls['pub'] ?></b><small>Lesson notes published (<?= number_format((int)$ls['v']) ?> reads)</small></div></div>
  <div class="card kpi"><span class="ic" style="background:var(--lemon)"><?= ic('eye') ?></span><div><b><?= number_format($views) ?></b><small>Paper views (30 days)</small></div></div>
</section>
<section class="card"><div class="hd"><div><h2>Your plan</h2><p class="sub"><?php
  if ($benefit) echo 'PrepDeck Plus is included with your lecturer account.';
  elseif ($plus) echo 'PrepDeck Plus' . ($paidTill ? ' until ' . e(fdate($paidTill)) : '') . '.';
  else echo 'Free. Course outline to lesson notes needs PrepDeck Plus.'; ?></p></div><?= $plus ? '<span class="pill plus">Plus</span>' : '<a class="btn sm" href="' . e(url('dashboard/plan.php')) . '">Get Plus</a>' ?></div>
  <?php if ($plus && Lessons::enabled()): $q = Lessons::quota(); $used = Lessons::usedThisMonth($uid); $pct = $q ? min(100, round(100 * $used / $q)) : 0; ?>
  <p class="note" style="margin-top:10px"><?= $q ? number_format($used) . ' of ' . number_format($q) . ' AI lesson notes used this month' : number_format($used) . ' AI lesson notes this month · no limit' ?></p>
  <?php if ($q): ?><div class="meter<?= $left === 0 ? ' full' : '' ?>" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= $pct ?>" aria-label="Monthly allowance used"><i style="width:<?= $pct ?>%"></i></div><?php endif; endif; ?>
</section>
<?= community_card($u) ?>
<section class="card"><h2>Teaching tools</h2>
  <div class="ltiles">
    <?php foreach ([
      ['lecturer/outlines.php', 'book', 'var(--pop)', 'Course outline to notes', 'Upload a syllabus, get a lesson note per topic', true],
      ['lecturer/lessons.php', 'lesson', 'var(--lemon)', 'Lesson notes', 'Edit, publish and download your notes', false],
      ['lecturer/insights.php', 'chart', 'var(--brand);color:var(--brand-ink)', 'Student insights', 'Views, practice scores, weak papers', false],
      Auth::can('upload') ? ['staff/papers.php', 'upload', 'var(--lemon)', 'Upload past papers', 'PrepDeck AI writes model answers', false] : null,
      Auth::can('upload') ? ['dashboard/recordings.php', 'mic', 'var(--pop)', 'Record a lecture', 'Transcript, guide and questions', false] : null,
      Auth::can('review') || Auth::can('approve') ? ['staff/review.php', 'docchk', 'var(--lemon)', 'Review queue', $queue ? $queue . ' waiting' : 'Nothing waiting', false] : null,
      Auth::can('announce') ? ['staff/announcements.php', 'mega', 'var(--surface);color:var(--ink)', 'Announcements', 'Message your department', false] : null,
    ] as $t): if (!$t) continue; [$href, $icon, $bg, $label, $sub, $lock] = $t; ?>
    <a class="ltile" href="<?= e(url($href)) ?>"><span class="ic" style="background:<?= $bg ?>"><?= ic($icon) ?></span><span><b><?= e($label) ?></b><small><?= e($sub) ?></small></span><?= $lock && !$plus ? ic('lock', 'i lk') : '' ?></a>
    <?php endforeach; ?>
  </div></section>
<section class="card dt"><?= dt_head('Your courses', number_format($cTotal) . ' course' . ($cTotal === 1 ? '' : 's'), '<a class="btn sm line" href="' . e(url('staff/courses.php')) . '">Manage courses</a> <a class="btn sm line" href="' . e(url('lecturer/insights.php')) . '">Insights</a>') ?>
  <div class="dtwrap"><table class="dtable"><thead><tr><th>Code</th><th>Course</th><th class="num">Papers</th><th class="num">Pending</th><th class="num">Lesson notes</th><th class="num">Views 30d</th><th class="num">Practice 30d</th><th class="num">Avg score</th></tr></thead><tbody>
  <?php foreach ($cList as $c): $href = url('lecturer/insights.php?course=' . $c['id']); ?><tr data-href="<?= e($href) ?>"><td class="nw"><span class="code"><?= e($c['code']) ?></span></td><td class="dmain"><a class="dtt" href="<?= e($href) ?>"><?= e($c['title']) ?></a><span class="ts"><?= e($c['dept']) ?></span></td>
    <td class="num"><?= (int)$c['pub'] ?></td><td class="num"><?= (int)$c['pend'] ?></td><td class="num"><?= (int)$c['lessons'] ?></td><td class="num"><?= number_format((int)$c['views30']) ?></td><td class="num"><?= number_format((int)$c['tries30']) ?></td>
    <td class="num"><?= $c['score'] === null ? '<span class="muted">–</span>' : '<span class="pill ' . ($c['score'] >= 60 ? 'ok' : ($c['score'] >= 40 ? 'wait' : 'bad')) . '">' . (int)$c['score'] . '%</span>' ?></td></tr>
  <?php endforeach; if (!$cList) echo dt_empty(8, 'No courses assigned.'); ?></tbody></table></div>
  <?= dt_foot($cTotal, $per, $page) ?></section>
<div class="two">
  <section class="card dt"><?= dt_head('Recent course outlines', 'Last 5', '<a class="btn sm line" href="' . e(url('lecturer/outlines.php')) . '">All outlines</a>') ?>
    <div class="dtwrap"><table class="dtable"><thead><tr><th>Outline</th><th class="num">Topics</th><th class="num">Notes</th><th>AI</th></tr></thead><tbody>
    <?php foreach ($outlines as $o): $href = url('lecturer/outlines.php?id=' . $o['id']); ?><tr data-href="<?= e($href) ?>"><td class="dmain"><a class="dtt" href="<?= e($href) ?>"><?= e($o['title']) ?></a><span class="ts"><?= e($o['code']) ?> · <?= e(ago($o['created_at'])) ?></span></td><td class="num"><?= (int)$o['topics'] ?></td><td class="num"><?= (int)$o['notes'] ?></td><td><?= status_pill($o['ai_status'] === 'none' ? 'queued' : $o['ai_status']) ?></td></tr>
    <?php endforeach; if (!$outlines) echo dt_empty(4, $plus ? 'Upload your first course outline.' : 'Course outlines are part of Plus.'); ?></tbody></table></div></section>
  <section class="card dt"><?= dt_head('Upcoming exams', 'Your courses') ?>
    <div class="dtwrap"><table class="dtable"><thead><tr><th>Course</th><th>Date</th><th>Venue</th></tr></thead><tbody>
    <?php foreach ($exams as $x): ?><tr><td class="dmain"><span class="code"><?= e($x['code']) ?></span><span class="ts"><?= e($x['title']) ?></span></td><td class="nw"><?= e(fdate($x['exam_date'], 'D j M')) ?><?= $x['exam_time'] ? ' · ' . e(substr($x['exam_time'], 0, 5)) : '' ?></td><td class="muted"><?= e($x['venue'] ?: '–') ?></td></tr>
    <?php endforeach; if (!$exams) echo dt_empty(3, 'No exams scheduled for your courses.'); ?></tbody></table></div></section>
</div>
<?php include ROOT . '/includes/dashboard_footer.php'; ?>
