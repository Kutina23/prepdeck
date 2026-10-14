<?php
require __DIR__ . '/../../app/bootstrap.php';
$u = require_perm('decks'); $adminArea = true; $active = 'study'; $title = 'Study content'; $scripts = ['forms.js'];
if (is_post()) {
    form_guard(); $a = $_POST['a'] ?? ''; $id = (int)($_POST['id'] ?? 0);
    $own = str_starts_with($a, 'deck_') ? in_my_school('decks', $id) : (str_starts_with($a, 'guide_') ? in_my_school('study_guides', $id)
        : ($a === 'q_status' ? (bool)val('SELECT COUNT(*) FROM exam_questions q WHERE q.id=? AND ' . questions_in_school('q'), [$id]) : true));
    if (!$own) { flash('bad', 'Not found in this school.'); redirect_self_or_ajax(); }
    // games are shared by every school, so only the platform owner changes them
    if ($a === 'deck_status') { $s = Validator::in($_POST['status'] ?? '', ['active', 'hidden']); if ($s) { q('UPDATE decks SET status=? WHERE id=?', [$s, $id]); Audit::log("deck $id $s", 'deck', $id); flash('ok', 'Deck ' . $s . '.'); } }
    elseif ($a === 'deck_vis') { $v = Validator::in($_POST['vis'] ?? '', ['private', 'shared']); if ($v) { q('UPDATE decks SET visibility=? WHERE id=?', [$v, $id]); Audit::log("deck $id $v", 'deck', $id); flash('ok', 'Deck is now ' . $v . '.'); } }
    elseif ($a === 'deck_answers') { q('UPDATE decks SET contains_answers=1-contains_answers WHERE id=?', [$id]); flash('ok', 'Updated.'); }
    elseif ($a === 'deck_delete') { q('DELETE FROM decks WHERE id=?', [$id]); Audit::log("deleted deck $id", 'deck', $id); flash('ok', 'Deck deleted.'); }
    elseif ($a === 'guide_status') { $s = Validator::in($_POST['status'] ?? '', ['active', 'hidden']); if ($s) { q('UPDATE study_guides SET status=? WHERE id=?', [$s, $id]); flash('ok', 'Guide ' . $s . '.'); } }
    elseif ($a === 'guide_delete') { q('DELETE FROM study_guides WHERE id=?', [$id]); flash('ok', 'Guide deleted.'); }
    elseif ($a === 'game' && is_super()) { q('UPDATE games SET is_plus=?, is_enabled=? WHERE id=?', [isset($_POST['is_plus']) ? 1 : 0, isset($_POST['is_enabled']) ? 1 : 0, $id]); Audit::log("updated game $id", 'game', $id); flash('ok', 'Game updated.'); }
    elseif ($a === 'q_status') { $s = Validator::in($_POST['status'] ?? '', ['published', 'hidden', 'draft']); if ($s) q('UPDATE exam_questions SET status=? WHERE id=?', [$s, $id]); flash('ok', 'Updated.'); }
    redirect_self_or_ajax();
}
$tab = $_GET['tab'] ?? 'decks'; $q = trim((string)($_GET['q'] ?? '')); $st = $_GET['status'] ?? ''; $per = per_page(15); $page = page_no();
$act = 'admin/study.php';
include ROOT . '/includes/dashboard_header.php';
echo page_head('Study content', 'Moderate decks, study guides, solved answers and games.');
?>
<div class="tabs" role="navigation"><?php foreach (['decks' => 'Decks', 'guides' => 'Study guides', 'answers' => 'Solved answers', 'games' => 'Games'] as $k => $l): ?><a class="tab" href="?tab=<?= $k ?>" aria-selected="<?= $tab === $k ? 'true' : 'false' ?>"><?= $l ?></a><?php endforeach; ?></div>
<?php if ($tab === 'decks'):
    $w = [in_school('d')]; $a = []; if ($q !== '') { $w[] = '(d.title LIKE ? OR c.code LIKE ?)'; array_push($a, "%$q%", "%$q%"); } if (in_array($st, ['active', 'hidden'], true)) { $w[] = 'd.status=?'; $a[] = $st; } $W = implode(' AND ', $w);
    $total = (int)val("SELECT COUNT(*) FROM decks d LEFT JOIN courses c ON c.id=d.course_id WHERE $W", $a);
    $list = rows("SELECT d.*,u.full_name,c.code,(SELECT COUNT(*) FROM reports r WHERE r.deck_id=d.id AND r.status='open') rep FROM decks d JOIN users u ON u.id=d.owner_id LEFT JOIN courses c ON c.id=d.course_id WHERE $W ORDER BY d.id DESC LIMIT $per OFFSET " . (($page - 1) * $per), $a); ?>
