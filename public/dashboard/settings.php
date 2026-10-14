<?php
require __DIR__ . '/../../app/bootstrap.php';
$u = require_login(); $plus = is_plus($u); $uid = (int)$u['id'];
$active = 'settings'; $title = 'Settings'; $scripts = ['pw.js', 'forms.js'];
if (is_post()) {
    form_guard(); $a = $_POST['a'] ?? '';
    if ($a === 'profile') {
        $name = Validator::str($_POST['full_name'] ?? '', 2, 120); $dep = (int)($_POST['department_id'] ?? 0);
        $lvl = Validator::in($_POST['level'] ?? '', ['100', '200', '300', '400', '500', 'postgraduate']);
        if (!$name || !$lvl || !val('SELECT COUNT(*) FROM departments WHERE id=? AND school_id=?', [$dep, (int)$u['school_id']])) flash('bad', 'Check your name, department and level.');
        else { q('UPDATE users SET full_name=?, department_id=?, level=? WHERE id=?', [$name, $dep, $lvl, $uid]); flash('ok', 'Profile saved.'); }
    } elseif ($a === 'email_prefs') {
        q('UPDATE users SET email_opt_out=? WHERE id=?', [isset($_POST['notify']) ? 0 : 1, $uid]); flash('ok', 'Email preferences saved.');
    } elseif ($a === 'password') {
        $row = row('SELECT password_hash FROM users WHERE id=?', [$uid]); $new = (string)($_POST['new'] ?? '');
        if (!password_verify((string)($_POST['current'] ?? ''), $row['password_hash'])) flash('bad', 'Your current password is not right.');
        elseif (strlen($new) < 8) flash('bad', 'Use at least 8 characters for the new password.');
        else { q('UPDATE users SET password_hash=? WHERE id=?', [password_hash($new, PASSWORD_DEFAULT), $uid]); Auth::keepSession(); Audit::log('changed password', 'user', $uid); flash('ok', 'Password changed. You were signed out on your other devices.'); }
    }
    redirect_or_ajax('dashboard/settings.php');
}
$deps = rows('SELECT id,name FROM departments WHERE is_active=1 AND school_id=? ORDER BY name', [(int)$u['school_id']]);   // always the home school
$levels = ['100' => '100 level', '200' => '200 level', '300' => '300 level', '400' => '400 level', '500' => '500 level', 'postgraduate' => 'Postgraduate'];
$lv = []; foreach ($levels as $k => $v) $lv[] = ['id' => $k, 'n' => $v];
include ROOT . '/includes/dashboard_header.php';
echo page_head('Settings', e($u['email']));
?>
<div class="grid2">
<button type="button" class="btn" data-sheet="tpl-profile" data-title="Edit profile"><?= ic('edit') ?>Edit profile</button>
<template id="tpl-profile">
<form class="card mform" method="post" data-action="dashboard/settings.php"><?= csrf_field() ?><input type="hidden" name="a" value="profile"><h2>Profile</h2>
  <div class="field"><label for="fn">Full name</label><input class="in" id="fn" name="full_name" value="<?= e($u['full_name']) ?>" required></div>
  <div class="field"><label for="dp">Department</label><select class="in" id="dp" name="department_id"><?= options($deps, $u['department_id']) ?></select></div>
  <div class="field"><label for="lv">Level</label><select class="in" id="lv" name="level"><?= options($lv, $u['level'], 'id', 'n') ?></select></div>
  <button class="btn" type="submit">Save profile</button></form>
</template>
<button type="button" class="btn" data-sheet="tpl-password" data-title="Change password"><?= ic('lock') ?>Change password</button>
<button type="button" class="btn line" data-sheet="tpl-email" data-title="Email preferences"><?= ic('mega') ?>Email preferences</button>
<template id="tpl-email">
<form class="card mform" method="post" data-action="dashboard/settings.php"><?= csrf_field() ?><input type="hidden" name="a" value="email_prefs"><h2>Email</h2>
  <p class="note">Emails go to <b><?= e($u['email']) ?></b>. Password resets, email confirmations and payment receipts are always sent.</p>
  <label class="chk"><input type="checkbox" name="notify" value="1"<?= empty($u['email_opt_out']) ? ' checked' : '' ?>>Email me when PrepDeck AI finishes my uploads</label>
  <button class="btn" type="submit">Save</button></form>
</template>
<template id="tpl-password">
<form class="card mform" method="post" autocomplete="off" data-action="dashboard/settings.php"><?= csrf_field() ?><input type="hidden" name="a" value="password"><h2>Password</h2>
  <div class="field"><label for="cu">Current password</label><input class="in" id="cu" name="current" type="password" required autocomplete="current-password"></div>
  <div class="field"><label for="nw">New password (8+ characters)</label><input class="in" id="nw" name="new" type="password" minlength="8" required autocomplete="new-password"></div>
  <button class="btn" type="submit">Change password</button></form>
</template>
<section class="card"><h2>Account</h2><dl class="dl" style="margin-top:12px"><dt>Plan</dt><dd><?= $plus ? 'Plus' : 'Free' ?><?= $u['plus_expires'] ? ' until ' . e(fdate($u['plus_expires'])) : '' ?></dd><dt>Email</dt><dd><?= e($u['email']) ?> <?= !empty($u['email_verified_at']) ? '<span class="pill ok">Confirmed</span>' : '<span class="pill wait">Not confirmed</span>' ?></dd><dt>Member since</dt><dd><?= e(fdate($u['created_at'])) ?></dd><dt>Role</dt><dd><?= e(ucfirst(str_replace('_', ' ', $u['role']))) ?></dd></dl>
  <div class="inline" style="margin-top:14px"><a class="btn sm line" href="<?= e(url('dashboard/plan.php')) ?>">Plan &amp; billing</a><a class="btn sm line" href="<?= e(url('logout.php')) ?>">Sign out</a></div></section>
</div>
<?php include ROOT . '/includes/dashboard_footer.php'; ?>
