<?php
require __DIR__ . '/../../app/bootstrap.php';
$u = require_login(); $plus = is_plus($u); $uid = (int)$u['id'];
$active = 'materials'; $scripts = ['forms.js', 'process.js'];
$freeMax = (int)setting('free_upload_max', 3); $freePages = (int)setting('free_upload_pages', 1);
$id = (int)($_GET['id'] ?? 0);
if ($id) {
    $m = row('SELECT * FROM materials WHERE id=? AND user_id=? AND ' . in_school(), [$id, $uid]);
    if (!$m) { http_response_code(404); $title = 'Not found'; include ROOT . '/includes/dashboard_header.php'; echo page_head('Not found') . empty_state('That upload is not yours or no longer exists.', '<a class="btn sm" href="' . e(url('dashboard/materials.php')) . '">Back</a>'); include ROOT . '/includes/dashboard_footer.php'; exit; }
    $title = $m['title'];
    $job = row("SELECT id,status FROM processing_jobs WHERE source_type='material' AND source_id=? ORDER BY id DESC LIMIT 1", [$id]);
    $decks = rows('SELECT id,title,card_count,contains_answers FROM decks WHERE material_id=? ORDER BY id', [$id]);
    $guides = rows('SELECT id,title FROM study_guides WHERE material_id=? ORDER BY id', [$id]);
    $qs = rows("SELECT question,answer,kind,topic,difficulty FROM exam_questions WHERE source_type='material' AND source_id=? AND status<>'hidden' ORDER BY id", [$id]);
    include ROOT . '/includes/dashboard_header.php';
    echo page_head($m['title'], fmt_bytes($m['size_bytes']) . ' · ' . (int)$m['page_count'] . ' pages · uploaded ' . ago($m['created_at']), '<a class="btn sm line" href="' . e(url('dashboard/materials.php')) . '">All uploads</a>');
    echo ai_live('material', $id);
    ?>
    <div class="grid2">
      <section class="card"><h2>Made from your notes</h2><div class="list" style="margin-top:12px">
        <?php foreach ($decks as $d): ?><a class="rowx" href="<?= e(url('dashboard/decks.php?id=' . $d['id'])) ?>"><h3><?= e($d['title']) ?></h3><span class="col">Flashcards · <?= (int)$d['card_count'] ?> cards</span></a><?php endforeach; ?>
        <?php foreach ($guides as $g): ?><a class="rowx" href="<?= e(url('dashboard/guides.php?id=' . $g['id'])) ?>"><h3><?= e($g['title']) ?></h3><span class="col">Study guide</span></a><?php endforeach; ?>
        <?php foreach ($decks as $d): ?><a class="rowx" href="<?= e(url('dashboard/games.php?deck=' . $d['id'])) ?>"><h3>Play: <?= e($d['title']) ?></h3><span class="col">Games</span></a><?php break; endforeach; ?>
        <?php if (!$decks && !$guides) echo empty_state('Nothing made yet.'); ?></div></section>
      <section class="card"><h2>Likely exam questions</h2><p class="sub"><?= count($qs) ?> questions</p>
        <div class="list" style="margin-top:12px"><?php foreach ($qs as $q): ?><details class="rowx"><summary><b><?= e(mb_strimwidth($q['question'], 0, 100, '…')) ?></b></summary><p style="margin-top:8px"><?= nl2br(e($q['question'])) ?></p><?php if ($plus): ?><div class="banner" style="margin-top:8px;background:var(--bg);color:var(--ink);font-weight:500"><b>Answer</b><br><?= nl2br(e($q['answer'])) ?></div><?php else: ?><p class="note" style="margin-top:8px"><?= ic('lock', 'i') ?> Answer is part of Plus.</p><?php endif; ?></details><?php endforeach; ?></div></section>
    </div>
    <?php if ($qs): ?><a class="btn" href="<?= e(url('dashboard/practice.php?type=material&id=' . $id)) ?>"><?= ic('docchk') ?>Practice these as an exam</a><?php endif; ?>
    <button class="btn danger sm" data-api="materials.php?a=delete" data-body='{"id":<?= $id ?>}' data-redirect="dashboard/materials.php" data-confirm="Delete this upload and everything made from it?">Delete upload</button>
    <?php include ROOT . '/includes/dashboard_footer.php'; exit;
}
$title = 'My materials';
$used = (int)val('SELECT COUNT(*) FROM materials WHERE user_id=? AND ' . in_school(), [$uid]); $full = !$plus && $used >= $freeMax;
$q = trim((string)($_GET['q'] ?? '')); $per = per_page(15); $page = page_no();
$w = 'user_id=? AND ' . in_school(); $a = [$uid]; if ($q !== '') { $w .= ' AND title LIKE ?'; $a[] = "%$q%"; }
$total = (int)val("SELECT COUNT(*) FROM materials WHERE $w", $a);
$list = rows("SELECT id,title,size_bytes,ai_status,page_count,created_at FROM materials WHERE $w ORDER BY id DESC LIMIT $per OFFSET " . (($page - 1) * $per), $a);
include ROOT . '/includes/dashboard_header.php';
echo page_head('My materials', 'Upload lecture notes. PrepDeck makes a study guide, flashcards, games and likely exam questions.', modal_btn('tpl-up', 'Upload notes', 'Upload notes', 'btn', 'upload'));
?>
<section class="card dt">
  <?= dt_head('Your uploads', $plus ? $used . ' uploaded · unlimited on Plus' : "$used of $freeMax free uploads used") ?>
  <form class="toolbar" method="get"><label class="search"><?= ic('search', 'i') ?><span class="vh">Search</span><input name="q" value="<?= e($q) ?>" type="search" placeholder="Search your uploads"></label><button class="btn sm">Search</button></form>
  <div class="dtwrap"><table class="dtable"><thead><tr><th>Title</th><th class="num">Pages</th><th class="num">Size</th><th>Uploaded</th><th>Study material</th><th data-nosort><span class="vh">Actions</span></th></tr></thead><tbody>
  <?php foreach ($list as $m): $href = url('dashboard/materials.php?id=' . $m['id']); ?><tr data-href="<?= e($href) ?>">
    <td class="dmain"><a class="dtt" href="<?= e($href) ?>"><?= e($m['title']) ?></a></td><td class="num"><?= (int)$m['page_count'] ?></td><td class="num" data-v="<?= (int)$m['size_bytes'] ?>"><?= fmt_bytes($m['size_bytes']) ?></td>
    <td class="nw muted" data-v="<?= e($m['created_at']) ?>"><?= e(ago($m['created_at'])) ?></td><td><?= status_pill($m['ai_status'] === 'none' ? 'pending' : $m['ai_status']) ?></td>
    <td class="act"><div class="rowact"><a class="btn sm line" href="<?= e($href) ?>">Open</a><button class="btn sm danger" data-api="materials.php?a=delete" data-body='{"id":<?= (int)$m['id'] ?>}' data-confirm="Delete this upload and everything made from it?">Delete</button></div></td></tr>
  <?php endforeach; if (!$list) echo dt_empty(6, 'Nothing uploaded yet.'); ?></tbody></table></div>
  <?= dt_foot($total, $per, $page, ['q' => $q]) ?>
