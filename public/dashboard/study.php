<?php
// Study hub: today's goal and smart review, tasks from lecturers, exam countdowns with readiness, and the activity calendar.
require __DIR__ . '/../../app/bootstrap.php';
$u = require_login(); $plus = is_plus($u); $uid = (int)$u['id'];
$active = 'study'; $title = 'Study hub'; $scripts = ['forms.js'];
if (is_post()) {
    form_guard(); $a = $_POST['a'] ?? '';
    if ($a === 'goal') { $g = max(5, min(500, (int)($_POST['goal'] ?? 20))); q('UPDATE users SET daily_card_goal=? WHERE id=?', [$g, $uid]); flash('ok', "Daily goal set to $g cards."); }
    elseif ($a === 'exam_add') {
        $t = Validator::str($_POST['title'] ?? '', 2, 160); $d = (string)($_POST['exam_date'] ?? ''); $c = (int)($_POST['course_id'] ?? 0);
        if ($c && !in_my_school('courses', $c)) $c = 0;
        if (!$t && $c) $t = (string)val('SELECT CONCAT(code," ",title) FROM courses WHERE id=?', [$c]);
        if (!$t || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) || $d < gmdate('Y-m-d')) flash('bad', 'Give the exam a name and a date from today on.');
        elseif ((int)val('SELECT COUNT(*) FROM personal_exams WHERE user_id=? AND exam_date>=CURDATE()', [$uid]) >= 30) flash('bad', 'You can track up to 30 upcoming exams.');
        else { q('INSERT INTO personal_exams(user_id,school_id,course_id,title,exam_date) VALUES (?,?,?,?,?)', [$uid, sid() ?: null, $c ?: null, mb_substr($t, 0, 160), $d]); flash('ok', 'Exam added to your countdown.'); }
    } elseif ($a === 'exam_delete') { q('DELETE FROM personal_exams WHERE id=? AND user_id=?', [(int)($_POST['id'] ?? 0), $uid]); flash('ok', 'Exam removed.'); }
    elseif ($a === 'task_done' || $a === 'task_undo') {
        $tid = (int)($_POST['id'] ?? 0);
        $ok = in_array($tid, array_map('intval', array_column(Study::tasksFor($u), 'id')), true);
        if (!$ok) flash('bad', 'That task is not one of yours.');
        elseif ($a === 'task_done') { q('INSERT IGNORE INTO study_task_done(task_id,user_id,auto) VALUES (?,?,0)', [$tid, $uid]); flash('ok', 'Task marked as done. Nice work.'); }
        else { q('DELETE FROM study_task_done WHERE task_id=? AND user_id=?', [$tid, $uid]); flash('ok', 'Task marked as not done.'); }
    }
    redirect_or_ajax('dashboard/study.php');
}
$activity = Study::activity($uid); $streak = Study::streak($activity); $best = Study::bestStreak($activity);
$activeDays = count(array_filter($activity)); $today = end($activity);
$goal = (int)($u['daily_card_goal'] ?? 20); $done = Study::reviewedToday($uid); $pct = min(100, (int)round(100 * $done / max(1, $goal)));
$due = $plus ? Study::dueCount($uid) : 0; $newLeft = $plus ? max(0, Study::NEW_PER_DAY - Study::newToday($uid)) : 0;
$tasks = !empty($u['department_id']) ? Study::tasksFor($u) : []; $open = count(array_filter($tasks, fn($t) => !$t['done_at']));
$dep = (int)$u['department_id'];
$school = rows('SELECT e.id,e.exam_date,e.exam_time,e.venue,c.id course_id,c.code,c.title FROM exam_timetable e JOIN courses c ON c.id=e.course_id WHERE e.exam_date>=CURDATE() AND ' . in_school('c') . ' AND (c.department_id=? OR ?=0) ORDER BY e.exam_date LIMIT 8', [$dep, $dep]);
$mine = rows('SELECT p.*,c.code FROM personal_exams p LEFT JOIN courses c ON c.id=p.course_id WHERE p.user_id=? AND p.exam_date>=CURDATE() ORDER BY p.exam_date', [$uid]);
$exams = [];
foreach ($school as $e) $exams[] = ['when' => $e['exam_date'], 'name' => $e['code'] . ' · ' . $e['title'], 'sub' => trim(($e['exam_time'] ? substr($e['exam_time'], 0, 5) : '') . ($e['venue'] ? ' · ' . $e['venue'] : ''), ' ·'), 'course' => (int)$e['course_id'], 'own' => 0];
foreach ($mine as $e) $exams[] = ['when' => $e['exam_date'], 'name' => $e['title'], 'sub' => 'Added by you', 'course' => (int)$e['course_id'], 'own' => (int)$e['id']];
usort($exams, fn($x, $y) => strcmp($x['when'], $y['when']));
$courses = rows('SELECT id,CONCAT(code," · ",title) n FROM courses WHERE ' . in_school() . ' AND (department_id=? OR ?=0) ORDER BY code', [$dep, $dep]);
$decks = $plus ? rows("SELECT d.id,d.title,d.card_count FROM decks d WHERE d.status='active' AND " . in_school('d') . " AND (d.owner_id=? OR d.visibility='shared') AND d.card_count>0 AND d.id NOT IN (SELECT DISTINCT c.deck_id FROM card_progress p JOIN cards c ON c.id=p.card_id WHERE p.user_id=? AND p.last_reviewed_at IS NOT NULL) AND (d.course_id IS NULL OR d.course_id IN (SELECT id FROM courses WHERE department_id=? OR ?=0)) ORDER BY d.id DESC LIMIT 6", [$uid, $uid, $dep, $dep]) : [];
$days = fn(string $d) => (int)round((strtotime($d) - strtotime(gmdate('Y-m-d'))) / 86400);
include ROOT . '/includes/dashboard_header.php';
echo page_head('Study hub', 'Your plan for today, tasks from lecturers and how ready you are for each exam.');
?>
<section class="sh-today card">
  <div class="sh-ring" style="--p:<?= $pct ?>" role="img" aria-label="Daily goal: <?= $done ?> of <?= $goal ?> cards reviewed today">
    <svg viewBox="0 0 120 120" aria-hidden="true"><circle class="tr" cx="60" cy="60" r="52"/><circle class="pg" cx="60" cy="60" r="52" pathLength="100" stroke-dasharray="<?= $pct ?> 100"/></svg>
    <div><b><?= $done ?></b><small>of <?= $goal ?> cards</small></div>
  </div>
  <div class="sh-now">
    <p class="mono">Today</p>
    <h2><?= $pct >= 100 ? 'Goal reached. Keep the streak going.' : ($due ? $due . ' card' . ($due === 1 ? ' is' : 's are') . ' due for review' : ($plus ? 'Nothing due. Learn something new.' : 'Build a daily review habit')) ?></h2>
    <p class="note"><?= $plus ? 'Smart review shows each card just before you would forget it, so a few minutes a day beats a long cram.' . ($newLeft ? " Up to $newLeft new cards left today." : '') : 'Smart review spaces out your flashcards so you remember them in the exam. Part of Plus.' ?></p>
    <div class="rowact">
      <a class="btn" href="<?= e(url('dashboard/review.php')) ?>"<?= $plus ? '' : plus_lock('review') ?>><?= ic($plus ? 'play' : 'lock') ?><?= $due ? 'Start review' : 'Open smart review' ?></a>
      <?= modal_btn('tpl-goal', 'Daily goal', 'Change goal', 'btn line') ?>
    </div>
  </div>
  <dl class="sh-stats">
    <div><dt>Streak</dt><dd><?= ic('flame', 'i') ?><?= $streak ?> day<?= $streak === 1 ? '' : 's' ?></dd></div>
    <div><dt>Best streak</dt><dd><?= $best ?> day<?= $best === 1 ? '' : 's' ?></dd></div>
    <div><dt>Active days</dt><dd><?= $activeDays ?> of <?= count($activity) ?></dd></div>
    <div><dt>Done today</dt><dd><?= (int)$today ?> thing<?= (int)$today === 1 ? '' : 's' ?></dd></div>
  </dl>