<section class="card dt">
  <?= dt_head('Decks', number_format($total) . ' in this view') ?>
  <form class="toolbar" method="get"><input type="hidden" name="tab" value="decks"><label class="search"><?= ic('search', 'i') ?><span class="vh">Search</span><input name="q" value="<?= e($q) ?>" type="search" placeholder="Deck title or course"></label><select class="in" name="status" aria-label="Status"><option value="">Any status</option><option value="active"<?= $st === 'active' ? ' selected' : '' ?>>Active</option><option value="hidden"<?= $st === 'hidden' ? ' selected' : '' ?>>Hidden</option></select><button class="btn sm">Filter</button></form>
  <div class="dtwrap"><table class="dtable"><thead><tr><th>Deck</th><th>Course</th><th class="num">Cards</th><th>Source</th><th>Visibility</th><th>Answers</th><th>Status</th><th data-nosort><span class="vh">Actions</span></th></tr></thead><tbody>
  <?php foreach ($list as $d): ?><tr>
    <td class="dmain"><a class="dtt" href="<?= e(url('dashboard/decks.php?id=' . $d['id'])) ?>"><?= e($d['title']) ?></a><span class="ts">by <?= e($d['full_name']) ?><?= $d['rep'] ? ' · <b style="color:var(--bad)">' . (int)$d['rep'] . ' open report(s)</b>' : '' ?></span></td>
    <td class="nw"><?= $d['code'] ? '<span class="code">' . e($d['code']) . '</span>' : '<span class="muted">None</span>' ?></td><td class="num"><?= (int)$d['card_count'] ?></td>
    <td><?= e($d['source']) ?><?= $d['ai_generated'] ? ' <span class="pill free">AI</span>' : '' ?></td>
    <td><span class="pill <?= $d['visibility'] === 'shared' ? 'ok' : 'free' ?>"><?= e(ucfirst($d['visibility'])) ?></span></td><td><?= $d['contains_answers'] ? '<span class="pill plus">Plus</span>' : '<span class="pill free">Free</span>' ?></td><td><?= status_pill($d['status']) ?></td>
    <td class="act"><div class="rowact"><?= modal_btn('tpl-dk' . $d['id'], $d['title'], 'Manage', 'btn sm line') ?><a class="btn sm line" href="<?= e(url('dashboard/decks.php?id=' . $d['id'])) ?>">Open</a></div>
      <template id="tpl-dk<?= $d['id'] ?>"><p class="note"><?= (int)$d['card_count'] ?> cards &middot; by <?= e($d['full_name']) ?> &middot; <?= e($d['visibility']) ?> &middot; <?= e($d['status']) ?></p>
        <?= row_form($act, ['a' => 'deck_status', 'id' => $d['id'], 'status' => $d['status'] === 'active' ? 'hidden' : 'active'], $d['status'] === 'active' ? 'Hide deck' : 'Show deck', 'btn sm line') ?>
        <?= row_form($act, ['a' => 'deck_vis', 'id' => $d['id'], 'vis' => $d['visibility'] === 'shared' ? 'private' : 'shared'], $d['visibility'] === 'shared' ? 'Make private' : 'Share with all', 'btn sm line') ?>
        <?= row_form($act, ['a' => 'deck_answers', 'id' => $d['id']], $d['contains_answers'] ? 'Make answers free' : 'Make answers Plus-only', 'btn sm line') ?>
        <?= row_form($act, ['a' => 'deck_delete', 'id' => $d['id']], 'Delete deck', 'btn sm danger', 'Delete this deck and its cards?') ?></template></td></tr>
  <?php endforeach; if (!$list) echo dt_empty(8, 'No decks.'); ?></tbody></table></div>
  <?= dt_foot($total, $per, $page, ['tab' => 'decks', 'q' => $q, 'status' => $st]) ?>
