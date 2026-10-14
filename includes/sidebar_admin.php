<?php /** Admin sidebar, built from admin_nav() and the viewer's permissions. */
$badges = admin_badges(); ?>
  <aside class="side" id="side" aria-label="Admin menu">
    <a class="logo" href="<?= e(url('admin/')) ?>" aria-label="PrepDeck admin"><?= $logoSvg ?>PrepDeck</a>
    <span class="chipadmin"><?= is_super($u) ? 'Platform owner' : ((int)$u['role_id'] === 4 ? 'PrepDeck staff &middot; School admin' : 'Staff &middot; limited access') ?></span>
    <nav id="snav" aria-label="Admin sections">
      <?php foreach (admin_nav() as [$group, $items]):
        $vis = array_filter($items, fn($i) => admin_allowed($i[4])); if (!$vis) continue; ?>
      <span class="grp mono" style="display:block"><?= e($group) ?></span>
      <?php foreach ($vis as [$key, $label, $icon, $href, $perm, $badge]): ?>
      <a class="sl" href="<?= e(url($href)) ?>"<?= $active === $key ? ' aria-current="page"' : '' ?>><span class="ic"><?= ic($icon) ?></span><?= e($label) ?><?= $badge && !empty($badges[$badge]) ? '<span class="cnt">' . (int)$badges[$badge] . '</span>' : '' ?></a>
      <?php endforeach; endforeach; ?>
      <span class="grp mono" style="display:block">Account</span>
      <?php if (is_super($u)): ?><a class="sl" href="<?= e(url('platform/')) ?>"><span class="ic"><?= ic('shield') ?></span>Platform</a><?php endif; ?>
      <a class="sl" href="<?= e(url('dashboard/')) ?>"><span class="ic"><?= ic('home') ?></span>Student view</a>
      <a class="sl" href="<?= e(url('logout.php')) ?>"><span class="ic"><?= ic('logout') ?></span>Sign out</a>
    </nav>
    <div class="me"><span class="av" aria-hidden="true"><?= $av ?></span><div><b><?= $firstName ?></b><small><?= $roleLabel ?></small></div></div>
  </aside>
