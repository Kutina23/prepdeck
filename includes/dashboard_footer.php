<?php
/** Closes the shell opened by dashboard_header.php. Student: tools rail, bottom tabs, sheets. Admin: bottom tabs, sheets. */
$lecturerArea = !empty($lecturerArea);
$staffArea = !empty($staffArea) || $lecturerArea;
$isAdmin = !empty($adminArea) || $staffArea;
$navFn = !empty($platformArea) ? 'platform_nav' : ($lecturerArea ? 'lecturer_nav' : ($staffArea ? 'staff_nav' : 'admin_nav'));
$plus = $plus ?? is_plus($u);
$timerMax = $plus ? (int)setting('plus_max_timer', 180) : (int)setting('free_timer', 15);
$bell = $latest ?? [];
?>
  </main>
  <?php if ($lecturerArea) include __DIR__ . '/lecturer_tools.php'; ?>
  <?php if (!$isAdmin): ?>
  <aside class="tools" id="tools" aria-label="Study tools">
    <div class="thead">
      <h2>Study tools</h2>
      <button class="tt" id="tt" type="button" aria-expanded="false" aria-controls="tools" aria-label="Expand study tools" title="Expand study tools"><?= ic('chev') ?></button>
    </div>
    <div class="tgrid">
      <?php
      $tools = [['ai', 'PrepDeck AI', 'Ask, quiz, plan', 'spark', 'var(--pop)', 'dashboard/ai.php', true],
                ['learn', 'Learn', 'Adaptive: choose, then write', 'flame', 'var(--brand);color:var(--brand-ink)', 'dashboard/learn.php', false],
                ['decks', 'Flashcards', 'Flip through a deck', 'cards', 'var(--lemon)', 'dashboard/decks.php', false],
                ['test', 'Test', 'Auto-made test, marked', 'docchk', 'var(--lemon)', 'dashboard/test.php', false],
                ['review', 'Smart review', 'Spaced repetition', 'refresh', 'var(--pop)', 'dashboard/review.php', true],
                ['study', 'Study hub', 'Goal, tasks, exams', 'cal', 'var(--surface);color:var(--ink)', 'dashboard/study.php', false],
                ['practice', 'Practice exam', 'Timed, with model answers', 'docchk', 'var(--pop)', 'dashboard/practice.php', true],
                ['guides', 'Study guide', 'Guides from your papers', 'book', 'var(--lemon)', 'dashboard/guides.php', true],
                ['recordings', 'Record lecture', 'Audio and transcript', 'mic', 'var(--brand);color:var(--brand-ink)', 'dashboard/recordings.php', true],
                ['games', 'Games', 'Match is free', 'game', 'var(--lemon)', 'dashboard/games.php', false],
                ['materials', 'Upload notes', 'Guides, cards, games', 'upload', 'var(--lemon)', 'dashboard/materials.php', false],
                ['notes', 'Notes', 'Write and organise', 'note', 'var(--surface);color:var(--ink)', 'dashboard/notes.php', false]];
      foreach ($tools as [$k, $label, $sub, $icon, $bg, $href, $lock]): $lock = $lock && plus_locked($k); ?>
      <a class="tool" href="<?= e(url($href)) ?>" title="<?= e($label) ?>" aria-label="<?= e($label) . ($lock ? ' (Plus)' : '') ?>"<?= $lock ? plus_lock($k) : '' ?><?= ($active ?? '') === $k ? ' aria-current="page"' : '' ?>><?php if ($lock): ?><span class="lk"><?= ic('lock', '') ?></span><?php endif; ?><span class="ic" style="background:<?= $bg ?>;"><?= ic($icon) ?></span><span class="tx"><b><?= e($label) ?></b><small><?= e($sub) ?></small></span></a>
      <?php endforeach; ?>
    </div>
    <div class="railbtns">
      <button class="rb" type="button" data-expand="timerbox" title="Focus timer" aria-label="Focus timer"><span class="ic" style="background:var(--surface);color:var(--ink);border-color:var(--ink);"><?= ic('timer') ?></span><small id="tmini"></small></button>
    </div>
    <section class="tbox" id="timerbox" aria-labelledby="th" data-max="<?= $timerMax ?>" data-plus="<?= $plus ? 1 : 0 ?>">
      <h3 id="th">Focus timer</h3>
      <div class="seg" id="seg" role="group" aria-label="Timer mode">
        <button type="button" data-mode="focus" aria-pressed="true">Focus <?= $plus ? 25 : min($timerMax, 15) ?></button>
        <button type="button" data-mode="break" aria-pressed="false">Break 5</button>
      </div>
      <div class="adj" id="adj"<?= $plus ? '' : ' hidden' ?>>
        <div class="step" role="group" aria-label="Session length">
          <button class="stp" type="button" id="dec" aria-label="Shorter by 5 minutes">&minus;</button>
          <output id="lenv"><?= $plus ? 25 : min($timerMax, 15) ?> min</output>
          <button class="stp" type="button" id="inc" aria-label="Longer by 5 minutes">+</button>
        </div>
        <div class="presets" id="presets" role="group" aria-label="Preset lengths"></div>
      </div>
      <?php if (!$plus): ?><a class="adjlock" href="<?= e(url('dashboard/plan.php')) ?>"<?= plus_lock('timer') ?>><?= ic('lock') ?>Set your own length<b>Plus</b></a><?php endif; ?>
      <div class="timer">
        <div class="dial"><svg viewBox="0 0 100 100" aria-hidden="true"><circle class="tr" cx="50" cy="50" r="44"/><circle class="pg" id="tp" cx="50" cy="50" r="44"/></svg><output id="tt2"><?= sprintf('%02d:00', $plus ? 25 : min($timerMax, 15)) ?></output></div>
        <div class="ctl"><button class="btn sm" type="button" id="ts">Start</button><button class="btn sm line" type="button" id="tr">Reset</button></div>
      </div>
    </section>
    <section class="tbox" id="notesbox" aria-labelledby="nh">
      <h3 id="nh">Quick notes <span class="note" id="ns">Saved on this device</span></h3>
      <label for="notes" class="vh">Quick notes</label>
      <textarea class="in" id="notes" placeholder="Jot a formula, a question for the lecturer, anything."></textarea>
      <div class="nai"><a class="btn sm" href="<?= e(url('dashboard/notes.php')) ?>">All notes<?= $plus ? '' : '<b class="pl">Plus</b>' ?></a></div>
    </section>
  </aside>

  <nav class="bnav" aria-label="Main">
    <a class="bn" href="<?= e(url('dashboard/')) ?>"<?= ($active ?? '') === 'home' ? ' aria-current="page"' : '' ?>><?= ic('home') ?><span>Home</span></a>
    <a class="bn" href="<?= e(url('dashboard/papers.php')) ?>"<?= ($active ?? '') === 'papers' ? ' aria-current="page"' : '' ?>><?= ic('file') ?><span>Papers</span></a>
    <a class="bn askai" href="<?= e(url('dashboard/ai.php')) ?>" aria-label="Ask PrepDeck AI"<?= plus_locked('ai') ? plus_lock('ai') : '' ?>><span class="orb"><?= ic('spark') ?></span><span>Ask AI</span></a>
    <a class="bn" href="<?= e(url('dashboard/decks.php')) ?>"<?= ($active ?? '') === 'decks' ? ' aria-current="page"' : '' ?>><?= ic('cards') ?><span>Decks</span></a>
    <button class="bn" type="button" data-sheet="more" data-title="More"><?= ic('more') ?><span>More</span></button>
  </nav>
  <?php else: ?>
  <?php
  $tabs = $lecturerArea ? array_values(array_filter([['overview', 'Home', 'grid', 'lecturer/index.php'], ['outlines', 'Outlines', 'book', 'lecturer/outlines.php'], ['lessons', 'Lessons', 'lesson', 'lecturer/lessons.php'], ['review', 'Review', 'docchk', 'staff/review.php', 'review']], fn($t) => empty($t[4]) || Auth::can($t[4]) || Auth::can('approve')))
    : ($staffArea ? array_values(array_filter([['overview', 'Desk', 'grid', 'staff/index.php'], ['papers', 'Upload', 'upload', 'staff/papers.php', 'upload'], ['review', 'Review', 'docchk', 'staff/review.php', 'review']], fn($t) => empty($t[4]) || Auth::can($t[4]) || ($t[4] === 'review' && Auth::can('approve'))))
    : array_values(array_filter([['overview', 'Overview', 'grid', 'admin/index.php'], ['users', 'Users', 'users', 'admin/users.php', 'users'], ['papers', 'Papers', 'file', 'admin/papers.php', 'approve'], ['payments', 'Payments', 'coin', 'admin/payments.php', 'plans']], fn($t) => empty($t[4]) || Auth::can($t[4])))); ?>
  <nav class="bnav" id="bnav" aria-label="Admin tabs" style="grid-template-columns:repeat(<?= count($tabs) + 1 ?>,1fr)">
    <?php foreach ($tabs as $t): ?><a class="bn" href="<?= e(url($t[3])) ?>"<?= ($active ?? '') === $t[0] ? ' aria-current="page"' : '' ?>><?= ic($t[2]) ?><span><?= e($t[1]) ?></span></a><?php endforeach; ?>
    <button class="bn" type="button" data-sheet="more" data-title="More"><?= ic('more') ?><span>More</span></button>
  </nav>
  <?php endif; ?>