</section>
<?php elseif ($tab === 'guides'):
    $w = in_school('g'); $a = []; if ($q !== '') { $w .= ' AND g.title LIKE ?'; $a = ["%$q%"]; }
    $total = (int)val("SELECT COUNT(*) FROM study_guides g WHERE $w", $a); $list = rows("SELECT g.*,u.full_name FROM study_guides g JOIN users u ON u.id=g.owner_id WHERE $w ORDER BY g.id DESC LIMIT $per OFFSET " . (($page - 1) * $per), $a); ?>
<section class="card dt">
  <?= dt_head('Study guides', number_format($total) . ' in this view') ?>
  <form class="toolbar" method="get"><input type="hidden" name="tab" value="guides"><label class="search"><?= ic('search', 'i') ?><span class="vh">Search</span><input name="q" value="<?= e($q) ?>" type="search" placeholder="Guide title"></label><button class="btn sm">Search</button></form>
  <div class="dtwrap"><table class="dtable"><thead><tr><th>Guide</th><th>Owner</th><th>Made by</th><th>Created</th><th>Status</th><th data-nosort><span class="vh">Actions</span></th></tr></thead><tbody>
  <?php foreach ($list as $g): ?><tr>
    <td class="dmain"><a class="dtt" href="<?= e(url('dashboard/guides.php?id=' . $g['id'])) ?>"><?= e($g['title']) ?></a></td><td><?= e($g['full_name']) ?></td><td><?= $g['ai_generated'] ? 'PrepDeck AI' : 'Person' ?></td>
    <td class="nw muted" data-v="<?= e($g['created_at']) ?>"><?= e(ago($g['created_at'])) ?></td><td><?= status_pill($g['status']) ?></td>
    <td class="act"><div class="rowact"><a class="btn sm line" href="<?= e(url('dashboard/guides.php?id=' . $g['id'])) ?>">Read</a>
      <?= row_form($act, ['a' => 'guide_status', 'id' => $g['id'], 'status' => $g['status'] === 'active' ? 'hidden' : 'active'], $g['status'] === 'active' ? 'Hide' : 'Show', 'btn sm line') ?>
      <?= row_form($act, ['a' => 'guide_delete', 'id' => $g['id']], 'Delete', 'btn sm danger', 'Delete this guide?') ?></div></td></tr>
  <?php endforeach; if (!$list) echo dt_empty(6, 'No guides yet.'); ?></tbody></table></div>
  <?= dt_foot($total, $per, $page, ['tab' => 'guides', 'q' => $q]) ?>
</section>
<?php elseif ($tab === 'answers'):
    $f = $_GET['f'] ?? 'draft'; $w = [questions_in_school('q')]; $a = []; if (in_array($f, ['draft', 'published', 'hidden'], true)) { $w[] = 'q.status=?'; $a[] = $f; } if ($q !== '') { $w[] = '(q.question LIKE ? OR q.topic LIKE ?)'; array_push($a, "%$q%", "%$q%"); } $W = implode(' AND ', $w);
    $total = (int)val("SELECT COUNT(*) FROM exam_questions q WHERE $W", $a); $list = rows("SELECT q.* FROM exam_questions q WHERE $W ORDER BY q.id DESC LIMIT $per OFFSET " . (($page - 1) * $per), $a); ?>
