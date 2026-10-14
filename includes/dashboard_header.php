<?php
/**
 * Shared shell for the student dashboard AND the admin area.
 * Set before including:  $title, $active (nav key), $scripts (array of js files), optional $adminArea = true, $staffArea = true, $lecturerArea = true or $platformArea = true
 * The sidebar lives in its own files: sidebar_student.php / sidebar_admin.php / sidebar_staff.php / sidebar_lecturer.php
 * Lecturers always get the lecturer sidebar (shared staff pages and the few account pages they may open); class reps and other staff keep the staff desk.
 */
$u = $u ?? require_login();
$lecturerArea = !empty($lecturerArea) || is_lecturer($u);   // lecturers never get the student shell, even on shared account pages
$staffArea = !empty($staffArea) || $lecturerArea;
$platformArea = !empty($platformArea);
$adminArea = !empty($adminArea) || $staffArea || $platformArea;   // the staff desk, lecturer dashboard and platform area reuse the admin layout
$plus = $plus ?? is_plus($u);
$active = $active ?? '';
$scripts = $scripts ?? [];
if (!$adminArea && in_maintenance($u)) {
    http_response_code(503);
    exit('<!doctype html><meta name="viewport" content="width=device-width,initial-scale=1"><body style="font:16px system-ui;padding:32px;max-width:520px;margin:auto"><h1>Back soon</h1><p>PrepDeck is down for maintenance. Please try again in a little while.</p>');
}
$isAdmin = $adminArea;
$logoSvg = logo_svg();
$av = e(initials($u['full_name']));
$firstName = e(first_name($u['full_name']));
$roleLabel = $adminArea ? (is_super($u) ? 'Platform owner' : ((int)$u['role_id'] === 4 ? 'School admin' : e(ucfirst(str_replace('_', ' ', $u['role'])) . ((int)$u['role_id'] === 2 ? ' (class rep)' : '')))) : ($plus ? 'Plus member' : 'Free member');
$home = $platformArea ? 'platform/' : ($lecturerArea ? 'lecturer/' : ($staffArea ? 'staff/' : ($adminArea ? 'admin/' : 'dashboard/')));
$school = Tenant::school(); $schoolChoices = is_super($u) ? rows('SELECT id,name,short_name FROM schools WHERE is_active=1 ORDER BY name') : Tenant::choices($u);
$latest = rows('SELECT title,body,publish_at FROM announcements WHERE ' . in_school() . ' AND publish_at<=NOW() AND (audience="all" OR (audience="plus" AND ?=1) OR (audience="free" AND ?=0) OR (audience="department" AND department_id=?)) ORDER BY publish_at DESC LIMIT 6',
    [$plus ? 1 : 0, $plus ? 1 : 0, (int)$u['department_id']]);
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?= e($title ?? 'PrepDeck') ?> &middot; PrepDeck</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,500;12..96,700;12..96,800&family=Figtree:wght@400;500;600&family=IBM+Plex+Mono:wght@500&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
  <link rel="icon" type="image/svg+xml" href="<?= e(url('favicon.svg')) ?>">
  <link rel="stylesheet" href="<?= e(asset($adminArea ? 'css/admin.css' : 'css/app.css')) ?>">
  <link rel="stylesheet" href="<?= e(asset('css/pw.css')) ?>">
  <link rel="stylesheet" href="<?= e(asset('css/ui.css')) ?>">
  <meta name="csrf" content="<?= Csrf::token() ?>">
  <meta name="base" content="<?= e(url()) ?>">
