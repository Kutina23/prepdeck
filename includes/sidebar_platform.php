<?php /** Platform owner sidebar (super admin), built from platform_nav(). */
$badges = platform_badges(); ?>
  <aside class="side" id="side" aria-label="Platform menu">
    <a class="logo" href="<?= e(url('platform/')) ?>" aria-label="PrepDeck platform"><?= $logoSvg ?>PrepDeck</a>
    <span class="chipadmin">Platform owner</span>
    <nav id="snav" aria-label="Platform sections">
      <?php foreach (platform_nav() as [$group, $items]): ?>
      <span class="grp mono" style="display:block"><?= e($group) ?></span>
      <?php foreach ($items as [$key, $label, $icon, $href, $perm, $badge]): ?>
      <a class="sl" href="<?= e(url($href)) ?>"<?= $active === $key ? ' aria-current="page"' : '' ?>><span class="ic"><?= ic($icon) ?></span><?= e($label) ?><?= $badge && !empty($badges[$badge]) ? '<span class="cnt">' . (int)$badges[$badge] . '</span>' : '' ?></a>
      <?php endforeach; endforeach; ?>
      <span class="grp mono" style="display:block">School area</span>
      <a class="sl" href="<?= e(url('admin/')) ?>"><span class="ic"><?= ic('grid') ?></span><?= e(Tenant::school()['short_name'] ?? '') ?: 'School' ?> admin</a>
      <a class="sl" href="<?= e(url('dashboard/')) ?>"><span class="ic"><?= ic('home') ?></span>Student view</a>
      <a class="sl" href="<?= e(url('logout.php')) ?>"><span class="ic"><?= ic('logout') ?></span>Sign out</a>
    </nav>
    <div class="me"><span class="av" aria-hidden="true"><?= $av ?></span><div><b><?= $firstName ?></b><small><?= $roleLabel ?></small></div></div>
  </aside>
