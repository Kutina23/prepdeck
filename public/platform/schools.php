<?php
// Platform owner: the schools directory. Add and edit schools, licences, school admins and per-school switches,
// approve "my school is not listed" requests, and open any school's dashboard.
require __DIR__ . '/../../app/bootstrap.php';
$u = require_super(); $platformArea = true; $active = 'p-schools'; $title = 'Schools'; $scripts = ['forms.js'];
$act = 'platform/schools.php';
/** A free slug for a new school. */
function school_slug(string $base): string {
    $b = Tenant::slug($base) ?: 'school'; $s = $b; $i = 2;
    while (val('SELECT COUNT(*) FROM schools WHERE slug=?', [$s])) $s = $b . '-' . $i++;
    return $s;
}
/** School fields from a posted form. */
function school_fields(): array {
    $em = strtolower(trim((string)($_POST['contact_email'] ?? '')));
    return [
        'name' => Validator::str($_POST['name'] ?? '', 3, 160), 'short_name' => mb_substr(trim((string)($_POST['short_name'] ?? '')), 0, 40) ?: null,
        'type' => isset(Tenant::TYPES[$_POST['type'] ?? '']) ? $_POST['type'] : 'other', 'ownership' => ($_POST['ownership'] ?? '') === 'private' ? 'private' : 'public',
        'region' => in_array($_POST['region'] ?? '', Tenant::REGIONS, true) ? $_POST['region'] : null, 'city' => mb_substr(trim((string)($_POST['city'] ?? '')), 0, 80) ?: null,
        'contact_email' => Validator::email($em) ? $em : null, 'phone' => mb_substr(trim((string)($_POST['phone'] ?? '')), 0, 40) ?: null, 'website' => mb_substr(trim((string)($_POST['website'] ?? '')), 0, 190) ?: null,
    ];
}
// per-school switches the platform owner controls (stored as that school's own settings)
$switches = [
    'reg_open' => ['Students can register', '1'],
    'lecturer_plus' => ['Lecturers get Plus free', '1'],
    'rep_plus' => ['Class reps get Plus free', '0'],
    'lesson_tools_on' => ['Lecturer outline-to-lesson-notes tool', '1'],
    'ai_on' => ['PrepDeck AI chat for students', '1'],
];
if (is_post()) {
    form_guard(); $a = $_POST['a'] ?? ''; $id = (int)($_POST['id'] ?? 0);
    $s = $id ? Tenant::find($id) : null;
    if ($a === 'add' || $a === 'req_approve') {
        $f = school_fields(); $req = $a === 'req_approve' ? row("SELECT * FROM school_requests WHERE id=? AND status='pending'", [(int)($_POST['request'] ?? 0)]) : null;
        if ($a === 'req_approve' && !$req) { flash('bad', 'Request not found.'); redirect_self_or_ajax(); }
        if (!$f['name']) { flash('bad', 'Enter the school name.'); redirect_self_or_ajax(); }
        $existing = (int)val('SELECT id FROM schools WHERE LOWER(name)=LOWER(?)', [$f['name']]);
        if ($existing && $a === 'add') { flash('bad', 'That school is already in the directory.'); redirect_or_ajax($act . '?id=' . $existing); }
        if (!$existing) {
            q('INSERT INTO schools(slug,name,short_name,type,ownership,region,city,contact_email,phone,website,plan,plan_expires,max_students,is_active,is_listed) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,1,1)',
                [school_slug($f['short_name'] ? $f['short_name'] . ' ' . ($f['city'] ?? '') : $f['name']), $f['name'], $f['short_name'], $f['type'], $f['ownership'], $f['region'], $f['city'], $f['contact_email'], $f['phone'], $f['website'],
                 Validator::in($_POST['plan'] ?? '', ['trial', 'basic', 'standard', 'premium']) ?? Settings::global('school_default_plan', 'basic'), ($_POST['plan_expires'] ?? '') ?: null, ((int)($_POST['max_students'] ?? 0)) ?: null]);
            $existing = (int)db()->lastInsertId(); Audit::log("added school {$f['name']}", 'school', $existing);
        }
        if ($req) {
            q("UPDATE school_requests SET status='approved', school_id=?, handled_by=?, handled_at=NOW() WHERE id=?", [$existing, $u['id'], $req['id']]);
            // everyone else who asked for the same school is told too
            foreach (rows("SELECT id,requester_email FROM school_requests WHERE (id=? OR (LOWER(name)=LOWER(?) AND status='pending'))", [$req['id'], $req['name']]) as $r) {
                q("UPDATE school_requests SET status='approved', school_id=?, handled_by=?, handled_at=NOW() WHERE id=?", [$existing, $u['id'], $r['id']]);
                try { Mailer::schoolAdded($r['requester_email'], $f['name'], $existing); } catch (Throwable $e) { error_log('[prepdeck-mail] school added: ' . $e->getMessage()); }
            }
        }
        flash('ok', $req ? 'School added and the people who asked were emailed.' : 'School added.'); redirect_or_ajax($act . '?id=' . $existing);
    }
    if ($a === 'req_reject') { q("UPDATE school_requests SET status='rejected', handled_by=?, handled_at=NOW() WHERE id=?", [$u['id'], (int)($_POST['request'] ?? 0)]); flash('ok', 'Request closed.'); redirect_self_or_ajax(); }
    if (!$s) { flash('bad', 'School not found.'); redirect_or_ajax($act); }
    if ($a === 'open') { Tenant::switchTo($id); Audit::log("opened the dashboard of {$s['name']}", 'school', $id); redirect_or_ajax('admin/'); }
    elseif ($a === 'save') {
        $f = school_fields();
        if (!$f['name']) flash('bad', 'Enter the school name.');
        else {
            $slug = Tenant::slug((string)($_POST['slug'] ?? '')) ?: $s['slug'];
            if ($slug !== $s['slug'] && val('SELECT COUNT(*) FROM schools WHERE slug=? AND id<>?', [$slug, $id])) { flash('bad', 'That web address is taken by another school.'); redirect_self_or_ajax(); }
            q('UPDATE schools SET slug=?,name=?,short_name=?,type=?,ownership=?,region=?,city=?,contact_email=?,phone=?,website=?,notes=? WHERE id=?',
                [$slug, $f['name'], $f['short_name'], $f['type'], $f['ownership'], $f['region'], $f['city'], $f['contact_email'], $f['phone'], $f['website'], mb_substr(trim((string)($_POST['notes'] ?? '')), 0, 2000) ?: null, $id]);
            if ($err = Tenant::saveLogo($id, $_FILES['logo'] ?? [])) flash('bad', $err);
            Audit::log("edited school {$f['name']}", 'school', $id); flash('ok', 'School saved.');
        }
    } elseif ($a === 'licence') {
        q('UPDATE schools SET plan=?, plan_expires=?, max_students=?, is_active=?, is_listed=? WHERE id=?', [Validator::in($_POST['plan'] ?? '', ['trial', 'basic', 'standard', 'premium']) ?? $s['plan'],
            ($_POST['plan_expires'] ?? '') ?: null, ((int)($_POST['max_students'] ?? 0)) ?: null, isset($_POST['is_active']) ? 1 : 0, isset($_POST['is_listed']) ? 1 : 0, $id]);
        Audit::log("changed the licence of {$s['name']}", 'school', $id); flash('ok', 'Licence and status saved.' . (isset($_POST['is_active']) ? '' : ' The school is switched off: its members cannot sign in.'));
    } elseif ($a === 'switches') {
        foreach ($switches as $k => [$l, $def]) { $v = $_POST['sw'][$k] ?? 'default'; $v === 'default' ? Settings::reset($id, $k) : Settings::put($id, $k, $v === '1' ? '1' : '0'); }
        Audit::log("changed switches for {$s['name']}", 'school', $id); flash('ok', 'Saved.');
    }     elseif ($a === 'admin_add') {
        $pick = (string)($_POST['user_id'] ?? '');
        $uid = ctype_digit($pick) ? (int)$pick : 0;
        $email = strtolower(trim((string)($_POST['email'] ?? ''))); $name = Validator::str($_POST['full_name'] ?? '', 2, 120);
        if ($uid) {   // an existing account of this school, picked from the list
            $t = row('SELECT * FROM users WHERE id=? AND school_id=?', [$uid, $id]);
            if (!$t) flash('bad', 'Pick a user of this school.');
            elseif ((int)$t['role_id'] === Tenant::SUPER) flash('bad', 'That is a platform owner account.');
            elseif ((int)$t['role_id'] === 4) flash('bad', $t['full_name'] . ' is already an admin of this school.');
            else { q('UPDATE users SET role_id=4 WHERE id=?', [$t['id']]); Audit::log("made {$t['email']} admin of {$s['name']}", 'user', (int)$t['id']); flash('ok', $t['full_name'] . ' is now an admin of ' . $s['name'] . '.'); }
        } elseif ($pick === 'new') {   // nobody at this school has that person yet: create the account
            $t = row('SELECT * FROM users WHERE email=?', [$email]);
            if (!Validator::email($email)) flash('bad', 'Enter a valid email address.');
            elseif ($t && (int)$t['school_id'] === $id) flash('bad', 'That account is already at this school — pick it from the list above.');
            elseif ($t) flash('bad', 'That email belongs to an account at another school. Move it in All users first, or use a different email.');
            elseif (!$name) flash('bad', 'Add their name to create the account.');
            else {
                $pw = bin2hex(random_bytes(5));
                q("INSERT INTO users(school_id,role_id,full_name,email,password_hash,status,email_verified_at) VALUES (?,4,?,?,?,'active',NOW())", [$id, $name, $email, password_hash($pw, PASSWORD_DEFAULT)]);
                $nid = (int)db()->lastInsertId(); Audit::log("created admin $email for {$s['name']}", 'user', $nid);
                try { Mailer::schoolAdminWelcome(['id' => $nid, 'full_name' => $name, 'email' => $email], $s['name'], $pw); } catch (Throwable $e) { error_log('[prepdeck-mail] admin welcome: ' . $e->getMessage()); }
                flash('ok', "Admin account created. Temporary password for $email: $pw (also emailed; shown once).");
            }
        } else flash('bad', 'Pick an existing user, or choose "create a new account".');
    } elseif ($a === 'admin_remove') {
        $uid = (int)($_POST['user'] ?? 0);
        q('UPDATE users SET role_id=1 WHERE id=? AND school_id=? AND role_id=4', [$uid, $id]); Audit::log("removed school admin $uid from {$s['name']}", 'user', $uid); flash('ok', 'They are a regular account again.');
    } elseif ($a === 'delete') {
        $busy = (int)val('SELECT COUNT(*) FROM users WHERE school_id=?', [$id]) + (int)val('SELECT COUNT(*) FROM papers WHERE school_id=?', [$id]);
        if ($busy) flash('bad', 'This school has accounts or papers. Switch it off instead.');
        else {
            foreach (['exam_timetable', 'academic_terms', 'courses', 'departments', 'announcements', 'alert_subscribers', 'coupons'] as $t) q("DELETE FROM `$t` WHERE school_id=?", [$id]);
            q('DELETE FROM site_settings WHERE school_id=?', [$id]); q('DELETE FROM plan_prices WHERE school_id=?', [$id]); q('DELETE FROM schools WHERE id=?', [$id]);
            Audit::log("deleted school {$s['name']}", 'school', $id); flash('ok', 'School deleted.'); redirect_or_ajax($act);
        }
    }
    redirect_self_or_ajax();
}

