<?php
require __DIR__ . '/../../app/bootstrap.php';
$u = require_login(); $plus = is_plus($u); $uid = (int)$u['id'];
$active = 'decks'; $scripts = ['forms.js', 'study.js'];
$id = (int)($_GET['id'] ?? 0);
if ($id) {
    $d = row('SELECT d.*,c.code FROM decks d LEFT JOIN courses c ON c.id=d.course_id WHERE d.id=?', [$id]);
    if (!$d || (int)$d['school_id'] !== sid() || !((int)$d['owner_id'] === $uid || Auth::can('decks') || ($d['visibility'] === 'shared' && $d['status'] === 'active'))) { http_response_code(404); $title = 'Not found'; include ROOT . '/includes/dashboard_header.php'; echo page_head('Deck not found') . empty_state('That deck is not available.', '<a class="btn sm" href="' . e(url('dashboard/decks.php')) . '">All decks</a>'); include ROOT . '/includes/dashboard_footer.php'; exit; }
    $title = $d['title']; $locked = (int)$d['contains_answers'] && !$plus;
    $games = rows('SELECT code,name,is_plus FROM games WHERE is_enabled=1 ORDER BY id');
    include ROOT . '/includes/dashboard_header.php';
    echo page_head($d['title'], ($d['code'] ? $d['code'] . ' · ' : '') . (int)$d['card_count'] . ' cards' . ($d['ai_generated'] ? ' · made by PrepDeck AI' : ''), '<a class="btn sm line" href="' . e(url('dashboard/decks.php')) . '">All decks</a>');
    if ($locked) echo lock_note('Solved answers on these cards', 'answers');
    ?>
    <nav class="modes" aria-label="Study this set">
      <a class="mode" href="<?= e(url('dashboard/learn.php?deck=' . $id)) ?>"<?= $locked ? plus_lock('answers') : '' ?>><span class="ic" style="background:var(--brand);color:var(--brand-ink)"><?= ic('flame') ?></span><span><b>Learn</b><small>Choose, then write</small></span></a>
      <a class="mode" href="<?= e(url('dashboard/test.php?deck=' . $id)) ?>"<?= $locked ? plus_lock('answers') : '' ?>><span class="ic" style="background:var(--lemon)"><?= ic('docchk') ?></span><span><b>Test</b><small>Auto-made, marked</small></span></a>
      <a class="mode" href="<?= e(url('dashboard/review.php?deck=' . $id)) ?>"<?= $plus ? '' : plus_lock('review') ?>><span class="ic" style="background:var(--pop)"><?= ic('refresh') ?></span><span><b>Smart review</b><small><?= $plus ? 'Spaced repetition' : 'Plus' ?></small></span></a>
      <a class="mode" href="<?= e(url('dashboard/games.php?game=match&deck=' . $id)) ?>"><span class="ic" style="background:var(--surface);color:var(--ink)"><?= ic('game') ?></span><span><b>Match</b><small>Beat the clock</small></span></a>
    </nav>
    <section class="studyhub" id="study" data-deck="<?= $id ?>" data-plus="<?= $plus ? 1 : 0 ?>">
      <div class="studytop"><output class="studyct" id="sct" aria-live="polite">0 / 0</output><div class="progressbar neon" aria-hidden="true"><i id="sbar" style="width:0"></i></div>
        <button class="fcicon" type="button" id="sshuf" aria-label="Shuffle the cards" title="Shuffle"><?= ic('refresh') ?></button></div>
      <button class="fc" id="fc" type="button" aria-pressed="false" aria-label="Flashcard. Press to flip."><span class="fcin">
        <span class="f a"><span class="fl">Question</span><b id="fq">Loading…</b><small class="fh">Tap to flip</small></span>
        <span class="f b"><span class="fl">Answer</span><b id="fa"></b><small class="fh">Did you know it?</small></span></span></button>
      <div class="fcctl">
        <button class="fcicon" type="button" id="sprev" aria-label="Previous card"><?= ic('chev') ?></button>
        <button class="btn fcbad" type="button" id="sbad">Still learning</button><button class="btn fcgood" type="button" id="sgood"><?= ic('check') ?>I know it</button>
        <button class="fcicon" type="button" id="snext" aria-label="Next card"><?= ic('chev', 'i flip') ?></button></div>
      <p class="fckeys">Space flips · arrows move · on a phone, swipe right if you know it, left to keep learning</p>
      <?php if (!$plus): ?><p class="note"><?= ic('lock', 'i') ?> Free members can flip through cards. <a href="<?= e(url('dashboard/plan.php')) ?>"<?= plus_lock('decks') ?>><b>Plus</b></a> saves which cards you know.</p><?php endif; ?>
    </section>
    <section class="card"><h2>Play with this deck</h2><div class="gmini"><?php foreach ($games as $g): $gl = $g['is_plus'] && !$plus; ?>
      <a class="gminic" href="<?= e(url($gl ? 'dashboard/plan.php' : 'dashboard/games.php?game=' . $g['code'] . '&deck=' . $id)) ?>"<?= $gl ? plus_lock('games') : '' ?>><span class="gminiart" aria-hidden="true"><?= ic($g['code'] === 'match' ? 'cards' : 'game') ?></span><span><b><?= e($g['name']) ?></b><small><?= $gl ? 'Plus' : 'Play now' ?></small></span><?= $gl ? ic('lock', 'i lk') : ic('play', 'i go') ?></a>
    <?php endforeach; ?></div></section>
    <?php if ((int)$d['owner_id'] === $uid || Auth::can('decks')): ?><button class="btn danger sm" data-api="decks.php?a=delete" data-body='{"id":<?= $id ?>}' data-redirect="dashboard/decks.php" data-confirm="Delete this deck and its cards?">Delete deck</button><?php else: ?>
    <form class="card mform" data-api="reports.php" data-done="Thanks, we will check it."><h2>Report this deck</h2><input type="hidden" name="deck_id" value="<?= $id ?>"><div class="field"><label for="rr">What is wrong?</label><input class="in" id="rr" name="reason" required maxlength="255"></div><p class="err note" role="alert"></p><button class="btn sm line">Send report</button></form><?php endif;
    include ROOT . '/includes/dashboard_footer.php'; exit;
}
$title = 'Flashcards';
$tab = ($_GET['tab'] ?? '') === 'mine' ? 'mine' : 'shared'; $q = trim((string)($_GET['q'] ?? '')); $per = per_page(15); $page = page_no();
$mineN = (int)val('SELECT COUNT(*) FROM decks WHERE owner_id=? AND ' . in_school(), [$uid]);
if ($tab === 'mine') {
    $w = 'd.owner_id=? AND ' . in_school('d'); $a = [$uid]; if ($q !== '') { $w .= ' AND (d.title LIKE ? OR c.code LIKE ?)'; array_push($a, "%$q%", "%$q%"); }
    $total = (int)val("SELECT COUNT(*) FROM decks d LEFT JOIN courses c ON c.id=d.course_id WHERE $w", $a);
    $list = rows("SELECT d.id,d.title,d.card_count,d.status,d.ai_generated,d.contains_answers,c.code,(SELECT COUNT(*) FROM card_progress p JOIN cards k ON k.id=p.card_id WHERE k.deck_id=d.id AND p.user_id=? AND p.known=1) kn FROM decks d LEFT JOIN courses c ON c.id=d.course_id WHERE $w ORDER BY d.id DESC LIMIT $per OFFSET " . (($page - 1) * $per), array_merge([$uid], $a));
} else {
    $w = "d.visibility='shared' AND d.status='active' AND " . in_school('d'); $a = [];
    if ($u['department_id']) { $w .= ' AND (c.department_id=? OR d.course_id IS NULL)'; $a[] = $u['department_id']; }
    if ($q !== '') { $w .= ' AND (d.title LIKE ? OR c.code LIKE ?)'; array_push($a, "%$q%", "%$q%"); }
    $total = (int)val("SELECT COUNT(*) FROM decks d LEFT JOIN courses c ON c.id=d.course_id WHERE $w", $a);
    $list = rows("SELECT d.id,d.title,d.card_count,d.contains_answers,d.ai_generated,c.code,u.full_name FROM decks d LEFT JOIN courses c ON c.id=d.course_id JOIN users u ON u.id=d.owner_id WHERE $w ORDER BY d.id DESC LIMIT $per OFFSET " . (($page - 1) * $per), $a);
}
$courses = rows('SELECT id,CONCAT(code," · ",title) n FROM courses WHERE ' . in_school() . ' ORDER BY code');
include ROOT . '/includes/dashboard_header.php';
echo page_head('Flashcards', 'Shared decks are free. Plus lets you build your own and track what you know.', $plus ? modal_btn('tpl-newdeck', 'Create a deck', 'New deck', 'btn', 'plus', 'wide') : '');
?>
<div class="tabs" role="navigation"><a class="tab" href="?tab=shared" aria-selected="<?= $tab === 'shared' ? 'true' : 'false' ?>">Shared decks</a><a class="tab" href="?tab=mine" aria-selected="<?= $tab === 'mine' ? 'true' : 'false' ?>">My decks (<?= $mineN ?>)</a></div>
<section class="card dt">
  <?= dt_head($tab === 'mine' ? 'My decks' : 'Shared decks', number_format($total) . ' deck' . ($total === 1 ? '' : 's')) ?>
  <form class="toolbar" method="get"><input type="hidden" name="tab" value="<?= $tab ?>"><label class="search"><?= ic('search', 'i') ?><span class="vh">Search decks</span><input name="q" value="<?= e($q) ?>" type="search" placeholder="Search decks"></label><button class="btn sm">Search</button></form>
  <?php if (!$list) echo '<div class="deckempty">' . empty_state($tab === 'mine' ? 'You have not made any decks yet.' : 'No shared decks yet.', $tab === 'mine' && $plus ? modal_btn('tpl-newdeck', 'Create a deck', 'Make your first deck', 'btn sm', 'plus', 'wide') : '') . '</div>'; ?>
  <ul class="deckgrid" role="list">
  <?php foreach ($list as $i => $d): $href = url('dashboard/decks.php?id=' . $d['id']); $n = (int)$d['card_count']; ?>
    <li><a class="deck" href="<?= e($href) ?>" style="--i:<?= $i ?>">
      <span class="deck-top"><span class="deck-code"><?= e($d['code'] ?: 'DECK') ?></span><?= $d['ai_generated'] ? '<span class="deck-ai">' . ic('spark') . 'AI</span>' : '' ?></span>
      <b class="deck-title"><?= e($d['title']) ?></b>
      <span class="deck-meta"><?= $n ?> card<?= $n === 1 ? '' : 's' ?><?= $tab === 'mine' ? '' : ' · by ' . e(first_name((string)$d['full_name'])) ?></span>
      <span class="deck-foot">
      <?php if ($tab === 'mine'): $pc = $n ? (int)round(100 * $d['kn'] / $n) : 0; ?>
        <span class="deck-ring" style="--p:<?= $pc ?>" role="img" aria-label="<?= $pc ?>% known"><svg viewBox="0 0 44 44" aria-hidden="true"><circle class="t" cx="22" cy="22" r="18"/><circle class="v" cx="22" cy="22" r="18"/></svg><b><?= $pc ?>%</b></span>
        <span class="deck-state"><?= (int)$d['kn'] ?>/<?= $n ?> known<?= $d['status'] === 'hidden' ? '<small>Awaiting review</small>' : '' ?></span>
      <?php else: ?>
        <span class="deck-ans <?= $d['contains_answers'] ? 'plus' : '' ?>"><?= $d['contains_answers'] ? ic('lock') . 'Answers: Plus' : 'Free to study' ?></span>
      <?php endif; ?>
        <span class="deck-go" aria-hidden="true">Study <?= ic('chev', 'i flip') ?></span>
      </span>
    </a></li>
  <?php endforeach; ?>
  </ul>
  <?= dt_foot($total, $per, $page, ['tab' => $tab, 'q' => $q]) ?>
</section>
<?php if ($plus): ?>
<template id="tpl-newdeck">
<form class="card mform" id="deckform" data-api="decks.php?a=create" data-redirect="dashboard/decks.php?id={id}">
  <div class="f2"><div class="field"><label for="dt">Deck title</label><input class="in" id="dt" name="title" required maxlength="160" placeholder="CSC 201 key terms"></div>
  <div class="field"><label for="dc">Course (optional)</label><select class="in" id="dc" name="course_id"><?= options($courses, null, 'id', 'n', 'No course') ?></select></div></div>
  <div id="cardrows" class="stack"></div>
  <div class="inline"><button class="btn sm line" type="button" id="addcard"><?= ic('plus') ?>Add card</button></div>
  <p class="err note" role="alert"></p><button class="btn" type="submit">Save deck</button>
</form>
</template>
<?php else: echo lock_note('Building your own decks', 'decks'); endif; ?>
<?php include ROOT . '/includes/dashboard_footer.php'; ?>
