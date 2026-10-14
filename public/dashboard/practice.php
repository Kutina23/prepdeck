<?php
require __DIR__ . '/../../app/bootstrap.php';
$u = require_login(); $plus = is_plus($u); $uid = (int)$u['id'];
$active = 'practice'; $title = 'Practice exams'; $scripts = ['practice.js'];
$staff = Auth::can('approve');

/** The questions this student may practise from one source, or null when they cannot open it. */
function practice_source(string $type, int $id, int $uid, bool $staff): ?array {
    if ($type === 'paper') {
        $s = row("SELECT p.id,p.title,c.code FROM papers p JOIN courses c ON c.id=p.course_id WHERE p.id=? AND " . in_school('p') . " AND (p.status='published' OR ?=1)", [$id, (int)$staff]);
        $qs = $s ? rows("SELECT id,number,question,answer,topic,marks,kind FROM exam_questions WHERE source_type='paper' AND source_id=? AND status='published' AND answer<>'' ORDER BY kind,id", [$id]) : [];
    } elseif ($type === 'material') {
        $s = row('SELECT id,title,NULL code FROM materials WHERE id=? AND user_id=? AND ' . in_school(), [$id, $uid]);
        $qs = $s ? rows("SELECT id,number,question,answer,topic,marks,kind FROM exam_questions WHERE source_type='material' AND source_id=? AND status<>'hidden' AND answer<>'' ORDER BY id", [$id]) : [];
    } elseif ($type === 'recording') {
        $s = row("SELECT r.id,r.title,c.code FROM recordings r LEFT JOIN courses c ON c.id=r.course_id WHERE r.id=? AND " . in_school('r') . " AND (r.owner_id=? OR (r.status='published' AND r.visibility='course'))", [$id, $uid]);
        $qs = $s ? rows("SELECT id,number,question,answer,topic,marks,kind FROM exam_questions WHERE source_type='recording' AND source_id=? AND status<>'hidden' AND answer<>'' ORDER BY id", [$id]) : [];
    } else return null;
    return $s ? $s + ['questions' => $qs, 'type' => $type] : null;
}

if (is_post()) {                                    // save a finished attempt (sent by practice.js)
    form_guard();
    $type = Validator::in($_POST['type'] ?? '', ['paper', 'material', 'recording']); $sid = (int)($_POST['id'] ?? 0);
    if (!$plus || !$type || !practice_source($type, $sid, $uid, $staff)) Response::error('Not available.', 403);
    $total = max(0, min(500, (int)($_POST['total'] ?? 0))); $correct = max(0, min($total, (int)round((float)($_POST['correct'] ?? 0))));
    q('INSERT INTO practice_attempts(school_id,user_id,source_type,source_id,total,correct,seconds) VALUES (?,?,?,?,?,?,?)', [sid(), $uid, $type, $sid, $total, $correct, max(0, min(86400, (int)($_POST['seconds'] ?? 0)))]);
    Response::ok(['saved' => true]);
}

