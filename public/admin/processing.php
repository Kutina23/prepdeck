<?php
require __DIR__ . '/../../app/bootstrap.php';
$u = require_perm('approve'); $adminArea = true; $active = 'processing'; $title = 'AI processing'; $scripts = ['forms.js', 'process.js'];
if (is_post()) {
    form_guard(); $a = $_POST['a'] ?? ''; $id = (int)($_POST['id'] ?? 0);
    $own = in_array($a, ['retry', 'delete'], true) ? in_my_school('processing_jobs', $id) : (in_array($a, ['q_status', 'q_save'], true) ? (bool)val('SELECT COUNT(*) FROM exam_questions q WHERE q.id=? AND ' . questions_in_school('q'), [$id]) : true);
    if ($a === 'publish_all') $own = ($t = Validator::in($_POST['type'] ?? '', ['paper', 'material', 'recording'])) && in_my_school(JobQueue::table($t), (int)($_POST['sid'] ?? 0));
    if (!$own) { flash('bad', 'Not found in this school.'); redirect_self_or_ajax(); }
    if ($a === 'retry') { $j = row('SELECT * FROM processing_jobs WHERE id=?', [$id]); if ($j) { q("UPDATE processing_jobs SET status='queued',stage='queued',progress=0,message=NULL,error=NULL,attempts=0 WHERE id=?", [$id]); JobQueue::setSourceStatus($j['source_type'], (int)$j['source_id'], 'queued'); JobQueue::kick(); Audit::log("retried job $id", 'job', $id); flash('ok', 'Job queued again.'); } }
    elseif ($a === 'delete' && Auth::can('admin')) { q('DELETE FROM processing_jobs WHERE id=?', [$id]); flash('ok', 'Job removed.'); }
    elseif ($a === 'q_status') { $s = Validator::in($_POST['status'] ?? '', ['published', 'hidden', 'draft']); if ($s) q('UPDATE exam_questions SET status=? WHERE id=?', [$s, $id]); flash('ok', 'Question ' . $s . '.'); }
    elseif ($a === 'q_save') { q("UPDATE exam_questions SET question=?, answer=?, confidence='high', edited_by=? WHERE id=?", [(string)$_POST['question'], (string)$_POST['answer'], $u['id'], $id]); Audit::log("edited AI answer $id", 'question', $id); flash('ok', 'Saved.'); }
    elseif ($a === 'publish_all') {
        $type = Validator::in($_POST['type'] ?? '', ['paper', 'material', 'recording']); $sid = (int)($_POST['sid'] ?? 0);
        if ($type) { $col = JobQueue::col($type);
            q("UPDATE exam_questions SET status='published' WHERE source_type=? AND source_id=? AND status='draft' AND confidence<>'low'", [$type, $sid]);
            q("UPDATE decks SET status='active' WHERE $col=? AND ai_generated=1", [$sid]); q("UPDATE study_guides SET status='active' WHERE $col=? AND ai_generated=1", [$sid]);
            if ($type === 'paper') q('UPDATE papers SET has_answers=1 WHERE id=?', [$sid]);
            Audit::log("published AI content for $type $sid", $type, $sid); flash('ok', 'Published. Low-confidence answers stay as drafts for a manual check.'); }
    }
    redirect_self_or_ajax();
}
$type = Validator::in($_GET['type'] ?? '', ['paper', 'material', 'recording']); $sid = (int)($_GET['sid'] ?? 0);
$act = 'admin/processing.php';
$confPill = fn(string $c) => '<span class="pill ' . ($c === 'low' ? 'bad' : ($c === 'high' ? 'ok' : 'wait')) . '">' . e(ucfirst($c)) . '</span>';
if ($type && $sid && !in_my_school(JobQueue::table($type), $sid)) { flash('bad', 'Not found in this school.'); redirect('admin/processing.php'); }
if ($type && $sid) {
    $title = 'Review AI output'; $f = $_GET['f'] ?? ''; $per = per_page(25); $page = page_no();
    $w = 'source_type=? AND source_id=?'; $a = [$type, $sid]; if (in_array($f, ['draft', 'published', 'hidden'], true)) { $w .= ' AND status=?'; $a[] = $f; }
    $total = (int)val("SELECT COUNT(*) FROM exam_questions WHERE $w", $a); $all = (int)val('SELECT COUNT(*) FROM exam_questions WHERE source_type=? AND source_id=?', [$type, $sid]);
    $qs = rows("SELECT * FROM exam_questions WHERE $w ORDER BY kind,id LIMIT $per OFFSET " . (($page - 1) * $per), $a);
    $col = JobQueue::col($type); $decks = rows("SELECT id,title,card_count,status FROM decks WHERE $col=?", [$sid]); $guides = rows("SELECT id,title,status FROM study_guides WHERE $col=?", [$sid]);
    include ROOT . '/includes/dashboard_header.php';
    echo page_head('Review AI output', ucfirst($type) . ' #' . $sid . ' · ' . $all . ' questions', '<a class="btn sm line" href="' . e(url('admin/processing.php')) . '">All jobs</a>');
    ?>
    <section class="card"><div class="rowact"><?= row_form($act, ['a' => 'publish_all', 'type' => $type, 'sid' => $sid], 'Publish all confident items', 'btn', 'Publish every draft that is not low confidence, plus the decks and guides?') ?></div>
      <p class="note" style="margin-top:8px"><?= count($decks) ?> deck(s): <?php foreach ($decks as $d) echo e($d['title']) . ' (' . e($d['status']) . ') '; ?> &middot; <?= count($guides) ?> guide(s)</p></section>
    <section class="card dt">
      <?= dt_head('Questions', number_format($total) . ' in this view') ?>
      <div class="tabs"><?php foreach (['' => 'All', 'draft' => 'Drafts', 'published' => 'Published', 'hidden' => 'Hidden'] as $k => $l): ?><a class="tab" href="?type=<?= e($type) ?>&sid=<?= $sid ?>&f=<?= $k ?>" aria-selected="<?= $f === $k ? 'true' : 'false' ?>"><?= $l ?></a><?php endforeach; ?></div>
      <div class="dtwrap"><table class="dtable"><thead><tr><th class="num">No.</th><th>Question</th><th>Kind</th><th>Topic</th><th>Confidence</th><th>Status</th><th data-nosort><span class="vh">Actions</span></th></tr></thead><tbody>
      <?php foreach ($qs as $q): ?><tr>
        <td class="num"><?= e((string)$q['number']) ?></td><td class="dmain"><span class="clip"><?= e(mb_strimwidth($q['question'], 0, 160, '…')) ?></span></td>
        <td><span class="pill role"><?= e($q['kind']) ?></span></td><td class="muted"><?= e($q['topic'] ?: '–') ?></td><td><?= $confPill($q['confidence']) ?></td><td><?= status_pill($q['status']) ?></td>
        <td class="act"><div class="rowact"><?= modal_btn('tpl-q' . $q['id'], 'Question ' . $q['number'] . ' (' . $q['kind'] . ')', 'Edit', 'btn sm line', 'edit', 'wide') ?>
          <?= $q['status'] !== 'published' ? row_form($act, ['a' => 'q_status', 'id' => $q['id'], 'status' => 'published'], 'Publish', 'btn sm') : row_form($act, ['a' => 'q_status', 'id' => $q['id'], 'status' => 'hidden'], 'Hide', 'btn sm danger') ?></div>
          <template id="tpl-q<?= $q['id'] ?>">
            <form class="card mform" method="post" data-action="<?= $act ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $q['id'] ?>"><input type="hidden" name="a" value="q_save"><h2>Edit</h2>
            <p class="note"><?= status_pill($q['status']) ?> <?= $confPill($q['confidence']) ?> <?= $q['topic'] ? e($q['topic']) : '' ?></p>
            <div class="field"><label for="qq<?= $q['id'] ?>">Question</label><textarea class="in" id="qq<?= $q['id'] ?>" name="question" rows="4"><?= e($q['question']) ?></textarea></div>
            <div class="field"><label for="qa<?= $q['id'] ?>">Answer</label><textarea class="in" id="qa<?= $q['id'] ?>" name="answer" rows="8"><?= e($q['answer']) ?></textarea></div>
            <button class="btn" type="submit">Save edits</button></form>
            <?php if ($q['status'] !== 'published') echo row_form($act, ['a' => 'q_status', 'id' => $q['id'], 'status' => 'published'], 'Publish', 'btn sm line'); ?>
            <?php if ($q['status'] !== 'hidden') echo row_form($act, ['a' => 'q_status', 'id' => $q['id'], 'status' => 'hidden'], 'Hide', 'btn sm danger'); ?>
          </template></td></tr>
      <?php endforeach; if (!$qs) echo dt_empty(7, 'No questions here.'); ?></tbody></table></div>
      <?= dt_foot($total, $per, $page, ['type' => $type, 'sid' => $sid, 'f' => $f]) ?>
    </section>
    <?php include ROOT . '/includes/dashboard_footer.php'; exit;
}
$st = $_GET['status'] ?? ''; $src = Validator::in($_GET['src'] ?? '', ['paper', 'material', 'recording', 'outline']);
$w = [in_school('j')]; $a = []; if (in_array($st, ['queued', 'running', 'done', 'failed'], true)) { $w[] = 'j.status=?'; $a[] = $st; } if ($src) { $w[] = 'j.source_type=?'; $a[] = $src; }
$W = implode(' AND ', $w); $per = per_page(20); $page = page_no(); $total = (int)val("SELECT COUNT(*) FROM processing_jobs j WHERE $W", $a);
$jobs = rows("SELECT j.*,u.full_name,(SELECT COUNT(*) FROM exam_questions q WHERE q.source_type=j.source_type AND q.source_id=j.source_id AND q.status='draft') drafts FROM processing_jobs j JOIN users u ON u.id=j.owner_id WHERE $W ORDER BY j.id DESC LIMIT $per OFFSET " . (($page - 1) * $per), $a);
$sum = row("SELECT COUNT(*) n, COALESCE(SUM(tokens_in),0) ti, COALESCE(SUM(tokens_out),0) tou, SUM(status='failed') f, SUM(status IN ('queued','running')) r FROM processing_jobs WHERE " . in_school());
include ROOT . '/includes/dashboard_header.php';
echo page_head('AI processing', 'Every uploaded paper, note, recording and course outline that PrepDeck AI has read.');
?>
<section class="kpis"><div class="card kpi"><b><?= (int)$sum['n'] ?></b><small>Jobs in total</small></div><div class="card kpi"><b><?= (int)$sum['r'] ?></b><small>Running or queued</small></div><div class="card kpi"><b style="color:<?= $sum['f'] ? 'var(--bad)' : 'inherit' ?>"><?= (int)$sum['f'] ?></b><small>Failed</small></div><div class="card kpi"><b><?= number_format(($sum['ti'] + $sum['tou']) / 1000, 1) ?>k</b><small>Tokens used</small></div></section>
<div class="tabs" role="navigation"><?php foreach (['' => 'All', 'queued' => 'Queued', 'running' => 'Running', 'failed' => 'Failed', 'done' => 'Done'] as $k => $l): ?><a class="tab" href="?status=<?= $k ?>" aria-selected="<?= $st === $k ? 'true' : 'false' ?>"><?= $l ?></a><?php endforeach; ?></div>
<section class="card dt">
  <?= dt_head('Jobs', 'Jobs run in the background: keep php bin/worker.php running (see README), or the next upload starts one automatically.') ?>
  <form class="toolbar" method="get"><input type="hidden" name="status" value="<?= e($st) ?>"><select class="in" name="src" aria-label="Source"><option value="">Any source</option><?php foreach (['paper' => 'Papers', 'material' => 'Student notes', 'recording' => 'Recordings', 'outline' => 'Course outlines'] as $k => $l): ?><option value="<?= $k ?>"<?= $src === $k ? ' selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select><button class="btn sm">Filter</button></form>
  <div class="dtwrap"><table class="dtable"><thead><tr><th class="num">Job</th><th>Source</th><th>Owner</th><th>Stage</th><th class="num">Tokens</th><th>Status</th><th>Started</th><th data-nosort><span class="vh">Actions</span></th></tr></thead><tbody>
  <?php foreach ($jobs as $j): ?><tr>
    <td class="num">#<?= $j['id'] ?></td>
    <td class="dmain"><span class="dtt"><?= e(ucfirst($j['source_type'])) ?> #<?= (int)$j['source_id'] ?></span><span class="ts"><?= e($j['detected_kind'] ?: $j['requested_kind']) ?></span></td>
    <td><?= e($j['full_name']) ?></td>
    <td><span class="clip"><?= e($j['message'] ?: $j['stage']) ?></span><?php if ($j['status'] === 'running'): ?><div class="progressbar" style="margin-top:6px;min-width:100px"><i style="width:<?= (int)$j['progress'] ?>%"></i></div><?php endif; ?><?php if ($j['error']): ?><span class="ts" style="color:var(--bad)"><?= e(mb_strimwidth($j['error'], 0, 80, '…')) ?></span><?php endif; ?></td>
    <td class="num"><?= number_format($j['tokens_in'] + $j['tokens_out']) ?></td>
    <td><div class="tags"><?= status_pill($j['status']) ?><?php if ($j['drafts']): ?><span class="pill wait"><?= (int)$j['drafts'] ?> drafts</span><?php endif; ?></div></td>
    <td class="nw muted" data-v="<?= e($j['created_at']) ?>"><?= e(ago($j['created_at'])) ?></td>
    <td class="act"><div class="rowact">
      <?php if (in_array($j['status'], ['queued', 'running'], true)) echo modal_btn('tpl-live' . $j['id'], 'Live: ' . ucfirst($j['source_type']) . ' #' . $j['source_id'], 'Watch live', 'btn sm', '', 'wide'); ?>
      <?php if (in_array($j['status'], ['queued', 'running'], true)): ?><template id="tpl-live<?= $j['id'] ?>"><div data-live-job="<?= $j['id'] ?>"></div></template><?php endif; ?>
      <?php if ($j['error']) echo modal_btn('tpl-err' . $j['id'], 'Job #' . $j['id'] . ' error', 'Error', 'btn sm line'); ?>
      <a class="btn sm line" href="<?= $j['source_type'] === 'outline' ? e(url('admin/teaching.php?tab=outlines&id=' . (int)$j['source_id'])) : '?type=' . e($j['source_type']) . '&sid=' . (int)$j['source_id'] ?>">Review</a>
      <?= row_form($act, ['a' => 'retry', 'id' => $j['id']], 'Retry', 'btn sm line') ?>
      <?php if (Auth::can('admin')) echo row_form($act, ['a' => 'delete', 'id' => $j['id']], 'Remove', 'btn sm danger', 'Remove this job record?'); ?></div>
      <?php if ($j['error']): ?><template id="tpl-err<?= $j['id'] ?>"><p class="note"><?= e(ucfirst($j['source_type'])) ?> #<?= (int)$j['source_id'] ?> &middot; stage <?= e($j['stage']) ?> &middot; <?= (int)$j['attempts'] ?> attempt(s)</p><div class="mbody mono" style="text-transform:none;letter-spacing:0;line-height:1.5"><?= e($j['error']) ?></div><?= row_form($act, ['a' => 'retry', 'id' => $j['id']], 'Retry job', 'btn sm') ?></template><?php endif; ?></td></tr>
  <?php endforeach; if (!$jobs) echo dt_empty(8, 'No jobs yet.'); ?></tbody></table></div>
  <?= dt_foot($total, $per, $page, ['status' => $st, 'src' => $src]) ?>
</section>
<?php include ROOT . '/includes/dashboard_footer.php'; ?>
