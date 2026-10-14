<?php
require __DIR__ . '/../../app/bootstrap.php';
$u = require_perm('approve'); $adminArea = true; $active = 'recordings'; $title = 'Recordings'; $scripts = ['forms.js', 'process.js'];
if (is_post()) {
    form_guard(); $a = $_POST['a'] ?? ''; $id = (int)($_POST['id'] ?? 0);
    if (!in_my_school('recordings', $id)) { flash('bad', 'Recording not found.'); redirect_self_or_ajax(); }
    if ($a === 'save_transcript') { $t = Validator::str($_POST['transcript'] ?? '', 50, 500000); if ($t) { q("UPDATE recordings SET transcript=?, word_count=?, stt_status='ready', transcript_edited_by=? WHERE id=?", [$t, str_word_count($t), $u['id'], $id]); Audit::log('edited transcript', 'recording', $id); flash('ok', 'Transcript saved. Use Rebuild to refresh the study material.'); } else flash('bad', 'The transcript is too short.'); }
    redirect_self_or_ajax();
}
$id = (int)($_GET['id'] ?? 0);
include ROOT . '/includes/dashboard_header.php';
if ($id) {
    $r = row('SELECT r.*,u.full_name owner,c.code FROM recordings r JOIN users u ON u.id=r.owner_id LEFT JOIN courses c ON c.id=r.course_id WHERE r.id=? AND ' . in_school('r'), [$id]);
    if (!$r) { echo empty_state('Recording not found.', '<a class="btn sm" href="' . e(url('admin/recordings.php')) . '">Back</a>'); include ROOT . '/includes/dashboard_footer.php'; exit; }
    $decks = rows('SELECT d.id,d.title,d.status,d.card_count FROM decks d WHERE d.recording_id=?', [$id]); $guides = rows('SELECT id,title,status FROM study_guides WHERE recording_id=?', [$id]);
    $qs = rows("SELECT id,kind,question,confidence,status FROM exam_questions WHERE source_type='recording' AND source_id=? ORDER BY id", [$id]);
    $jobs = rows("SELECT * FROM processing_jobs WHERE source_type='recording' AND source_id=? ORDER BY id DESC LIMIT 5", [$id]);
    $hasAudio = $r['audio_path'] && !$r['audio_deleted_at']; $live = $jobs && in_array($jobs[0]['status'], ['queued', 'running'], true);
    echo page_head($r['title'], ($r['code'] ? $r['code'] . ' · ' : '') . 'by ' . $r['owner'] . ' · ' . ago($r['created_at']), '<a class="btn sm line" href="' . e(url('admin/recordings.php')) . '">All recordings</a>');
    echo ai_live('recording', $id);
    ?>
    <div class="grid2">
      <section class="stack">
        <div class="card"><h2>Audio</h2><p class="sub"><?= e($r['format'] ?: '') ?> &middot; <?= fmt_bytes($r['size_bytes']) ?> &middot; <?= $r['seconds'] ? floor($r['seconds'] / 60) . ' min' : 'length unknown' ?></p>
          <?php if ($hasAudio): ?><audio controls preload="none" style="width:100%;margin-top:10px" src="<?= e(url('api/recordings.php?a=audio&id=' . $id)) ?>"></audio><?php else: echo '<p class="note">Audio was deleted. The transcript and study material are kept.</p>'; endif; ?>
          <div class="rowact" style="margin-top:12px"><?php if ($hasAudio): ?><button class="btn sm line" data-api="recordings.php?a=retranscribe" data-body='{"id":<?= $id ?>}'>Transcribe again</button><button class="btn sm danger" data-api="recordings.php?a=delete_audio" data-body='{"id":<?= $id ?>}' data-confirm="Delete the audio file? The transcript stays.">Delete audio</button><?php endif; ?></div></div>
        <section class="card dt"><?= dt_head('Transcript', (int)$r['word_count'] . ' words' . ($r['stt_confidence'] ? ' · ' . (int)$r['stt_confidence'] . '% confidence' : ''), status_pill($r['stt_status']) . modal_btn('tpl-transcript', 'Edit transcript', 'Edit', 'btn sm line', 'edit', 'wide')) ?>
          <div style="padding:0 18px 18px;max-height:46vh;overflow:auto;white-space:pre-wrap;line-height:1.65"><?= $r['transcript'] ? e($r['transcript']) : '<span class="note">No transcript yet.</span>' ?></div></section>
      </section>
      <section class="stack">
        <div class="card"><h2>Publishing</h2><p class="note">Status <?= status_pill($r['status']) ?> &middot; <?= e($r['visibility'] === 'course' ? 'shared with the course' : 'private to the owner') ?></p>
          <div class="rowact" style="margin-top:10px"><button class="btn sm" data-api="recordings.php?a=publish" data-body='{"id":<?= $id ?>,"on":true}'>Publish with study material</button><button class="btn sm line" data-api="recordings.php?a=publish" data-body='{"id":<?= $id ?>,"on":false}'>Hide</button><button class="btn sm line" data-api="recordings.php?a=rebuild" data-body='{"id":<?= $id ?>}'>Rebuild material</button></div>
          <button class="btn sm danger" style="margin-top:10px" data-api="recordings.php?a=delete" data-body='{"id":<?= $id ?>}' data-redirect="admin/recordings.php" data-confirm="Delete the recording and everything built from it?">Delete everything</button></div>
        <section class="card dt"><?= dt_head('Built from this lecture') ?>
          <div class="dtwrap"><table class="dtable"><thead><tr><th>Item</th><th>Type</th><th>Status</th></tr></thead><tbody>
          <?php foreach ($decks as $d): ?><tr data-href="<?= e(url('dashboard/decks.php?id=' . $d['id'])) ?>"><td class="dmain"><a class="dtt" href="<?= e(url('dashboard/decks.php?id=' . $d['id'])) ?>"><?= e($d['title']) ?></a></td><td>Flashcards &middot; <?= (int)$d['card_count'] ?> cards</td><td><?= status_pill($d['status']) ?></td></tr><?php endforeach; ?>
          <?php foreach ($guides as $g): ?><tr data-href="<?= e(url('dashboard/guides.php?id=' . $g['id'])) ?>"><td class="dmain"><a class="dtt" href="<?= e(url('dashboard/guides.php?id=' . $g['id'])) ?>"><?= e($g['title']) ?></a></td><td>Study guide</td><td><?= status_pill($g['status']) ?></td></tr><?php endforeach; ?>
          <?php if ($qs): ?><tr data-href="<?= e(url('admin/processing.php?type=recording&sid=' . $id)) ?>"><td class="dmain"><a class="dtt" href="<?= e(url('admin/processing.php?type=recording&sid=' . $id)) ?>"><?= count($qs) ?> exam questions</a></td><td>Q&amp;A</td><td><span class="pill wait">Review</span></td></tr><?php endif; ?>
          <?php if (!$decks && !$guides && !$qs) echo dt_empty(3, 'Nothing built yet.'); ?></tbody></table></div></section>
        <section class="card dt"><?= dt_head('Jobs', 'Last 5') ?>
          <div class="dtwrap"><table class="dtable"><thead><tr><th>When</th><th>Stage</th><th class="num">Tokens</th><th>Status</th></tr></thead><tbody>
          <?php foreach ($jobs as $j): ?><tr><td class="nw muted" data-v="<?= e($j['created_at']) ?>"><?= e(ago($j['created_at'])) ?></td><td class="dmain"><?= e($j['message'] ?: $j['stage']) ?></td><td class="num"><?= number_format($j['tokens_in'] + $j['tokens_out']) ?></td><td><?= status_pill($j['status']) ?></td></tr><?php endforeach; if (!$jobs) echo dt_empty(4, 'No jobs.'); ?></tbody></table></div></section>
      </section>
    </div>
    <template id="tpl-transcript">
    <form class="card mform" method="post" data-action="admin/recordings.php"><?= csrf_field() ?><input type="hidden" name="a" value="save_transcript"><input type="hidden" name="id" value="<?= $id ?>"><h2>Transcript</h2>
      <p class="note">After saving, use Rebuild material to refresh the decks, guide and questions.</p>
      <label class="vh" for="tr">Transcript</label><textarea class="in" id="tr" name="transcript" rows="18"><?= e($r['transcript']) ?></textarea><p class="err note" role="alert"></p>
      <button class="btn" type="submit">Save transcript</button></form>
    </template>
    <?php include ROOT . '/includes/dashboard_footer.php'; exit;
}
$f = $_GET['f'] ?? 'all'; $q = trim((string)($_GET['q'] ?? ''));
$cond = ['all' => '1', 'attention' => "(r.stt_status='failed' OR r.ai_status='failed' OR r.stt_confidence<85)", 'processing' => "(r.stt_status IN ('queued','running') OR r.ai_status IN ('queued','running'))", 'ready' => "r.ai_status='done'"][$f] ?? '1';
$a = []; $w = $cond . ' AND ' . in_school('r'); if ($q !== '') { $w .= ' AND (r.title LIKE ? OR u.full_name LIKE ? OR c.code LIKE ?)'; $a = ["%$q%", "%$q%", "%$q%"]; }
$per = per_page(15); $page = page_no(); $total = (int)val("SELECT COUNT(*) FROM recordings r JOIN users u ON u.id=r.owner_id LEFT JOIN courses c ON c.id=r.course_id WHERE $w", $a);
$list = rows("SELECT r.id,r.title,r.format,r.size_bytes,r.seconds,r.stt_status,r.stt_confidence,r.ai_status,r.status,r.created_at,r.audio_deleted_at,u.full_name,c.code,
  (SELECT COUNT(*) FROM cards k JOIN decks d ON d.id=k.deck_id WHERE d.recording_id=r.id) cards,(SELECT COUNT(*) FROM study_guides g WHERE g.recording_id=r.id) guides,(SELECT COUNT(*) FROM exam_questions e WHERE e.source_type='recording' AND e.source_id=r.id) qs
  FROM recordings r JOIN users u ON u.id=r.owner_id LEFT JOIN courses c ON c.id=r.course_id WHERE $w ORDER BY r.id DESC LIMIT $per OFFSET " . (($page - 1) * $per), $a);
$kp = row("SELECT COUNT(*) n, COALESCE(SUM(seconds),0)/3600 hours, COALESCE(SUM(CASE WHEN audio_deleted_at IS NULL THEN size_bytes END),0)/1048576 mb, COALESCE(SUM(stt_status='failed' OR ai_status='failed'),0) attn FROM recordings WHERE " . in_school());
echo page_head('Recordings', 'Every lecture audio file, its transcript and what was built from it.');
?>
<section class="kpis"><div class="card kpi"><b><?= (int)$kp['n'] ?></b><small>Recordings</small></div><div class="card kpi"><b><?= round($kp['hours'], 1) ?></b><small>Hours of audio</small></div><div class="card kpi"><b><?= round($kp['mb']) ?></b><small>MB on disk</small></div><div class="card kpi"><b style="color:<?= $kp['attn'] ? 'var(--bad)' : 'inherit' ?>"><?= (int)$kp['attn'] ?></b><small>Need attention</small></div></section>
<div class="tabs"><?php foreach (['all' => 'All', 'attention' => 'Needs attention', 'processing' => 'Processing', 'ready' => 'Ready'] as $k => $l): ?><a class="tab" href="?f=<?= $k ?>" aria-selected="<?= $f === $k ? 'true' : 'false' ?>"><?= $l ?></a><?php endforeach; ?></div>
<section class="card dt">
  <?= dt_head('Recordings', number_format($total) . ' in this view') ?>
  <form class="toolbar" method="get"><input type="hidden" name="f" value="<?= e($f) ?>"><label class="search"><?= ic('search', 'i') ?><span class="vh">Search</span><input name="q" value="<?= e($q) ?>" type="search" placeholder="Title, student or course"></label><button class="btn sm">Search</button></form>
  <div class="dtwrap"><table class="dtable"><thead><tr><th>Recording</th><th>Course</th><th class="num">Length</th><th class="num">Size</th><th>Transcript</th><th>Built</th><th>Status</th><th>Added</th><th data-nosort><span class="vh">Actions</span></th></tr></thead><tbody>
  <?php foreach ($list as $r): $href = url('admin/recordings.php?id=' . $r['id']); ?><tr data-href="<?= e($href) ?>">
    <td class="dmain"><a class="dtt" href="<?= e($href) ?>"><?= e($r['title']) ?></a><span class="ts">by <?= e($r['full_name']) ?><?= $r['audio_deleted_at'] ? ' · audio deleted' : '' ?></span></td>
    <td class="nw"><?= $r['code'] ? '<span class="code">' . e($r['code']) . '</span>' : '<span class="muted">None</span>' ?></td>
    <td class="num" data-v="<?= (int)$r['seconds'] ?>"><?= $r['seconds'] ? floor($r['seconds'] / 60) . ' min' : '&ndash;' ?></td><td class="num" data-v="<?= (int)$r['size_bytes'] ?>"><?= fmt_bytes($r['size_bytes']) ?></td>
    <td><?= status_pill($r['stt_status'] === 'none' ? 'pending' : $r['stt_status']) ?><?= $r['stt_confidence'] ? '<span class="ts">' . (int)$r['stt_confidence'] . '% sure</span>' : '' ?></td>
    <td class="nw"><?= (int)$r['cards'] ?> cards &middot; <?= (int)$r['guides'] ?> guide &middot; <?= (int)$r['qs'] ?> Q&amp;A</td>
    <td><?= status_pill($r['status']) ?></td><td class="nw muted" data-v="<?= e($r['created_at']) ?>"><?= e(ago($r['created_at'])) ?></td>
    <td class="act"><a class="btn sm line" href="<?= e($href) ?>">Open</a></td></tr>
  <?php endforeach; if (!$list) echo dt_empty(9, 'No recordings yet.'); ?></tbody></table></div>
  <?= dt_foot($total, $per, $page, ['f' => $f, 'q' => $q]) ?>
</section>
<?php include ROOT . '/includes/dashboard_footer.php'; ?>
