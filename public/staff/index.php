<?php
require __DIR__ . '/../../app/bootstrap.php';
$u = staff_area_user(); if (is_lecturer($u)) redirect('lecturer/');   // lecturers have their own dashboard
$staffArea = true; $active = 'overview'; $title = 'My desk'; $scripts = [];
$uid = (int)$u['id']; $ids = staff_course_ids(); $isRep = (int)$u['role_id'] === 2;
$in = $ids === null ? '' : ($ids ? 'IN (' . implode(',', $ids) . ')' : 'IN (0)');
$courses = rows('SELECT c.id,c.code,c.title,d.name dept,(SELECT COUNT(*) FROM papers p WHERE p.course_id=c.id AND p.status="published") pub,(SELECT COUNT(*) FROM papers p WHERE p.course_id=c.id AND p.status="pending") pend,(SELECT COALESCE(SUM(p.views),0) FROM papers p WHERE p.course_id=c.id) views FROM courses c JOIN departments d ON d.id=c.department_id WHERE ' . in_school('c') . ($in ? " AND c.id $in" : '') . ' ORDER BY c.code');
$mine = row("SELECT COUNT(*) n, SUM(status='published') pub, SUM(status='pending') pend, SUM(status='rejected') rej, COALESCE(SUM(views),0) views FROM papers WHERE uploaded_by=? AND " . in_school(), [$uid]);
$recent = rows('SELECT p.id,p.title,p.status,p.created_at,p.reject_reason,c.code FROM papers p JOIN courses c ON c.id=p.course_id WHERE p.uploaded_by=? AND ' . in_school('p') . ' ORDER BY p.id DESC LIMIT 6', [$uid]);
$queue = Auth::can('review') || Auth::can('approve') ? staff_queue_count() : 0;
$auto = Auth::can('approve') || (!$isRep && setting('lecturer_autopublish', '1') === '1') || ($isRep && setting('rep_autopublish', '0') === '1');
$per = per_page(10); $page = page_no(); [$cList, $cTotal] = page_slice($courses, $per, $page);
$upBtn = (picks_own_courses($u) ? '<a class="btn sm line" href="' . e(url('staff/courses.php')) . '">Manage courses</a> ' : '') . (Auth::can('upload') ? '<a class="btn sm" href="' . e(url('staff/papers.php')) . '">' . ic('upload') . 'Upload</a>' : '');
include ROOT . '/includes/dashboard_header.php';
echo page_head(($isRep ? 'Class rep desk' : 'Staff desk'), 'Hello ' . first_name($u['full_name']) . '. ' . ($isRep ? 'Upload past questions for your class so everyone can study them.' : 'Share past questions, review uploads and check what PrepDeck AI wrote for your courses.'));
if (!$courses && $ids !== null) echo '<div class="banner warn">No courses are assigned to your account yet, so you cannot upload. ' . (picks_own_courses($u) ? '<a href="' . e(url('staff/courses.php')) . '">Pick your courses</a>.' : 'Ask an admin to assign your courses.') . '</div>';
?>
<section class="kpis">
  <div class="card kpi"><span class="ic" style="background:var(--lemon)"><?= ic('file') ?></span><div><b><?= (int)$mine['n'] ?></b><small>Papers you uploaded</small></div></div>
  <div class="card kpi"><span class="ic" style="background:var(--brand);color:var(--brand-ink)"><?= ic('check') ?></span><div><b><?= (int)$mine['pub'] ?></b><small>Live for students</small></div></div>
  <div class="card kpi"><span class="ic" style="background:var(--pop)"><?= ic('clock') ?></span><div><b><?= (int)$mine['pend'] ?></b><small>Waiting for review</small></div></div>
  <div class="card kpi"><span class="ic" style="background:var(--lemon)"><?= ic('eye') ?></span><div><b><?= number_format((int)$mine['views']) ?></b><small>Views on your papers</small></div></div>
</section>
<section class="card"><h2>Your privileges</h2><ul class="stack" style="padding-left:18px;margin:10px 0 0">
  <li>Upload past questions and notes to <?= $ids === null ? 'every course' : count($ids) . ' assigned course' . (count($ids) === 1 ? '' : 's') ?>.</li>
  <li><?= $auto ? 'Your uploads go live straight away.' : 'Your uploads are checked by a reviewer before students see them.' ?></li>
  <?php if (Auth::can('review') || Auth::can('approve')): ?><li>Review papers and AI-written answers for <?= Auth::can('approve') ? 'all courses' : 'your courses' ?>.</li><?php endif; ?>
  <?php if (Auth::can('announce')): ?><li>Post announcements to your department.</li><?php endif; ?>
  <?php if (picks_own_courses($u)): ?><li>Pick the courses you work on yourself (desk &rsaquo; My courses).</li><?php endif; ?>
  <?php if (Auth::can('courses')): ?><li>Add courses to <?= e(school_name()) ?> (desk &rsaquo; <?= picks_own_courses($u) ? 'My courses' : 'Courses' ?>).</li><?php endif; ?>
  <?php if (is_plus($u)): ?><li>PrepDeck Plus features are included with your role.</li><?php endif; ?></ul></section>
<?php if ($queue): ?><a class="banner" style="display:block;color:inherit;text-decoration:none" href="<?= e(url('staff/review.php')) ?>"><b><?= $queue ?> paper<?= $queue === 1 ? '' : 's' ?> waiting for review</b> &rsaquo;</a><?php endif; ?>
<section class="card dt"><?= dt_head('Your courses', number_format($cTotal) . ' course' . ($cTotal === 1 ? '' : 's'), $upBtn) ?>
  <div class="dtwrap"><table class="dtable"><thead><tr><th>Code</th><th>Course</th><th>Department</th><th class="num">Live</th><th class="num">Pending</th><th class="num">Views</th></tr></thead><tbody>
  <?php foreach ($cList as $c): ?><tr><td class="nw"><span class="code"><?= e($c['code']) ?></span></td><td class="dmain"><span class="dtt"><?= e($c['title']) ?></span></td><td class="muted"><?= e($c['dept']) ?></td><td class="num"><?= (int)$c['pub'] ?></td><td class="num"><?= (int)$c['pend'] ?></td><td class="num"><?= number_format($c['views']) ?></td></tr>
  <?php endforeach; if (!$cList) echo dt_empty(6, 'No courses assigned.'); ?></tbody></table></div>
  <?= dt_foot($cTotal, $per, $page) ?></section>
<section class="card dt"><?= dt_head('Your recent uploads', 'Last 6', '<a class="btn sm line" href="' . e(url('staff/papers.php')) . '">All uploads</a>') ?>
  <div class="dtwrap"><table class="dtable"><thead><tr><th>Paper</th><th>Course</th><th>Uploaded</th><th>Status</th></tr></thead><tbody>
  <?php foreach ($recent as $p): $href = url('dashboard/papers.php?id=' . $p['id']); ?><tr data-href="<?= e($href) ?>"><td class="dmain"><a class="dtt" href="<?= e($href) ?>"><?= e($p['title']) ?></a><?= $p['reject_reason'] ? '<span class="ts" style="color:var(--bad)">' . e($p['reject_reason']) . '</span>' : '' ?></td><td class="nw"><span class="code"><?= e($p['code']) ?></span></td><td class="nw muted" data-v="<?= e($p['created_at']) ?>"><?= e(ago($p['created_at'])) ?></td><td><?= status_pill($p['status']) ?></td></tr>
  <?php endforeach; if (!$recent) echo dt_empty(4, 'Nothing uploaded yet.'); ?></tbody></table></div></section>
<?php include ROOT . '/includes/dashboard_footer.php'; ?>
