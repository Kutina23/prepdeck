<?php /** PrepDeck staff desk sidebar (class reps and other staff; lecturers use sidebar_lecturer.php), built from staff_nav() and the viewer's permissions. */
$badges = ['staffq' => staff_queue_count()]; ?>
  <aside class="side" id="side" aria-label="Staff menu">
    <a class="logo" href="<?= e(url('staff/')) ?>" aria-label="PrepDeck staff desk"><?= $logoSvg ?>PrepDeck</a>
    <span class="chipadmin"><?= (int)$u['role_id'] === 2 ? 'Class rep desk' : 'Staff desk' ?></span>
    <nav id="snav" aria-label="Staff sections">
      <?php foreach (staff_nav() as [$group, $items]):
        $vis = array_filter($items, fn($i) => admin_allowed($i[4])); if (!$vis) continue; ?>
      <span class="grp mono" style="display:block"><?= e($group) ?></span>
      <?php foreach ($vis as [$key, $label, $icon, $href, $perm, $badge]): ?>
      <a class="sl" href="<?= e(url($href)) ?>"<?= $active === $key ? ' aria-current="page"' : '' ?>><span class="ic"><?= ic($icon) ?></span><?= e($label) ?><?= $badge && !empty($badges[$badge]) ? '<span class="cnt">' . (int)$badges[$badge] . '</span>' : '' ?></a>
      <?php endforeach; endforeach; ?>
      <span class="grp mono" style="display:block">Account</span>
      <?php if (admin_allowed(['admin', 'approve', 'decks', 'users', 'plans', 'reports', 'settings'])): ?><a class="sl" href="<?= e(url('admin/')) ?>"><span class="ic"><?= ic('shield') ?></span>Admin area</a><?php endif; ?>
      <a class="sl" href="<?= e(url('dashboard/')) ?>"><span class="ic"><?= ic('home') ?></span>Student view</a>
      <a class="sl" href="<?= e(url('logout.php')) ?>"><span class="ic"><?= ic('logout') ?></span>Sign out</a>
    </nav>
    <div class="me"><span class="av" aria-hidden="true"><?= $av ?></span><div><b><?= $firstName ?></b><small><?= $roleLabel ?></small></div></div>
  </aside>
