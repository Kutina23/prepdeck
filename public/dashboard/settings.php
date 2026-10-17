<?php
require __DIR__ . '/../../app/bootstrap.php';
$u = require_login(); $plus = is_plus($u); $uid = (int)$u['id'];
$active = 'settings'; $title = 'Settings'; $scripts = ['pw.js', 'forms.js', 'vendor/qrcode.min.js', 'twofa.js'];
$twofaOn = !empty($u['twofa_enabled_at']);
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
        else { q('UPDATE users SET password_hash=? WHERE id=?', [password_hash($new, PASSWORD_DEFAULT), $uid]); Auth::keepSession(); Audit::log('changed password', 'user', $uid); SecurityLog::record('password_changed', 'warning', $u); flash('ok', 'Password changed. You were signed out on your other devices.'); }
    } elseif ($a === 'twofa_on' && !$twofaOn) {
        // the secret was made when this page was shown; the typed code proves the app holds it before it is switched on
        $secret = (string)($_SESSION['twofa_setup'] ?? ''); $step = $secret !== '' ? Totp::verify($secret, (string)($_POST['code'] ?? '')) : null;
        if ($step === null) flash('bad', 'That code is not right. Scan the QR code again and type the newest 6-digit code.');
        else {
            $_SESSION['twofa_codes'] = Auth::twoFactorOn($uid, $secret, $step); unset($_SESSION['twofa_setup']);
            Audit::log('turned on two-step sign-in', 'user', $uid); SecurityLog::record('twofa_enabled', 'info', $u);
            flash('ok', 'Two-step sign-in is on. Save your recovery codes now.');
        }
    } elseif ($a === 'twofa_off' && $twofaOn) {
        $row = row('SELECT password_hash FROM users WHERE id=?', [$uid]);
        if (Throttle::over("twofa-settings:$uid", 10, 15)) flash('bad', 'Too many tries. Wait 15 minutes and try again.');
        elseif (!password_verify((string)($_POST['current'] ?? ''), $row['password_hash'])) flash('bad', 'Your password is not right.');
        elseif (!Auth::checkSecondFactor($u, (string)($_POST['code'] ?? ''))) { SecurityLog::record('twofa_failed', 'warning', $u, null, 'while turning two-step off'); flash('bad', 'That code is not right.'); }
        else { Auth::twoFactorOff($uid); Audit::log('turned off two-step sign-in', 'user', $uid); SecurityLog::record('twofa_disabled', 'warning', $u); flash('ok', 'Two-step sign-in is off. Only your password protects this account now.'); }
    } elseif ($a === 'twofa_codes' && $twofaOn) {
        if (Throttle::over("twofa-settings:$uid", 10, 15)) flash('bad', 'Too many tries. Wait 15 minutes and try again.');
        elseif (!Auth::checkSecondFactor($u, (string)($_POST['code'] ?? ''))) { SecurityLog::record('twofa_failed', 'warning', $u, null, 'while making new recovery codes'); flash('bad', 'That code is not right.'); }
        else { $_SESSION['twofa_codes'] = Auth::newRecoveryCodes($uid); Audit::log('made new recovery codes', 'user', $uid); SecurityLog::record('recovery_new', 'info', $u); flash('ok', 'New recovery codes made. The old ones no longer work.'); }
    }
    redirect_or_ajax('dashboard/settings.php');
}
$deps = rows('SELECT id,name FROM departments WHERE is_active=1 AND school_id=? ORDER BY name', [(int)$u['school_id']]);   // always the home school
$levels = ['100' => '100 level', '200' => '200 level', '300' => '300 level', '400' => '400 level', '500' => '500 level', 'postgraduate' => 'Postgraduate'];
$lv = []; foreach ($levels as $k => $v) $lv[] = ['id' => $k, 'n' => $v];
// two-step sign-in: a set-up secret waits in the session (same QR code on every reload) until a code from it is typed back
if (!$twofaOn) $_SESSION['twofa_setup'] ??= Totp::secret();
$setup = $twofaOn ? '' : $_SESSION['twofa_setup'];
$newCodes = $_SESSION['twofa_codes'] ?? null; unset($_SESSION['twofa_codes']);   // shown once, right after they are made
$codesLeft = $twofaOn ? Auth::recoveryLeft($uid) : 0;
$events = rows('SELECT event,severity,detail,ip,user_agent,created_at FROM security_log WHERE user_id=? ORDER BY id DESC LIMIT 8', [$uid]);
include ROOT . '/includes/dashboard_header.php';
echo page_head('Settings', e($u['email']));
?>
<?php if ($newCodes): ?>
<section class="card twofa-codes" aria-labelledby="rch">
  <h2 id="rch">Your recovery codes</h2>
  <p class="note">If you lose your phone, each of these codes signs you in once instead of an authenticator code. Save them somewhere safe now (a password manager, or printed). <b>They will not be shown again.</b></p>
  <ol class="rcodes" id="rcodes"><?php foreach ($newCodes as $c): ?><li><code><?= e($c) ?></code></li><?php endforeach; ?></ol>
  <div class="rowact"><button type="button" class="btn sm" id="rcCopy"><?= ic('copy') ?>Copy</button><button type="button" class="btn sm line" id="rcSave"><?= ic('download') ?>Download</button><button type="button" class="btn sm line" onclick="window.print()">Print</button></div>
