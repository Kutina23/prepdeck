<?php /** Lecturer dashboard sidebar, built from lecturer_nav() and the viewer's permissions. Plus features show a lock until the lecturer has Plus. */
$badges = ['staffq' => staff_queue_count(), 'drafts' => (int)val("SELECT COUNT(*) FROM lesson_notes WHERE user_id=? AND status='draft'", [$u['id']])]; ?>
  <aside class="side" id="side" aria-label="Lecturer menu">
    <a class="logo" href="<?= e(url('lecturer/')) ?>" aria-label="PrepDeck lecturer dashboard"><?= $logoSvg ?>PrepDeck</a>
    <span class="chipadmin">Lecturer &middot; <?= $plus ? 'Plus' : 'Free' ?></span>
    <nav id="snav" aria-label="Lecturer sections">
      <?php foreach (lecturer_nav() as [$group, $items]):
        $vis = array_filter($items, fn($i) => admin_allowed($i[4])); if (!$vis) continue; ?>
      <span class="grp mono" style="display:block"><?= e($group) ?></span>
      <?php foreach ($vis as [$key, $label, $icon, $href, $perm, $badge, $isPlus]): ?>
      <a class="sl" href="<?= e(url($href)) ?>"<?= $active === $key ? ' aria-current="page"' : '' ?>><span class="ic"><?= ic($icon) ?></span><?= e($label) ?><?= $isPlus && !$plus ? ic('lock', 'i lk') : '' ?><?= $badge && !empty($badges[$badge]) ? '<span class="cnt">' . (int)$badges[$badge] . '</span>' : '' ?></a>
      <?php endforeach; endforeach; ?>
      <span class="grp mono" style="display:block">Account</span>
      <?php if (!$plus): ?><a class="sl" href="<?= e(url('dashboard/plan.php')) ?>"><span class="ic"><?= ic('spark') ?></span>Get Plus</a><?php endif; ?>
      <?php if (!is_lecturer($u)): /* admins previewing the lecturer dashboard */ ?>
      <?php if (admin_allowed(Auth::ADMIN_AREA)): ?><a class="sl" href="<?= e(url('admin/')) ?>"><span class="ic"><?= ic('shield') ?></span>Admin area</a><?php endif; ?>
      <a class="sl" href="<?= e(url('dashboard/')) ?>"><span class="ic"><?= ic('home') ?></span>Student view</a>
      <?php endif; ?>
      <a class="sl" href="<?= e(url('dashboard/settings.php')) ?>"><span class="ic"><?= ic('gear') ?></span>Settings</a>
      <a class="sl" href="<?= e(url('logout.php')) ?>"><span class="ic"><?= ic('logout') ?></span>Sign out</a>
    </nav>
    <div class="me"><span class="av" aria-hidden="true"><?= $av ?></span><div><b><?= $firstName ?></b><small><?= $roleLabel ?></small></div></div>
  </aside>
