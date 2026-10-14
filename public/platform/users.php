<?php
// Platform owner: every account in every school. Move an account to another school, change any role (school admin included),
// suspend, reset passwords, add a lecturer to more schools, and set extra permissions for one person.
require __DIR__ . '/../../app/bootstrap.php';
$u = require_super(); $platformArea = true; $active = 'p-users'; $title = 'All users'; $scripts = ['forms.js'];
$act = 'platform/users.php';
$roles = rows('SELECT id,name FROM roles ORDER BY id'); foreach ($roles as &$r) $r['n'] = role_label($r['name']); unset($r);
$schools = rows('SELECT id,name n FROM schools WHERE is_active=1 OR EXISTS (SELECT 1 FROM users x WHERE x.school_id=schools.id) ORDER BY name');
/** Move an account to another school: their own study items go with them; department, course assignments and old-school grants do not. */
function move_user(array $t, int $to): void {
    $id = (int)$t['id']; $old = (int)$t['school_id'];
    if ($old === $to) return;
    q('UPDATE users SET school_id=?, department_id=NULL WHERE id=?', [$to, $id]);
    foreach (['notes', 'materials', 'practice_attempts', 'game_scores', 'focus_sessions', 'paper_views'] as $tb) q("UPDATE `$tb` SET school_id=? WHERE user_id=? AND school_id=?", [$to, $id, $old]);
    q("UPDATE decks SET school_id=?, course_id=NULL WHERE owner_id=? AND school_id=? AND (visibility='private' OR material_id IS NOT NULL)", [$to, $id, $old]);   // their own decks, not shared course material
    q('UPDATE study_guides SET school_id=? WHERE owner_id=? AND school_id=? AND material_id IS NOT NULL', [$to, $id, $old]);
    q('UPDATE notes SET course_id=NULL WHERE user_id=? AND school_id=?', [$id, $to]);
    q('DELETE s FROM staff_courses s JOIN courses c ON c.id=s.course_id WHERE s.user_id=? AND c.school_id<>?', [$id, $to]);
    q('DELETE FROM user_schools WHERE user_id=? AND school_id=?', [$id, $to]);
}
if (is_post()) {
    form_guard(); $a = $_POST['a'] ?? ''; $id = (int)($_POST['id'] ?? 0); $t = row('SELECT * FROM users WHERE id=?', [$id]);
    if (!$t) { flash('bad', 'User not found.'); redirect_or_ajax($act); }
    $self = $id === (int)$u['id'];
    if ($a === 'role' && !$self) {
        $r = (int)($_POST['role_id'] ?? 0);
        if (!val('SELECT COUNT(*) FROM roles WHERE id=?', [$r])) flash('bad', 'Choose a role.');
        elseif ($r === Tenant::SUPER && ($_POST['confirm'] ?? '') !== 'OWNER') flash('bad', 'Type OWNER to make someone a platform owner.');
        else { q('UPDATE users SET role_id=? WHERE id=?', [$r, $id]); if ($r === 1) q('DELETE FROM user_permissions WHERE user_id=?', [$id]); Audit::log("set role of {$t['email']} to $r", 'user', $id); flash('ok', 'Role changed.'); }
    } elseif ($a === 'move') {
        $to = Tenant::find((int)($_POST['school_id'] ?? 0));
        if (!$to) flash('bad', 'Choose a school.');
        else {
            move_user($t, (int)$to['id']);
            Audit::log("moved {$t['email']} to school {$to['id']}", 'user', $id); flash('ok', 'Moved to ' . $to['name'] . '. They choose their department again in Settings.');
        }
    } elseif ($a === 'make_admin' && !$self) {
        // any account can become the admin of any school; if it is another school they move there (an admin works in one school)
        $to = Tenant::find((int)($_POST['school_id'] ?? 0));
        if (!$to) flash('bad', 'Choose the school they will run.');
        elseif ((int)$t['role_id'] === Tenant::SUPER) flash('bad', 'That is a platform owner account. Change its role first.');
        else {
            $moved = (int)$t['school_id'] !== (int)$to['id'];
            if ($moved) move_user($t, (int)$to['id']);
            q("UPDATE users SET role_id=4, status='active' WHERE id=?", [$id]);
            Audit::log("made {$t['email']} school admin of {$to['name']}", 'user', $id);
            flash('ok', $t['full_name'] . ' is now a school admin of ' . $to['name'] . ($moved ? ' (moved from their old school).' : '.'));
        }
    } elseif ($a === 'remove_admin' && (int)$t['role_id'] === 4) {
        $nr = Validator::in((int)($_POST['role_id'] ?? 1), [1, 2, 3]) ?? 1;
        q('UPDATE users SET role_id=? WHERE id=?', [$nr, $id]);
        if ($nr === 1) q('DELETE FROM user_permissions WHERE user_id=?', [$id]);   // a student keeps no staff permissions
        Audit::log("removed school admin {$t['email']} (now role $nr)", 'user', $id);
        flash('ok', $t['full_name'] . ' is no longer a school admin. They are now a ' . [1 => 'student', 2 => 'class rep', 3 => 'lecturer'][$nr] . '.');
    } elseif ($a === 'remove_admin') { flash('bad', 'That account is not a school admin.');
    } elseif ($a === 'member_add') {
        $to = (int)($_POST['school_id'] ?? 0); $role = Validator::in((int)($_POST['role_id'] ?? 3), [2, 3]) ?? 3;
        if (!Tenant::find($to) || $to === (int)$t['school_id']) flash('bad', 'Choose another school.');
        else { q('INSERT INTO user_schools(user_id,school_id,role_id,added_by) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE role_id=VALUES(role_id)', [$id, $to, $role, $u['id']]); Audit::log("added {$t['email']} to school $to", 'user', $id); flash('ok', 'Added. They can switch to that school from their dashboard.'); }
    } elseif ($a === 'member_remove') {
        $sid = (int)($_POST['school_id'] ?? 0);
        q('DELETE FROM user_schools WHERE user_id=? AND school_id=?', [$id, $sid]); q('DELETE s FROM staff_courses s JOIN courses c ON c.id=s.course_id WHERE s.user_id=? AND c.school_id=?', [$id, $sid]); flash('ok', 'Removed from that school.');
    } elseif ($a === 'perms') {
        q('DELETE FROM user_permissions WHERE user_id=?', [$id]);
        foreach (rows("SELECT id FROM permissions WHERE code NOT IN ('platform','plans')") as $p) if (isset($_POST['perm'][$p['id']])) q('INSERT INTO user_permissions(user_id,permission_id) VALUES (?,?)', [$id, $p['id']]);
        Audit::log("set extra permissions of {$t['email']}", 'user', $id); flash('ok', 'Extra permissions saved. They still never go beyond what the school\'s admins may do.');
    } elseif ($a === 'status' && !$self) { $s = Validator::in($_POST['status'] ?? '', ['active', 'suspended']); if ($s) { q('UPDATE users SET status=? WHERE id=?', [$s, $id]); Audit::log("$s {$t['email']}", 'user', $id); flash('ok', 'Account ' . $s . '.'); } }
    elseif ($a === 'reset' && !$self) { $pw = bin2hex(random_bytes(5)); q('UPDATE users SET password_hash=? WHERE id=?', [password_hash($pw, PASSWORD_DEFAULT), $id]); Audit::log("reset password of {$t['email']}", 'user', $id); flash('ok', "Temporary password for {$t['email']}: $pw (shown once)"); }
    elseif ($a === 'plus') { $p = Validator::in($_POST['period'] ?? '', ['month', 'semester', 'year']) ?? 'month'; Billing::activate($id, $p); Audit::log("granted Plus ($p) to {$t['email']}", 'user', $id); flash('ok', 'Plus granted.'); }
    elseif ($a === 'unplus') { Billing::revoke($id); Audit::log("revoked Plus from {$t['email']}", 'user', $id); flash('ok', 'Plus removed.'); }
    elseif ($a === 'delete' && !$self && (int)$t['role_id'] !== Tenant::SUPER) {
        try { q('DELETE FROM users WHERE id=?', [$id]); Audit::log("deleted user {$t['email']}", 'user', $id); flash('ok', 'User deleted.'); redirect_or_ajax($act); }
        catch (PDOException $e) { if ($e->getCode() !== '23000') throw $e; flash('bad', 'This account has uploaded papers, announcements, payments or reports, so it cannot be deleted. Suspend it instead.'); }
    }
    else flash('bad', 'You cannot do that to this account.');
    redirect_self_or_ajax();
}
$id = (int)($_GET['id'] ?? 0);
if ($id && ($t = row('SELECT u.*,r.name role,s.name school,d.name dept FROM users u JOIN roles r ON r.id=u.role_id LEFT JOIN schools s ON s.id=u.school_id LEFT JOIN departments d ON d.id=u.department_id WHERE u.id=?', [$id]))) {
    $title = $t['full_name'];
    $members = rows('SELECT m.school_id,m.role_id,s.name FROM user_schools m JOIN schools s ON s.id=m.school_id WHERE m.user_id=? ORDER BY s.name', [$id]);
    $perms = rows("SELECT * FROM permissions WHERE code NOT IN ('platform','plans') ORDER BY id"); $extra = array_map('intval', array_column(rows('SELECT permission_id FROM user_permissions WHERE user_id=?', [$id]), 'permission_id'));
    $fromRole = $t['school_id'] ? Auth::rolePerms((int)$t['role_id'], (int)$t['school_id']) : [];
    $tp = is_plus($t); $self = $id === (int)$u['id'];
    include ROOT . '/includes/dashboard_header.php';
    echo page_head($t['full_name'], $t['email'] . ' · ' . role_label($t['role']) . ' · ' . ($t['school'] ?: 'no school'), '<a class="btn sm line" href="' . e(url($act)) . '">All users</a>');
    ?>
    <div class="grid2">
      <section class="card dt"><?= dt_head('Account') ?><dl class="kv"><dt>School</dt><dd><?= $t['school_id'] ? '<a href="' . e(url('platform/schools.php?id=' . $t['school_id'])) . '">' . e($t['school']) . '</a>' : '-' ?></dd><dt>Role</dt><dd><?= e(role_label($t['role'])) ?></dd><dt>Status</dt><dd><?= status_pill($t['status']) ?></dd>
        <dt>Plan</dt><dd><?= $tp ? 'Plus' . ($t['plus_expires'] ? ' until ' . e(fdate($t['plus_expires'])) : '') : 'Free' ?></dd><dt>Department</dt><dd><?= e($t['dept'] ?: '-') ?></dd><dt>Joined</dt><dd><?= e(fdate($t['created_at'])) ?></dd><dt>Last sign-in</dt><dd><?= e(ago($t['last_login_at'])) ?></dd></dl></section>
      <section class="card"><h2>Controls</h2>
        <?php if ($self): ?><p class="note" style="margin-top:8px">This is your own account.</p><?php else: ?>
        <div class="rowact" style="margin-top:12px"><?= (int)$t['role_id'] === 4 ? modal_btn('tpl-un' . $id, 'Remove ' . $t['full_name'] . ' as school admin', 'Remove school admin', 'btn sm danger') . '<template id="tpl-un'.$id.'"><form class="card mform" method="post" data-action="'.$act.'">'.csrf_field().'<input type="hidden" name="a" value="remove_admin"><input type="hidden" name="id" value="'.$id.'"><h2>Remove school admin</h2>
        <p class="note">'.e($t['full_name']).' stops being a school admin and keeps their account in the same school.</p>
        <div class="field"><label for="un'.$id.'">They become</label><select class="in" id="un'.$id.'" name="role_id"><option value="1">Student</option><option value="3">Lecturer</option><option value="2">Class rep</option></select></div><button class="btn danger">Remove school admin</button></form></template>' : ((int)$t['role_id'] !== Tenant::SUPER ? modal_btn('tpl-mkadmin', 'Make ' . $t['full_name'] . ' a school admin', ic('shield') . 'Make school admin', 'btn sm') : '') ?><?= modal_btn('tpl-role', 'Change role', 'Change role', 'btn sm line') ?><?= modal_btn('tpl-move', 'Move to another school', 'Move school', 'btn sm line') ?>
          <?= row_form($act, ['a' => 'status', 'id' => $id, 'status' => $t['status'] === 'active' ? 'suspended' : 'active'], $t['status'] === 'active' ? 'Suspend' : 'Reactivate', 'btn sm line', $t['status'] === 'active' ? 'Suspend this account?' : '') ?>
          <?= row_form($act, ['a' => 'reset', 'id' => $id], 'Reset password', 'btn sm line', 'Create a temporary password?') ?>
          <?= $tp ? row_form($act, ['a' => 'unplus', 'id' => $id], 'Remove Plus', 'btn sm line', 'Remove Plus?') : modal_btn('tpl-plus', 'Give Plus', 'Give Plus', 'btn sm line') ?>
          <?php if ((int)$t['role_id'] !== Tenant::SUPER) echo row_form($act, ['a' => 'delete', 'id' => $id], 'Delete', 'btn sm danger', 'Delete this account and its data? This cannot be undone.'); ?></div><?php endif; ?></section>
    </div>
    <div class="grid2">
      <section class="card dt"><?= dt_head('Other schools', 'Lecturers and reps can work in more than one school.', in_array((int)$t['role_id'], [2, 3], true) ? modal_btn('tpl-member', 'Add to another school', 'Add school', 'btn sm', 'plus') : '') ?>
        <div class="dtwrap"><table class="dtable"><thead><tr><th>School</th><th>Role there</th><th data-nosort><span class="vh">Actions</span></th></tr></thead><tbody>
        <?php foreach ($members as $m): ?><tr><td class="dmain"><span class="dtt"><?= e($m['name']) ?></span></td><td><?= (int)$m['role_id'] === 2 ? 'Class rep' : 'Lecturer' ?></td><td class="act"><?= row_form($act, ['a' => 'member_remove', 'id' => $id, 'school_id' => $m['school_id']], 'Remove', 'btn sm line') ?></td></tr><?php endforeach; if (!$members) echo dt_empty(3, 'Only their home school.'); ?></tbody></table></div></section>
      <section class="card dt"><?= dt_head('Extra permissions', 'On top of their role, at their home school. Capped by what that school\'s admins may do.', $self || (int)$t['role_id'] === Tenant::SUPER ? '' : modal_btn('tpl-perms', 'Extra permissions', 'Edit', 'btn sm', 'edit')) ?>
        <div class="dtwrap"><table class="dtable"><thead><tr><th>Permission</th><th>Access</th></tr></thead><tbody>
        <?php foreach ($perms as $p): $inh = in_array($p['code'], $fromRole, true); $ext = in_array((int)$p['id'], $extra, true); ?><tr><td class="dmain"><span class="dtt"><?= e($p['code']) ?></span></td><td><?= (int)$t['role_id'] === Tenant::SUPER ? '<span class="pill ok">Everything</span>' : ($inh ? '<span class="pill ok">From role</span>' : ($ext ? '<span class="pill plus">Extra</span>' : '<span class="muted">&ndash;</span>')) ?></td></tr><?php endforeach; ?></tbody></table></div></section>
    </div>
    <?php if (!$self): ?>
    <template id="tpl-mkadmin"><form class="card mform" method="post" data-action="<?= $act ?>"><?= csrf_field() ?><input type="hidden" name="a" value="make_admin"><input type="hidden" name="id" value="<?= $id ?>"><h2>Make school admin</h2>
      <p class="note">A school admin is PrepDeck staff who runs one school: departments, courses, past papers, study content, lecturers and class reps. Choosing a different school moves them there.</p>
      <div class="field"><label for="mka">School</label><select class="in" id="mka" name="school_id" required><?= options($schools, $t['school_id'], 'id', 'n', 'Choose') ?></select></div><button class="btn">Make school admin</button></form></template>
    <template id="tpl-role"><form class="card mform" method="post" data-action="<?= $act ?>"><?= csrf_field() ?><input type="hidden" name="a" value="role"><input type="hidden" name="id" value="<?= $id ?>"><h2>Change role</h2>
      <div class="field"><label for="rr">Role</label><select class="in" id="rr" name="role_id"><?= options($roles, $t['role_id'], 'id', 'n') ?></select><span class="hint note">School admin manages one school. Platform owner has full control of every school.</span></div>
      <div class="field"><label for="rc">To make a platform owner, type OWNER</label><input class="in" id="rc" name="confirm" autocomplete="off"></div><button class="btn">Change role</button></form></template>
    <template id="tpl-move"><form class="card mform" method="post" data-action="<?= $act ?>"><?= csrf_field() ?><input type="hidden" name="a" value="move"><input type="hidden" name="id" value="<?= $id ?>"><h2>Move to another school</h2>
      <p class="note">Their own notes, uploaded materials, private decks and progress move with them. What they shared with their old school (papers, shared decks, recordings) stays there. Course assignments from the old school are removed and they pick a new department.</p>
      <div class="field"><label for="ms">School</label><select class="in" id="ms" name="school_id"><?= options($schools, $t['school_id'], 'id', 'n') ?></select></div><button class="btn">Move</button></form></template>
    <template id="tpl-member"><form class="card mform" method="post" data-action="<?= $act ?>"><?= csrf_field() ?><input type="hidden" name="a" value="member_add"><input type="hidden" name="id" value="<?= $id ?>"><h2>Add to another school</h2>
      <div class="field"><label for="mm">School</label><select class="in" id="mm" name="school_id"><?= options($schools, null, 'id', 'n', 'Choose') ?></select></div>
      <div class="field"><label for="mr">Role there</label><select class="in" id="mr" name="role_id"><option value="3">Lecturer</option><option value="2">Class rep</option></select></div><button class="btn">Add</button></form></template>
    <template id="tpl-plus"><form class="card mform" method="post" data-action="<?= $act ?>"><?= csrf_field() ?><input type="hidden" name="a" value="plus"><input type="hidden" name="id" value="<?= $id ?>"><h2>Give Plus</h2><p class="note">No payment is recorded.</p>
      <div class="field"><label for="pp">Period</label><select class="in" id="pp" name="period"><?php foreach (Billing::LABEL as $k => $l): ?><option value="<?= $k ?>"><?= e($l) ?></option><?php endforeach; ?></select></div><button class="btn">Give Plus</button></form></template>
    <template id="tpl-perms"><form class="card mform" method="post" data-action="<?= $act ?>"><?= csrf_field() ?><input type="hidden" name="a" value="perms"><input type="hidden" name="id" value="<?= $id ?>"><h2>Extra permissions</h2><div class="fgrid2">
      <?php foreach ($perms as $p): $inh = in_array($p['code'], $fromRole, true); ?><label class="chk"><input type="checkbox" name="perm[<?= $p['id'] ?>]" value="1"<?= $inh || in_array((int)$p['id'], $extra, true) ? ' checked' : '' ?><?= $inh ? ' disabled' : '' ?>><?= e($p['code']) ?><?= $inh ? ' <span class="pill free">from role</span>' : '' ?></label><?php endforeach; ?></div><button class="btn">Save</button></form></template>
    <?php endif; include ROOT . '/includes/dashboard_footer.php'; exit;
}
$q = trim((string)($_GET['q'] ?? '')); $sf = (int)($_GET['school'] ?? 0); $rf = (int)($_GET['role'] ?? 0); $st = (string)($_GET['status'] ?? ''); $pl = (string)($_GET['plan'] ?? '');
$w = ['1=1']; $a = [];
if ($q !== '') { $w[] = '(u.full_name LIKE ? OR u.email LIKE ?)'; array_push($a, "%$q%", "%$q%"); }
if ($sf) { $w[] = 'u.school_id=?'; $a[] = $sf; } if ($rf) { $w[] = 'u.role_id=?'; $a[] = $rf; } if (in_array($st, ['active', 'suspended'], true)) { $w[] = 'u.status=?'; $a[] = $st; }
if ($pl === 'plus') $w[] = 'u.plus=1 AND (u.plus_expires IS NULL OR u.plus_expires>NOW())'; elseif ($pl === 'free') $w[] = '(u.plus=0 OR u.plus_expires<=NOW())';
$W = implode(' AND ', $w); $per = per_page(25); $page = page_no(); $total = (int)val("SELECT COUNT(*) FROM users u WHERE $W", $a);
$list = rows("SELECT u.*,r.name role,s.name school,s.short_name sshort FROM users u JOIN roles r ON r.id=u.role_id LEFT JOIN schools s ON s.id=u.school_id WHERE $W ORDER BY u.id DESC LIMIT $per OFFSET " . (($page - 1) * $per), $a);
include ROOT . '/includes/dashboard_header.php';
$counts = row('SELECT COUNT(*) n, SUM(role_id=1) stu, SUM(role_id IN (2,3)) staff, SUM(role_id=4) adm, COUNT(DISTINCT school_id) sch FROM users');
echo page_head('All users', number_format($total) . ' in this view · ' . number_format((int)$counts['n']) . ' accounts in ' . number_format((int)$counts['sch']) . ' schools');
echo '<section class="kpis"><div class="card kpi"><b>' . number_format((int)$counts['stu']) . '</b><small>Students</small></div><div class="card kpi"><b>' . number_format((int)$counts['staff']) . '</b><small>Lecturers &amp; reps</small></div><div class="card kpi"><b>' . number_format((int)$counts['adm']) . '</b><small>School admins</small></div><div class="card kpi"><b>' . number_format((int)$counts['n']) . '</b><small>All accounts</small></div></section>';
?>
<section class="card dt"><?= dt_head('Accounts', 'Click a row to manage the account.') ?>
  <form class="toolbar" method="get"><label class="search"><?= ic('search', 'i') ?><span class="vh">Search</span><input name="q" value="<?= e($q) ?>" type="search" placeholder="Name or email"></label>
    <select class="in" name="school" aria-label="School"><?= options($schools, $sf, 'id', 'n', 'All schools') ?></select><select class="in" name="role" aria-label="Role"><?= options($roles, $rf, 'id', 'n', 'All roles') ?></select>
    <select class="in" name="plan" aria-label="Plan"><option value="">Any plan</option><option value="plus"<?= $pl === 'plus' ? ' selected' : '' ?>>Plus</option><option value="free"<?= $pl === 'free' ? ' selected' : '' ?>>Free</option></select>
    <select class="in" name="status" aria-label="Status"><option value="">Any status</option><option value="active"<?= $st === 'active' ? ' selected' : '' ?>>Active</option><option value="suspended"<?= $st === 'suspended' ? ' selected' : '' ?>>Suspended</option></select><button class="btn sm">Filter</button></form>
  <div class="dtwrap"><table class="dtable"><thead><tr><th>Name</th><th>School</th><th>Role</th><th>Plan</th><th>Status</th><th>Last sign-in</th><th>Joined</th><th data-nosort><span class="vh">Actions</span></th></tr></thead><tbody>
  <?php foreach ($list as $r): $href = url($act . '?id=' . $r['id']); $rp = is_plus($r); ?><tr data-href="<?= e($href) ?>">
    <td class="dmain"><div class="ucell"><span class="av"><?= e(initials($r['full_name'])) ?></span><div style="min-width:0"><a class="dtt" href="<?= e($href) ?>"><?= e($r['full_name']) ?></a><span class="ts"><?= e($r['email']) ?></span></div></div></td>
    <td><?= e($r['sshort'] ?: ($r['school'] ?: '-')) ?></td><td><span class="pill role"><?= e(role_label($r['role'])) ?></span></td><td><span class="pill <?= $rp ? 'plus' : 'free' ?>"><?= $rp ? 'Plus' : 'Free' ?></span></td>
    <td><?= status_pill($r['status']) ?></td><td class="nw muted"><?= e(ago($r['last_login_at'])) ?></td><td class="nw muted"><?= e(fdate($r['created_at'])) ?></td>
    <td class="act"><div class="rowact"><?php if ((int)$r['role_id'] === 4): ?><span class="pill ok">School admin</span><?= modal_btn('tpl-un' . $r['id'], 'Remove ' . $r['full_name'] . ' as school admin', 'Remove admin', 'btn sm danger') ?>
      <?= '<template id="tpl-un'.(int)$r['id'].'"><form class="card mform" method="post" data-action="'.$act.'">'.csrf_field().'<input type="hidden" name="a" value="remove_admin"><input type="hidden" name="id" value="'.(int)$r['id'].'"><h2>Remove school admin</h2>
        <p class="note">'.e($r['full_name']).' stops being a school admin and keeps their account in the same school.</p>
        <div class="field"><label for="un'.(int)$r['id'].'">They become</label><select class="in" id="un'.(int)$r['id'].'" name="role_id"><option value="1">Student</option><option value="3">Lecturer</option><option value="2">Class rep</option></select></div><button class="btn danger">Remove school admin</button></form></template>' ?><?php elseif ((int)$r['role_id'] !== Tenant::SUPER && (int)$r['id'] !== (int)$u['id']): ?><?= modal_btn('tpl-mk' . $r['id'], 'Make ' . $r['full_name'] . ' a school admin', 'Make admin', 'btn sm line') ?>
      <template id="tpl-mk<?= $r['id'] ?>"><form class="card mform" method="post" data-action="<?= $act ?>"><?= csrf_field() ?><input type="hidden" name="a" value="make_admin"><input type="hidden" name="id" value="<?= $r['id'] ?>"><h2>Make school admin</h2>
        <p class="note"><?= e($r['full_name']) ?> (<?= e($r['email']) ?>) will run the school you choose. A different school moves them there.</p>
        <div class="field"><label for="mk<?= $r['id'] ?>">School</label><select class="in" id="mk<?= $r['id'] ?>" name="school_id" required><?= options($schools, $r['school_id'], 'id', 'n', 'Choose') ?></select></div><button class="btn">Make school admin</button></form></template><?php endif; ?></div></td></tr>
  <?php endforeach; if (!$list) echo dt_empty(8, 'No accounts match.'); ?></tbody></table></div>
  <?= dt_foot($total, $per, $page, ['q' => $q, 'school' => $sf ?: '', 'role' => $rf ?: '', 'status' => $st, 'plan' => $pl]) ?></section>
<?php include ROOT . '/includes/dashboard_footer.php'; ?>
