<?php
// Lecturer study tasks: set "review this deck / practise this paper / read this lesson by <date>" for a course,
// then see who has done it. Deck, paper and lesson tasks tick themselves off from what students actually do.
require __DIR__ . '/../../app/bootstrap.php';
$u = lecturer_area_user(); $lecturerArea = true; $active = 'tasks'; $title = 'Study tasks'; $scripts = ['forms.js'];
$act = 'lecturer/tasks.php';
$ids = staff_course_ids($u); $myCourses = staff_courses($u);
$scopeSql = fn(string $col) => $ids === null ? "$col IN (SELECT id FROM courses WHERE " . in_school() . ')' : ($ids ? "$col IN (" . implode(',', $ids) . ')' : '0=1');
/** A task this person may manage (in their courses, this school). */
$own = fn(int $id) => row('SELECT t.*,c.code,c.title course FROM study_tasks t JOIN courses c ON c.id=t.course_id WHERE t.id=? AND ' . in_school('t') . ' AND ' . $scopeSql('t.course_id'), [$id]);

if (is_post()) {
    form_guard(); $a = $_POST['a'] ?? '';
    if ($a === 'create') {
        $what = (string)($_POST['target'] ?? 'custom'); [$kind, $tid] = array_pad(explode(':', $what, 2), 2, '0'); $tid = (int)$tid;
        $kind = isset(Study::TASK_KINDS[$kind]) ? $kind : 'custom';
        $course = (int)($_POST['course_id'] ?? 0); $label = '';
        if ($kind !== 'custom') {
            $tbl = ['deck' => "SELECT course_id,title FROM decks WHERE id=? AND status='active' AND visibility='shared' AND " . in_school(), 'paper' => "SELECT course_id,title FROM papers WHERE id=? AND status='published' AND " . in_school(), 'lesson' => "SELECT course_id,title FROM lesson_notes WHERE id=? AND status='published' AND " . in_school()][$kind];
            $tr = row($tbl, [$tid]); $course = (int)($tr['course_id'] ?? 0); $label = (string)($tr['title'] ?? '');
        }
        $titleIn = Validator::str($_POST['title'] ?? '', 2, 160);
        $titleV = $titleIn ?: ($label !== '' ? ['deck' => 'Review: ', 'paper' => 'Practise: ', 'lesson' => 'Read: '][$kind] . $label : null);
        $due = (string)($_POST['due_on'] ?? '');
        $inScope = $course && ($ids === null ? in_my_school('courses', $course) : in_array($course, $ids, true));
        if (!$inScope) flash('bad', $kind === 'custom' ? 'Choose one of your courses.' : 'Choose a deck, paper or lesson from your own courses.');
        elseif (!$titleV) flash('bad', 'Give the task a title.');
        elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $due) || $due < gmdate('Y-m-d')) flash('bad', 'Pick a due date from today on.');
        else {
            q('INSERT INTO study_tasks(school_id,course_id,created_by,kind,target_id,title,instructions,due_on) VALUES (?,?,?,?,?,?,?,?)', [sid(), $course, $u['id'], $kind, $kind === 'custom' ? null : $tid, mb_substr($titleV, 0, 160), mb_substr(trim((string)($_POST['instructions'] ?? '')), 0, 1000) ?: null, $due]);
            Audit::log('set a study task', 'study_task', (int)db()->lastInsertId()); flash('ok', 'Task set. Students in the course see it in their Study hub.');
        }
        redirect_or_ajax($act);
    }
    $t = $own((int)($_POST['id'] ?? 0));
    if (!$t) { flash('bad', 'Task not found.'); redirect_or_ajax($act); }
    if ($a === 'archive') { q("UPDATE study_tasks SET status=IF(status='active','archived','active') WHERE id=?", [$t['id']]); flash('ok', $t['status'] === 'active' ? 'Task archived. Students no longer see it.' : 'Task is active again.'); }
    elseif ($a === 'delete') { q('DELETE FROM study_tasks WHERE id=?', [$t['id']]); Audit::log('deleted a study task', 'study_task', (int)$t['id']); flash('ok', 'Task deleted.'); redirect_or_ajax($act); }
    elseif ($a === 'due') { $d = (string)($_POST['due_on'] ?? ''); if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) { q('UPDATE study_tasks SET due_on=? WHERE id=?', [$d, $t['id']]); flash('ok', 'Due date changed.'); } }
    redirect_self_or_ajax();
}

