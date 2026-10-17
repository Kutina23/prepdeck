<?php
// Lecturer flashcard sets: make term / definition sets for a course (typed, or pasted from a document), share them with
// the course's students, and see who studies them and how they score in Test mode.
require __DIR__ . '/../../app/bootstrap.php';
$u = lecturer_area_user(); $lecturerArea = true; $active = 'sets'; $title = 'Flashcard sets'; $scripts = ['sets.js'];
$act = 'lecturer/decks.php'; $uid = (int)$u['id'];
$ids = staff_course_ids($u); $myCourses = staff_courses($u);
$scope = fn(string $col) => $ids === null ? "$col IN (SELECT id FROM courses WHERE " . in_school() . ')' : ($ids ? "$col IN (" . implode(',', $ids) . ')' : '0=1');
/** A set this lecturer may edit: their own, or any set in their courses when they are an admin. */
$editable = function (int $id) use ($uid, $scope) {
    return row('SELECT * FROM decks WHERE id=? AND ' . in_school() . ' AND (owner_id=? OR (' . (Auth::can('decks') ? '1=1' : '0=1') . ' AND ' . $scope('course_id') . '))', [$id, $uid]);
};

if (is_post()) {
    form_guard(); $a = $_POST['a'] ?? ''; $id = (int)($_POST['id'] ?? 0);
    if ($a === 'save') {
        $d = $id ? $editable($id) : null;
        if ($id && !$d) { flash('bad', 'Set not found.'); redirect($act); }
        $t = Validator::str($_POST['title'] ?? '', 2, 160); $course = (int)($_POST['course_id'] ?? 0); $shared = isset($_POST['shared']);
        $okCourse = $course && ($ids === null ? in_my_school('courses', $course) : in_array($course, $ids, true));
        $fronts = (array)($_POST['front'] ?? []); $backs = (array)($_POST['back'] ?? []); $cids = (array)($_POST['cid'] ?? []); $cards = [];
        foreach ($fronts as $i => $f) {
            $f = trim((string)$f); $bk = trim((string)($backs[$i] ?? ''));
            if ($f === '' && $bk === '') continue;
            $cards[] = ['id' => (int)($cids[$i] ?? 0), 'front' => mb_substr($f, 0, 2000), 'back' => mb_substr($bk, 0, 2000)];
        }
        $half = array_filter($cards, fn($c) => $c['front'] === '' || $c['back'] === '');
        if (!$t) flash('bad', 'Give the set a title.');
        elseif (!$okCourse) flash('bad', 'Choose one of your courses.');
        elseif (count($cards) < 2) flash('bad', 'Add at least 2 cards.');
        elseif ($half) flash('bad', count($half) . ' card' . (count($half) === 1 ? ' is' : 's are') . ' missing a term or a definition.');
        elseif (count($cards) > 500) flash('bad', 'A set can have up to 500 cards. Split it into two sets.');
        else {
            $db = db(); $db->beginTransaction();
            try {
                if ($d) { q("UPDATE decks SET title=?,course_id=?,visibility=?,status='active',card_count=? WHERE id=?", [$t, $course, $shared ? 'shared' : 'private', count($cards), $d['id']]); $deckId = (int)$d['id']; }
                else { q("INSERT INTO decks(school_id,owner_id,course_id,title,source,visibility,status,card_count) VALUES (?,?,?,?,'manual',?,'active',?)", [sid(), $uid, $course, $t, $shared ? 'shared' : 'private', count($cards)]); $deckId = (int)$db->lastInsertId(); }
                // keep the ids of cards that stay, so students' review history survives an edit
                $have = array_map('intval', array_column(rows('SELECT id FROM cards WHERE deck_id=?', [$deckId]), 'id')); $kept = [];
                foreach ($cards as $pos => $c) {
                    if ($c['id'] && in_array($c['id'], $have, true)) { q('UPDATE cards SET front=?,back=?,position=? WHERE id=?', [$c['front'], $c['back'], $pos, $c['id']]); $kept[] = $c['id']; }
                    else q('INSERT INTO cards(deck_id,front,back,position) VALUES (?,?,?,?)', [$deckId, $c['front'], $c['back'], $pos]);
                }
                $gone = array_diff($have, $kept); if ($gone) q('DELETE FROM cards WHERE deck_id=? AND id IN (' . implode(',', array_map('intval', $gone)) . ')', [$deckId]);
                $db->commit();
            } catch (Throwable $e) { $db->rollBack(); throw $e; }
            Audit::log(($d ? 'edited' : 'created') . ' flashcard set', 'deck', $deckId);
            flash('ok', ($d ? 'Set saved. ' : 'Set created. ') . ($shared ? 'Students in the course can study it in Flashcards, Learn and Test.' : 'It is private: only you can see it until you share it.'));
            redirect($act . '?id=' . $deckId);
        }
        $_SESSION['set_draft'] = ['title' => $t ?: (string)($_POST['title'] ?? ''), 'course_id' => $course, 'shared' => $shared, 'cards' => $cards];
        redirect($act . ($id ? '?edit=' . $id : '?new=1'));
    }
    $d = $editable($id);
    if (!$d) { flash('bad', 'Set not found.'); redirect_or_ajax($act); }
    if ($a === 'share') { $v = $d['visibility'] === 'shared' ? 'private' : 'shared'; q('UPDATE decks SET visibility=? WHERE id=?', [$v, $id]); flash('ok', $v === 'shared' ? 'Shared with the course.' : 'Now private.'); }
    elseif ($a === 'delete') { q('DELETE FROM decks WHERE id=?', [$id]); Audit::log('deleted flashcard set', 'deck', $id); flash('ok', 'Set deleted.'); redirect_or_ajax($act); }
    redirect_self_or_ajax();
}