$tab = ($_GET['tab'] ?? '') === 'requests' ? 'requests' : 'schools';
$id = (int)($_GET['id'] ?? 0);
$typeOpts = array_map(fn($k, $l) => ['id' => $k, 'n' => $l], array_keys(Tenant::TYPES), Tenant::TYPES);
$regionOpts = array_map(fn($r) => ['id' => $r, 'n' => $r], Tenant::REGIONS);
$planOpts = array_map(fn($p) => ['id' => $p, 'n' => ucfirst($p)], ['trial', 'basic', 'standard', 'premium']);
/** The add / edit form fields. */
$form = function (?array $s) use ($typeOpts, $regionOpts): string {
    $v = fn($k) => e((string)($s[$k] ?? ''));
    return '<div class="f2"><div class="field"><label for="sn">Name</label><input class="in" id="sn" name="name" value="' . $v('name') . '" required></div><div class="field"><label for="ss">Short name</label><input class="in" id="ss" name="short_name" value="' . $v('short_name') . '" placeholder="UG, KNUST..."></div></div>'
        . '<div class="f2"><div class="field"><label for="st">Type</label><select class="in" id="st" name="type">' . options($typeOpts, $s['type'] ?? 'university', 'id', 'n') . '</select></div><div class="field"><label for="so">Ownership</label><select class="in" id="so" name="ownership"><option value="public">Public</option><option value="private"' . (($s['ownership'] ?? '') === 'private' ? ' selected' : '') . '>Private</option></select></div></div>'
        . '<div class="f2"><div class="field"><label for="sr">Region</label><select class="in" id="sr" name="region">' . options($regionOpts, $s['region'] ?? null, 'id', 'n', 'Choose') . '</select></div><div class="field"><label for="sc">Town or city</label><input class="in" id="sc" name="city" value="' . $v('city') . '"></div></div>'
        . '<div class="f2"><div class="field"><label for="se">Contact email</label><input class="in" id="se" type="email" name="contact_email" value="' . $v('contact_email') . '"></div><div class="field"><label for="sp">Phone</label><input class="in" id="sp" name="phone" value="' . $v('phone') . '"></div></div>'
        . '<div class="field"><label for="sw">Website</label><input class="in" id="sw" name="website" value="' . $v('website') . '" placeholder="https://"></div>';
};

