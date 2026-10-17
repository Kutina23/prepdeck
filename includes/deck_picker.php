<?php
/** Choose a deck for Learn / Test. Set before including: $mode ('learn' | 'test'), $decks (Study::studyDecks), $q, $plus. */
$modeName = $mode === 'learn' ? 'Learn' : 'Test';
?>
<section class="card dt"><?= dt_head('Choose a set', $decks ? 'Sets with 4 or more cards. The ones you studied last come first.' : '') ?>
  <form class="toolbar" method="get"><label class="search"><?= ic('search', 'i') ?><span class="vh">Search</span><input name="q" value="<?= e($q) ?>" type="search" placeholder="Course code or set name"></label><button class="btn sm">Search</button></form>
  <?php if (!$decks): ?><p class="note" style="padding:4px 18px 18px"><?= $q !== '' ? 'No sets match.' : 'No flashcard sets yet. Your lecturers\' sets and the ones PrepDeck AI makes from past papers appear here.' ?> <a href="<?= e(url('dashboard/decks.php')) ?>">Browse flashcards</a></p>
  <?php else: ?><div class="dm-pick"><?php foreach ($decks as $d): $lk = (int)$d['contains_answers'] && !$plus; $href = url('dashboard/' . $mode . '.php?deck=' . $d['id']); ?>
    <a class="dm-set" href="<?= e($href) ?>"<?= $lk ? plus_lock('answers') : '' ?>>
      <span class="ic" aria-hidden="true"><?= ic($mode === 'learn' ? 'spark' : 'docchk') ?></span>
      <span class="dm-st"><b><?= e($d['title']) ?></b><small><?= $d['code'] ? e($d['code']) . ' · ' : '' ?><?= (int)$d['card_count'] ?> terms<?= $d['last_test'] !== null ? ' · last test ' . (int)$d['last_test'] . '%' : '' ?></small></span>
      <?= $lk ? ic('lock', 'i lk') : '<span class="dm-go">' . e($modeName) . '</span>' ?></a>
  <?php endforeach; ?></div><?php endif; ?>
</section>