/* ---------- editor ---------- */
$edit = (int)($_GET['edit'] ?? 0); $new = isset($_GET['new']);
if ($edit || $new) {
    $d = $edit ? $editable($edit) : null;
    if ($edit && !$d) { flash('bad', 'Set not found.'); redirect($act); }
    $draft = $_SESSION['set_draft'] ?? null; unset($_SESSION['set_draft']);
    $cards = $draft['cards'] ?? ($d ? rows('SELECT id,front,back FROM cards WHERE deck_id=? ORDER BY position,id', [$d['id']]) : []);
    while (count($cards) < 5) $cards[] = ['id' => 0, 'front' => '', 'back' => ''];
    $title = $d ? 'Edit set' : 'New set';
    include ROOT . '/includes/dashboard_header.php';
    echo page_head($d ? 'Edit: ' . $d['title'] : 'New flashcard set', 'Terms on the left, definitions on the right. Students study it with Flashcards, Learn, Test, Smart review and games.', '<a class="btn sm line" href="' . e(url($d ? $act . '?id=' . $d['id'] : $act)) . '">Cancel</a>');
    if (!$myCourses) { echo '<div class="banner">Pick the courses you teach in <a href="' . e(url('staff/courses.php')) . '">My courses</a> first.</div>'; include ROOT . '/includes/dashboard_footer.php'; exit; }
    ?>
    <form class="setform" id="setForm" method="post" action="<?= e(url($act)) ?>"><?= csrf_field() ?><input type="hidden" name="a" value="save"><input type="hidden" name="id" value="<?= (int)($d['id'] ?? 0) ?>">
      <section class="card mform">
        <div class="f2"><div class="field"><label for="st">Title</label><input class="in" id="st" name="title" maxlength="160" required value="<?= e($draft['title'] ?? $d['title'] ?? '') ?>" placeholder="e.g. Week 4: Stacks and queues"></div>
          <div class="field"><label for="sc">Course</label><select class="in" id="sc" name="course_id" required><?= options($myCourses, $draft['course_id'] ?? $d['course_id'] ?? null, 'id', 'n', 'Choose a course') ?></select></div></div>
        <label class="chk"><input type="checkbox" name="shared" value="1"<?= ($draft['shared'] ?? (!$d || $d['visibility'] === 'shared')) ? ' checked' : '' ?>>Share with students in this course</label>
        <div class="rowact"><button class="btn line sm" type="button" data-sheet="tpl-import" data-title="Import cards" data-size="wide"><?= ic('upload') ?>Import from text</button><button class="btn line sm" type="button" id="setSwap" title="Swap every term and definition"><?= ic('refresh') ?>Swap terms and definitions</button></div>
      </section>
      <ol class="setcards" id="setCards">
        <?php foreach ($cards as $c): ?><li class="setcard"><input type="hidden" name="cid[]" value="<?= (int)$c['id'] ?>">
          <span class="setn" aria-hidden="true"></span>
          <label><span class="vh">Term</span><textarea class="in" name="front[]" rows="1" placeholder="Term" maxlength="2000"><?= e($c['front']) ?></textarea><small>Term</small></label>
          <label><span class="vh">Definition</span><textarea class="in" name="back[]" rows="1" placeholder="Definition" maxlength="2000"><?= e($c['back']) ?></textarea><small>Definition</small></label>
          <button class="icobtn setdel" type="button" aria-label="Remove this card" title="Remove"><?= ic('trash') ?></button></li>
        <?php endforeach; ?>
      </ol>
      <button class="setadd" type="button" id="setAdd"><?= ic('plus') ?>Add a card</button>
      <div class="setbar"><span id="setCount"></span><button class="btn" type="submit"><?= ic('check') ?><?= $d ? 'Save set' : 'Create set' ?></button></div>
    </form>
    <template id="tpl-import"><form class="card mform" id="setImport"><h2>Import cards</h2>
      <p class="note">Paste from Word, Excel, Google Docs or Quizlet: one card per line, the term and definition separated as you choose below.</p>
      <div class="field"><label for="imt">Your text</label><textarea class="in" id="imt" rows="9" placeholder="Stack	A last-in, first-out list&#10;Queue	A first-in, first-out list"></textarea></div>
      <div class="f2"><div class="field"><label for="ims">Between term and definition</label><select class="in" id="ims"><option value="tab">Tab (from Excel / Quizlet)</option><option value="dash"> - (dash)</option><option value="colon">: (colon)</option><option value="comma">, (comma)</option></select></div>
        <div class="field"><label for="imm">Add the cards</label><select class="in" id="imm"><option value="add">After the cards already here</option><option value="replace">Instead of the cards here</option></select></div></div>
      <p class="note" id="imPrev"></p><button class="btn" type="submit">Import</button></form></template>
    <?php include ROOT . '/includes/dashboard_footer.php'; exit;
}