</div>

<div class="modal" id="modal" hidden>
  <div class="sheet" id="sheet" role="dialog" aria-modal="true" aria-labelledby="mt" tabindex="-1">
    <button class="x" id="mx" type="button" aria-label="Close"><?= ic('x') ?></button>
    <h2 id="mt"></h2>
    <div id="mb"></div>
  </div>
</div>

<template id="tpl-more"><div class="morelist">
  <?php if ($isAdmin): foreach ($navFn() as [$g, $items]) foreach ($items as [$key, $label, $icon, $href, $perm]) if (admin_allowed($perm)): ?>
    <a href="<?= e(url($href)) ?>"><span class="ic"><?= ic($icon) ?></span><?= e($label) ?></a>
  <?php endif; ?>
    <?php if ($staffArea && admin_allowed(['admin', 'approve', 'decks', 'users', 'plans', 'reports', 'settings'])): ?><a href="<?= e(url('admin/')) ?>"><span class="ic"><?= ic('shield') ?></span>Admin area</a><?php endif; ?>
    <?php if (!is_lecturer($u)): ?><a href="<?= e(url('dashboard/')) ?>"><span class="ic"><?= ic('home') ?></span>Student view</a><?php endif; ?>
    <a href="<?= e(url('logout.php')) ?>"><span class="ic"><?= ic('logout') ?></span>Sign out</a>
  <?php else: foreach (student_nav() as [$g, $items]) foreach ($items as [$key, $label, $icon, $href, $lock]): $lock = $lock && plus_locked($key); ?>
    <a href="<?= e(url($href)) ?>"<?= $lock ? plus_lock($key) : '' ?>><span class="ic"><?= ic($icon) ?></span><?= e($label) ?><?= $lock ? ic('lock', 'i lk') : '' ?></a>
  <?php endforeach; if (staff_area_ok()): ?>
    <a href="<?= e(url(staff_home($u))) ?>"><span class="ic"><?= ic('cap') ?></span><?= is_lecturer($u) ? 'Lecturer dashboard' : ((int)$u['role_id'] === 2 ? 'Class rep desk' : 'Staff desk') ?></a>
  <?php endif; if (admin_allowed(['admin', 'approve', 'decks', 'users', 'plans', 'reports', 'settings'])): ?>
    <a href="<?= e(url('admin/')) ?>"><span class="ic"><?= ic('shield') ?></span>Admin area</a>
  <?php endif; endif; ?>