$id = (int)($_GET['id'] ?? 0);
if ($id && ($t = $own($id))) {
    $students = Study::taskStudents($t); $sTotal = count($students); $dn = count(array_filter($students, fn($s) => $s['done_at']));
    $title = $t['title'];
    include ROOT . '/includes/dashboard_header.php';
    echo page_head($t['title'], $t['code'] . ' · ' . Study::TASK_KINDS[$t['kind']] . ' · due ' . fdate($t['due_on'], 'D j M Y'), '<a class="btn sm line" href="' . e(url($act)) . '">All tasks</a>');
    ?>
    <section class="kpis">
      <?= stat_tile($sTotal ? (int)round(100 * $dn / $sTotal) . "%" : "–", "Completed", "", "$dn of $sTotal students") ?>
      <?= stat_tile((string)($sTotal - $dn), "Still to do") ?>
      <?= stat_tile(e(ucfirst($t['status'])), 'Status', '', $t['kind'] === 'custom' ? 'Students tick it off themselves' : 'Ticks off automatically') ?>
    </section>
    <?php if ($t['instructions']): ?><section class="card"><h2>Instructions</h2><p style="margin-top:6px"><?= nl2br(e($t['instructions'])) ?></p></section><?php endif; ?>
    <section class="card dt"><?= dt_head('Students', 'Everyone in ' . $t['code'] . ($sTotal ? "" : ": no students match this course yet (department and level)."), row_form($act, ['a' => 'archive', 'id' => $t['id']], $t['status'] === 'active' ? 'Archive' : 'Make active', 'btn sm line') . modal_btn('tpl-due', 'Change due date', 'Change due date', 'btn sm line')) ?>
      <div class="dtwrap"><table class="dtable"><thead><tr><th>Student</th><th>Level</th><th>Status</th><th>Done</th></tr></thead><tbody>
      <?php foreach ($students as $s): ?><tr><td class="dmain"><span class="dtt"><?= e($s['full_name']) ?></span></td><td class="muted"><?= e(level_label($s['level'])) ?></td>
        <td><?= $s['done_at'] ? '<span class="pill ok">Done' . ($s['auto'] ? ' (auto)' : '') . '</span>' : ($t['due_on'] < gmdate('Y-m-d') ? '<span class="pill bad">Overdue</span>' : '<span class="pill new">To do</span>') ?></td>
        <td class="nw muted"><?= $s['done_at'] ? e(ago($s['done_at'])) : '–' ?></td></tr>
      <?php endforeach; if (!$students) echo dt_empty(4, 'No students in this course yet.'); ?></tbody></table></div></section>
    <template id="tpl-due"><form class="card mform" method="post" data-action="<?= $act ?>"><?= csrf_field() ?><input type="hidden" name="a" value="due"><input type="hidden" name="id" value="<?= (int)$t['id'] ?>"><h2>Due date</h2>
      <div class="field"><label for="dd">Due on</label><input class="in" id="dd" name="due_on" type="date" value="<?= e($t['due_on']) ?>" required></div><button class="btn" type="submit">Save</button></form></template>
    <?php include ROOT . '/includes/dashboard_footer.php'; exit;
}