if ($id && ($s = Tenant::find($id))) {
    $title = $s['name'];
    $st = row("SELECT (SELECT COUNT(*) FROM users WHERE school_id=? AND role_id=1) students, (SELECT COUNT(*) FROM users WHERE school_id=? AND role_id IN (2,3)) staff,
        (SELECT COUNT(*) FROM users WHERE school_id=? AND plus=1 AND (plus_expires IS NULL OR plus_expires>NOW()) AND role_id NOT IN (4,5)) plus,
        (SELECT COUNT(*) FROM departments WHERE school_id=?) depts, (SELECT COUNT(*) FROM courses WHERE school_id=?) courses, (SELECT COUNT(*) FROM papers WHERE school_id=? AND status='published') papers,
        (SELECT COALESCE(SUM(amount),0) FROM transactions WHERE school_id=? AND status='paid') revenue, (SELECT COALESCE(SUM(tokens_in+tokens_out),0) FROM processing_jobs WHERE school_id=?) tokens,
        (SELECT MAX(last_login_at) FROM users WHERE school_id=?) seen", array_fill(0, 9, $id));
    $admins = rows('SELECT id,full_name,email,last_login_at,status FROM users WHERE school_id=? AND role_id=4 ORDER BY full_name', [$id]);
    $candidates = rows('SELECT u.id,u.full_name,u.email,r.name role FROM users u JOIN roles r ON r.id=u.role_id WHERE u.school_id=? AND u.role_id IN (1,2,3) ORDER BY u.full_name', [$id]);
    $visitors = rows('SELECT u.id,u.full_name,u.email,m.role_id,h.name home FROM user_schools m JOIN users u ON u.id=m.user_id LEFT JOIN schools h ON h.id=u.school_id WHERE m.school_id=? ORDER BY u.full_name', [$id]);
    $own = []; foreach (rows('SELECT k,v FROM site_settings WHERE school_id=?', [$id]) as $r) $own[$r['k']] = $r['v'];
    $hasMatrix = (bool)val("SELECT COUNT(*) FROM site_settings WHERE school_id=? AND k LIKE 'perm_matrix_%'", [$id]);
    include ROOT . '/includes/dashboard_header.php';
    echo page_head($s['name'], (Tenant::TYPES[$s['type']] ?? $s['type']) . ' · ' . trim(($s['city'] ?? '') . ', ' . ($s['region'] ?? ''), ', ') . ' · ' . ($s['is_active'] ? 'active' : 'switched off'),
        '<a class="btn sm line" href="' . e(url($act)) . '">All schools</a><a class="btn sm line" href="' . e(url('platform/finances.php?school=' . $id)) . '">' . ic('chart') . 'Finances</a>' . row_form($act, ['a' => 'open', 'id' => $id], ic('grid') . 'Open dashboard', 'btn sm') . modal_btn('tpl-edit', 'Edit ' . $s['name'], 'Edit', 'btn sm line', 'edit', 'wide'));
    if (!$s['is_active']) echo '<div class="banner warn">This school is switched off. Its students and staff cannot sign in and it is hidden from the directory.</div>';
    ?>
    <section class="kpis">
      <div class="card kpi"><b><?= number_format((int)$st['students']) ?></b><small>Students<?= $s['max_students'] ? ' of ' . number_format((int)$s['max_students']) : '' ?> · <?= (int)$st['plus'] ?> Plus</small></div>
      <div class="card kpi"><b><?= (int)$st['staff'] ?></b><small>Lecturers &amp; reps · <?= count($admins) ?> admin(s)</small></div>
      <div class="card kpi"><b><?= (int)$st['papers'] ?></b><small>Papers live · <?= (int)$st['depts'] ?> depts · <?= (int)$st['courses'] ?> courses</small></div>
      <div class="card kpi"><b><?= e(number_format((float)$st['revenue'], 2)) ?></b><small>Revenue · <?= number_format($st['tokens'] / 1000, 1) ?>k AI tokens · seen <?= e(ago($st['seen'])) ?></small></div>
    </section>
    <div class="grid2">
      <section class="card dt"><?= dt_head('Profile', '', $s['logo'] ? '<img src="' . e(url('api/schools.php?a=logo&id=' . $id)) . '" alt="" style="height:40px;border-radius:8px">' : '') ?>
        <dl class="kv"><dt>Short name</dt><dd><?= e($s['short_name'] ?: '-') ?></dd><dt>Web address</dt><dd><span class="code"><?= e($s['slug']) ?></span></dd><dt>Ownership</dt><dd><?= e(ucfirst($s['ownership'])) ?></dd>
          <dt>Contact</dt><dd><?= e(implode(' · ', array_filter([$s['contact_email'], $s['phone'], $s['website']])) ?: '-') ?></dd><dt>Directory</dt><dd><?= $s['is_listed'] ? 'Listed' : 'Hidden' ?></dd><dt>Added</dt><dd><?= e(fdate($s['created_at'])) ?></dd>
          <?php if ($s['notes']): ?><dt>Notes</dt><dd><?= nl2br(e($s['notes'])) ?></dd><?php endif; ?></dl></section>
      <section class="card dt"><?= dt_head('Licence & status', 'What this school has on PrepDeck', modal_btn('tpl-licence', 'Licence & status', 'Edit', 'btn sm', 'edit')) ?>
        <dl class="kv"><dt>Plan</dt><dd><?= e(ucfirst($s['plan'])) ?></dd><dt>Ends</dt><dd><?= $s['plan_expires'] ? status_pill(strtotime($s['plan_expires']) < time() ? 'expired' : 'active') . ' ' . e(fdate($s['plan_expires'])) : 'No end date' ?></dd>
          <dt>Student limit</dt><dd><?= $s['max_students'] ? number_format((int)$s['max_students']) : 'No limit' ?></dd><dt>Status</dt><dd><?= status_pill($s['is_active'] ? 'active' : 'suspended') ?></dd>
          <dt>Prices</dt><dd><?= Billing::priceSchool($id) === $id ? 'Own price list' : 'Platform default prices' ?></dd><dt>Permissions</dt><dd><?= $hasMatrix ? 'Custom for this school' : 'Platform defaults' ?> · <a href="<?= e(url('platform/permissions.php?school=' . $id)) ?>">Edit</a></dd></dl></section>
    </div>
    <div class="grid2">
      <section class="card dt"><?= dt_head('School admins', 'They manage this school only, with the permissions you allow.', modal_btn('tpl-admin', 'Add a school admin', 'Add admin', 'btn sm', 'plus')) ?>
        <div class="dtwrap"><table class="dtable"><thead><tr><th>Admin</th><th>Last seen</th><th data-nosort><span class="vh">Actions</span></th></tr></thead><tbody>
        <?php foreach ($admins as $x): ?><tr><td class="dmain"><span class="dtt"><?= e($x['full_name']) ?></span><span class="ts"><?= e($x['email']) ?></span></td><td class="nw muted"><?= e(ago($x['last_login_at'])) ?></td>
          <td class="act"><?= row_form($act, ['a' => 'admin_remove', 'id' => $id, 'user' => $x['id']], 'Remove admin', 'btn sm danger', 'Make ' . $x['full_name'] . ' a regular account?') ?></td></tr><?php endforeach; if (!$admins) echo dt_empty(3, 'No admin yet. Add one so the school can manage itself.'); ?></tbody></table></div></section>
      <section class="card dt"><?= dt_head('Switches', 'Overrides for this school. "Default" follows Platform settings.', modal_btn('tpl-sw', 'Switches for ' . $s['name'], 'Edit', 'btn sm', 'edit')) ?>
        <div class="dtwrap"><table class="dtable"><thead><tr><th>Switch</th><th>Value</th></tr></thead><tbody>
        <?php foreach ($switches as $k => [$l, $def]): $set = array_key_exists($k, $own); $on = ($set ? $own[$k] : Settings::global($k, $def)) === '1'; ?><tr><td class="dmain"><span class="dtt"><?= e($l) ?></span></td><td><?= $on ? '<span class="pill ok">On</span>' : '<span class="pill free">Off</span>' ?><span class="ts"><?= $set ? 'set for this school' : 'platform default' ?></span></td></tr><?php endforeach; ?></tbody></table></div></section>
    </div>
    <?php if ($visitors): ?><section class="card dt"><?= dt_head('Staff from other schools', 'Lecturers and reps whose home school is elsewhere') ?>
      <div class="dtwrap"><table class="dtable"><thead><tr><th>Name</th><th>Role here</th><th>Home school</th></tr></thead><tbody>
      <?php foreach ($visitors as $x): ?><tr><td class="dmain"><span class="dtt"><?= e($x['full_name']) ?></span><span class="ts"><?= e($x['email']) ?></span></td><td><?= (int)$x['role_id'] === 2 ? 'Class rep' : 'Lecturer' ?></td><td><?= e($x['home'] ?: '-') ?></td></tr><?php endforeach; ?></tbody></table></div></section><?php endif; ?>
    <section class="card"><h2>Danger zone</h2><p class="note" style="margin:6px 0 10px">A school with accounts or papers cannot be deleted: switch it off in Licence &amp; status instead.</p><?= row_form($act, ['a' => 'delete', 'id' => $id], 'Delete school', 'btn sm danger', 'Delete ' . $s['name'] . ' and its empty departments and courses?') ?></section>
    <template id="tpl-edit"><form class="card mform" method="post" data-action="<?= $act ?>" enctype="multipart/form-data"><?= csrf_field() ?><input type="hidden" name="a" value="save"><input type="hidden" name="id" value="<?= $id ?>"><h2>Edit school</h2>
      <?= $form($s) ?><div class="field"><label for="sg">Web address (slug)</label><input class="in" id="sg" name="slug" value="<?= e($s['slug']) ?>" pattern="[a-z0-9-]+"></div>
      <div class="field"><label for="sl">Logo (PNG, JPG or WebP, up to 1 MB)</label><input class="in" id="sl" type="file" name="logo" accept="image/png,image/jpeg,image/webp" style="padding-top:11px"></div>
      <div class="field"><label for="snt">Private notes</label><textarea class="in" id="snt" name="notes" rows="3"><?= e($s['notes'] ?? '') ?></textarea></div><button class="btn">Save school</button></form></template>
    <template id="tpl-licence"><form class="card mform" method="post" data-action="<?= $act ?>"><?= csrf_field() ?><input type="hidden" name="a" value="licence"><input type="hidden" name="id" value="<?= $id ?>"><h2>Licence &amp; status</h2>
      <div class="f2"><div class="field"><label for="lp">Plan</label><select class="in" id="lp" name="plan"><?= options($planOpts, $s['plan'], 'id', 'n') ?></select></div><div class="field"><label for="le">Ends (blank = no end)</label><input class="in" id="le" type="date" name="plan_expires" value="<?= e((string)$s['plan_expires']) ?>"></div></div>
      <div class="field"><label for="lm">Student limit (blank = no limit)</label><input class="in" id="lm" type="number" min="0" name="max_students" value="<?= e((string)$s['max_students']) ?>"></div>
      <label class="chk"><input type="checkbox" name="is_active" value="1"<?= $s['is_active'] ? ' checked' : '' ?>>Active (off = nobody from this school can sign in)</label>
      <label class="chk"><input type="checkbox" name="is_listed" value="1"<?= $s['is_listed'] ? ' checked' : '' ?>>Listed in the school directory</label><button class="btn">Save</button></form></template>
    <template id="tpl-sw"><form class="card mform" method="post" data-action="<?= $act ?>"><?= csrf_field() ?><input type="hidden" name="a" value="switches"><input type="hidden" name="id" value="<?= $id ?>"><h2>Switches</h2>
      <?php foreach ($switches as $k => [$l, $def]): $cur = $own[$k] ?? 'default'; ?><div class="frow"><div class="ft"><b><?= e($l) ?></b><small>Platform default: <?= Settings::global($k, $def) === '1' ? 'on' : 'off' ?></small></div>
        <select class="in" name="sw[<?= $k ?>]" aria-label="<?= e($l) ?>" style="width:auto"><option value="default"<?= $cur === 'default' ? ' selected' : '' ?>>Default</option><option value="1"<?= $cur === '1' ? ' selected' : '' ?>>On</option><option value="0"<?= $cur === '0' ? ' selected' : '' ?>>Off</option></select></div><?php endforeach; ?>
      <button class="btn">Save switches</button></form></template>
    <template id="tpl-admin"><form class="card mform" method="post" data-action="<?= $act ?>"><?= csrf_field() ?><input type="hidden" name="a" value="admin_add"><input type="hidden" name="id" value="<?= $id ?>"><h2>Add a school admin</h2>
      <p class="note">They manage this school only, with the permissions you allow in <a href="<?= e(url('platform/permissions.php?school=' . $id)) ?>">Permissions</a>.</p>
      <div class="field"><label for="au">Existing user</label>
        <select class="in" id="au" name="user_id">
          <option value="" selected>Select a user…</option>
          <?php foreach ($candidates as $c): ?><option value="<?= (int)$c['id'] ?>"><?= e($c['full_name']) ?> &middot; <?= e($c['email']) ?> (<?= e(role_label($c['role'])) ?>)</option><?php endforeach; ?>
          <option value="new">&mdash; Not listed? Create a new account &mdash;</option>
        </select>
        <span class="hint note">Pick a student, lecturer or class rep of this school to make them an admin.</span>
      </div>
      <div id="anew" hidden>
        <div class="f2"><div class="field"><label for="an">Full name</label><input class="in" id="an" name="full_name" autocomplete="name"></div><div class="field"><label for="ae">Email</label><input class="in" id="ae" type="email" name="email" autocomplete="email"></div></div>
        <p class="note">A temporary password is emailed to them.</p>
      </div>
      <button class="btn">Add admin</button></form></template>
    <script>document.addEventListener('change', function (e) { if (e.target && e.target.id === 'au') { var n = document.getElementById('anew'); if (n) n.hidden = e.target.value !== 'new'; } });</script>
    <?php include ROOT . '/includes/dashboard_footer.php'; exit;
}

include ROOT . '/includes/dashboard_header.php';
$reqN = (int)val("SELECT COUNT(*) FROM school_requests WHERE status='pending'");
echo page_head('Schools', 'The directory students pick from, and every school\'s licence and admins.', modal_btn('tpl-add', 'Add a school', 'Add school', 'btn', 'plus', 'wide'));
?>
<div class="tabs" role="navigation"><a class="tab" href="?tab=schools" aria-selected="<?= $tab === 'schools' ? 'true' : 'false' ?>">Directory</a><a class="tab" href="?tab=requests" aria-selected="<?= $tab === 'requests' ? 'true' : 'false' ?>">Requests<?= $reqN ? ' (' . $reqN . ')' : '' ?></a></div>
<?php if ($tab === 'requests'):
    $sf = Validator::in($_GET['status'] ?? 'pending', ['pending', 'approved', 'rejected']) ?? 'pending'; $per = per_page(15); $page = page_no();
    $total = (int)val('SELECT COUNT(*) FROM school_requests WHERE status=?', [$sf]);
    $list = rows("SELECT r.*,(SELECT COUNT(*) FROM school_requests x WHERE LOWER(x.name)=LOWER(r.name) AND x.status='pending') asks FROM school_requests r WHERE r.status=? ORDER BY r.id DESC LIMIT $per OFFSET " . (($page - 1) * $per), [$sf]); ?>
<section class="card dt"><?= dt_head('School requests', 'Approving adds the school to the directory and emails everyone who asked for it.') ?>
  <div class="tabs"><?php foreach (['pending' => 'Waiting', 'approved' => 'Added', 'rejected' => 'Closed'] as $k => $l): ?><a class="tab" href="?tab=requests&status=<?= $k ?>" aria-selected="<?= $sf === $k ? 'true' : 'false' ?>"><?= $l ?></a><?php endforeach; ?></div>
  <div class="dtwrap"><table class="dtable"><thead><tr><th>School</th><th>Where</th><th>Asked by</th><th class="num">Asks</th><th>When</th><th data-nosort><span class="vh">Actions</span></th></tr></thead><tbody>
  <?php foreach ($list as $r): ?><tr>
    <td class="dmain"><span class="dtt"><?= e($r['name']) ?></span><span class="ts"><?= e(Tenant::TYPES[$r['type']] ?? ($r['type'] ?: '')) ?><?= $r['message'] ? ' · ' . e(mb_strimwidth($r['message'], 0, 90, '…')) : '' ?></span></td>
    <td><?= e(trim(($r['city'] ?? '') . ', ' . ($r['region'] ?? ''), ', ') ?: '-') ?></td><td><?= e($r['requester_email']) ?></td><td class="num"><?= (int)$r['asks'] ?></td><td class="nw muted"><?= e(ago($r['created_at'])) ?></td>
    <td class="act"><?php if ($r['status'] === 'pending'): ?><div class="rowact"><?= modal_btn('tpl-rq' . $r['id'], 'Add ' . $r['name'], 'Add school', 'btn sm', '', 'wide') ?><?= row_form($act, ['a' => 'req_reject', 'request' => $r['id']], 'Close', 'btn sm line', 'Close this request without adding the school?') ?></div>
      <template id="tpl-rq<?= $r['id'] ?>"><form class="card mform" method="post" data-action="<?= $act ?>"><?= csrf_field() ?><input type="hidden" name="a" value="req_approve"><input type="hidden" name="request" value="<?= $r['id'] ?>"><h2>Add school</h2><p class="note">Check the details. If the school is already listed under this exact name, the request is linked to it.</p>
        <?= $form(['name' => $r['name'], 'type' => $r['type'] ?: 'university', 'region' => $r['region'], 'city' => $r['city']]) ?><button class="btn">Add and notify</button></form></template>
    <?php elseif ($r['school_id']): ?><a class="btn sm line" href="<?= e(url($act . '?id=' . $r['school_id'])) ?>">Open school</a><?php endif; ?></td></tr>
  <?php endforeach; if (!$list) echo dt_empty(6, 'Nothing here.'); ?></tbody></table></div>
  <?= dt_foot($total, $per, $page, ['tab' => 'requests', 'status' => $sf]) ?></section>
<?php else:
    $q = trim((string)($_GET['q'] ?? '')); $rg = (string)($_GET['region'] ?? ''); $ty = (string)($_GET['type'] ?? ''); $sf = (string)($_GET['status'] ?? ''); $per = per_page(25); $page = page_no();
    $w = ['1=1']; $a = [];
    if ($q !== '') { $w[] = '(s.name LIKE ? OR s.short_name LIKE ? OR s.city LIKE ?)'; array_push($a, "%$q%", "%$q%", "%$q%"); }
    if (in_array($rg, Tenant::REGIONS, true)) { $w[] = 's.region=?'; $a[] = $rg; } if (isset(Tenant::TYPES[$ty])) { $w[] = 's.type=?'; $a[] = $ty; }
    if ($sf === 'active') $w[] = 's.is_active=1'; elseif ($sf === 'off') $w[] = 's.is_active=0'; elseif ($sf === 'hidden') $w[] = 's.is_listed=0';
    elseif ($sf === 'used') $w[] = 'EXISTS (SELECT 1 FROM users x WHERE x.school_id=s.id)'; elseif ($sf === 'expired') $w[] = 's.plan_expires<CURDATE()';
    $W = implode(' AND ', $w); $total = (int)val("SELECT COUNT(*) FROM schools s WHERE $W", $a);
    $list = rows("SELECT s.*,(SELECT COUNT(*) FROM users x WHERE x.school_id=s.id AND x.role_id=1) students,(SELECT COUNT(*) FROM users x WHERE x.school_id=s.id AND x.role_id=4) admins,
        (SELECT COUNT(*) FROM papers p WHERE p.school_id=s.id AND p.status='published') papers FROM schools s WHERE $W ORDER BY students DESC, s.name LIMIT $per OFFSET " . (($page - 1) * $per), $a); ?>
<section class="card dt"><?= dt_head('Directory', number_format($total) . ' schools in this view') ?>
  <form class="toolbar" method="get"><label class="search"><?= ic('search', 'i') ?><span class="vh">Search</span><input name="q" value="<?= e($q) ?>" type="search" placeholder="Name, short name or town"></label>
    <select class="in" name="region" aria-label="Region"><?= options($regionOpts, $rg, 'id', 'n', 'All regions') ?></select><select class="in" name="type" aria-label="Type"><?= options($typeOpts, $ty, 'id', 'n', 'All types') ?></select>
    <select class="in" name="status" aria-label="Status"><?php foreach (['' => 'Any status', 'used' => 'Has accounts', 'active' => 'Active', 'off' => 'Switched off', 'hidden' => 'Hidden from directory', 'expired' => 'Licence ended'] as $k => $l): ?><option value="<?= $k ?>"<?= $sf === $k ? ' selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select><button class="btn sm">Filter</button></form>
  <div class="dtwrap"><table class="dtable"><thead><tr><th>School</th><th>Type</th><th>Region</th><th class="num">Students</th><th class="num">Papers</th><th>Admins</th><th>Licence</th><th>Status</th><th data-nosort><span class="vh">Actions</span></th></tr></thead><tbody>
  <?php foreach ($list as $s): $href = url($act . '?id=' . $s['id']); ?><tr data-href="<?= e($href) ?>">
    <td class="dmain"><a class="dtt" href="<?= e($href) ?>"><?= e($s['name']) ?></a><span class="ts"><?= e($s['short_name'] ?: '') ?><?= $s['city'] ? ' · ' . e($s['city']) : '' ?></span></td>
    <td class="muted"><?= e(Tenant::TYPES[$s['type']] ?? $s['type']) ?></td><td class="muted"><?= e($s['region'] ?: '-') ?></td><td class="num"><?= number_format((int)$s['students']) ?></td><td class="num"><?= (int)$s['papers'] ?></td>
    <td><?= $s['admins'] ? '<span class="pill ok">' . (int)$s['admins'] . '</span>' : '<span class="pill wait">None</span>' ?></td>
    <td class="nw"><?= e(ucfirst($s['plan'])) ?><?= $s['plan_expires'] ? '<span class="ts">' . (strtotime($s['plan_expires']) < time() ? 'ended ' : 'to ') . e(fdate($s['plan_expires'])) . '</span>' : '' ?></td>
    <td><?= status_pill($s['is_active'] ? 'active' : 'suspended') ?><?= $s['is_listed'] ? '' : '<span class="ts">hidden</span>' ?></td>
    <td class="act"><div class="rowact"><a class="btn sm line" href="<?= e($href) ?>">Manage</a><?= row_form($act, ['a' => 'open', 'id' => $s['id']], 'Open', 'btn sm line') ?></div></td></tr>
  <?php endforeach; if (!$list) echo dt_empty(9, 'No schools match.'); ?></tbody></table></div>
  <?= dt_foot($total, $per, $page, ['q' => $q, 'region' => $rg, 'type' => $ty, 'status' => $sf]) ?></section>
<?php endif; ?>
<template id="tpl-add"><form class="card mform" method="post" data-action="<?= $act ?>"><?= csrf_field() ?><input type="hidden" name="a" value="add"><h2>Add a school</h2>
  <?= $form(null) ?>
  <div class="f2"><div class="field"><label for="np">Plan</label><select class="in" id="np" name="plan"><?= options($planOpts, Settings::global('school_default_plan', 'basic'), 'id', 'n') ?></select></div><div class="field"><label for="ne">Licence ends (optional)</label><input class="in" id="ne" type="date" name="plan_expires"></div></div>
  <button class="btn">Add school</button></form></template>
<?php include ROOT . '/includes/dashboard_footer.php'; ?>