</section>
<template id="tpl-goal"><form class="card mform" method="post" data-action="dashboard/study.php"><?= csrf_field() ?><input type="hidden" name="a" value="goal"><h2>Daily goal</h2>
  <p class="note">How many flashcard reviews you aim for each day. 20 takes about 5 minutes.</p>
  <div class="field"><label for="gl">Cards per day</label><input class="in" id="gl" name="goal" type="number" min="5" max="500" step="5" value="<?= $goal ?>" required></div>
  <button class="btn" type="submit">Save goal</button></form></template>

<div class="grid2">
  <section class="card dt sh-col"><?= dt_head('Tasks from your lecturers', $tasks ? $open . ' to do' : 'Lecturers can set reading, decks and practice for your courses.') ?>
    <?php if (!$tasks): ?><p class="note" style="padding:4px 18px 18px">No tasks right now. When a lecturer sets one for your course it shows up here and ticks itself off when you do the work.</p><?php else: ?>
    <ul class="sh-tasks"><?php foreach ($tasks as $t): $link = Study::taskLink($t); $left = $days($t['due_on']); $isDone = (bool)$t['done_at']; ?>
      <li class="<?= $isDone ? 'done' : '' ?>">
        <span class="sh-check" aria-hidden="true"><?= ic($isDone ? 'check' : (['deck' => 'cards', 'paper' => 'docchk', 'lesson' => 'lesson'][$t['kind']] ?? 'list')) ?></span>
        <div class="sh-tbody"><b><?= e($t['title']) ?></b>
          <small><?= e($t['code']) ?> · <?= e(Study::TASK_KINDS[$t['kind']]) ?> · set by <?= e($t['by_name']) ?></small>
          <?php if ($t['instructions']): ?><p><?= nl2br(e($t['instructions'])) ?></p><?php endif; ?>
          <span class="pill <?= $isDone ? 'ok' : ($left < 0 ? 'bad' : ($left <= 2 ? 'wait' : 'new')) ?>"><?= $isDone ? ($t['auto'] ? 'Done (checked automatically)' : 'Done') : ($left < 0 ? 'Overdue ' . -$left . ' d' : ($left === 0 ? 'Due today' : 'Due in ' . $left . ' d')) ?></span></div>
        <div class="sh-tact"><?php if (!$isDone && $link): ?><a class="btn sm" href="<?= e($link) ?>">Start</a><?php endif; ?>
          <?= row_form('dashboard/study.php', ['a' => $isDone ? 'task_undo' : 'task_done', 'id' => $t['id']], $isDone ? 'Undo' : 'Mark done', 'btn sm line') ?></div>
      </li><?php endforeach; ?></ul><?php endif; ?>
  </section>

  <section class="card dt sh-col"><?= dt_head('Exam countdown', 'Readiness comes from your flashcards, practice scores and the papers you studied.', modal_btn('tpl-exam', 'Add an exam', 'Add exam', 'btn sm', 'plus')) ?>
    <?php if (!$exams): ?><p class="note" style="padding:4px 18px 18px">No upcoming exams. Your school's timetable appears here automatically; add your own with <b>Add exam</b>.</p><?php else: ?>
    <ul class="sh-exams"><?php foreach ($exams as $x): $n = $days($x['when']); $r = $x['course'] ? Study::readiness($uid, $x['course']) : ['score' => null, 'parts' => []]; ?>
      <li><div class="sh-count<?= $n <= 7 ? ' soon' : '' ?>"><b><?= $n ?></b><small><?= $n === 1 ? 'day' : 'days' ?></small></div>
        <div class="sh-ebody"><b><?= e($x['name']) ?></b><small><?= e(fdate($x['when'], 'D j M Y')) ?><?= $x['sub'] ? ' · ' . e($x['sub']) : '' ?></small>
          <?php if ($r['score'] !== null): ?><div class="sh-ready" title="<?= e(implode(' · ', array_map(fn($k, $v) => "$k $v%", array_keys($r['parts']), $r['parts']))) ?>"><span class="bt"><i style="width:<?= $r['score'] ?>%"></i></span><span><?= $r['score'] ?>% ready</span></div>
          <small class="note"><?= e(implode(' · ', array_map(fn($k, $v) => "$k $v%", array_keys($r['parts']), $r['parts']))) ?></small><?php endif; ?></div>
        <?php if ($x['own']) echo row_form('dashboard/study.php', ['a' => 'exam_delete', 'id' => $x['own']], ic('trash') . '<span class="vh">Remove</span>', 'btn sm line icon', 'Remove this exam from your countdown?'); ?></li>
    <?php endforeach; ?></ul><?php endif; ?>
  </section>