</head>
<body class="<?= $adminArea ? 'is-admin' : 'is-student' ?>">
<div id="pageLoader" class="spinner-overlay" role="status" aria-label="Loading page"><span class="spinner"></span></div>
<div class="app" id="app"<?= $adminArea ? '' : ' data-tools="closed" data-side="open" data-plan-now="' . ($plus ? 'plus' : 'free') . '"' ?>>

  <?php
  // account menu: where this person can go, then settings and sign out
  $acct = [];
  if (is_super($u)) $acct[] = ['platform/', 'shield', 'Platform'];
  if (admin_allowed(['admin', 'approve', 'decks', 'users', 'plans', 'reports', 'settings'])) $acct[] = ['admin/', 'grid', is_super($u) ? 'School admin area' : 'Admin area'];
  if (staff_area_ok()) $acct[] = [staff_home($u), 'cap', is_lecturer($u) ? 'Lecturer dashboard' : ((int)$u['role_id'] === 2 ? 'Class rep desk' : 'Staff desk')];
  if (!is_lecturer($u)) $acct[] = ['dashboard/', 'home', 'Student view'];
  if (!is_owner($u)) $acct[] = ['dashboard/plan.php', 'card', 'Plan & billing'];
  $acct[] = ['dashboard/settings.php', 'gear', 'Settings'];
  $acct[] = ['dashboard/help.php', 'help', 'Help'];
  $acct = array_values(array_filter($acct, fn($l) => !(($l[0] === 'platform/' && $platformArea) || ($l[0] === 'dashboard/' && !$adminArea))));
  ?>
  <header class="appbar">
    <button class="icobtn menubtn" type="button" id="menuBtn" aria-label="Open menu" aria-controls="side" aria-expanded="false"><?= ic('menu') ?></button>
    <a class="logo" href="<?= e(url($home)) ?>" aria-label="PrepDeck home"><?= $logoSvg ?><span>PrepDeck</span></a>
    <?php if ($adminArea): ?><span class="chipadmin"><?= $platformArea ? 'Platform' : ($lecturerArea ? 'Lecturer' : ($staffArea ? ((int)$u['role_id'] === 2 ? 'Rep' : 'Staff') : (is_owner($u) ? 'Admin' : 'Staff'))) ?></span><?php endif; ?>
    <span class="abtitle"><?= e($title ?? '') ?></span>
    <span class="sp"></span>
    <?php if (!$adminArea): ?><a class="planchip" href="<?= e(url('dashboard/plan.php')) ?>" data-plan="<?= $plus ? 'plus' : 'free' ?>" aria-label="Your plan. Manage subscription."><?= is_owner($u) ? 'Admin' : ($plus ? 'Plus' : 'Free') ?></a><?php endif; ?>
    <div class="dropwrap">
      <button class="icobtn bell-btn" type="button" data-drop="bellpane" aria-controls="bellpane" aria-expanded="false" aria-label="Notifications"><?= ic('bell') ?><span class="dot" hidden></span></button>
      <div class="hdrop" id="bellpane" role="dialog" aria-label="Notifications" hidden>
        <div class="drophd"><b>Notifications</b><small><?= e(Tenant::name() ?: 'PrepDeck') ?></small></div>
        <div class="droplist"><?php foreach ($latest as $n): ?><article class="dropitem"><h3><?= e($n['title']) ?></h3><small><?= e(ago($n['publish_at'])) ?></small><p><?= nl2br(e(mb_strimwidth($n['body'], 0, 400, '…'))) ?></p></article><?php endforeach; ?>
          <?php if (!$latest): ?><p class="dropempty">Nothing new. Announcements from your school show up here.</p><?php endif; ?></div>
      </div>
    </div>
    <div class="dropwrap">
      <button class="avbtn" type="button" data-drop="acctpane" aria-controls="acctpane" aria-expanded="false" aria-label="Account menu"><span class="av"><?= $av ?></span></button>
      <div class="hdrop" id="acctpane" role="menu" aria-label="Account" hidden>
        <div class="drophd acct"><span class="av"><?= $av ?></span><div><b><?= e($u['full_name']) ?></b><small><?= e($u['email']) ?></small><small><?= $roleLabel ?></small></div></div>
        <nav class="droplinks"><?php foreach ($acct as [$h, $i, $l]): ?><a role="menuitem" href="<?= e(url($h)) ?>"><?= ic($i) ?><?= e($l) ?></a><?php endforeach; ?>
          <a role="menuitem" class="out" href="<?= e(url('logout.php')) ?>"><?= ic('logout') ?>Sign out</a></nav>
      </div>
    </div>
  </header>

  <div class="scrim" id="scrim" hidden></div>
  <?php include __DIR__ . ($platformArea ? '/sidebar_platform.php' : ($lecturerArea ? '/sidebar_lecturer.php' : ($staffArea ? '/sidebar_staff.php' : ($adminArea ? '/sidebar_admin.php' : '/sidebar_student.php')))); ?>

  <main class="main" id="top">
    <?php if (!$platformArea && ($school || $schoolChoices)): ?>
    <div class="schoolbar">
      <?php if ($school): ?><span class="slogo" aria-hidden="true"><?php if ($school['logo']): ?><img src="<?= e(url('api/schools.php?a=logo&id=' . $school['id'])) ?>" alt=""><?php else: ?><?= e(initials($school['short_name'] ?: $school['name'])) ?><?php endif; ?></span>
      <span class="sname"><b><?= e($school['name']) ?></b><?php if ($school['region'] || $school['city']): ?><small><?= e(trim(($school['city'] ?? '') . ', ' . ($school['region'] ?? ''), ', ')) ?></small><?php endif; ?></span><?php else: ?><span class="sname"><b>No school selected</b><small>Pick one to work in</small></span><?php endif; ?>
      <?php if (count($schoolChoices) > 1 || (is_super($u) && $schoolChoices)): ?>
      <form class="sswitch" method="post" action="<?= e(url('switch.php')) ?>"><?= csrf_field() ?><input type="hidden" name="back" value="<?= e($_SERVER['REQUEST_URI'] ?? '') ?>">
        <label class="vh" for="sswitch">Switch school</label><select class="in" id="sswitch" name="school_id" data-autosubmit><?php foreach ($schoolChoices as $c): ?><option value="<?= (int)$c['id'] ?>"<?= (int)$c['id'] === sid() ? ' selected' : '' ?>><?= e($c['short_name'] ? $c['short_name'] . ' · ' . $c['name'] : $c['name']) ?></option><?php endforeach; ?></select>
        <noscript><button class="btn sm line">Switch</button></noscript></form>
      <?php endif; ?>
      <?php if (is_super($u)): ?><a class="btn sm line" href="<?= e(url('platform/')) ?>"><?= ic('shield') ?>Platform</a><?php endif; ?>
    </div>
    <?php endif; ?>
    <?= flash_render() ?>
    <?php if (!$adminArea && array_key_exists('email_verified_at', $u) && empty($u['email_verified_at'])): ?><div class="banner" style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;justify-content:space-between"><span>Please confirm your email (<?= e($u['email']) ?>) so password resets and receipts reach you. Check your inbox and spam folder.</span><button class="btn sm line" type="button" data-api="auth.php?a=resend_verify" data-stay>Send a new link</button></div><?php endif; ?>
    <?php if ($adminArea && Settings::global('platform_maintenance', '0') === '1'): ?><div class="banner warn">The whole platform is in maintenance mode. Students in every school see a maintenance page until the platform owner turns it off.</div>
    <?php elseif ($adminArea && !$platformArea && setting('maintenance', '0') === '1'): ?><div class="banner warn">Maintenance mode is on for this school. Its students see a maintenance page until you turn it off in Settings.</div><?php endif; ?>