</section>
<?php endif; ?>
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
<section class="card" aria-labelledby="tfh"><h2 id="tfh">Two-step sign-in</h2>
  <?php if ($twofaOn): ?>
  <p style="margin-top:8px"><span class="pill ok">On</span> since <?= e(fdate($u['twofa_enabled_at'])) ?></p>
  <p class="note" style="margin-top:8px">Signing in needs your password and a code from your authenticator app. <?= $codesLeft ?> of 10 recovery codes left<?= $codesLeft <= 3 ? ': <b>make new ones soon</b>' : '' ?>.</p>
  <div class="rowact" style="margin-top:14px"><?= modal_btn('tpl-twofa-codes', 'New recovery codes', 'New recovery codes', 'btn sm line', 'lock') ?><?= modal_btn('tpl-twofa-off', 'Turn off two-step sign-in', 'Turn off', 'btn sm danger') ?></div>
  <?php else: ?>
  <p style="margin-top:8px"><span class="pill wait">Off</span></p>
  <p class="note" style="margin-top:8px">Add a second check when you sign in: a 6-digit code from an authenticator app on your phone (Google Authenticator, Microsoft Authenticator, Authy, 1Password ...). Someone who learns your password still cannot get in.</p>
  <div class="rowact" style="margin-top:14px"><?= modal_btn('tpl-twofa-on', 'Set up two-step sign-in', 'Set up', 'btn sm', 'shield') ?></div>
  <?php endif; ?>
</section>
<?php if ($twofaOn): ?>
<template id="tpl-twofa-off">
<form class="card mform" method="post" autocomplete="off" data-action="dashboard/settings.php"><?= csrf_field() ?><input type="hidden" name="a" value="twofa_off"><h2>Turn off two-step sign-in</h2>
  <p class="note">Your account will be protected by your password only.</p>
  <div class="field"><label for="tfp">Password</label><input class="in" id="tfp" name="current" type="password" required autocomplete="current-password"></div>
  <div class="field"><label for="tfc">Authenticator code or recovery code</label><input class="in" id="tfc" name="code" required autocomplete="one-time-code" spellcheck="false"></div>
  <button class="btn danger" type="submit">Turn off</button></form>
</template>
<template id="tpl-twofa-codes">
<form class="card mform" method="post" autocomplete="off" data-action="dashboard/settings.php"><?= csrf_field() ?><input type="hidden" name="a" value="twofa_codes"><h2>New recovery codes</h2>
  <p class="note">You get 10 new codes and every old one stops working.</p>
  <div class="field"><label for="tfn">Authenticator code</label><input class="in" id="tfn" name="code" inputmode="numeric" required autocomplete="one-time-code" spellcheck="false"></div>
  <button class="btn" type="submit">Make new codes</button></form>
</template>
<?php else: ?>
<template id="tpl-twofa-on">
<form class="card mform" method="post" autocomplete="off" data-action="dashboard/settings.php"><?= csrf_field() ?><input type="hidden" name="a" value="twofa_on"><h2>Set up two-step sign-in</h2>
  <p class="note"><b>1.</b> In your authenticator app, add an account and scan this code.</p>
  <div class="twofa-qr" data-qr="<?= e(Totp::uri($setup, $u['email'])) ?>" role="img" aria-label="QR code for your authenticator app"></div>
  <p class="note">Cannot scan? Enter this key by hand (time-based):</p>
  <p><code class="code twofa-key"><?= e(trim(chunk_split($setup, 4, ' '))) ?></code></p>
  <div class="field"><label for="tfo"><b>2.</b> Type the 6-digit code the app shows</label><input class="in" id="tfo" name="code" inputmode="numeric" pattern="[0-9 ]{6,7}" maxlength="7" placeholder="123 456" required autocomplete="one-time-code" spellcheck="false"></div>
  <button class="btn" type="submit">Turn on</button></form>
</template>
<?php endif; ?>
<section class="card dt"><?= dt_head('Recent sign-in activity', 'Something you do not recognise? Change your password and turn on two-step sign-in.') ?>
  <div class="dtwrap"><table class="dtable"><thead><tr><th>When</th><th>What</th><th>Device</th><th>IP address</th></tr></thead><tbody>
  <?php foreach ($events as $ev): ?><tr><td class="nw" data-v="<?= e($ev['created_at']) ?>"><?= e(ago($ev['created_at'])) ?></td><td class="dmain"><?= $ev['severity'] !== 'info' ? '<span class="pill ' . ($ev['severity'] === 'critical' ? 'bad' : 'wait') . '">' . e(ucfirst($ev['severity'])) . '</span> ' : '' ?><?= e(SecurityLog::label($ev['event'])) ?></td><td class="nw muted"><?= e(SecurityLog::device($ev['user_agent'])) ?></td><td class="nw muted"><?= e($ev['ip'] ?: '–') ?></td></tr>
  <?php endforeach; if (!$events) echo dt_empty(4, 'Nothing yet.'); ?></tbody></table></div>
</section>
<section class="card"><h2>Account</h2><dl class="dl" style="margin-top:12px"><dt>Plan</dt><dd><?= $plus ? 'Plus' : 'Free' ?><?= $u['plus_expires'] ? ' until ' . e(fdate($u['plus_expires'])) : '' ?></dd><dt>Email</dt><dd><?= e($u['email']) ?> <?= !empty($u['email_verified_at']) ? '<span class="pill ok">Confirmed</span>' : '<span class="pill wait">Not confirmed</span>' ?></dd><dt>Member since</dt><dd><?= e(fdate($u['created_at'])) ?></dd><dt>Role</dt><dd><?= e(ucfirst(str_replace('_', ' ', $u['role']))) ?></dd></dl>
  <div class="inline" style="margin-top:14px"><a class="btn sm line" href="<?= e(url('dashboard/plan.php')) ?>">Plan &amp; billing</a><a class="btn sm line" href="<?= e(url('logout.php')) ?>">Sign out</a></div></section>
</div>
<?php include ROOT . '/includes/dashboard_footer.php'; ?>
