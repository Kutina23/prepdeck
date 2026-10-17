<?php
require __DIR__ . '/../../app/bootstrap.php';
$u = require_login(); $plus = is_plus($u); $uid = (int)$u['id'];
$active = 'games'; $title = 'Games'; $scripts = ['games.js'];
$games = rows('SELECT * FROM games WHERE is_enabled=1 ORDER BY id');
$code = $_GET['game'] ?? ''; $deck = (int)($_GET['deck'] ?? 0);
$game = null; foreach ($games as $g) if ($g['code'] === $code) $game = $g;
$decks = rows("SELECT d.id,d.title,d.card_count,d.contains_answers FROM decks d WHERE d.status='active' AND d.card_count>=3 AND " . in_school('d') . " AND (d.owner_id=? OR d.visibility='shared') ORDER BY d.owner_id=? DESC, d.id DESC LIMIT 60", [$uid, $uid]);
$best = []; foreach (rows('SELECT game_code,MAX(score) s FROM game_scores WHERE user_id=? AND ' . in_school() . ' GROUP BY game_code', [$uid]) as $r) $best[$r['game_code']] = (int)$r['s'];
include ROOT . '/includes/dashboard_header.php';
if ($game && $deck) {
    echo page_head($game['name'], 'Play with ' . (val('SELECT title FROM decks WHERE id=? AND ' . in_school(), [$deck]) ?: 'a deck'), '<a class="btn sm line" href="' . e(url('dashboard/games.php')) . '">All games</a>');
    if ($game['is_plus'] && !$plus) echo '<section class="card">' . lock_note($game['name'], 'games', true) . '</section>';
    else echo '<section class="garena" id="game" data-game="' . e($game['code']) . '" data-deck="' . $deck . '"><div class="ghud"><output id="gscore" class="ghudv">Score 0</output><output id="gtime" class="ghudv">00:00</output></div><div id="gboard" class="gboard" aria-live="polite">Loading…</div><p class="gmsg" id="gmsg" role="status"></p></section>';
    include ROOT . '/includes/dashboard_footer.php'; exit;
}
echo page_head('Games', 'Match is free. Other games are part of Plus. Pick a deck to play.');
// what each game is, and a small picture of it (decorative: the name and text carry the meaning)
$about = ['match' => 'Pair every question with its answer before the clock runs away.', 'scatter' => 'Cards burst across the board. Clear the pairs as fast as you can.'];
$art = [
    'match' => '<rect class="t" x="22" y="14" width="46" height="34" rx="9"/><rect class="t" x="78" y="14" width="46" height="34" rx="9"/><rect class="t on" x="134" y="14" width="46" height="34" rx="9"/><rect class="t on2" x="22" y="60" width="46" height="34" rx="9"/><rect class="t" x="78" y="60" width="46" height="34" rx="9"/><rect class="t" x="134" y="60" width="46" height="34" rx="9"/><path class="link" d="M157 48C157 70 60 40 45 60"/>',
    'scatter' => '<rect class="t" x="18" y="22" width="40" height="28" rx="8" transform="rotate(-12 38 36)"/><rect class="t on" x="70" y="8" width="40" height="28" rx="8" transform="rotate(8 90 22)"/><rect class="t" x="128" y="18" width="40" height="28" rx="8" transform="rotate(-6 148 32)"/><rect class="t on2" x="44" y="62" width="40" height="28" rx="8" transform="rotate(10 64 76)"/><rect class="t" x="104" y="58" width="40" height="28" rx="8" transform="rotate(-14 124 72)"/><circle class="spk" cx="176" cy="74" r="4"/><circle class="spk" cx="12" cy="80" r="3"/><circle class="spk" cx="100" cy="100" r="2.5"/>',
];
?>
<div class="ggrid">
<?php foreach ($games as $i => $g): $lock = $g['is_plus'] && !$plus; $b = $best[$g['code']] ?? null; ?>
  <section class="gcard<?= $lock ? ' locked' : '' ?>" style="--i:<?= $i ?>" aria-labelledby="gn<?= $g['id'] ?>">
    <div class="gart" aria-hidden="true"><svg viewBox="0 0 200 110"><?= $art[$g['code']] ?? $art['match'] ?></svg></div>
    <div class="gbody">
      <div class="ghead"><h2 id="gn<?= $g['id'] ?>"><?= e($g['name']) ?></h2><span class="gchip <?= $g['is_plus'] ? 'plus' : '' ?>"><?= $g['is_plus'] ? 'Plus' : 'Free' ?></span></div>
      <p class="gabout"><?= e($about[$g['code']] ?? 'Play with any of your decks.') ?></p>
      <p class="gbest"><?= ic('flame') ?><?= $b !== null ? 'Your best: <b>' . number_format($b) . '</b>' : 'No score yet. Set the first one.' ?></p>
      <?php if ($lock): ?><a class="btn gplay" href="<?= e(url('dashboard/plan.php')) ?>"<?= plus_lock('games') ?>><?= ic('lock') ?>Unlock with Plus</a><?php else: ?>
      <form method="get" class="gform"><input type="hidden" name="game" value="<?= e($g['code']) ?>"><label class="vh" for="dk<?= $g['id'] ?>">Deck</label><select class="in" id="dk<?= $g['id'] ?>" name="deck" required><option value="">Choose a deck</option><?php foreach ($decks as $d): ?><option value="<?= $d['id'] ?>"<?= $deck === (int)$d['id'] ? ' selected' : '' ?>><?= e($d['title']) ?><?= $d['contains_answers'] && !$plus ? ' (Plus)' : '' ?></option><?php endforeach; ?></select><button class="btn gplay"><?= ic('play') ?>Play</button></form><?php endif; ?>
    </div>
  </section>
<?php endforeach; ?>
</div>
<?php if (!$decks) echo empty_state('You need a deck with at least 3 cards to play.', '<a class="btn sm" href="' . e(url('dashboard/decks.php')) . '">Open decks</a>'); ?>
<?php include ROOT . '/includes/dashboard_footer.php'; ?>