</div></template>
<template id="tpl-bell"><div class="list">
  <?php foreach ($bell as $a): ?><div class="rowx" style="grid-template-columns:1fr"><div><h3><?= e($a['title']) ?></h3><small class="note"><?= e(ago($a['publish_at'])) ?></small><p style="margin-top:6px"><?= nl2br(e($a['body'])) ?></p></div></div><?php endforeach; ?>
  <?php if (!$bell): ?><div class="empty">Nothing new. Announcements from your school show up here.</div><?php endif; ?>
</div></template>

<?php if (!$plus):
  // Plus pop-up: locked tools open this over the page instead of going to it (shell.js fills in the tool that was clicked)
  $pwPrices = Billing::prices(); $pwFrom = null; foreach ($pwPrices as $p) if ($p['period'] === 'month') $pwFrom = $p; $pwFrom ??= $pwPrices[0] ?? null;
  $pwFeat = []; foreach (plus_features() as $k => [$t, $d, $i]) $pwFeat[$k] = ['t' => $t, 'd' => $d, 'i' => ic($i)]; ?>
<div class="paywall" id="paywall" hidden data-features="<?= e(json_encode($pwFeat, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?>">
  <div class="pwcard" role="dialog" aria-modal="true" aria-labelledby="pwt" aria-describedby="pwd" tabindex="-1">
    <button class="pwx" type="button" data-pw-close aria-label="Close"><?= ic('x') ?></button>
    <div class="pwhead">
      <span class="pwicon" id="pwi" aria-hidden="true"><?= ic('spark') ?></span>
      <span class="pwchip"><?= ic('lock', 'i') ?>PrepDeck Plus</span>
      <h2 id="pwt">Unlock PrepDeck Plus</h2>
      <p id="pwd"><?= e(plus_features()['plus'][1]) ?></p>
    </div>
    <div class="pwbody">
      <p class="pwlbl">Everything in Plus</p>
      <ul class="pwperks"><?php foreach (plus_perks() as $p): ?><li><?= ic('check', 'i') ?><?= e($p) ?></li><?php endforeach; ?></ul>
      <?php if ($pwFrom): ?><p class="pwprice"><small>From</small> <b><?= e(money($pwFrom['amount'], $pwFrom['currency'])) ?></b> <small>/ <?= e(['month' => 'month', 'semester' => 'semester', 'year' => 'year'][$pwFrom['period']] ?? $pwFrom['period']) ?></small></p>
      <p class="pwfine"><?= count($pwPrices) > 1 ? 'Monthly, per-semester and yearly plans. ' : '' ?>Plus switches on as soon as your payment is confirmed, and you can stop renewing any time.</p>
      <?php else: ?><p class="pwfine">Plus is not on sale right now. Check back soon or ask your school.</p><?php endif; ?>
    </div>
    <div class="pwfoot">
      <?php if ($pwFrom): ?><a class="btn pwgo" href="<?= e(url('dashboard/plan.php#checkout')) ?>"><?= ic('spark') ?>Upgrade to Plus</a><?php endif; ?>
      <button class="btn line pwlater" type="button" data-pw-close>Maybe later</button>
    </div>
  </div>
</div>
<?php endif; ?>
<div class="toast" id="toast" role="status"></div>
<script>window.PD = <?= json_encode(['plus' => $plus, 'timerMax' => $timerMax, 'admin' => $isAdmin, 'base' => url(), 'latest' => $bell ? strtotime($bell[0]['publish_at'] . ' UTC') : 0], JSON_UNESCAPED_SLASHES) ?>;</script>
<script src="<?= e(asset('js/api.js')) ?>"></script>
<script src="<?= e(asset('js/spinner.js')) ?>"></script>
<script src="<?= e(asset('js/shell.js')) ?>"></script>
<script src="<?= e(asset('js/forms.js')) ?>"></script>
<?php foreach (array_diff($scripts ?? [], ['forms.js']) as $s): ?>
<script src="<?= e(asset('js/' . $s)) ?>"></script>
<?php endforeach; ?>
</body></html>
