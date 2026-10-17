<?php
require __DIR__ . '/../../app/bootstrap.php';
$u = require_perm('users'); $adminArea = true; $active = 'users'; $title = 'Users'; $scripts = ['pw.js', 'forms.js'];
// a school admin can hand out student, class rep and lecturer; making someone a school admin is up to the platform owner
$roles = rows('SELECT id,name FROM roles WHERE id ' . (is_super() ? '<>5' : 'IN (1,2,3)') . ' ORDER BY id'); $deps = rows('SELECT id,name FROM departments WHERE ' . in_school() . ' ORDER BY name');
$isAdmin = is_owner($u); $roleIds = array_map('intval', array_column($roles, 'id'));
if (is_post()) {
    form_guard(); $a = $_POST['a'] ?? ''; $id = (int)($_POST['id'] ?? 0); $t = row('SELECT * FROM users WHERE id=? AND ' . in_school(), [$id]);
    if ($a === 'create' && $isAdmin) {
        $email = strtolower(trim((string)($_POST['email'] ?? ''))); $name = Validator::str($_POST['full_name'] ?? '', 2, 120); $pw = (string)($_POST['password'] ?? ''); $role = (int)($_POST['role_id'] ?? 1);
        if (!in_array($role, $roleIds, true)) $role = 1;
        $dep = (int)($_POST['department_id'] ?? 0); if ($dep && !in_my_school('departments', $dep)) $dep = 0;
        if (!sid()) flash('bad', 'Pick a school first.');
        elseif (!Validator::email($email) || !$name || strlen($pw) < 8) flash('bad', 'Enter a name, a valid email and a password of 8+ characters.');
        elseif (val('SELECT COUNT(*) FROM users WHERE email=?', [$email])) flash('bad', 'That email already has an account.');
        else { q("INSERT INTO users(school_id,role_id,department_id,full_name,email,password_hash,status) VALUES (?,?,?,?,?,?,'active')", [sid(), $role, $dep ?: null, $name, $email, password_hash($pw, PASSWORD_DEFAULT)]); Audit::log("created user $email", 'user', (int)db()->lastInsertId()); flash('ok', 'User created.'); }
        redirect_or_ajax('admin/users.php');
    }
    if (!$t) { flash('bad', 'User not found.'); redirect_or_ajax('admin/users.php'); }
    $isTop = (int)$t['role_id'] === 4 && !is_super() || (int)$t['role_id'] === 5;   // school admins are managed by the platform owner
    if (in_array($a, ['grant', 'revoke'], true) && !is_super()) flash('bad', 'Plus is managed by the platform owner.');
    elseif ($a === 'grant') { $p = Validator::in($_POST['period'] ?? '', ['month', 'semester', 'year']) ?? 'month'; Billing::activate($id, $p); Audit::log("granted Plus ($p) to {$t['email']}", 'user', $id); flash('ok', 'Plus granted.'); }
    elseif ($a === 'revoke') { Billing::revoke($id); Audit::log("revoked Plus from {$t['email']}", 'user', $id); flash('ok', 'Plus removed.'); }
    elseif ($a === 'status' && $id !== (int)$u['id'] && !$isTop) { $s = Validator::in($_POST['status'] ?? '', ['active', 'suspended']); if ($s) { q('UPDATE users SET status=? WHERE id=?', [$s, $id]); Audit::log("$s {$t['email']}", 'user', $id); flash('ok', 'Account ' . $s . '.'); } }
    elseif ($a === 'role' && $isAdmin && $id !== (int)$u['id'] && !$isTop) { $r = (int)($_POST['role_id'] ?? 0); if (in_array($r, $roleIds, true)) { q('UPDATE users SET role_id=? WHERE id=?', [$r, $id]); Audit::log("changed role of {$t['email']} to $r", 'user', $id); flash('ok', 'Role changed.'); } else flash('bad', 'Only the platform owner can give that role.'); }
    elseif ($a === 'reset' && $isAdmin && !$isTop) { $pw = bin2hex(random_bytes(5)); q('UPDATE users SET password_hash=? WHERE id=?', [password_hash($pw, PASSWORD_DEFAULT), $id]); Audit::log("reset password of {$t['email']}", 'user', $id); SecurityLog::record('password_reset_admin', 'warning', $t, null, 'by ' . $u['email']); flash('ok', "Temporary password for {$t['email']}: $pw (shown once)"); }
    // lost phone and recovery codes: an admin switches two-step sign-in off so the member can sign in with the password and set it up again
    elseif ($a === 'twofa_reset' && $isAdmin && $id !== (int)$u['id'] && !$isTop && !empty($t['twofa_enabled_at'])) { Auth::twoFactorOff($id); Audit::log("reset two-step sign-in of {$t['email']}", 'user', $id); SecurityLog::record('twofa_reset', 'critical', $t, null, 'by ' . $u['email']); flash('ok', 'Two-step sign-in is off for this account. Ask them to set it up again from Settings.'); }
    elseif ($a === 'delete' && $isAdmin && $id !== (int)$u['id'] && !$isTop) {
        // papers, announcements, payments and reports keep their author, so an account that has any of them cannot be deleted
        try { q('DELETE FROM users WHERE id=?', [$id]); Audit::log("deleted user {$t['email']}", 'user', $id); flash('ok', 'User deleted.'); redirect_or_ajax('admin/users.php'); }
        catch (PDOException $e) { if ($e->getCode() !== '23000') throw $e; flash('bad', 'This account has uploaded papers, announcements, payments or reports, so it cannot be deleted. Suspend it instead.'); }
    }
    else flash('bad', 'You cannot do that to this account.');
    redirect_self_or_ajax();
}
$id = (int)($_GET['id'] ?? 0);
$owner = is_owner($u);   // dashboard_header.php reuses $isAdmin, so the view keeps its own flag
$act = 'admin/users.php';
if ($id) {
    $t = row('SELECT u.*,r.name role,d.name dept FROM users u JOIN roles r ON r.id=u.role_id LEFT JOIN departments d ON d.id=u.department_id WHERE u.id=? AND ' . in_school('u'), [$id]);
    if (!$t) { flash('bad', 'User not found.'); redirect_or_ajax('admin/users.php'); }
    $title = $t['full_name'];
    $tp = is_plus($t); $subs = rows('SELECT * FROM subscriptions WHERE user_id=? ORDER BY id DESC LIMIT 8', [$id]); $tx = rows('SELECT * FROM transactions WHERE user_id=? ORDER BY id DESC LIMIT 8', [$id]);
    $stats = ['Uploads' => val('SELECT COUNT(*) FROM materials WHERE user_id=?', [$id]), 'Notes' => val('SELECT COUNT(*) FROM notes WHERE user_id=?', [$id]), 'Decks' => val('SELECT COUNT(*) FROM decks WHERE owner_id=?', [$id]), 'Recordings' => val('SELECT COUNT(*) FROM recordings WHERE owner_id=?', [$id]), 'AI messages' => val('SELECT COUNT(*) FROM ai_usage WHERE user_id=?', [$id]), 'Focus minutes' => val('SELECT COALESCE(SUM(minutes),0) FROM focus_sessions WHERE user_id=?', [$id])];
    $locked = $id === (int)$u['id'] || (int)$t['role_id'] === 5 || ((int)$t['role_id'] === 4 && !is_super());
    include ROOT . '/includes/dashboard_header.php';
    echo page_head($t['full_name'], $t['email'], '<a class="btn sm line" href="' . e(url('admin/users.php')) . '">All users</a>');
    ?>
    <div class="grid2">
      <section class="card dt"><?= dt_head('Account') ?><dl class="kv"><dt>Role</dt><dd><?= e(role_label($t['role'])) ?></dd><dt>Status</dt><dd><?= status_pill($t['status']) ?></dd><dt>Plan</dt><dd><?= $tp ? 'Plus' . ($t['plus_expires'] ? ' until ' . e(fdate($t['plus_expires'])) : '') : 'Free' ?></dd><dt>Department</dt><dd><?= e($t['dept'] ?: '-') ?></dd><dt>Level</dt><dd><?= e(level_label($t['level']) ?: '-') ?></dd><dt>Joined</dt><dd><?= e(fdate($t['created_at'])) ?></dd><dt>Last sign-in</dt><dd><?= e(ago($t['last_login_at'])) ?></dd><dt>Two-step sign-in</dt><dd><?= !empty($t['twofa_enabled_at']) ? '<span class="pill ok">On</span>' : '<span class="pill new">Off</span>' ?></dd></dl></section>
      <section class="card dt"><?= dt_head('Activity') ?><dl class="kv"><?php foreach ($stats as $k => $v): ?><dt><?= e($k) ?></dt><dd><?= (int)$v ?></dd><?php endforeach; ?></dl></section>
    </div>
    <div class="grid2">
      <?php if (is_super()): ?><section class="card"><h2>Plus access</h2><p class="note" style="margin-top:4px"><?= $tp ? 'This member has Plus.' : 'This member is on the free plan.' ?></p>
        <div class="rowact" style="margin-top:12px"><?= modal_btn('tpl-grant', 'Grant Plus to ' . $t['full_name'], 'Grant Plus', 'btn sm') ?>
        <?php if ($tp && (int)$t['role_id'] !== 4) echo row_form($act, ['a' => 'revoke', 'id' => $id], 'Remove Plus', 'btn sm danger', 'Remove Plus from this user?'); ?></div></section><?php endif; ?>
      <section class="card"><h2>Account controls</h2>
        <?php if (!$locked): ?><div class="rowact" style="margin-top:12px">
        <?= row_form($act, ['a' => 'status', 'id' => $id, 'status' => $t['status'] === 'active' ? 'suspended' : 'active'], $t['status'] === 'active' ? 'Suspend account' : 'Reactivate account', 'btn sm line', $t['status'] === 'active' ? 'Suspend this account? They will not be able to sign in.' : '') ?>
        <?php if ($owner): ?><?= modal_btn('tpl-role', 'Change role', 'Change role', 'btn sm line') ?>
        <?= row_form($act, ['a' => 'reset', 'id' => $id], 'Reset password', 'btn sm line', 'Create a temporary password for this user?') ?>
        <?php if (!empty($t['twofa_enabled_at'])) echo row_form($act, ['a' => 'twofa_reset', 'id' => $id], 'Reset two-step sign-in', 'btn sm line', 'Turn off two-step sign-in for this account? Only do this after checking who is asking, for example in person.'); ?>
        <?= row_form($act, ['a' => 'delete', 'id' => $id], 'Delete user', 'btn sm danger', 'Delete this account and its data? This cannot be undone.') ?><?php endif; ?></div>
        <?php else: ?><p class="note" style="margin-top:8px">This is an admin or your own account, so these controls are locked.</p><?php endif; ?></section>
    </div>
    <div class="grid2">
      <section class="card dt"><?= dt_head('Subscriptions', 'Last 8') ?><div class="dtwrap"><table class="dtable"><thead><tr><th>Plan</th><th>Starts</th><th>Ends</th><th>Status</th></tr></thead><tbody>
        <?php foreach ($subs as $s): ?><tr><td class="dmain"><span class="dtt"><?= e(Billing::LABEL[$s['period']] ?? $s['period']) ?></span></td><td class="nw" data-v="<?= e($s['starts_at']) ?>"><?= e(fdate($s['starts_at'])) ?></td><td class="nw" data-v="<?= e($s['ends_at']) ?>"><?= e(fdate($s['ends_at'])) ?></td><td><?= status_pill($s['status']) ?></td></tr><?php endforeach; if (!$subs) echo dt_empty(4, 'None.'); ?></tbody></table></div></section>
      <section class="card dt"><?= dt_head('Payments', 'Last 8') ?><div class="dtwrap"><table class="dtable"><thead><tr><th class="num">Amount</th><th>Reference</th><th>Date</th><th>Status</th></tr></thead><tbody>
        <?php foreach ($tx as $x): ?><tr><td class="num dmain" data-v="<?= e($x['amount']) ?>"><b><?= e(money($x['amount'], $x['currency'])) ?></b></td><td class="nw"><span class="code"><?= e($x['gateway_ref']) ?></span></td><td class="nw" data-v="<?= e($x['created_at']) ?>"><?= e(fdate($x['created_at'])) ?></td><td><?= status_pill($x['status']) ?></td></tr><?php endforeach; if (!$tx) echo dt_empty(4, 'None.'); ?></tbody></table></div></section>
    </div>
    <?php if (is_super()): ?><template id="tpl-grant">
    <form class="card mform" method="post" data-action="<?= $act ?>"><?= csrf_field() ?><input type="hidden" name="a" value="grant"><input type="hidden" name="id" value="<?= $id ?>"><h2>Grant Plus</h2>
      <p class="note">Time is added on top of any Plus they already have. No payment is recorded.</p>
      <div class="field"><label for="gp">Period</label><select class="in" id="gp" name="period"><?php foreach (Billing::LABEL as $k => $l): ?><option value="<?= $k ?>"><?= e($l) ?> (<?= Billing::DAYS[$k] ?> days)</option><?php endforeach; ?></select></div><button class="btn" type="submit">Grant Plus</button></form>
    </template><?php endif; ?>
    <?php if ($owner && !$locked): ?><template id="tpl-role">
    <form class="card mform" method="post" data-action="<?= $act ?>"><?= csrf_field() ?><input type="hidden" name="a" value="role"><input type="hidden" name="id" value="<?= $id ?>"><h2>Change role</h2>
      <div class="field"><label for="nr">Role</label><select class="in" id="nr" name="role_id"><?= options($roles, $t['role_id'], 'id', 'name') ?></select><span class="hint note">For course access and extra permissions use Lecturers &amp; reps.</span></div><button class="btn" type="submit">Change role</button></form>
    </template><?php endif; ?>
    <?php include ROOT . '/includes/dashboard_footer.php'; exit;
}
$q = trim((string)($_GET['q'] ?? '')); $role = (int)($_GET['role'] ?? 0); $plan = $_GET['plan'] ?? ''; $st = $_GET['status'] ?? ''; $dep = (int)($_GET['dep'] ?? 0);
$w = [in_school('u')]; $a = [];
if ($q !== '') { $w[] = '(u.full_name LIKE ? OR u.email LIKE ?)'; array_push($a, "%$q%", "%$q%"); }
if ($role) { $w[] = 'u.role_id=?'; $a[] = $role; } if (in_array($st, ['active', 'suspended'], true)) { $w[] = 'u.status=?'; $a[] = $st; } if ($dep) { $w[] = 'u.department_id=?'; $a[] = $dep; }
if ($plan === 'plus') $w[] = 'u.plus=1 AND (u.plus_expires IS NULL OR u.plus_expires>NOW())'; if ($plan === 'free') $w[] = '(u.plus=0 OR u.plus_expires<=NOW())';
$W = implode(' AND ', $w); $per = per_page(15); $page = page_no(); $total = (int)val("SELECT COUNT(*) FROM users u WHERE $W", $a);
$list = rows("SELECT u.*,r.name role,d.name dept FROM users u JOIN roles r ON r.id=u.role_id LEFT JOIN departments d ON d.id=u.department_id WHERE $W ORDER BY u.id DESC LIMIT $per OFFSET " . (($page - 1) * $per), $a);
include ROOT . '/includes/dashboard_header.php';
echo page_head('Users', number_format($total) . ' account' . ($total === 1 ? '' : 's'), $owner ? modal_btn('tpl-user-add', 'Add a user', 'Add a user', 'btn', 'plus') : '');
?>
<section class="card dt">
  <?= dt_head('Accounts', 'Click a row to open the account.') ?>
  <form class="toolbar" method="get"><label class="search"><?= ic('search', 'i') ?><span class="vh">Search</span><input name="q" value="<?= e($q) ?>" type="search" placeholder="Name or email"></label>
    <select class="in" name="role" aria-label="Role"><?= options($roles, $role, 'id', 'name', 'All roles') ?></select>
    <select class="in" name="dep" aria-label="Department"><?= options($deps, $dep, 'id', 'name', 'All departments') ?></select>
    <select class="in" name="plan" aria-label="Plan"><option value="">Any plan</option><option value="plus"<?= $plan === 'plus' ? ' selected' : '' ?>>Plus</option><option value="free"<?= $plan === 'free' ? ' selected' : '' ?>>Free</option></select>
    <select class="in" name="status" aria-label="Status"><option value="">Any status</option><option value="active"<?= $st === 'active' ? ' selected' : '' ?>>Active</option><option value="suspended"<?= $st === 'suspended' ? ' selected' : '' ?>>Suspended</option></select>
    <button class="btn sm">Filter</button></form>
  <div class="dtwrap"><table class="dtable"><thead><tr><th>Name</th><th>Role</th><th>Plan</th><th>Department</th><th>Status</th><th>Last sign-in</th><th>Joined</th><th data-nosort><span class="vh">Actions</span></th></tr></thead><tbody>
  <?php foreach ($list as $r): $rp = is_plus($r); $href = url('admin/users.php?id=' . $r['id']); ?><tr data-href="<?= e($href) ?>">
    <td class="dmain"><div class="ucell"><span class="av"><?= e(initials($r['full_name'])) ?></span><div style="min-width:0"><a class="dtt" href="<?= e($href) ?>"><?= e($r['full_name']) ?></a><span class="ts"><?= e($r['email']) ?></span></div></div></td>
    <td><span class="pill role"><?= e(role_label($r['role'])) ?></span></td><td><span class="pill <?= $rp ? 'plus' : 'free' ?>"><?= $rp ? 'Plus' : 'Free' ?></span></td>
    <td class="muted"><?= e($r['dept'] ?: '–') ?></td><td><?= status_pill($r['status']) ?></td>
    <td class="nw muted" data-v="<?= e((string)$r['last_login_at']) ?>"><?= e(ago($r['last_login_at'])) ?></td><td class="nw muted" data-v="<?= e($r['created_at']) ?>"><?= e(fdate($r['created_at'])) ?></td>
    <td class="act"><a class="btn sm line" href="<?= e($href) ?>">Open</a></td></tr>
  <?php endforeach; if (!$list) echo dt_empty(8, 'No users match.'); ?></tbody></table></div>
  <?= dt_foot($total, $per, $page, ['q' => $q, 'role' => $role ?: '', 'dep' => $dep ?: '', 'plan' => $plan, 'status' => $st]) ?>
</section>
<?php if ($owner): ?>
<template id="tpl-user-add">
<form class="card mform" method="post" data-action="<?= $act ?>"><?= csrf_field() ?><input type="hidden" name="a" value="create"><h2>Add a user</h2><p class="note">For lecturers and class reps. Students register themselves. School admins are PrepDeck staff and are added by the platform owner.</p>
  <div class="f2"><div class="field"><label for="cn">Full name</label><input class="in" id="cn" name="full_name" required></div><div class="field"><label for="ce">Email</label><input class="in" id="ce" name="email" type="email" required></div></div>
  <div class="f2"><div class="field"><label for="cr">Role</label><select class="in" id="cr" name="role_id"><?= options($roles, 3, 'id', 'name') ?></select></div><div class="field"><label for="cd">Department</label><select class="in" id="cd" name="department_id"><?= options($deps, null, 'id', 'name', 'None') ?></select></div></div>
  <div class="field"><label for="cp">Temporary password (8+)</label><input class="in" id="cp" name="password" type="password" minlength="8" required autocomplete="new-password"></div><button class="btn" type="submit">Create user</button></form>
</template>
<?php endif; ?>
<?php include ROOT . '/includes/dashboard_footer.php'; ?>