</div>
<template id="tpl-exam"><form class="card mform" method="post" data-action="dashboard/study.php"><?= csrf_field() ?><input type="hidden" name="a" value="exam_add"><h2>Add an exam</h2>
  <div class="field"><label for="ec">Course (gives you a readiness score)</label><select class="in" id="ec" name="course_id"><?= options($courses, null, 'id', 'n', 'Not linked to a course') ?></select></div>
  <div class="field"><label for="et">Name</label><input class="in" id="et" name="title" maxlength="160" placeholder="Leave empty to use the course name"></div>
  <div class="field"><label for="ed">Date</label><input class="in" id="ed" name="exam_date" type="date" min="<?= gmdate('Y-m-d') ?>" required></div>
  <button class="btn" type="submit">Add to countdown</button></form></template>

<section class="card"><div class="hd"><div><h2>Study activity</h2><p class="sub">Last 16 weeks: flashcards, practice exams, papers, lessons and focus sessions</p></div></div>
  <div class="sh-heat"><?= Study::heatmap($activity) ?></div>
  <div class="sh-legend" aria-hidden="true"><span>Less</span><i class="h0"></i><i class="h1"></i><i class="h2"></i><i class="h3"></i><i class="h4"></i><span>More</span></div>
</section>
<?php if ($decks): ?>
<section class="card dt"><?= dt_head('Add a deck to smart review', 'Decks for your courses you have not started yet') ?>
  <div class="sh-decks"><?php foreach ($decks as $d): ?><a class="sh-deck" href="<?= e(url('dashboard/review.php?deck=' . $d['id'])) ?>"><span class="ic"><?= ic('cards') ?></span><span><b><?= e($d['title']) ?></b><small><?= (int)$d['card_count'] ?> cards</small></span><?= ic('play', 'i go') ?></a><?php endforeach; ?></div>
</section>
<?php endif; ?>
<?php include ROOT . '/includes/dashboard_footer.php'; ?>
