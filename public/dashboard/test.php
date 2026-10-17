<?php
// Test mode: a practice test made from one flashcard set (multiple choice, true/false, written, matching), marked at once.
require __DIR__ . '/../../app/bootstrap.php';
$u = require_login(); $plus = is_plus($u); $uid = (int)$u['id'];
$active = 'test'; $title = 'Test'; $scripts = ['deckmodes.js'];
$deck = Study::deck($uid, (int)($_GET['deck'] ?? 0)); $q = trim((string)($_GET['q'] ?? ''));
include ROOT . '/includes/dashboard_header.php';
if (!$deck) {
    echo page_head('Test', 'Make a practice test from any flashcard set: multiple choice, true or false, written and matching questions, marked instantly.');
    $mode = 'test'; $decks = Study::studyDecks($u, $q); include ROOT . '/includes/deck_picker.php';
    include ROOT . '/includes/dashboard_footer.php'; exit;
}
$termN = (int)$deck['card_count'];
$best = val("SELECT MAX(ROUND(100*correct/NULLIF(total,0))) FROM deck_test_attempts WHERE user_id=? AND deck_id=? AND mode='test'", [$uid, $deck['id']]);
echo page_head('Test: ' . $deck['title'], ($deck['code'] ? $deck['code'] . ' · ' : '') . $termN . ' terms' . ($best !== null && $best !== false ? ' · your best ' . (int)$best . '%' : ''), '<a class="btn sm line" href="' . e(url('dashboard/decks.php?id=' . $deck['id'])) . '">Flashcards</a><a class="btn sm line" href="' . e(url('dashboard/learn.php?deck=' . $deck['id'])) . '">Learn</a>');
if ((int)$deck['contains_answers'] && !$plus) { echo '<section class="card">' . lock_note('Testing yourself on solved answers', 'answers', true) . '</section>'; include ROOT . '/includes/dashboard_footer.php'; exit; }
?>
<section class="dm" id="test" data-deck="<?= (int)$deck['id'] ?>" aria-label="Practice test">
  <form class="card mform dm-setup" id="tsSetup">
    <h2>Set up your test</h2>
    <div class="f2"><div class="field"><label for="tsN">Questions (max <?= min(50, $termN) ?>)</label><input class="in" id="tsN" name="n" type="number" min="1" max="<?= min(50, $termN) ?>" value="<?= min(20, $termN) ?>" required></div>
      <div class="field"><label for="tsDir">Answer with</label><select class="in" id="tsDir" name="dir"><option value="back">The definition</option><option value="front">The term</option><option value="both">Both (mixed)</option></select></div></div>
    <fieldset class="dm-types"><legend>Question types</legend>
      <label class="chk"><input type="checkbox" name="mc" checked>Multiple choice</label>
      <label class="chk"><input type="checkbox" name="tf" checked>True or false</label>
      <label class="chk"><input type="checkbox" name="wr" checked>Written</label>
      <label class="chk"><input type="checkbox" name="mt" checked>Matching</label></fieldset>
    <label class="chk"><input type="checkbox" name="timer">Time me</label>
    <p class="err" role="alert"></p>
    <button class="btn" type="submit"><?= ic('docchk') ?>Create test</button>
  </form>
  <form class="dm-paper" id="tsPaper" hidden autocomplete="off" novalidate>
    <div class="dm-paperhd"><b id="tsCount"></b><span id="tsClock" class="mono" hidden></span></div>
    <ol class="dm-qs" id="tsQs"></ol>
    <div class="dm-submit"><button class="btn" type="submit">Submit test</button></div>
  </form>
  <div class="dm-result card" id="tsResult" hidden>
    <div class="dm-score"><svg viewBox="0 0 120 120" aria-hidden="true"><circle class="tr" cx="60" cy="60" r="52"/><circle class="pg" id="tsRing" cx="60" cy="60" r="52" pathLength="100" stroke-dasharray="0 100"/></svg><b id="tsPct">0%</b></div>
    <div><h2 id="tsMsg"></h2><p class="note" id="tsSub"></p>
      <div class="rowact"><button class="btn" type="button" id="tsRetake">New test</button><a class="btn line" href="<?= e(url('dashboard/learn.php?deck=' . $deck['id'])) ?>">Learn these terms</a></div></div>
  </div>
</section>
<?php include ROOT . '/includes/dashboard_footer.php'; ?>