$type = $_GET['type'] ?? ''; $sid = (int)($_GET['id'] ?? 0);
if ($type && $sid) {
    $src = practice_source($type, $sid, $uid, $staff);
    include ROOT . '/includes/dashboard_header.php';
    if (!$src) { echo page_head('Practice exam') . empty_state('That paper is not available for practice.', '<a class="btn sm" href="' . e(url('dashboard/practice.php')) . '">All practice exams</a>'); include ROOT . '/includes/dashboard_footer.php'; exit; }
    echo page_head('Practice: ' . $src['title'], ($src['code'] ? $src['code'] . ' · ' : '') . count($src['questions']) . ' questions with model answers', '<a class="btn sm line" href="' . e(url('dashboard/practice.php')) . '">All practice exams</a>');
    if (!$plus) { echo '<section class="card">' . lock_note('Practice exams with model answers') . '</section>'; include ROOT . '/includes/dashboard_footer.php'; exit; }
    if (!$src['questions']) { echo empty_state('PrepDeck AI has not written answers for this yet. Check back after it is processed and reviewed.'); include ROOT . '/includes/dashboard_footer.php'; exit; }
    $best = row('SELECT MAX(ROUND(100*correct/NULLIF(total,0))) best, COUNT(*) n FROM practice_attempts WHERE user_id=? AND source_type=? AND source_id=? AND ' . in_school(), [$uid, $type, $sid]);
    $data = ['type' => $type, 'id' => $sid, 'title' => $src['title'], 'questions' => array_map(fn($q) => ['n' => (string)($q['number'] ?: ''), 'q' => $q['question'], 'a' => $q['answer'], 'topic' => $q['topic'], 'marks' => $q['marks'] ? (int)$q['marks'] : null, 'kind' => $q['kind']], $src['questions'])];
    $n = count($src['questions']);
    ?>
    <section class="card stack" id="practice" data-action="dashboard/practice.php">
      <script type="application/json" id="pdata"><?= json_encode($data, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
      <form class="mform" id="psetup">
        <h2>Set up your exam</h2>
        <p class="note"><?= (int)$best['n'] ? 'Best score so far: <b>' . (int)$best['best'] . '%</b> over ' . (int)$best['n'] . ' attempt' . ($best['n'] == 1 ? '' : 's') . '.' : 'Answer each question in your own words, then compare with the model answer and mark yourself honestly.' ?></p>
        <div class="f2"><div class="field"><label for="pn">Questions</label><select class="in" id="pn" name="count"><?php foreach (array_unique([5, 10, 20, $n]) as $k) if ($k <= $n) echo '<option value="' . $k . '"' . ($k === min(10, $n) ? ' selected' : '') . '>' . ($k === $n ? "All $n" : $k) . '</option>'; ?></select></div>
          <div class="field"><label for="pt">Time limit</label><select class="in" id="pt" name="minutes"><option value="0">No timer</option><?php foreach ([10, 20, 30, 45, 60, 90, 120] as $m): ?><option value="<?= $m ?>"<?= $m === 30 ? ' selected' : '' ?>><?= $m ?> minutes</option><?php endforeach; ?></select></div></div>
        <label class="chk"><input type="checkbox" name="shuffle" value="1" checked>Shuffle the questions</label>
        <button class="btn" type="submit">Start exam</button>
      </form>
      <div id="prun" hidden></div>
    </section>
    <?php include ROOT . '/includes/dashboard_footer.php'; exit;
}

// list: everything this student can practise
$q = trim((string)($_GET['q'] ?? '')); $per = per_page(15); $page = page_no();
$dep = (int)$u['department_id'];
$like = $q !== '' ? '%' . $q . '%' : null;
$sql = "SELECT 'paper' t, p.id, p.title, c.code, COUNT(e.id) n, p.created_at FROM papers p JOIN courses c ON c.id=p.course_id JOIN exam_questions e ON e.source_type='paper' AND e.source_id=p.id AND e.status='published' AND e.answer<>''
          WHERE p.status='published' AND " . in_school('p') . " AND (c.department_id=? OR ?=0)" . ($like ? ' AND (p.title LIKE ? OR c.code LIKE ?)' : '') . " GROUP BY p.id
        UNION ALL SELECT 'material', m.id, m.title, NULL, COUNT(e.id), m.created_at FROM materials m JOIN exam_questions e ON e.source_type='material' AND e.source_id=m.id AND e.status<>'hidden' AND e.answer<>''
          WHERE m.user_id=? AND " . in_school('m') . ($like ? ' AND m.title LIKE ?' : '') . " GROUP BY m.id
        UNION ALL SELECT 'recording', r.id, r.title, c.code, COUNT(e.id), r.created_at FROM recordings r LEFT JOIN courses c ON c.id=r.course_id JOIN exam_questions e ON e.source_type='recording' AND e.source_id=r.id AND e.status<>'hidden' AND e.answer<>''
          WHERE " . in_school('r') . " AND (r.owner_id=? OR (r.status='published' AND r.visibility='course'))" . ($like ? ' AND r.title LIKE ?' : '') . " GROUP BY r.id";
$args = array_merge([$dep, $dep], $like ? [$like, $like] : [], [$uid], $like ? [$like] : [], [$uid], $like ? [$like] : []);
$total = (int)val("SELECT COUNT(*) FROM ($sql) x", $args);
$list = rows("SELECT x.*, (SELECT MAX(ROUND(100*a.correct/NULLIF(a.total,0))) FROM practice_attempts a WHERE a.user_id=? AND a.source_type=x.t AND a.source_id=x.id) best,
  (SELECT MAX(a.created_at) FROM practice_attempts a WHERE a.user_id=? AND a.source_type=x.t AND a.source_id=x.id) lastat FROM ($sql) x ORDER BY lastat IS NULL, lastat DESC, created_at DESC LIMIT $per OFFSET " . (($page - 1) * $per), array_merge([$uid, $uid], $args));
$recent = rows("SELECT a.*, COALESCE(p.title,m.title,r.title) title FROM practice_attempts a LEFT JOIN papers p ON a.source_type='paper' AND p.id=a.source_id LEFT JOIN materials m ON a.source_type='material' AND m.id=a.source_id LEFT JOIN recordings r ON a.source_type='recording' AND r.id=a.source_id WHERE a.user_id=? AND " . in_school('a') . " ORDER BY a.id DESC LIMIT 5", [$uid]);
$avg = row('SELECT COUNT(*) n, ROUND(AVG(100*correct/NULLIF(total,0))) avg, COALESCE(SUM(total),0) qs FROM practice_attempts WHERE user_id=? AND ' . in_school(), [$uid]);
include ROOT . '/includes/dashboard_header.php';
echo page_head('Practice exams', 'Timed exam practice from past papers, your notes and lectures, with model answers to mark yourself against.');
if (!$plus) echo '<section class="card">' . lock_note('Practice exams with model answers') . '</section>';
?>
<section class="stats"><div class="card stat"><span class="ic" style="background:var(--lemon)"><?= ic('docchk') ?></span><div><b><?= (int)$avg['n'] ?></b><small>Exams taken</small></div></div>
  <div class="card stat"><span class="ic" style="background:var(--brand);color:var(--brand-ink)"><?= ic('check') ?></span><div><b><?= $avg['avg'] !== null ? (int)$avg['avg'] . '%' : '–' ?></b><small>Average score</small></div></div>
  <div class="card stat"><span class="ic" style="background:var(--pop)"><?= ic('spark') ?></span><div><b><?= (int)$avg['qs'] ?></b><small>Questions answered</small></div></div>
  <div class="card stat"><span class="ic" style="background:var(--lemon)"><?= ic('file') ?></span><div><b><?= number_format($total) ?></b><small>Ready to practise</small></div></div></section>
<section class="card dt">
  <?= dt_head('Choose what to practise', 'Only papers with model answers appear here.') ?>
  <form class="toolbar" method="get"><label class="search"><?= ic('search', 'i') ?><span class="vh">Search</span><input name="q" value="<?= e($q) ?>" type="search" placeholder="Paper, course or notes"></label><button class="btn sm">Search</button></form>
  <div class="dtwrap"><table class="dtable"><thead><tr><th>Course</th><th>Practise from</th><th>Type</th><th class="num">Questions</th><th class="num">Best</th><th>Last tried</th><th data-nosort><span class="vh">Actions</span></th></tr></thead><tbody>
  <?php foreach ($list as $x): $href = url('dashboard/practice.php?type=' . $x['t'] . '&id=' . $x['id']); ?><tr data-href="<?= e($href) ?>">
    <td class="nw"><span class="code"><?= e($x['code'] ?: ($x['t'] === 'material' ? 'NOTES' : 'LECTURE')) ?></span></td><td class="dmain"><a class="dtt" href="<?= e($href) ?>"><?= e($x['title']) ?></a></td>
    <td><?= ['paper' => 'Past paper', 'material' => 'My notes', 'recording' => 'Lecture'][$x['t']] ?></td><td class="num"><?= (int)$x['n'] ?></td>
    <td class="num"><?= $x['best'] !== null ? '<b>' . (int)$x['best'] . '%</b>' : '&ndash;' ?></td><td class="nw muted" data-v="<?= e((string)$x['lastat']) ?>"><?= $x['lastat'] ? e(ago($x['lastat'])) : 'Never' ?></td>
    <td class="act"><a class="btn sm" href="<?= e($href) ?>"><?= $x['lastat'] ? 'Try again' : 'Start' ?></a></td></tr>
  <?php endforeach; if (!$list) echo dt_empty(7, 'Nothing to practise yet. Upload your notes or open a past paper once PrepDeck AI has written its answers.'); ?></tbody></table></div>
  <?= dt_foot($total, $per, $page, ['q' => $q]) ?>
</section>
<?php if ($recent): ?><section class="card dt"><?= dt_head('Your recent attempts') ?>
  <div class="dtwrap"><table class="dtable"><thead><tr><th>Exam</th><th class="num">Score</th><th class="num">Time</th><th>When</th></tr></thead><tbody>
  <?php foreach ($recent as $a): $pc = $a['total'] ? (int)round(100 * $a['correct'] / $a['total']) : 0; ?><tr><td class="dmain"><span class="dtt"><?= e($a['title'] ?? 'Deleted') ?></span></td>
    <td class="num" data-v="<?= $pc ?>"><span class="pill <?= $pc >= 70 ? 'ok' : ($pc >= 40 ? 'wait' : 'bad') ?>"><?= $pc ?>%</span> <span class="muted"><?= (int)$a['correct'] ?>/<?= (int)$a['total'] ?></span></td>
    <td class="num" data-v="<?= (int)$a['seconds'] ?>"><?= floor($a['seconds'] / 60) ?>:<?= str_pad((string)($a['seconds'] % 60), 2, '0', STR_PAD_LEFT) ?></td><td class="nw muted" data-v="<?= e($a['created_at']) ?>"><?= e(ago($a['created_at'])) ?></td></tr><?php endforeach; ?>
  </tbody></table></div></section><?php endif; ?>
<?php include ROOT . '/includes/dashboard_footer.php'; ?>