/* ---------- one set ---------- */
$id = (int)($_GET['id'] ?? 0);
if ($id && ($d = row('SELECT d.*,c.code,c.title course FROM decks d LEFT JOIN courses c ON c.id=d.course_id WHERE d.id=? AND ' . in_school('d') . ' AND (d.owner_id=? OR ' . $scope('d.course_id') . ')', [$id, $uid]))) {
    $canEdit = (bool)$editable($id);
    $cards = rows('SELECT front,back FROM cards WHERE deck_id=? ORDER BY position,id', [$id]);
    $st = row("SELECT (SELECT COUNT(DISTINCT p.user_id) FROM card_progress p JOIN cards k ON k.id=p.card_id WHERE k.deck_id=?) learners,
        (SELECT COUNT(*) FROM card_reviews r JOIN cards k ON k.id=r.card_id WHERE k.deck_id=? AND r.reviewed_at>=NOW()-INTERVAL 30 DAY) reviews,
        (SELECT COUNT(*) FROM deck_test_attempts WHERE deck_id=? AND mode='test') tests,
        (SELECT ROUND(100*SUM(correct)/NULLIF(SUM(total),0)) FROM deck_test_attempts WHERE deck_id=? AND mode='test') score", [$id, $id, $id, $id]);
    // the terms students miss most (Again ratings in review and Learn)
    $hard = rows('SELECT k.front,COUNT(*) misses FROM card_reviews r JOIN cards k ON k.id=r.card_id WHERE k.deck_id=? AND r.rating=1 GROUP BY k.id,k.front ORDER BY misses DESC LIMIT 5', [$id]);
    $title = $d['title'];
    include ROOT . '/includes/dashboard_header.php';
    echo page_head($d['title'], ($d['code'] ? $d['code'] . ' · ' : '') . count($cards) . ' cards · ' . ($d['visibility'] === 'shared' && $d['status'] === 'active' ? 'shared with students' : 'private'),
        ($canEdit ? '<a class="btn sm" href="' . e(url($act . '?edit=' . $id)) . '">' . ic('edit') . 'Edit</a>' : '') . '<a class="btn sm line" href="' . e(url('lecturer/tasks.php')) . '">Set as a task</a><a class="btn sm line" href="' . e(url($act)) . '">All sets</a>');
    ?>
    <section class="kpis">
      <?= stat_tile(number_format((int)$st['learners']), 'Students studying it') ?>
      <?= stat_tile(number_format((int)$st['reviews']), 'Reviews, 30 days') ?>
      <?= stat_tile(number_format((int)$st['tests']), 'Tests taken') ?>
      <?= stat_tile($st['score'] === null ? '–' : (int)$st['score'] . '%', 'Average test score') ?>
    </section>
    <div class="two">
      <section class="card dt"><?= dt_head('Cards', count($cards) . ' terms') ?><div class="dtwrap"><table class="dtable"><thead><tr><th>Term</th><th>Definition</th></tr></thead><tbody>
        <?php foreach ($cards as $c): ?><tr><td class="dmain"><?= nl2br(e($c['front'])) ?></td><td><?= nl2br(e($c['back'])) ?></td></tr><?php endforeach; ?></tbody></table></div></section>
      <section class="card dt"><?= dt_head('Hardest terms', 'Most often marked "Again" by students') ?><div class="dtwrap"><table class="dtable"><thead><tr><th>Term</th><th class="num">Misses</th></tr></thead><tbody>
        <?php foreach ($hard as $h): ?><tr><td class="dmain"><?= e(mb_strimwidth($h['front'], 0, 120, '…')) ?></td><td class="num"><span class="pill bad"><?= (int)$h['misses'] ?></span></td></tr><?php endforeach; if (!$hard) echo dt_empty(2, 'No misses recorded yet.'); ?></tbody></table></div>
        <?php if ($canEdit): ?><div class="rowact" style="padding:12px 18px 18px"><?= row_form($act, ['a' => 'share', 'id' => $id], $d['visibility'] === 'shared' ? 'Make private' : 'Share with students', 'btn sm line') ?><?= row_form($act, ['a' => 'delete', 'id' => $id], 'Delete set', 'btn sm danger', 'Delete this set? Students lose their progress on it.') ?></div><?php endif; ?></section>
    </div>
    <?php include ROOT . '/includes/dashboard_footer.php'; exit;
}

/* ---------- all sets ---------- */
$q = trim((string)($_GET['q'] ?? '')); $cf = (int)($_GET['course'] ?? 0);
$w = [in_school('d'), "d.status='active'", '(d.owner_id=? OR ' . $scope('d.course_id') . ')']; $a = [$uid];
if ($q !== '') { $w[] = 'd.title LIKE ?'; $a[] = "%$q%"; } if ($cf) { $w[] = 'd.course_id=?'; $a[] = $cf; }
$W = implode(' AND ', $w); $per = per_page(15); $page = page_no(); $total = (int)val("SELECT COUNT(*) FROM decks d WHERE $W", $a);
$list = rows("SELECT d.*,c.code,(SELECT COUNT(DISTINCT p.user_id) FROM card_progress p JOIN cards k ON k.id=p.card_id WHERE k.deck_id=d.id) learners,
    (SELECT ROUND(100*SUM(t.correct)/NULLIF(SUM(t.total),0)) FROM deck_test_attempts t WHERE t.deck_id=d.id AND t.mode='test') score
    FROM decks d LEFT JOIN courses c ON c.id=d.course_id WHERE $W ORDER BY d.owner_id=$uid DESC, d.id DESC LIMIT $per OFFSET " . (($page - 1) * $per), $a);
include ROOT . '/includes/dashboard_header.php';
echo page_head('Flashcard sets', 'Make term and definition sets for your courses. Students study them with Flashcards, Learn, Test and Smart review.', $myCourses ? '<a class="btn" href="' . e(url($act . '?new=1')) . '">' . ic('plus') . 'New set</a>' : '');
?>
<section class="card dt"><?= dt_head('Sets in your courses', number_format($total) . ' set' . ($total === 1 ? '' : 's') . ', yours first') ?>
  <form class="toolbar" method="get"><label class="search"><?= ic('search', 'i') ?><span class="vh">Search</span><input name="q" value="<?= e($q) ?>" type="search" placeholder="Set name"></label>
    <select class="in" name="course" aria-label="Course"><?= options($myCourses, $cf ?: null, 'id', 'n', 'All my courses') ?></select><button class="btn sm">Filter</button></form>
  <div class="dtwrap"><table class="dtable"><thead><tr><th>Set</th><th>Course</th><th class="num">Cards</th><th>Who sees it</th><th class="num">Students</th><th class="num">Avg test</th><th data-nosort><span class="vh">Actions</span></th></tr></thead><tbody>
  <?php foreach ($list as $r): $href = url($act . '?id=' . $r['id']); $mine = (int)$r['owner_id'] === $uid; ?><tr data-href="<?= e($href) ?>">
    <td class="dmain"><a class="dtt" href="<?= e($href) ?>"><?= e($r['title']) ?></a><span class="ts"><?= $mine ? 'Yours' : ($r['ai_generated'] ? 'Made by PrepDeck AI' : 'By a colleague') ?></span></td>
    <td class="nw"><?= $r['code'] ? '<span class="code">' . e($r['code']) . '</span>' : '–' ?></td><td class="num"><?= (int)$r['card_count'] ?></td>
    <td><?= $r['visibility'] === 'shared' ? '<span class="pill ok">Students</span>' : '<span class="pill new">Only you</span>' ?></td>
    <td class="num"><?= number_format((int)$r['learners']) ?></td><td class="num"><?= $r['score'] === null ? '<span class="muted">–</span>' : (int)$r['score'] . '%' ?></td>
    <td class="act"><a class="btn sm line" href="<?= e($href) ?>">Open</a><?= $mine || Auth::can('decks') ? '<a class="btn sm line" href="' . e(url($act . '?edit=' . $r['id'])) . '">Edit</a>' : '' ?></td></tr>
  <?php endforeach; if (!$list) echo dt_empty(7, $myCourses ? 'No sets yet. Press New set to make the first one.' : 'Pick the courses you teach in My courses first.'); ?></tbody></table></div>
  <?= dt_foot($total, $per, $page, ['q' => $q, 'course' => $cf ?: '']) ?></section>
<?php include ROOT . '/includes/dashboard_footer.php'; ?>