<section class="card dt">
  <?= dt_head('Solved answers', number_format($total) . ' in this view') ?>
  <div class="tabs"><?php foreach (['draft' => 'Drafts', 'published' => 'Published', 'hidden' => 'Hidden', 'all' => 'All'] as $k => $l): ?><a class="tab" href="?tab=answers&f=<?= $k ?>" aria-selected="<?= $f === $k ? 'true' : 'false' ?>"><?= $l ?></a><?php endforeach; ?></div>
  <form class="toolbar" method="get"><input type="hidden" name="tab" value="answers"><input type="hidden" name="f" value="<?= e($f) ?>"><label class="search"><?= ic('search', 'i') ?><span class="vh">Search</span><input name="q" value="<?= e($q) ?>" type="search" placeholder="Question or topic"></label><button class="btn sm">Search</button></form>
  <div class="dtwrap"><table class="dtable"><thead><tr><th>Question</th><th>Source</th><th>Kind</th><th>Confidence</th><th>Status</th><th data-nosort><span class="vh">Actions</span></th></tr></thead><tbody>
  <?php foreach ($list as $x): ?><tr>
    <td class="dmain"><span class="clip"><?= e(mb_strimwidth($x['question'], 0, 160, '…')) ?></span></td><td class="nw"><?= e(ucfirst($x['source_type'])) ?> #<?= (int)$x['source_id'] ?></td>
    <td><span class="pill role"><?= e($x['kind']) ?></span></td><td><span class="pill <?= $x['confidence'] === 'low' ? 'bad' : ($x['confidence'] === 'high' ? 'ok' : 'wait') ?>"><?= e(ucfirst($x['confidence'])) ?></span></td><td><?= status_pill($x['status']) ?></td>
    <td class="act"><div class="rowact"><a class="btn sm line" href="<?= e(url('admin/processing.php?type=' . $x['source_type'] . '&sid=' . $x['source_id'])) ?>">Edit</a>
      <?php foreach (['published' => 'Publish', 'hidden' => 'Hide'] as $s => $l): if ($x['status'] === $s) continue; echo row_form($act, ['a' => 'q_status', 'id' => $x['id'], 'status' => $s], $l, $s === 'hidden' ? 'btn sm danger' : 'btn sm'); endforeach; ?></div></td></tr>
  <?php endforeach; if (!$list) echo dt_empty(6, 'Nothing here.'); ?></tbody></table></div>
  <?= dt_foot($total, $per, $page, ['tab' => 'answers', 'f' => $f, 'q' => $q]) ?>
</section>
<?php else: $games = rows('SELECT g.*,(SELECT COUNT(*) FROM game_scores s WHERE s.game_code=g.code AND ' . in_school('s') . ') plays FROM games g ORDER BY id'); [$list, $total] = page_slice($games, $per, $page); ?>
<section class="card dt">
  <?= dt_head('Games', is_super() ? 'Games are shared by every school. Turn them on or off and choose which need Plus.' : 'Games are shared by every school; the platform owner turns them on or off.') ?>
  <div class="dtwrap"><table class="dtable"><thead><tr><th>Game</th><th class="num">Plays</th><th>Enabled</th><th>Access</th><th data-nosort><span class="vh">Actions</span></th></tr></thead><tbody>
  <?php foreach ($list as $g): ?><tr>
    <td class="dmain"><span class="dtt"><?= e($g['name']) ?></span><span class="ts"><?= e($g['code']) ?></span></td><td class="num"><?= (int)$g['plays'] ?></td>
    <td><?= $g['is_enabled'] ? '<span class="pill ok">On</span>' : '<span class="pill free">Off</span>' ?></td><td><?= $g['is_plus'] ? '<span class="pill plus">Plus</span>' : '<span class="pill free">Free</span>' ?></td>
    <td class="act"><?php if (is_super()): ?><?= modal_btn('tpl-g' . $g['id'], 'Edit ' . $g['name'], 'Edit', 'btn sm line', 'edit') ?>
      <template id="tpl-g<?= $g['id'] ?>"><form class="card mform" method="post" data-action="<?= $act ?>"><?= csrf_field() ?><input type="hidden" name="a" value="game"><input type="hidden" name="id" value="<?= $g['id'] ?>"><h2><?= e($g['name']) ?></h2>
        <label class="chk"><input type="checkbox" name="is_enabled" value="1"<?= $g['is_enabled'] ? ' checked' : '' ?>>Enabled for students</label><label class="chk"><input type="checkbox" name="is_plus" value="1"<?= $g['is_plus'] ? ' checked' : '' ?>>Plus only</label><button class="btn" type="submit">Save</button></form></template><?php endif; ?></td></tr>
  <?php endforeach; if (!$list) echo dt_empty(5, 'No games.'); ?></tbody></table></div>
  <?= dt_foot($total, $per, $page, ['tab' => 'games']) ?>
</section>
<?php endif; ?>
<?php include ROOT . '/includes/dashboard_footer.php'; ?>