$show = ($_GET['show'] ?? 'active') === 'archived' ? 'archived' : 'active';
$tasks = rows('SELECT t.*,c.code FROM study_tasks t JOIN courses c ON c.id=t.course_id WHERE ' . in_school('t') . ' AND ' . $scopeSql('t.course_id') . ' AND t.status=? ORDER BY t.due_on DESC, t.id DESC LIMIT 100', [$show]);
foreach ($tasks as &$t) { $s = Study::taskStudents($t); $t['n'] = count($s); $t['dn'] = count(array_filter($s, fn($x) => $x['done_at'])); } unset($t);
$targets = [
    'Flashcard decks' => rows("SELECT CONCAT('deck:',d.id) v, CONCAT(c.code,' · ',d.title,' (',d.card_count,' cards)') n FROM decks d JOIN courses c ON c.id=d.course_id WHERE d.status='active' AND d.visibility='shared' AND " . in_school('d') . ' AND ' . $scopeSql('d.course_id') . ' ORDER BY c.code,d.title LIMIT 300'),
    'Past papers (practice exam)' => rows("SELECT CONCAT('paper:',p.id) v, CONCAT(c.code,' · ',p.title) n FROM papers p JOIN courses c ON c.id=p.course_id WHERE p.status='published' AND " . in_school('p') . ' AND ' . $scopeSql('p.course_id') . ' ORDER BY c.code,p.exam_year DESC LIMIT 300'),
    'Lesson notes' => rows("SELECT CONCAT('lesson:',n.id) v, CONCAT(c.code,' · ',n.title) n FROM lesson_notes n JOIN courses c ON c.id=n.course_id WHERE n.status='published' AND " . in_school('n') . ' AND ' . $scopeSql('n.course_id') . ' ORDER BY c.code,n.title LIMIT 300'),
];
include ROOT . '/includes/dashboard_header.php';
echo page_head('Study tasks', 'Set work for a course and see who has done it. Deck, paper and lesson tasks tick themselves off.', $myCourses ? modal_btn('tpl-task', 'Set a study task', 'New task', 'btn', 'plus', 'wide') : '');
?>
<div class="tabs" role="navigation" aria-label="Show"><a class="tab" href="?show=active" aria-selected="<?= $show === 'active' ? 'true' : 'false' ?>">Active</a><a class="tab" href="?show=archived" aria-selected="<?= $show === 'archived' ? 'true' : 'false' ?>">Archived</a></div>
<section class="card dt"><?= dt_head('Tasks', count($tasks) . ' ' . $show) ?>
  <div class="dtwrap"><table class="dtable"><thead><tr><th>Task</th><th>Course</th><th>Due</th><th>Completion</th><th data-nosort><span class="vh">Actions</span></th></tr></thead><tbody>
  <?php foreach ($tasks as $t): $href = url($act . '?id=' . $t['id']); $pc = $t['n'] ? (int)round(100 * $t['dn'] / $t['n']) : 0; $late = $t['due_on'] < gmdate('Y-m-d'); ?><tr data-href="<?= e($href) ?>">
    <td class="dmain"><a class="dtt" href="<?= e($href) ?>"><?= e($t['title']) ?></a><span class="ts"><?= e(Study::TASK_KINDS[$t['kind']]) ?></span></td>
    <td class="nw"><span class="code"><?= e($t['code']) ?></span></td>
    <td class="nw" data-v="<?= e($t['due_on']) ?>"><?= e(fdate($t['due_on'], 'j M')) ?><?= $late ? ' <span class="pill new">Past</span>' : '' ?></td>
    <td data-v="<?= $pc ?>"><div class="sh-ready"><span class="bt"><i style="width:<?= $pc ?>%"></i></span><span><?= $t['dn'] ?>/<?= $t['n'] ?></span></div></td>
    <td class="act"><a class="btn sm line" href="<?= e($href) ?>">Open</a><?= row_form($act, ['a' => 'delete', 'id' => $t['id']], ic('trash') . '<span class="vh">Delete</span>', 'btn sm line icon', 'Delete this task? Students stop seeing it and its progress is removed.') ?></td></tr>
  <?php endforeach; if (!$tasks) echo dt_empty(5, $show === 'active' ? 'No tasks yet. Press New task to set one for a course.' : 'No archived tasks.'); ?></tbody></table></div></section>
<?php if ($myCourses): ?>
<template id="tpl-task"><form class="card mform" method="post" data-action="<?= $act ?>"><?= csrf_field() ?><input type="hidden" name="a" value="create"><h2>New study task</h2>
  <div class="field"><label for="tt">What should students do?</label><select class="in" id="tt" name="target" required>
    <option value="custom">Something else (describe it below)</option>
    <?php foreach ($targets as $g => $opts) if ($opts): ?><optgroup label="<?= e($g) ?>"><?= options($opts, null, 'v', 'n') ?></optgroup><?php endif; ?></select>
    <span class="hint note">Decks, papers and lessons tick off automatically when a student reviews 80% of the deck, takes the practice exam or reads the lesson.</span></div>
  <div class="field"><label for="tc">Course (only needed for "Something else")</label><select class="in" id="tc" name="course_id"><?= options($myCourses, null, 'id', 'n', 'Taken from the deck, paper or lesson') ?></select></div>
  <div class="f2"><div class="field"><label for="tn">Title</label><input class="in" id="tn" name="title" maxlength="160" placeholder="Leave empty to name it after the item"></div>
    <div class="field"><label for="td">Due on</label><input class="in" id="td" name="due_on" type="date" min="<?= gmdate('Y-m-d') ?>" value="<?= gmdate('Y-m-d', time() + 7 * 86400) ?>" required></div></div>
  <div class="field"><label for="ti">Instructions (optional)</label><textarea class="in" id="ti" name="instructions" rows="3" maxlength="1000" placeholder="For example: focus on questions 3 to 5 before Thursday's tutorial."></textarea></div>
  <button class="btn" type="submit">Set task</button></form></template>
<?php else: ?><div class="banner">Pick the courses you teach in <a href="<?= e(url('staff/courses.php')) ?>">My courses</a> first, then you can set tasks for them.</div><?php endif; ?>
<?php include ROOT . '/includes/dashboard_footer.php'; ?>
