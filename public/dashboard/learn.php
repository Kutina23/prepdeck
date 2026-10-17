<?php
// Learn mode: adaptive study of one flashcard set. Multiple choice first, then type the answer; missed terms come back
// until every term is mastered. With Plus each first answer also schedules the card in smart review.
require __DIR__ . '/../../app/bootstrap.php';
$u = require_login(); $plus = is_plus($u); $uid = (int)$u['id'];
$active = 'learn'; $title = 'Learn'; $scripts = ['deckmodes.js'];
$deck = Study::deck($uid, (int)($_GET['deck'] ?? 0)); $q = trim((string)($_GET['q'] ?? ''));
include ROOT . '/includes/dashboard_header.php';
if (!$deck) {
    echo page_head('Learn', 'Adaptive study: answer multiple choice, then write the answer yourself. Terms you miss come back until you know them all.');
    $mode = 'learn'; $decks = Study::studyDecks($u, $q); include ROOT . '/includes/deck_picker.php';
    include ROOT . '/includes/dashboard_footer.php'; exit;
}
echo page_head('Learn: ' . $deck['title'], ($deck['code'] ? $deck['code'] . ' · ' : '') . (int)$deck['card_count'] . ' terms', '<a class="btn sm line" href="' . e(url('dashboard/decks.php?id=' . $deck['id'])) . '">Flashcards</a><a class="btn sm line" href="' . e(url('dashboard/test.php?deck=' . $deck['id'])) . '">Test</a>');
if ((int)$deck['contains_answers'] && !$plus) { echo '<section class="card">' . lock_note('Learning sets with solved answers', 'answers', true) . '</section>'; include ROOT . '/includes/dashboard_footer.php'; exit; }
?>
<section class="dm" id="learn" data-deck="<?= (int)$deck['id'] ?>" data-track="<?= $plus ? 1 : 0 ?>" aria-label="Learn session">
  <div class="dm-top">
    <div class="dm-prog" aria-live="polite"><span><b id="lnMastered">0</b> mastered</span><span><b id="lnFamiliar">0</b> familiar</span><span><b id="lnNew">0</b> not studied</span></div>
    <div class="dm-bar" aria-hidden="true"><i class="m" id="lnBarM"></i><i class="f" id="lnBarF"></i></div>
    <div class="dm-opts"><label class="vh" for="lnDir">Answer with</label><select class="in" id="lnDir"><option value="back">Answer with the definition</option><option value="front">Answer with the term</option></select></div>
  </div>
  <div class="dm-card" id="lnCard">
    <p class="dm-kind" id="lnKind">Loading…</p>
    <p class="dm-prompt" id="lnPrompt"></p>
    <div class="dm-choices" id="lnChoices" role="group" aria-label="Choose the answer"></div>
    <form class="dm-write" id="lnWrite" hidden autocomplete="off"><label class="vh" for="lnInput">Your answer</label><input class="in" id="lnInput" spellcheck="false" placeholder="Type the answer"><button class="btn" type="submit">Answer</button><button class="btn line" type="button" id="lnSkip">I don't know</button></form>
    <div class="dm-fb" id="lnFb" hidden aria-live="assertive"></div>
  </div>
  <div class="dm-done card" id="lnDone" hidden><span class="rvbadge" aria-hidden="true"><?= ic('check') ?></span><h2>You have mastered every term</h2><p class="note" id="lnDoneSub"></p>
    <div class="rowact"><button class="btn" type="button" id="lnAgain">Learn again</button><a class="btn line" href="<?= e(url('dashboard/test.php?deck=' . $deck['id'])) ?>">Take a test</a><a class="btn line" href="<?= e(url('dashboard/learn.php')) ?>">Another set</a></div></div>
  <p class="note dm-keys">Keys: <kbd>1</kbd>–<kbd>4</kbd> choose · <kbd>Enter</kbd> continue<?= $plus ? ' · your first answer to each term also schedules it in Smart review' : '' ?></p>
</section>
<?php include ROOT . '/includes/dashboard_footer.php'; ?>
