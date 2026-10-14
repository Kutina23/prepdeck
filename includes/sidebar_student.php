<?php /** Student sidebar. Needs: $u, $plus, $active, $av, $firstName, $roleLabel, $logoSvg */ ?>
  <aside class="side" id="side" aria-label="Main menu">
    <a class="logo" href="<?= e(url('dashboard/')) ?>" aria-label="PrepDeck home"><?= $logoSvg ?><span>PrepDeck</span></a>
    <nav aria-label="Dashboard menu">
      <?php foreach (student_nav() as [$group, $items]): ?>
      <span class="grp mono"><?= e($group) ?></span>
      <?php foreach ($items as [$key, $label, $icon, $href, $plusOnly]): ?>
      <a class="link" href="<?= e(url($href)) ?>"<?= $active === $key ? ' aria-current="page"' : '' ?>><span class="ic"><?= ic($icon) ?></span><span class="t"><?= e($label) ?></span><?= $plusOnly ? ic('lock', 'i lk') : '' ?></a>
      <?php endforeach; endforeach; ?>
    </nav>
    <?php if (!$plus): ?>
    <div class="upsell">
      <b>Unlock PrepDeck Plus</b>
      <p>AI help, solved answers, study guides, lecture recording and every game.</p>
      <a class="upbtn" href="<?= e(url('dashboard/plan.php')) ?>">See what is included</a>
    </div>
    <?php endif; ?>
    <div class="me">
      <span class="av" aria-hidden="true"><?= $av ?></span>
      <div><b><?= $firstName ?></b><small><?= $roleLabel ?></small></div>
    </div>
    <button class="sidetoggle" type="button" id="side-toggle" aria-label="Collapse sidebar" title="Collapse sidebar"><?= ic('chev') ?></button>
  </aside>
