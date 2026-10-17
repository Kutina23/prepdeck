<?php
// Smart review: a spaced-repetition session over every deck the student studies (or one deck with ?deck=).
require __DIR__ . '/../../app/bootstrap.php';
$u = require_login(); $plus = is_plus($u); $uid = (int)$u['id'];
$active = 'study'; $title = 'Smart review'; $scripts = ['review.js'];
$deck = (int)($_GET['deck'] ?? 0);
$d = $deck ? row("SELECT id,title FROM decks WHERE id=? AND status='active' AND " . in_school() . " AND (owner_id=? OR visibility='shared')", [$deck, $uid]) : null;
include ROOT . '/includes/dashboard_header.php';
echo page_head('Smart review', $d ? $d['title'] : 'Cards due today across your decks, then a few new ones.', '<a class="btn sm line" href="' . e(url('dashboard/study.php')) . '">Study hub</a>');
if (!$plus) { echo '<section class="card">' . lock_note('Smart review', 'review', true) . '</section>'; include ROOT . '/includes/dashboard_footer.php'; exit; }
if ($deck && !$d) { echo empty_state('That deck is not available.', '<a class="btn sm" href="' . e(url('dashboard/review.php')) . '">Review all decks</a>'); include ROOT . '/includes/dashboard_footer.php'; exit; }
?>
<section class="rv" id="review" data-deck="<?= $deck ?>" aria-label="Review session">
  <div class="rvtop"><span class="mono" id="rvdeck">Loading…</span><span class="rvcount" id="rvcount" aria-live="polite"></span></div>
  <div class="rvbar" aria-hidden="true"><i id="rvbar"></i></div>
  <button class="rvcard" id="rvcard" type="button" aria-describedby="rvhint" disabled>
    <span class="rvtag" id="rvtag" hidden>New</span>
    <span class="rvq" id="rvq"></span>
    <span class="rva" id="rva" hidden></span>
    <small class="rvhint" id="rvhint">Tap the card or press Space to show the answer</small>
  </button>
  <div class="rvrate" id="rvrate" role="group" aria-label="How well did you know it?" hidden>
    <button type="button" data-r="1" class="r1"><b>Again</b><small></small><kbd>1</kbd></button>
    <button type="button" data-r="2" class="r2"><b>Hard</b><small></small><kbd>2</kbd></button>
    <button type="button" data-r="3" class="r3"><b>Good</b><small></small><kbd>3</kbd></button>
    <button type="button" data-r="4" class="r4"><b>Easy</b><small></small><kbd>4</kbd></button>
  </div>
  <div class="rvdone card" id="rvdone" hidden>
    <span class="rvbadge" aria-hidden="true"><?= ic('check') ?></span>
    <h2 id="rvdt">All done for now</h2>
    <p id="rvds" class="note"></p>
    <div class="rowact"><a class="btn" href="<?= e(url('dashboard/study.php')) ?>">Back to Study hub</a><a class="btn line" href="<?= e(url('dashboard/decks.php')) ?>">Browse decks</a></div>
  </div>
  <p class="note rvkeys">Keys: <kbd>Space</kbd> show answer · <kbd>1</kbd>–<kbd>4</kbd> rate. Cards you rate <b>Again</b> come back in this session.</p>
</section>
<?php include ROOT . '/includes/dashboard_footer.php'; ?>
