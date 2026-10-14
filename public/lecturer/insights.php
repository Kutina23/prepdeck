<?php
require __DIR__ . '/../../app/bootstrap.php';
$u = lecturer_area_user(); $lecturerArea = true; $active = 'insights'; $title = 'Student insights'; $scripts = [];
$ids = staff_course_ids($u);
$myCourses = staff_courses($u);
$cf = (int)($_GET['course'] ?? 0); if ($cf && !Lessons::courseOk($cf, $ids)) $cf = 0;
$scope = in_school('p') . ' AND ' . ($cf ? 'p.course_id=' . $cf : staff_course_sql('p.course_id'));
$days = []; for ($i = 29; $i >= 0; $i--) $days[gmdate('Y-m-d', time() - $i * 86400)] = 0;
foreach (rows("SELECT DATE(v.viewed_at) d, COUNT(*) c FROM paper_views v JOIN papers p ON p.id=v.paper_id WHERE $scope AND v.viewed_at>=CURDATE()-INTERVAL 29 DAY GROUP BY d") as $r) $days[$r['d']] = (int)$r['c'];
$mx = max(3, max($days));
$k = row("SELECT (SELECT COUNT(*) FROM paper_views v JOIN papers p ON p.id=v.paper_id WHERE $scope AND v.viewed_at>=NOW()-INTERVAL 30 DAY) views,
  (SELECT COUNT(DISTINCT v.user_id) FROM paper_views v JOIN papers p ON p.id=v.paper_id WHERE $scope AND v.viewed_at>=NOW()-INTERVAL 30 DAY) students,
  (SELECT COUNT(*) FROM practice_attempts a JOIN papers p ON a.source_type='paper' AND p.id=a.source_id WHERE $scope AND a.created_at>=NOW()-INTERVAL 30 DAY) tries,
  (SELECT ROUND(100*SUM(a.correct)/NULLIF(SUM(a.total),0)) FROM practice_attempts a JOIN papers p ON a.source_type='paper' AND p.id=a.source_id WHERE $scope AND a.created_at>=NOW()-INTERVAL 30 DAY) score");
$papers = rows("SELECT p.id,p.title,p.exam_year,p.semester,p.views,c.code,
  (SELECT COUNT(DISTINCT v.user_id) FROM paper_views v WHERE v.paper_id=p.id) readers,
  (SELECT COUNT(*) FROM practice_attempts a WHERE a.source_type='paper' AND a.source_id=p.id) tries,
  (SELECT ROUND(100*SUM(a.correct)/NULLIF(SUM(a.total),0)) FROM practice_attempts a WHERE a.source_type='paper' AND a.source_id=p.id) score
  FROM papers p JOIN courses c ON c.id=p.course_id WHERE $scope AND p.status='published' ORDER BY p.views DESC LIMIT 300");
$weak = array_values(array_filter($papers, fn($p) => $p['tries'] >= 3 && $p['score'] !== null && $p['score'] < 50)); usort($weak, fn($a, $b) => $a['score'] <=> $b['score']);
$lscope = str_replace(['p.course_id', 'p.school_id'], ['n.course_id', 'n.school_id'], $scope);
$lessons = rows("SELECT n.id,n.title,n.views,n.user_id,c.code FROM lesson_notes n JOIN courses c ON c.id=n.course_id WHERE $lscope AND n.status='published' ORDER BY n.views DESC LIMIT 8");
$per = per_page(15); $page = page_no(); [$pList, $pTotal] = page_slice($papers, $per, $page);
include ROOT . '/includes/dashboard_header.php';
echo page_head('Student insights', 'How students use the material for your courses over the last 30 days.');
?>
<form class="toolbar card" method="get" style="padding:12px"><label for="cf" class="vh">Course</label><select class="in" id="cf" name="course" data-autosubmit><?= options($myCourses, $cf ?: null, 'id', 'n', 'All my courses') ?></select><button class="btn sm">Show</button></form>
<section class="kpis">
  <div class="card kpi"><b><?= number_format((int)$k['views']) ?></b><small>Paper views</small></div>
  <div class="card kpi"><b><?= number_format((int)$k['students']) ?></b><small>Students who studied</small></div>
  <div class="card kpi"><b><?= number_format((int)$k['tries']) ?></b><small>Practice exams taken</small></div>
  <div class="card kpi"><b><?= $k['score'] === null ? '–' : (int)$k['score'] . '%' ?></b><small>Average practice score</small></div>
</section>
<section class="card"><div class="hd"><div><h2>Daily paper views</h2><p class="sub">Last 30 days</p></div></div>
  <svg class="chart" viewBox="0 0 320 120" role="img" aria-label="Paper views per day, last 30 days"><?php $i = 0; foreach ($days as $d => $c): $h = $c ? max(4, round(80 * $c / $mx)) : 2; ?><rect class="bar<?= $d === gmdate('Y-m-d') ? ' today' : '' ?>" style="--i:<?= $i ?>" x="<?= 2 + $i * 10.6 ?>" y="<?= 90 - $h ?>" width="7.5" height="<?= $h ?>" rx="2"><title><?= e($d) ?>: <?= $c ?></title></rect><?php $i++; endforeach; ?><line class="gl" x1="0" y1="91" x2="320" y2="91"/><text x="2" y="110"><?= e(gmdate('j M', strtotime(array_key_first($days)))) ?></text><text x="318" y="110" text-anchor="end">Today</text></svg></section>
<div class="two">
  <section class="card dt"><?= dt_head('Where students struggle', 'Papers with an average practice score under 50% (3+ attempts)') ?>
    <div class="dtwrap"><table class="dtable"><thead><tr><th>Paper</th><th class="num">Attempts</th><th class="num">Avg score</th></tr></thead><tbody>
    <?php foreach (array_slice($weak, 0, 8) as $p): ?><tr><td class="dmain"><a class="dtt" href="<?= e(url('dashboard/papers.php?id=' . $p['id'])) ?>"><?= e($p['title']) ?></a><span class="ts"><?= e($p['code']) ?></span></td><td class="num"><?= (int)$p['tries'] ?></td><td class="num"><span class="pill bad"><?= (int)$p['score'] ?>%</span></td></tr>
    <?php endforeach; if (!$weak) echo dt_empty(3, 'Nothing flagged. Students score 50% or more where they practise.'); ?></tbody></table></div>
    <p class="note" style="margin-top:8px">Tip: write or publish a lesson note on these topics, or post an announcement before the exam.</p></section>
  <section class="card dt"><?= dt_head('Most read lesson notes', 'Published notes in these courses') ?>
    <div class="dtwrap"><table class="dtable"><thead><tr><th>Lesson</th><th class="num">Reads</th></tr></thead><tbody>
    <?php foreach ($lessons as $l): $href = (int)$l['user_id'] === (int)$u['id'] ? url('lecturer/lessons.php?id=' . $l['id']) : url('dashboard/lessons.php?id=' . $l['id']); ?><tr data-href="<?= e($href) ?>"><td class="dmain"><a class="dtt" href="<?= e($href) ?>"><?= e($l['title']) ?></a><span class="ts"><?= e($l['code']) ?></span></td><td class="num"><?= number_format((int)$l['views']) ?></td></tr>
    <?php endforeach; if (!$lessons) echo dt_empty(2, 'No published lesson notes yet.'); ?></tbody></table></div></section>
</div>
<section class="card dt"><?= dt_head('Papers', number_format($pTotal) . ' published') ?>
  <div class="dtwrap"><table class="dtable"><thead><tr><th>Paper</th><th>Course</th><th>Year</th><th class="num">Views</th><th class="num">Students</th><th class="num">Practice</th><th class="num">Avg score</th></tr></thead><tbody>
  <?php foreach ($pList as $p): $href = url('dashboard/papers.php?id=' . $p['id']); ?><tr data-href="<?= e($href) ?>"><td class="dmain"><a class="dtt" href="<?= e($href) ?>"><?= e($p['title']) ?></a></td><td class="nw"><span class="code"><?= e($p['code']) ?></span></td><td class="nw"><?= (int)$p['exam_year'] ?> S<?= (int)$p['semester'] ?></td>
    <td class="num"><?= number_format((int)$p['views']) ?></td><td class="num"><?= number_format((int)$p['readers']) ?></td><td class="num"><?= number_format((int)$p['tries']) ?></td>
    <td class="num"><?= $p['score'] === null ? '<span class="muted">–</span>' : '<span class="pill ' . ($p['score'] >= 60 ? 'ok' : ($p['score'] >= 40 ? 'wait' : 'bad')) . '">' . (int)$p['score'] . '%</span>' ?></td></tr>
  <?php endforeach; if (!$pList) echo dt_empty(7, 'No published papers in these courses yet.'); ?></tbody></table></div>
  <?= dt_foot($pTotal, $per, $page, ['course' => $cf ?: '']) ?></section>
<?php include ROOT . '/includes/dashboard_footer.php'; ?>