</section>
<template id="tpl-up">
<form class="card mform" data-api="materials.php?a=upload" data-job="1" data-redirect="dashboard/materials.php?id={id}" enctype="multipart/form-data">
  <h2>Upload notes</h2>
  <p class="note"><?= $plus ? 'Plus: unlimited uploads, any length.' : "Free: $used of $freeMax documents used, up to $freePages page each. Plus is unlimited." ?></p>
  <?php if ($full): echo lock_note('More uploads'); endif; ?>
  <div class="field"><label for="mt2">Title</label><input class="in" id="mt2" name="title" placeholder="CSC 201 week 4 notes" required minlength="2" maxlength="160"<?= $full ? ' disabled' : '' ?>></div>
  <div class="drop"><?= ic('upload') ?><label for="mf"><b>Choose a PDF</b></label><input id="mf" name="file" type="file" accept="application/pdf" required<?= $full ? ' disabled' : '' ?>></div>
  <div class="progressbar" hidden><i style="width:0"></i></div><p class="note" data-jobmsg></p><p class="err note" role="alert"></p>
  <button class="btn" type="submit"<?= $full ? ' disabled' : '' ?>><?= ic('upload') ?>Upload and generate</button>
</form>
</template>
<?php include ROOT . '/includes/dashboard_footer.php'; ?>
