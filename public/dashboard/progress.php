<?php
require __DIR__ . '/../../app/bootstrap.php';
$u = require_login(); $plus = is_plus($u); $uid = (int)$u['id'];
$active = 'progress'; $title = 'Progress'; $scripts = [];
$S = in_school();   // progress in the school being viewed
$mins = (int)val("SELECT COALESCE(SUM(minutes),0) FROM focus_sessions WHERE user_id=? AND $S", [$uid]);
$sessions = (int)val("SELECT COUNT(*) FROM focus_sessions WHERE user_id=? AND $S", [$uid]);
$known = (int)val('SELECT COUNT(*) FROM card_progress WHERE user_id=? AND known=1', [$uid]);
$papers = (int)val("SELECT COUNT(DISTINCT paper_id) FROM paper_views WHERE user_id=? AND $S", [$uid]);
$scores = rows("SELECT g.name,MAX(s.score) best,COUNT(*) plays FROM game_scores s JOIN games g ON g.code=s.game_code WHERE s.user_id=? AND s.$S GROUP BY g.name", [$uid]);
$decks = rows('SELECT d.id,d.title,d.card_count,(SELECT COUNT(*) FROM card_progress p JOIN cards c ON c.id=p.card_id WHERE c.deck_id=d.id AND p.user_id=? AND p.known=1) kn FROM decks d WHERE d.' . $S . ' AND EXISTS (SELECT 1 FROM card_progress p JOIN cards c ON c.id=p.card_id WHERE c.deck_id=d.id AND p.user_id=?) ORDER BY d.id DESC LIMIT 10', [$uid, $uid]);
$recent = rows('SELECT p.id,p.title,MAX(v.viewed_at) at FROM paper_views v JOIN papers p ON p.id=v.paper_id WHERE v.user_id=? AND v.' . $S . ' GROUP BY p.id,p.title ORDER BY at DESC LIMIT 8', [$uid]);
include ROOT . '/includes/dashboard_header.php';
echo page_head('Progress', 'What you have done so far.');
?>
<section class="stats">
  <div class="card stat"><span class="ic" style="background:var(--lemon)"><?= ic('clock') ?></span><div><b><?= round($mins / 60, 1) ?> h</b><small>Focus time</small></div></div>
  <div class="card stat"><span class="ic" style="background:var(--pop)"><?= ic('timer') ?></span><div><b><?= $sessions ?></b><small>Focus sessions</small></div></div>
  <div class="card stat"><span class="ic" style="background:var(--brand);color:var(--brand-ink)"><?= ic('check') ?></span><div><b><?= $known ?></b><small>Cards known</small></div></div>
  <div class="card stat"><span class="ic" style="background:var(--lemon)"><?= ic('docchk') ?></span><div><b><?= $papers ?></b><small>Papers opened</small></div></div>
</section>
<div class="grid2">
  <section class="card"><h2>Decks</h2><?php if (!$plus) echo '<p class="note">Plus saves which cards you know.</p>'; if (!$decks) echo empty_state('No tracked decks yet.'); ?>
    <div class="svc" style="margin-top:12px"><?php foreach ($decks as $d): $pc = $d['card_count'] ? round(100 * $d['kn'] / $d['card_count']) : 0; ?><a class="barrow" href="<?= e(url('dashboard/decks.php?id=' . $d['id'])) ?>"><span><?= e($d['title']) ?></span><span class="bt"><i style="width:<?= $pc ?>%"></i></span><b><?= $pc ?>%</b></a><?php endforeach; ?></div></section>
  <section class="card dt"><?= dt_head('Game scores') ?>
    <div class="dtwrap"><table class="dtable"><thead><tr><th>Game</th><th class="num">Best</th><th class="num">Plays</th></tr></thead><tbody>
    <?php foreach ($scores as $s): ?><tr><td class="dmain"><span class="dtt"><?= e($s['name']) ?></span></td><td class="num"><?= (int)$s['best'] ?></td><td class="num"><?= (int)$s['plays'] ?></td></tr><?php endforeach; if (!$scores) echo dt_empty(3, 'Play a game to see scores.'); ?></tbody></table></div></section>
</div>
<section class="card dt"><?= dt_head('Recently opened papers', 'Last 8') ?>
  <div class="dtwrap"><table class="dtable"><thead><tr><th>Paper</th><th>Opened</th><th data-nosort><span class="vh">Actions</span></th></tr></thead><tbody>
  <?php foreach ($recent as $r): $href = url('dashboard/papers.php?id=' . $r['id']); ?><tr data-href="<?= e($href) ?>"><td class="dmain"><a class="dtt" href="<?= e($href) ?>"><?= e($r['title']) ?></a></td><td class="nw muted" data-v="<?= e($r['at']) ?>"><?= e(ago($r['at'])) ?></td><td class="act"><a class="btn sm line" href="<?= e($href) ?>">Open again</a></td></tr><?php endforeach; if (!$recent) echo dt_empty(3, 'Nothing opened yet.'); ?></tbody></table></div></section>
<?php include ROOT . '/includes/dashboard_footer.php'; ?>
