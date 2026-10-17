<?php
require __DIR__ . '/../../app/bootstrap.php';
$u = require_login(); $plus = is_plus($u); $uid = (int)$u['id'];
$active = 'recordings'; $title = 'Recordings'; $scripts = ['forms.js', 'process.js', 'record.js'];
$id = (int)($_GET['id'] ?? 0);
include ROOT . '/includes/dashboard_header.php';
$canUse = $plus || Auth::can('upload');
if (!$canUse) { echo page_head('Record lecture', 'Record or upload a lecture. PrepDeck writes the transcript, notes and flashcards.') . '<section class="card">' . lock_note('Lecture recording', 'recordings', true) . '</section>'; include ROOT . '/includes/dashboard_footer.php'; exit; }
if ($id) {
    $r = row('SELECT r.*,c.code FROM recordings r LEFT JOIN courses c ON c.id=r.course_id WHERE r.id=? AND ' . in_school('r') . ' AND (r.owner_id=? OR (r.status="published" AND r.visibility="course"))', [$id, $uid]);
    if (!$r) { echo page_head('Not found') . empty_state('That recording is not available.', '<a class="btn sm" href="' . e(url('dashboard/recordings.php')) . '">Back</a>'); include ROOT . '/includes/dashboard_footer.php'; exit; }
    $job = row("SELECT id,status FROM processing_jobs WHERE source_type='recording' AND source_id=? ORDER BY id DESC LIMIT 1", [$id]);
    $decks = rows('SELECT id,title,card_count FROM decks WHERE recording_id=?', [$id]); $guides = rows('SELECT id,title FROM study_guides WHERE recording_id=?', [$id]);
    $qs = rows("SELECT question,answer FROM exam_questions WHERE source_type='recording' AND source_id=? AND status<>'hidden'", [$id]);
    echo page_head($r['title'], ($r['code'] ? $r['code'] . ' · ' : '') . ($r['seconds'] ? floor($r['seconds'] / 60) . ' min · ' : '') . ago($r['created_at']), '<a class="btn sm line" href="' . e(url('dashboard/recordings.php')) . '">All recordings</a>');
    echo ai_live('recording', $id);
    ?>
    <div class="grid2">
      <section class="card"><h2>Transcript</h2><p class="sub"><?= (int)$r['word_count'] ?> words<?= $r['language'] ? ' · ' . e($r['language']) : '' ?></p>
        <div style="margin-top:12px;max-height:60vh;overflow:auto;line-height:1.7;white-space:pre-wrap"><?= $r['transcript'] ? e($r['transcript']) : '<span class="note">The transcript will appear here once it is ready.</span>' ?></div></section>
      <section class="stack"><div class="card"><h2>Made from this lecture</h2><div class="list" style="margin-top:12px">
        <?php foreach ($decks as $d): ?><a class="rowx" href="<?= e(url('dashboard/decks.php?id=' . $d['id'])) ?>"><h3><?= e($d['title']) ?></h3><span class="col"><?= (int)$d['card_count'] ?> cards</span></a><?php endforeach; ?>
        <?php foreach ($guides as $g): ?><a class="rowx" href="<?= e(url('dashboard/guides.php?id=' . $g['id'])) ?>"><h3><?= e($g['title']) ?></h3><span class="col">Study guide</span></a><?php endforeach; ?>
        <?php if (!$decks && !$guides) echo empty_state('Nothing yet.'); ?></div></div>
        <?php if ($qs): ?><a class="btn" href="<?= e(url('dashboard/practice.php?type=recording&id=' . $id)) ?>"><?= ic('docchk') ?>Practice this lecture as an exam</a><?php endif; ?>
        <div class="card"><h2>Likely exam questions</h2><div class="list" style="margin-top:12px"><?php foreach ($qs as $q): ?><details class="rowx"><summary><b><?= e(mb_strimwidth($q['question'], 0, 100, '…')) ?></b></summary><p style="margin-top:8px"><?= nl2br(e($q['answer'])) ?></p></details><?php endforeach; if (!$qs) echo empty_state('None yet.'); ?></div></div></section>
    </div>
    <?php include ROOT . '/includes/dashboard_footer.php'; exit;
}
$q = trim((string)($_GET['q'] ?? '')); $per = per_page(15); $page = page_no();
$w = 'r.owner_id=? AND ' . in_school('r'); $a = [$uid]; if ($q !== '') { $w .= ' AND r.title LIKE ?'; $a[] = "%$q%"; }
$total = (int)val("SELECT COUNT(*) FROM recordings r WHERE $w", $a);
$list = rows("SELECT r.id,r.title,r.seconds,r.stt_status,r.ai_status,r.created_at,r.status,c.code FROM recordings r LEFT JOIN courses c ON c.id=r.course_id WHERE $w ORDER BY r.id DESC LIMIT $per OFFSET " . (($page - 1) * $per), $a);
$courses = rows('SELECT id,CONCAT(code," · ",title) n FROM courses WHERE ' . in_school() . ' AND (department_id=? OR ?=0) ORDER BY code', [$u['department_id'], (int)$u['department_id']]);
echo page_head('Record lecture', 'Record in class or upload an audio file. Only you can see your recordings.', modal_btn('tpl-rec', 'New recording', 'New recording', 'btn', 'mic'));
?>
<section class="card dt">
  <?= dt_head('Your recordings', number_format($total) . ' recording' . ($total === 1 ? '' : 's')) ?>
  <form class="toolbar" method="get"><label class="search"><?= ic('search', 'i') ?><span class="vh">Search</span><input name="q" value="<?= e($q) ?>" type="search" placeholder="Search recordings"></label><button class="btn sm">Search</button></form>
  <div class="dtwrap"><table class="dtable"><thead><tr><th>Recording</th><th>Course</th><th class="num">Length</th><th>Recorded</th><th>Progress</th><th data-nosort><span class="vh">Actions</span></th></tr></thead><tbody>
  <?php foreach ($list as $r): $href = url('dashboard/recordings.php?id=' . $r['id']); ?><tr data-href="<?= e($href) ?>">
    <td class="dmain"><a class="dtt" href="<?= e($href) ?>"><?= e($r['title']) ?></a></td><td class="nw"><?= $r['code'] ? '<span class="code">' . e($r['code']) . '</span>' : '<span class="muted">None</span>' ?></td>
    <td class="num" data-v="<?= (int)$r['seconds'] ?>"><?= $r['seconds'] ? floor($r['seconds'] / 60) . ' min' : '&ndash;' ?></td><td class="nw muted" data-v="<?= e($r['created_at']) ?>"><?= e(ago($r['created_at'])) ?></td>
    <td><?= status_pill($r['stt_status'] === 'ready' ? ($r['ai_status'] === 'done' ? 'done' : 'running') : $r['stt_status']) ?></td>
    <td class="act"><a class="btn sm line" href="<?= e($href) ?>">Open</a></td></tr>
  <?php endforeach; if (!$list) echo dt_empty(6, 'No recordings yet.'); ?></tbody></table></div>
  <?= dt_foot($total, $per, $page, ['q' => $q]) ?>
