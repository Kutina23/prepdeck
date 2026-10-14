<?php
require __DIR__ . '/../../app/bootstrap.php';
$u = require_login(); $plus = is_plus($u); $uid = (int)$u['id'];
$active = 'lessons'; $title = 'Lesson notes'; $scripts = [];
$id = (int)($_GET['id'] ?? 0);
include ROOT . '/includes/dashboard_header.php';
if (!Lessons::studentsCanRead($u) && !Auth::can('admin')) { echo page_head('Lesson notes', 'Notes your lecturers prepared for each topic.') . '<section class="card">' . lock_note('Reading lesson notes') . '</section>'; include ROOT . '/includes/dashboard_footer.php'; exit; }
if ($id) {
    $n = row('SELECT n.*,c.code,c.title course,u.full_name author FROM lesson_notes n JOIN courses c ON c.id=n.course_id JOIN users u ON u.id=n.user_id WHERE n.id=?', [$id]);
    $ok = $n && (int)$n['school_id'] === sid() && ((int)$n['user_id'] === $uid || $n['status'] === 'published' || Auth::can('admin'));
    if (!$ok) { echo page_head('Not found') . empty_state('That lesson note is not available.', '<a class="btn sm" href="' . e(url('dashboard/lessons.php')) . '">All lesson notes</a>'); include ROOT . '/includes/dashboard_footer.php'; exit; }
    if ((int)$n['user_id'] !== $uid && $n['status'] === 'published') q('UPDATE lesson_notes SET views=views+1 WHERE id=?', [$id]);
    echo page_head($n['title'], $n['code'] . ' · ' . $n['course'] . ($n['week'] ? ' · ' . $n['week'] : '') . ' · by ' . $n['author'], '<a class="btn sm line" href="' . e(url('dashboard/lessons.php?course=' . $n['course_id'])) . '">All ' . e($n['code']) . ' notes</a>');
    echo '<article class="card guide">' . guide_html($n['body']) . '</article>';
    include ROOT . '/includes/dashboard_footer.php'; exit;
}
$dep = (int)$u['department_id']; $all = isset($_GET['all']) || !$dep;
$cf = (int)($_GET['course'] ?? 0); $q = trim((string)($_GET['q'] ?? '')); $per = per_page(15); $page = page_no();
$w = ["n.status='published'", in_school('n')]; $a = [];
if ($cf) { $w[] = 'n.course_id=?'; $a[] = $cf; } elseif (!$all) { $w[] = 'c.department_id=?'; $a[] = $dep; }
if ($q !== '') { $w[] = '(n.title LIKE ? OR c.code LIKE ? OR c.title LIKE ?)'; array_push($a, "%$q%", "%$q%", "%$q%"); }
$W = implode(' AND ', $w);
$total = (int)val("SELECT COUNT(*) FROM lesson_notes n JOIN courses c ON c.id=n.course_id WHERE $W", $a);
$list = rows("SELECT n.id,n.title,n.week,n.word_count,n.published_at,n.updated_at,c.code,u.full_name author FROM lesson_notes n JOIN courses c ON c.id=n.course_id JOIN users u ON u.id=n.user_id WHERE $W
  ORDER BY c.code, n.outline_id IS NULL, n.outline_id, (SELECT t.position FROM outline_topics t WHERE t.id=n.topic_id), n.id LIMIT $per OFFSET " . (($page - 1) * $per), $a);
$cOpts = rows("SELECT DISTINCT c.id,CONCAT(c.code,' · ',c.title) n FROM lesson_notes n JOIN courses c ON c.id=n.course_id WHERE n.status='published' AND " . in_school('n') . ($all ? '' : ' AND c.department_id=' . $dep) . ' ORDER BY c.code');
echo page_head('Lesson notes', 'Notes your lecturers prepared for each topic of the course.');
?>
<section class="card dt">
  <?= dt_head('Published notes', number_format($total) . ' note' . ($total === 1 ? '' : 's') . ($all ? '' : ' in your department'), $dep ? '<a class="btn sm line" href="?' . ($all ? '' : 'all=1') . '">' . ($all ? 'My department only' : 'All departments') . '</a>' : '') ?>
  <form class="toolbar" method="get"><?php if ($all && $dep): ?><input type="hidden" name="all" value="1"><?php endif; ?><label class="search"><?= ic('search', 'i') ?><span class="vh">Search</span><input name="q" value="<?= e($q) ?>" type="search" placeholder="Topic or course"></label>
    <select class="in" name="course" aria-label="Course"><?= options($cOpts, $cf ?: null, 'id', 'n', 'All courses') ?></select><button class="btn sm">Filter</button></form>
  <div class="dtwrap"><table class="dtable"><thead><tr><th>Lesson</th><th>Course</th><th>Week</th><th>Lecturer</th><th class="num">Words</th><th data-nosort><span class="vh">Actions</span></th></tr></thead><tbody>
  <?php foreach ($list as $n): $href = url('dashboard/lessons.php?id=' . $n['id']); ?><tr data-href="<?= e($href) ?>">
    <td class="dmain"><a class="dtt" href="<?= e($href) ?>"><?= e($n['title']) ?></a></td><td class="nw"><span class="code"><?= e($n['code']) ?></span></td><td class="nw muted"><?= e($n['week'] ?: '–') ?></td>
    <td><?= e($n['author']) ?></td><td class="num"><?= number_format((int)$n['word_count']) ?></td><td class="act"><a class="btn sm" href="<?= e($href) ?>">Read</a></td></tr>
  <?php endforeach; if (!$list) echo dt_empty(6, 'No lesson notes yet. They appear here when your lecturers publish them.'); ?></tbody></table></div>
  <?= dt_foot($total, $per, $page, ['q' => $q, 'course' => $cf ?: '', 'all' => $all && $dep ? 1 : '']) ?>
</section>
<?php include ROOT . '/includes/dashboard_footer.php'; ?>
