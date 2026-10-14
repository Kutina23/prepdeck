<?php
require __DIR__ . '/../../app/bootstrap.php';
$u = require_perm('admin'); $adminArea = true; $active = 'staff'; $title = 'Lecturers & reps'; $scripts = ['pw.js', 'forms.js'];
$S = sid();
// a school admin can give lecturers and class reps only permissions the admin holds themself (never admin, and never Plus, which the platform owner runs)
$ceiling = Auth::perms();
$perms = array_values(array_filter(rows('SELECT * FROM permissions ORDER BY id'), fn($p) => !in_array($p['code'], array_merge(['admin'], Auth::OWNER_ONLY), true) && in_array($p['code'], $ceiling, true)));
$deps = rows('SELECT id,name FROM departments WHERE ' . in_school() . ' ORDER BY name');
/** Staff of this school: home members, or people from another school added here (user_schools). Returns the user plus 'guest' => membership row or null. */
function staff_row(int $id, int $S): ?array {
    $t = row('SELECT u.*,r.name role,d.name dept,s.name home_school FROM users u JOIN roles r ON r.id=u.role_id LEFT JOIN departments d ON d.id=u.department_id LEFT JOIN schools s ON s.id=u.school_id WHERE u.id=? AND u.role_id NOT IN (4,5)', [$id]);
    if (!$t) return null;
    if ((int)$t['school_id'] === $S) return $t + ['guest' => null];
    $m = row('SELECT * FROM user_schools WHERE user_id=? AND school_id=?', [$id, $S]);
    return $m ? $t + ['guest' => $m] : null;
}
$desc = ['view' => 'Browse papers', 'upload' => 'Upload papers and share recordings', 'approve' => 'Review ANY paper and AI output (whole site)', 'review' => 'Review papers and AI answers in assigned courses only', 'decks' => 'Moderate study content', 'users' => 'Manage users', 'plans' => 'Prices, payments, coupons', 'reports' => 'Handle reports', 'announce' => 'Post announcements to their department', 'settings' => 'Site content and settings', 'courses' => 'Add courses to the school', 'admin' => 'Full admin'];
if (is_post()) {
    form_guard(); $a = $_POST['a'] ?? ''; $id = (int)($_POST['id'] ?? 0);
    if ($a === 'promote') {
        $email = strtolower(trim((string)($_POST['email'] ?? ''))); $role = Validator::in((int)($_POST['role_id'] ?? 0), [2, 3]); $name = Validator::str($_POST['full_name'] ?? '', 2, 120); $pw = (string)($_POST['password'] ?? '');
        $t = row('SELECT id,role_id,school_id FROM users WHERE email=?', [$email]);
        if (!$S) flash('bad', 'Pick a school first.');
        elseif (!$role || !Validator::email($email)) flash('bad', 'Enter a valid email and choose a role.');
        elseif ($t && in_array((int)$t['role_id'], [4, 5], true)) flash('bad', 'That account is an admin.');
        elseif ($t && (int)$t['school_id'] !== $S) {    // someone from another school who also teaches here: add them to this school with this role
            q('INSERT INTO user_schools(user_id,school_id,role_id,added_by) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE role_id=VALUES(role_id)', [$t['id'], $S, $role, $u['id']]);
            Audit::log("added $email from another school as " . ($role === 2 ? 'class rep' : 'lecturer'), 'user', (int)$t['id']); flash('ok', 'Added to this school. They can switch schools from their dashboard. Now assign their courses.'); redirect_or_ajax('admin/staff.php?id=' . $t['id']);
        }
        elseif ($t) { q('UPDATE users SET role_id=? WHERE id=?', [$role, $t['id']]); Audit::log("made $email " . ($role === 2 ? 'class rep' : 'lecturer'), 'user', (int)$t['id']); flash('ok', 'Role updated. Now assign their courses.'); redirect_or_ajax('admin/staff.php?id=' . $t['id']); }
        elseif (!$name || strlen($pw) < 8) flash('bad', 'No account has that email. Add a name and a password (8+ characters) to create one.');
        else { $dep = (int)($_POST['department_id'] ?? 0); if ($dep && !in_my_school('departments', $dep)) $dep = 0;
            q("INSERT INTO users(school_id,role_id,department_id,full_name,email,password_hash,status) VALUES (?,?,?,?,?,?,'active')", [$S, $role, $dep ?: null, $name, $email, password_hash($pw, PASSWORD_DEFAULT)]); $nid = (int)db()->lastInsertId(); Audit::log("created staff $email", 'user', $nid); flash('ok', 'Account created. Now assign their courses.'); redirect_or_ajax('admin/staff.php?id=' . $nid); }
    } elseif ($a === 'save') {
        $t = staff_row($id, $S);
        if (!$t) flash('bad', 'Account not found.');
        else {
            $role = Validator::in((int)($_POST['role_id'] ?? 0), [1, 2, 3]);
            if ($t['guest']) { if ($role) q('UPDATE user_schools SET role_id=? WHERE user_id=? AND school_id=?', [$role, $id, $S]); }   // their home school keeps its own role and permissions
            else {
                if ($role) q('UPDATE users SET role_id=? WHERE id=?', [$role, $id]);
                // only the permissions this admin may give are replaced; anything else stays as it was
                $mine = array_map('intval', array_column($perms, 'id'));
                if ($mine) q('DELETE FROM user_permissions WHERE user_id=? AND permission_id IN (' . implode(',', $mine) . ')', [$id]);
                foreach ($perms as $p) if (isset($_POST['perm'][$p['id']])) q('INSERT IGNORE INTO user_permissions(user_id,permission_id) VALUES (?,?)', [$id, $p['id']]);
            }
            q('DELETE s FROM staff_courses s JOIN courses c ON c.id=s.course_id WHERE s.user_id=? AND c.school_id=?', [$id, $S]);
            foreach (array_map('intval', (array)($_POST['course'] ?? [])) as $c) if ($c && in_my_school('courses', $c)) q('INSERT IGNORE INTO staff_courses(user_id,course_id,assigned_by) VALUES (?,?,?)', [$id, $c, $u['id']]);
            Audit::log("updated staff access for {$t['email']}", 'user', $id); flash('ok', 'Saved.');
        }
    } elseif ($a === 'demote' && ($t = staff_row($id, $S))) {
        q('DELETE s FROM staff_courses s JOIN courses c ON c.id=s.course_id WHERE s.user_id=? AND c.school_id=?', [$id, $S]);
        if ($t['guest']) { q('DELETE FROM user_schools WHERE user_id=? AND school_id=?', [$id, $S]); Audit::log('removed visiting staff from school', 'user', $id); flash('ok', 'Removed from this school.'); }
        else { q('UPDATE users SET role_id=1 WHERE id=? AND role_id IN (2,3)', [$id]); q('DELETE FROM user_permissions WHERE user_id=?', [$id]); Audit::log('returned staff to student', 'user', $id); flash('ok', 'Now a regular student.'); }
        redirect_or_ajax('admin/staff.php'); }
    elseif ($a === 'benefits' && !is_super()) flash('bad', 'Plus benefits are set by the platform owner.');
    elseif ($a === 'benefits') { foreach (['rep_plus', 'lecturer_plus', 'lecturer_autopublish', 'rep_autopublish'] as $k) Settings::set($k, isset($_POST[$k]) ? '1' : '0'); Audit::log('changed staff benefits', 'settings'); flash('ok', 'Saved.'); }
    redirect_self_or_ajax();
}
$id = (int)($_GET['id'] ?? 0);
$act = 'admin/staff.php';
if ($id) {
    $t = staff_row($id, $S);
    if (!$t) { flash('bad', 'Account not found.'); redirect_or_ajax('admin/staff.php'); }
    if ($t['guest']) $t['role_id'] = $t['guest']['role_id'];   // the role they have in this school
    $title = $t['full_name'];
    $fromRole = array_column(rows('SELECT id permission_id FROM permissions WHERE code IN (' . implode(',', array_map(fn($c) => db()->quote($c), Auth::rolePerms((int)$t['role_id'], $S) ?: ['-'])) . ')'), 'permission_id');
    $extra = array_column(rows('SELECT permission_id FROM user_permissions WHERE user_id=?', [$id]), 'permission_id');
    $mine = array_column(rows('SELECT s.course_id FROM staff_courses s JOIN courses c ON c.id=s.course_id WHERE s.user_id=? AND c.school_id=?', [$id, $S]), 'course_id');
    $courses = rows('SELECT c.id,c.code,c.title,c.department_id,d.name dept FROM courses c JOIN departments d ON d.id=c.department_id WHERE ' . in_school('c') . ' ORDER BY d.name,c.code');
    $byDept = []; foreach ($courses as $c) $byDept[$c['dept']][] = $c;
    $assigned = array_values(array_filter($courses, fn($c) => in_array($c['id'], $mine)));
    $per = per_page(10); $page = page_no(); [$aList, $aTotal] = page_slice($assigned, $per, $page);
    $st = row("SELECT COUNT(*) n, COALESCE(SUM(status='published'),0) pub, COALESCE(SUM(status='pending'),0) pend, COALESCE(SUM(views),0) v FROM papers WHERE uploaded_by=? AND school_id=?", [$id, $S]);
    $roleName = [1 => 'Student', 2 => 'Class rep', 3 => 'Lecturer'][(int)$t['role_id']] ?? ucfirst(str_replace('_', ' ', $t['role']));
    $lt = (int)$t['role_id'] === 3 ? row("SELECT (SELECT COUNT(*) FROM course_outlines WHERE user_id=? AND school_id=$S) outl, (SELECT COUNT(*) FROM lesson_notes WHERE user_id=? AND status='published' AND school_id=$S) pub,
      (SELECT COUNT(*) FROM lesson_notes WHERE user_id=? AND status='draft' AND school_id=$S) dr, (SELECT COALESCE(SUM(views),0) FROM lesson_notes WHERE user_id=? AND school_id=$S) rd", [$id, $id, $id, $id]) : null;
    $ownPlus = (int)$t['plus'] === 1 && (empty($t['plus_expires']) || strtotime($t['plus_expires'] . ' UTC') > time());
    include ROOT . '/includes/dashboard_header.php';
    if ($t['guest']) echo '<div class="banner">' . e($t['full_name']) . ' belongs to ' . e($t['home_school'] ?: 'another school') . ' and also works here as ' . e(strtolower($roleName)) . '. Their extra permissions are set by their home school.</div>';
    echo page_head($t['full_name'], $t['email'] . ' · ' . $roleName, '<a class="btn sm line" href="' . e(url('admin/staff.php')) . '">All staff</a>' . modal_btn('tpl-access', 'Edit access for ' . $t['full_name'], 'Edit access', 'btn sm', 'edit', 'wide'));
    ?>
    <section class="kpis"><div class="card kpi"><b><?= (int)$st['n'] ?></b><small>Uploads</small></div><div class="card kpi"><b><?= (int)$st['pub'] ?></b><small>Live</small></div><div class="card kpi"><b><?= (int)$st['pend'] ?></b><small>Pending</small></div><div class="card kpi"><b><?= number_format((int)$st['v']) ?></b><small>Views</small></div></section>
    <?php if ($lt): $benefit = setting('lecturer_plus', '1') === '1'; $quota = Lessons::quota(); ?>
    <section class="card"><div class="hd"><div><h2>Lecturer dashboard</h2><p class="sub">Course outline to lesson notes needs PrepDeck Plus.
      <?= $ownPlus ? 'Own Plus' . ($t['plus_expires'] ? ' until ' . e(fdate($t['plus_expires'])) : '') . '.' : ($benefit ? 'Plus comes with the lecturer role benefit.' : 'No Plus: the outline tool is locked for this lecturer.') ?></p></div>
      <div class="rowact"><a class="btn sm line" href="<?= e(url('admin/teaching.php?tab=notes&user=' . $id)) ?>">Lesson notes</a>
        <?= !is_super() ? '' : ($ownPlus ? row_form('admin/teaching.php', ['a' => 'revoke', 'id' => $id], 'Remove Plus', 'btn sm danger', 'Remove the Plus subscription from ' . $t['full_name'] . '?') : modal_btn('tpl-lt-plus', 'Give Plus to ' . $t['full_name'], 'Give Plus', 'btn sm')) ?></div></div>
      <div class="kpis" style="margin-top:12px"><div class="card kpi"><b><?= (int)$lt['outl'] ?></b><small>Course outlines</small></div><div class="card kpi"><b><?= (int)$lt['pub'] ?></b><small>Lesson notes live</small></div><div class="card kpi"><b><?= (int)$lt['dr'] ?></b><small>Drafts</small></div>
        <div class="card kpi"><b><?= Lessons::usedThisMonth($id) ?><?= $quota ? '<small style="display:inline">/' . $quota . '</small>' : '' ?></b><small>AI notes this month · <?= number_format((int)$lt['rd']) ?> reads</small></div></div>
      <?php if (!$ownPlus && is_super()): ?><template id="tpl-lt-plus"><form class="card mform" method="post" data-action="admin/teaching.php"><?= csrf_field() ?><input type="hidden" name="a" value="grant"><input type="hidden" name="id" value="<?= $id ?>"><h2>Give Plus</h2><p class="note">No payment is taken.</p>
        <div class="field"><label for="ltp">Period</label><select class="in" id="ltp" name="period"><?php foreach (Billing::LABEL as $pk => $pl): ?><option value="<?= $pk ?>"<?= $pk === 'semester' ? ' selected' : '' ?>><?= e($pl) ?></option><?php endforeach; ?></select></div><button class="btn">Give Plus</button></form></template><?php endif; ?>
    </section>
    <?php endif; ?>
    <div class="grid2">
    <section class="card dt"><?= dt_head('Permissions', 'Role: ' . $roleName . ($t['dept'] ? ' · ' . $t['dept'] : '')) ?>
      <div class="dtwrap"><table class="dtable"><thead><tr><th>Permission</th><th>Access</th></tr></thead><tbody>
      <?php foreach ($perms as $p): if ($p['code'] === 'admin') continue; $inh = in_array($p['id'], $fromRole); $ext = in_array($p['id'], $extra); ?><tr>
        <td class="dmain"><span class="dtt"><?= e($p['code']) ?></span><span class="ts"><?= e($desc[$p['code']] ?? '') ?></span></td>
        <td><?= $inh ? '<span class="pill ok">From role</span>' : ($ext ? '<span class="pill plus">Extra</span>' : '<span class="muted">&ndash;</span>') ?></td></tr>
      <?php endforeach; ?></tbody></table></div></section>
    <section class="card dt"><?= dt_head('Assigned courses', 'Uploading, reviewing and announcements only work inside these courses.') ?>
      <div class="dtwrap"><table class="dtable"><thead><tr><th>Code</th><th>Title</th><th>Department</th></tr></thead><tbody>
      <?php foreach ($aList as $c): ?><tr><td class="dmain nw"><span class="code"><?= e($c['code']) ?></span></td><td><?= e($c['title']) ?></td><td class="muted"><?= e($c['dept']) ?></td></tr><?php endforeach; if (!$aList) echo dt_empty(3, 'None assigned yet. Use Edit access to assign courses.'); ?></tbody></table></div>
      <?= dt_foot($aTotal, $per, $page, ['id' => $id]) ?></section>
    </div>
    <div><?= row_form($act, ['a' => 'demote', 'id' => $id], 'Remove staff access', 'btn sm danger', 'Return this person to a regular student and remove their extra access?') ?></div>
    <template id="tpl-access">
    <form class="card mform" method="post" data-action="<?= $act ?>"><?= csrf_field() ?><input type="hidden" name="a" value="save"><input type="hidden" name="id" value="<?= $id ?>"><h2>Access</h2>
      <div class="field"><label for="rl">Role</label><select class="in" id="rl" name="role_id"><option value="1"<?= $t['role_id'] == 1 ? ' selected' : '' ?>>Student</option><option value="2"<?= $t['role_id'] == 2 ? ' selected' : '' ?>>Class rep</option><option value="3"<?= $t['role_id'] == 3 ? ' selected' : '' ?>>Lecturer</option></select><span class="hint note">Permissions marked "from role" come with the role. Tick extra ones to give this person more than their role.</span></div>
      <h2>Permissions</h2><div class="fgrid2">
      <?php if (!$t['guest']) foreach ($perms as $p): $inh = in_array($p['id'], $fromRole); ?>
      <label class="chk" style="align-items:flex-start"><input type="checkbox" name="perm[<?= $p['id'] ?>]" value="1"<?= $inh || in_array($p['id'], $extra) ? ' checked' : '' ?><?= $inh ? ' disabled' : '' ?>><span><b><?= e($p['code']) ?></b> <?= $inh ? '<span class="pill free">from role</span>' : '' ?><br><small class="note"><?= e($desc[$p['code']] ?? '') ?></small></span></label>
      <?php endforeach; ?></div>
      <h2>Assigned courses</h2>
      <?php foreach ($byDept as $dn => $cs): ?><fieldset style="border:1.5px solid var(--line);border-radius:14px;margin:0;padding:10px 12px"><legend><b><?= e($dn) ?></b></legend>
        <label class="chk"><input type="checkbox" onclick="this.closest('fieldset').querySelectorAll('input[name=\'course[]\']').forEach(c=>c.checked=this.checked)">Select all</label>
        <div class="fgrid2" style="gap:0 14px"><?php foreach ($cs as $c): ?><label class="chk"><input type="checkbox" name="course[]" value="<?= $c['id'] ?>"<?= in_array($c['id'], $mine) ? ' checked' : '' ?>><?= e($c['code']) ?> &middot; <?= e($c['title']) ?></label><?php endforeach; ?></div></fieldset>
      <?php endforeach; if (!$byDept) echo empty_state('Add courses first in Departments & exams.'); ?>
      <button class="btn" type="submit">Save access</button></form>
    </template>
    <?php include ROOT . '/includes/dashboard_footer.php'; exit;
}
$q = trim((string)($_GET['q'] ?? '')); $rf = (int)($_GET['role'] ?? 0);
$w = ['((' . in_school('u') . ' AND (u.role_id IN (2,3) OR EXISTS (SELECT 1 FROM user_permissions x WHERE x.user_id=u.id))) OR EXISTS (SELECT 1 FROM user_schools m WHERE m.user_id=u.id AND m.school_id=' . $S . '))', 'u.role_id NOT IN (4,5)']; $a = [];
if ($q !== '') { $w[] = '(u.full_name LIKE ? OR u.email LIKE ?)'; array_push($a, "%$q%", "%$q%"); } if (in_array($rf, [2, 3], true)) { $w[] = 'u.role_id=?'; $a[] = $rf; }
$W = implode(' AND ', $w); $per = per_page(15); $page = page_no(); $total = (int)val("SELECT COUNT(*) FROM users u WHERE $W", $a);
$list = rows("SELECT u.id,u.full_name,u.email,u.role_id,r.name role,d.name dept,
  (SELECT COUNT(*) FROM staff_courses s JOIN courses c ON c.id=s.course_id WHERE s.user_id=u.id AND c.school_id=$S) courses, IF(u.school_id=$S, (SELECT COUNT(*) FROM user_permissions x WHERE x.user_id=u.id), 0) extra,
  (SELECT COUNT(*) FROM papers p WHERE p.uploaded_by=u.id AND p.school_id=$S) up, (SELECT COUNT(*) FROM papers p WHERE p.uploaded_by=u.id AND p.status='published' AND p.school_id=$S) pub,
  (SELECT COUNT(*) FROM lesson_notes n WHERE n.user_id=u.id AND n.school_id=$S) lessons, u.school_id<>$S guest, (SELECT m.role_id FROM user_schools m WHERE m.user_id=u.id AND m.school_id=$S) mrole
  FROM users u JOIN roles r ON r.id=u.role_id LEFT JOIN departments d ON d.id=u.department_id WHERE $W ORDER BY u.role_id DESC, u.full_name LIMIT $per OFFSET " . (($page - 1) * $per), $a);
include ROOT . '/includes/dashboard_header.php';
echo page_head('Lecturers & reps', 'Decide who can upload, review and announce, and for which courses.', '<a class="btn line" href="' . e(url('admin/teaching.php')) . '">' . ic('lesson') . 'Lecturer tools</a>' . (is_super() ? modal_btn('tpl-staff-bf', 'Role benefits', 'Edit benefits', 'btn line', 'edit') : '') . modal_btn('tpl-staff-add', 'Add a lecturer or class rep', 'Add staff', 'btn', 'plus'));
?>
<section class="card dt">
  <?= dt_head('Staff', number_format($total) . ' in this view') ?>
  <form class="toolbar" method="get"><label class="search"><?= ic('search', 'i') ?><span class="vh">Search</span><input name="q" value="<?= e($q) ?>" type="search" placeholder="Name or email"></label>
    <select class="in" name="role" aria-label="Role"><option value="">All roles</option><option value="3"<?= $rf === 3 ? ' selected' : '' ?>>Lecturers</option><option value="2"<?= $rf === 2 ? ' selected' : '' ?>>Class reps</option></select><button class="btn sm">Filter</button></form>
  <div class="dtwrap"><table class="dtable"><thead><tr><th>Name</th><th>Role</th><th>Department</th><th class="num">Courses</th><th>Extra access</th><th class="num">Uploads</th><th class="num">Live</th><th class="num">Lesson notes</th><th data-nosort><span class="vh">Actions</span></th></tr></thead><tbody>
  <?php foreach ($list as $r): $href = url('admin/staff.php?id=' . $r['id']); ?><tr data-href="<?= e($href) ?>">
    <td class="dmain"><div class="ucell"><span class="av"><?= e(initials($r['full_name'])) ?></span><div style="min-width:0"><a class="dtt" href="<?= e($href) ?>"><?= e($r['full_name']) ?></a><span class="ts"><?= e($r['email']) ?></span></div></div></td>
    <?php if ($r['guest']) $r['role_id'] = $r['mrole']; ?><td><span class="pill role"><?= $r['role_id'] == 2 ? 'Class rep' : ($r['role_id'] == 3 ? 'Lecturer' : e(ucfirst($r['role']))) ?></span><?= $r['guest'] ? '<span class="ts">From another school</span>' : '' ?></td><td class="muted"><?= e($r['dept'] ?: '–') ?></td>
    <td class="num"><span class="pill <?= $r['courses'] ? 'ok' : 'wait' ?>"><?= (int)$r['courses'] ?></span></td><td><?= $r['extra'] ? '<span class="pill plus">+' . (int)$r['extra'] . '</span>' : '<span class="muted">&ndash;</span>' ?></td>
    <td class="num"><?= (int)$r['up'] ?></td><td class="num"><?= (int)$r['pub'] ?></td><td class="num"><?= $r['role_id'] == 3 ? (int)$r['lessons'] : '<span class="muted">&ndash;</span>' ?></td>
    <td class="act"><a class="btn sm line" href="<?= e($href) ?>">Manage</a></td></tr>
  <?php endforeach; if (!$list) echo dt_empty(9, 'No lecturers or class reps yet. Use Add staff.'); ?></tbody></table></div>
  <?= dt_foot($total, $per, $page, ['q' => $q, 'role' => $rf ?: '']) ?>
</section>
<template id="tpl-staff-add">
<form class="card mform" method="post" data-action="<?= $act ?>"><?= csrf_field() ?><input type="hidden" name="a" value="promote"><h2>Add a lecturer or class rep</h2><p class="note">Use the email of an existing student to promote them, or fill in a name and password to create the account. A lecturer who already has a PrepDeck account at another school is added to this school too, and keeps their account.</p>
  <div class="field"><label for="pe">Email</label><input class="in" id="pe" type="email" name="email" required></div>
  <div class="f2"><div class="field"><label for="pr">Role</label><select class="in" id="pr" name="role_id"><option value="2">Class rep</option><option value="3">Lecturer</option></select></div><div class="field"><label for="pd">Department (new account)</label><select class="in" id="pd" name="department_id"><?= options($deps, null, 'id', 'name', 'None') ?></select></div></div>
  <div class="f2"><div class="field"><label for="pn">Full name (new account)</label><input class="in" id="pn" name="full_name"></div><div class="field"><label for="pp">Password (new account)</label><input class="in" id="pp" name="password" type="password" minlength="8" autocomplete="new-password"></div></div><button class="btn">Continue</button></form>
</template>
<template id="tpl-staff-bf">
<form class="card mform" method="post" data-action="<?= $act ?>"><?= csrf_field() ?><input type="hidden" name="a" value="benefits"><h2>Role benefits</h2>
  <label class="chk"><input type="checkbox" name="rep_plus" value="1"<?= setting('rep_plus', '0') === '1' ? ' checked' : '' ?>>Class reps get Plus features free</label>
  <label class="chk"><input type="checkbox" name="lecturer_plus" value="1"<?= setting('lecturer_plus', '1') === '1' ? ' checked' : '' ?>>Lecturers get Plus features free (includes course outline to lesson notes)</label>
  <label class="chk"><input type="checkbox" name="rep_autopublish" value="1"<?= setting('rep_autopublish', '0') === '1' ? ' checked' : '' ?>>Class rep uploads go live without review</label>
  <label class="chk"><input type="checkbox" name="lecturer_autopublish" value="1"<?= setting('lecturer_autopublish', '1') === '1' ? ' checked' : '' ?>>Lecturer uploads go live without review</label><button class="btn">Save benefits</button></form>
</template>
<?php include ROOT . '/includes/dashboard_footer.php'; ?>