</section>
<template id="tpl-rec">
<form class="card mform" id="recform" data-api="recordings.php?a=upload" data-job="1" data-redirect="dashboard/recordings.php?id={id}" enctype="multipart/form-data">
  <h2>New recording</h2>
  <div class="field"><label for="rt">Title</label><input class="in" id="rt" name="title" placeholder="CSC 201 lecture 5" required minlength="2"></div>
  <div class="field"><label for="rc">Course</label><select class="in" id="rc" name="course_id"><?= options($courses, null, 'id', 'n', 'No course') ?></select></div>
  <div class="card stack" style="background:var(--bg)"><div class="inline"><button class="btn" type="button" id="recbtn"><?= ic('mic') ?><span>Start recording</span></button><output id="rectime" class="mono">00:00</output></div><audio id="recplay" controls hidden style="width:100%"></audio><p class="note" id="recnote">Your browser asks for the microphone the first time.</p></div>
  <div class="drop"><?= ic('upload') ?><label for="rf"><b>Or upload an audio file</b></label><input id="rf" name="file" type="file" accept="audio/*,video/mp4"><small class="note">MP3, M4A, WAV, WebM or OGG, up to <?= (int)setting('rec_max_mb', 200) ?> MB</small></div>
  <div class="progressbar" hidden><i style="width:0"></i></div><p class="note" data-jobmsg></p><p class="err note" role="alert"></p>
  <button class="btn" type="submit">Upload and transcribe</button>
</form>
</template>
<?php include ROOT . '/includes/dashboard_footer.php'; ?>
