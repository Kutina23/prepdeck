<?php
require __DIR__ . '/../../app/bootstrap.php';
$u = require_login(); if (!Auth::can('review') && !Auth::can('approve')) { http_response_code(403); exit('No access.'); }
$staffArea = true; $active = 'review'; $title = 'Review queue'; $scripts = ['forms.js'];
$ids = staff_course_ids(); if (Auth::can('approve')) $ids = null;
$scope = in_school('p') . ' AND ' . staff_course_sql('p.course_id');
function in_scope(int $paperId, ?array $ids): ?array { $p = row('SELECT p.*,c.code FROM papers p JOIN courses c ON c.id=p.course_id WHERE p.id=? AND ' . in_school('p'), [$paperId]); return ($p && ($ids === null || in_array((int)$p['course_id'], $ids, true))) ? $p : null; }
if (is_post()) {
    form_guard(); $a = $_POST['a'] ?? ''; $id = (int)($_POST['id'] ?? 0);
    if (in_array($a, ['approve', 'reject'], true)) {
        $p = in_scope($id, $ids);
        if (!$p || $p['status'] === 'published' && $a === 'approve') flash('bad', 'Paper not found in your courses.');
        else { $new = $a === 'approve' ? 'published' : 'rejected'; q('UPDATE papers SET status=?, reject_reason=?, reviewed_by=? WHERE id=?', [$new, $a === 'reject' ? (Validator::str($_POST['reason'] ?? '', 1, 250) ?? 'Does not meet the guidelines') : null, $u['id'], $id]);
            if ($new === 'published' && $p['status'] !== 'published') q('UPDATE courses SET paper_count=paper_count+1 WHERE id=?', [$p['course_id']]);
            Audit::log("paper $id $new (staff review)", 'paper', $id); flash('ok', 'Paper ' . $new . '.'); }
    } elseif (in_array($a, ['q_save', 'q_status'], true)) {
        $q = row("SELECT * FROM exam_questions WHERE id=? AND source_type='paper'", [$id]);
        if (!$q || !in_scope((int)$q['source_id'], $ids)) flash('bad', 'Question not found in your courses.');
        elseif ($a === 'q_save') { q("UPDATE exam_questions SET question=?, answer=?, confidence='high', edited_by=? WHERE id=?", [(string)$_POST['question'], (string)$_POST['answer'], $u['id'], $id]); flash('ok', 'Saved.'); }
        else { $s = Validator::in($_POST['status'] ?? '', ['published', 'hidden', 'draft']); if ($s) q('UPDATE exam_questions SET status=? WHERE id=?', [$s, $id]); flash('ok', 'Question ' . $s . '.'); }
    } elseif ($a === 'publish_all') {
        $p = in_scope($id, $ids);
        if ($p) { q("UPDATE exam_questions SET status='published' WHERE source_type='paper' AND source_id=? AND status='draft' AND confidence<>'low'", [$id]);
            q("UPDATE decks SET status='active' WHERE paper_id=? AND ai_generated=1", [$id]); q("UPDATE study_guides SET status='active' WHERE paper_id=? AND ai_generated=1", [$id]); q('UPDATE papers SET has_answers=1 WHERE id=?', [$id]);
            Audit::log("published AI content for paper $id (staff review)", 'paper', $id); flash('ok', 'Published. Low-confidence answers stay as drafts until you edit them.'); }
    }
    redirect_self_or_ajax();
}
$pid = (int)($_GET['paper'] ?? 0);
$act = 'staff/review.php';
$confPill = fn(string $c) => '<span class="pill ' . ($c === 'low' ? 'bad' : ($c === 'high' ? 'ok' : 'wait')) . '">' . e(ucfirst($c)) . '</span>';
include ROOT . '/includes/dashboard_header.php';
if ($pid) {
    $p = in_scope($pid, $ids); if (!$p) { echo empty_state('Paper not found in your courses.', '<a class="btn sm" href="' . e(url('staff/review.php')) . '">Back</a>'); include ROOT . '/includes/dashboard_footer.php'; exit; }
    $f = $_GET['f'] ?? ''; $per = per_page(25); $page = page_no();
    $w = "source_type='paper' AND source_id=?"; $a = [$pid]; if (in_array($f, ['draft', 'published', 'hidden'], true)) { $w .= ' AND status=?'; $a[] = $f; }
    $total = (int)val("SELECT COUNT(*) FROM exam_questions WHERE $w", $a);
    $qs = rows("SELECT * FROM exam_questions WHERE $w ORDER BY kind,id LIMIT $per OFFSET " . (($page - 1) * $per), $a);
    echo page_head($p['title'], $p['code'] . ' · AI answers for review', '<a class="btn sm line" href="' . e(url('staff/review.php')) . '">Back</a>');
    echo '<section class="card">' . row_form($act, ['a' => 'publish_all', 'id' => $pid], 'Publish all confident items', 'btn', 'Publish every draft answer that is not low confidence?') . '</section>'; ?>
    <section class="card dt">
      <?= dt_head('AI answers', number_format($total) . ' in this view') ?>
      <div class="tabs"><?php foreach (['' => 'All', 'draft' => 'Drafts', 'published' => 'Published', 'hidden' => 'Hidden'] as $k => $l): ?><a class="tab" href="?paper=<?= $pid ?>&f=<?= $k ?>" aria-selected="<?= $f === $k ? 'true' : 'false' ?>"><?= $l ?></a><?php endforeach; ?></div>
      <div class="dtwrap"><table class="dtable"><thead><tr><th class="num">No.</th><th>Question</th><th>Kind</th><th>Confidence</th><th>Status</th><th data-nosort><span class="vh">Actions</span></th></tr></thead><tbody>
      <?php foreach ($qs as $q): ?><tr>
        <td class="num"><?= e((string)$q['number']) ?></td><td class="dmain"><span class="clip"><?= e(mb_strimwidth($q['question'], 0, 160, '…')) ?></span></td>
        <td><span class="pill role"><?= e($q['kind']) ?></span></td><td><?= $confPill($q['confidence']) ?></td><td><?= status_pill($q['status']) ?></td>
        <td class="act"><div class="rowact"><?= modal_btn('tpl-q' . $q['id'], 'Question ' . $q['number'] . ' (' . $q['kind'] . ')', 'Edit', 'btn sm line', 'edit', 'wide') ?>
          <?= $q['status'] !== 'published' ? row_form($act, ['a' => 'q_status', 'id' => $q['id'], 'status' => 'published'], 'Publish', 'btn sm') : row_form($act, ['a' => 'q_status', 'id' => $q['id'], 'status' => 'hidden'], 'Hide', 'btn sm danger') ?></div>
          <template id="tpl-q<?= $q['id'] ?>">
            <form class="card mform" method="post" data-action="<?= $act ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $q['id'] ?>"><input type="hidden" name="a" value="q_save"><h2>Edit</h2>
            <p class="note"><?= status_pill($q['status']) ?> <?= $confPill($q['confidence']) ?></p>
            <div class="field"><label for="qq<?= $q['id'] ?>">Question</label><textarea class="in" id="qq<?= $q['id'] ?>" name="question" rows="4"><?= e($q['question']) ?></textarea></div>
            <div class="field"><label for="qa<?= $q['id'] ?>">Answer</label><textarea class="in" id="qa<?= $q['id'] ?>" name="answer" rows="8"><?= e($q['answer']) ?></textarea></div>
            <button class="btn" type="submit">Save edits</button></form>
            <?php if ($q['status'] !== 'published') echo row_form($act, ['a' => 'q_status', 'id' => $q['id'], 'status' => 'published'], 'Publish', 'btn sm line'); ?>
            <?php if ($q['status'] !== 'hidden') echo row_form($act, ['a' => 'q_status', 'id' => $q['id'], 'status' => 'hidden'], 'Hide', 'btn sm danger'); ?>
          </template></td></tr>
      <?php endforeach; if (!$qs) echo dt_empty(6, 'No AI answers here.'); ?></tbody></table></div>
      <?= dt_foot($total, $per, $page, ['paper' => $pid, 'f' => $f]) ?>
    </section>
    <?php include ROOT . '/includes/dashboard_footer.php'; exit;
}
echo page_head('Review queue', 'Papers waiting for approval and AI answers waiting to be checked.');
$per = per_page(15); $page = page_no();
$total = (int)val("SELECT COUNT(*) FROM papers p WHERE p.status='pending' AND $scope");
$pend = rows("SELECT p.id,p.title,p.exam_year,p.semester,p.created_at,c.code,u.full_name,u.role_id FROM papers p JOIN courses c ON c.id=p.course_id JOIN users u ON u.id=p.uploaded_by WHERE p.status='pending' AND $scope ORDER BY p.id LIMIT $per OFFSET " . (($page - 1) * $per));
$drafts = rows("SELECT p.id,p.title,c.code,(SELECT COUNT(*) FROM exam_questions q WHERE q.source_type='paper' AND q.source_id=p.id AND q.status='draft') d FROM papers p JOIN courses c ON c.id=p.course_id WHERE $scope HAVING d>0 ORDER BY p.id DESC LIMIT 30");
?>
<section class="card dt">
  <?= dt_head('Papers to approve', number_format($total) . ' waiting') ?>
  <div class="dtwrap"><table class="dtable"><thead><tr><th>Paper</th><th>Course</th><th>Year</th><th>Uploaded by</th><th>Waiting</th><th data-nosort><span class="vh">Actions</span></th></tr></thead><tbody>
  <?php foreach ($pend as $p): ?><tr>
    <td class="dmain"><a class="dtt" href="<?= e(url('dashboard/papers.php?id=' . $p['id'])) ?>"><?= e($p['title']) ?></a></td><td class="nw"><span class="code"><?= e($p['code']) ?></span></td><td class="nw"><?= (int)$p['exam_year'] ?> S<?= (int)$p['semester'] ?></td>
    <td><?= e($p['full_name']) ?><?= (int)$p['role_id'] === 2 ? '<span class="ts">Class rep</span>' : '' ?></td><td class="nw muted" data-v="<?= e($p['created_at']) ?>"><?= e(ago($p['created_at'])) ?></td>
    <td class="act"><div class="rowact"><a class="btn sm line" href="<?= e(url('dashboard/papers.php?id=' . $p['id'])) ?>">Open</a>
      <?= row_form($act, ['a' => 'approve', 'id' => $p['id']], 'Approve', 'btn sm') ?><?= modal_btn('tpl-rj' . $p['id'], 'Reject ' . $p['title'], 'Reject', 'btn sm danger') ?></div>
      <template id="tpl-rj<?= $p['id'] ?>">
      <form class="card mform" method="post" data-action="<?= $act ?>"><?= csrf_field() ?><input type="hidden" name="a" value="reject"><input type="hidden" name="id" value="<?= $p['id'] ?>"><h2>Reject</h2>
        <p class="note">The uploader sees this reason.</p><div class="field"><label for="rr<?= $p['id'] ?>">Reason</label><input class="in" id="rr<?= $p['id'] ?>" name="reason" placeholder="Unreadable scan, wrong course…" required maxlength="250"></div><button class="btn" type="submit">Reject paper</button></form>
      </template></td></tr>
  <?php endforeach; if (!$pend) echo dt_empty(6, 'Nothing waiting.'); ?></tbody></table></div>
  <?= dt_foot($total, $per, $page) ?>
</section>
<section class="card dt">
  <?= dt_head('AI answers to check', 'Papers with draft answers') ?>
  <div class="dtwrap"><table class="dtable"><thead><tr><th>Paper</th><th>Course</th><th class="num">Drafts</th><th data-nosort><span class="vh">Actions</span></th></tr></thead><tbody>
  <?php foreach ($drafts as $d): $href = url('staff/review.php?paper=' . $d['id']); ?><tr data-href="<?= e($href) ?>"><td class="dmain"><a class="dtt" href="<?= e($href) ?>"><?= e($d['title']) ?></a></td><td class="nw"><span class="code"><?= e($d['code']) ?></span></td><td class="num"><?= (int)$d['d'] ?></td><td class="act"><a class="btn sm line" href="<?= e($href) ?>">Check</a></td></tr>
  <?php endforeach; if (!$drafts) echo dt_empty(4, 'No drafts to check.'); ?></tbody></table></div>
</section>
<?php include ROOT . '/includes/dashboard_footer.php'; ?>
