<?php
// Renders every dashboard page as an admin, a lecturer and a student and fails on any PHP warning or broken page.
//   php tests/smoke.php          use existing accounts
//   php tests/smoke.php --seed   create test accounts first (CI only; refused when APP_ENV=production)
if (PHP_SAPI !== 'cli') exit('CLI only');
require dirname(__DIR__) . '/app/bootstrap.php';
if (in_array('--seed', $argv, true)) {
    if (env('APP_ENV') === 'production') { fwrite(STDERR, "Refusing to seed a production database.\n"); exit(1); }
    $dep = (int)val('SELECT id FROM departments WHERE school_id=1 ORDER BY id LIMIT 1');
    foreach ([[1, 'ci-student@prepdeck.test', 'CI Student'], [3, 'ci-lecturer@prepdeck.test', 'CI Lecturer'], [2, 'ci-rep@prepdeck.test', 'CI Rep'], [4, 'ci-schooladmin@prepdeck.test', 'CI School Admin']] as [$role, $email, $name])
        q("INSERT IGNORE INTO users(school_id,role_id,department_id,full_name,email,password_hash,status,level) VALUES (1,?,?,?,?,?,'active','200')", [$role, $dep ?: null, $name, $email, password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT)]);
}
$owner = (int)val('SELECT id FROM users WHERE role_id=5 ORDER BY id LIMIT 1');                 // platform owner (super admin)
$admin = (int)val('SELECT id FROM users WHERE role_id=4 ORDER BY id LIMIT 1') ?: $owner;    // a school admin
$lect = (int)val('SELECT id FROM users WHERE role_id=3 ORDER BY id LIMIT 1');
$stu = (int)val('SELECT id FROM users WHERE role_id=1 ORDER BY id LIMIT 1');
$rep = (int)val('SELECT id FROM users WHERE role_id=2 ORDER BY id LIMIT 1');
$outline = (int)val('SELECT id FROM course_outlines ORDER BY id LIMIT 1'); $lesson = (int)val('SELECT id FROM lesson_notes ORDER BY id LIMIT 1');
$paper = (int)val("SELECT id FROM papers ORDER BY id LIMIT 1");
$pages = [];
$school = (int)val('SELECT id FROM schools ORDER BY id LIMIT 1');
if ($owner) {
    foreach (['index', 'schools', 'users', 'permissions', 'subscriptions', 'settings', 'audit', 'security'] as $p) $pages[] = [$owner, "platform/$p.php", ''];
    foreach (['subs', 'txn', 'licences', 'prices', 'coupons'] as $t) $pages[] = [$owner, 'platform/subscriptions.php', "tab=$t"];
    foreach (['', 'days=7&sort=revenue', 'days=90&sort=tokens'] as $q) $pages[] = [$owner, 'platform/index.php', $q];
    foreach (['', 'days=365&sort=growth', 'lic=ended'] as $q) $pages[] = [$owner, 'platform/finances.php', $q];
    if ($school) foreach (['', '&days=90'] as $q) $pages[] = [$owner, 'platform/finances.php', "school=$school$q"];
    $pages[] = [$owner, 'platform/schools.php', 'tab=requests'];
    if ($school) { $pages[] = [$owner, 'platform/schools.php', "id=$school"]; $pages[] = [$owner, 'platform/permissions.php', "school=$school"]; }
    $pages[] = [$owner, 'platform/users.php', "id=$owner"];
    foreach (['index', 'users', 'staff', 'papers', 'academics', 'payments', 'plans', 'settings'] as $p) $pages[] = [$owner, "admin/$p.php", ''];
    $pages[] = [$owner, 'platform/site.php', '']; foreach (['faq', 'pages'] as $t) $pages[] = [$owner, 'platform/site.php', "tab=$t"];
}
foreach (['index', 'users', 'staff', 'teaching', 'papers', 'study', 'recordings', 'processing', 'academics', 'reports', 'announcements', 'audit', 'security', 'settings'] as $p) $pages[] = [$admin, "admin/$p.php", ''];
// Plus (plans, payments, coupons) is run by the platform owner only; a school admin gets "No access" there
foreach (['plans', 'payments'] as $p) $pages[] = [$owner ?: $admin, "admin/$p.php", ''];
foreach (['outlines', 'notes', 'settings'] as $t) $pages[] = [$admin, 'admin/teaching.php', "tab=$t"];
if ($outline) $pages[] = [$admin, 'admin/teaching.php', "tab=outlines&id=$outline"];
if ($lesson) $pages[] = [$admin, 'admin/teaching.php', "tab=notes&view=$lesson"];
if ($lect) $pages[] = [$admin, 'admin/staff.php', "id=$lect"];
foreach (['courses', 'terms', 'exams'] as $t) $pages[] = [$admin, 'admin/academics.php', "tab=$t"];
foreach (['ai', 'email', 'roles', 'system'] as $t) $pages[] = [$admin, 'admin/settings.php', "tab=$t"];
foreach (['subs', 'coupons'] as $t) $pages[] = [$owner ?: $admin, 'admin/payments.php', "tab=$t"];
foreach (['guides', 'answers', 'games'] as $t) $pages[] = [$admin, 'admin/study.php', "tab=$t"];
if ($paper) $pages[] = [$admin, 'admin/papers.php', "id=$paper"];
foreach (array_filter([$lect, $admin]) as $id) foreach (['papers', 'review', 'announcements'] as $p) $pages[] = [$id, "staff/$p.php", ''];
foreach (array_filter([$lect, $rep, $admin]) as $id) $pages[] = [$id, 'staff/courses.php', ''];   // lecturers, reps and admins can add courses to their school
foreach (array_filter([$rep, $admin]) as $id) $pages[] = [$id, 'staff/index.php', ''];            // the staff desk (lecturers are sent to /lecturer/)
foreach (array_filter([$lect, $admin]) as $id) foreach (['index', 'outlines', 'lessons', 'insights'] as $p) $pages[] = [$id, "lecturer/$p.php", ''];
if ($lect && ($o = (int)val('SELECT id FROM course_outlines WHERE user_id=? LIMIT 1', [$lect]))) $pages[] = [$lect, 'lecturer/outlines.php', "id=$o"];
if ($lect && ($n = (int)val('SELECT id FROM lesson_notes WHERE user_id=? LIMIT 1', [$lect]))) $pages[] = [$lect, 'lecturer/lessons.php', "id=$n"];
foreach (array_filter([$stu, $admin]) as $id) {
    foreach (['index', 'papers', 'decks', 'materials', 'recordings', 'guides', 'lessons', 'plan', 'progress', 'notes', 'games', 'help', 'settings', 'ai', 'practice'] as $p) $pages[] = [$id, "dashboard/$p.php", ''];
    $pages[] = [$id, 'dashboard/decks.php', 'tab=mine'];
    if ($lesson) $pages[] = [$id, 'dashboard/lessons.php', "id=$lesson"];
    if ($paper) { $pages[] = [$id, 'dashboard/papers.php', "id=$paper"]; $pages[] = [$id, 'dashboard/practice.php', "type=paper&id=$paper"]; }
}
// lecturers keep only their account pages and the paper / lesson viewers from the student dashboard (inside the lecturer shell)
$blocked = [];
if ($lect) {
    foreach (['papers', 'recordings', 'lessons', 'plan', 'help', 'settings'] as $p) $pages[] = [$lect, "dashboard/$p.php", ''];
    if ($lesson) $pages[] = [$lect, 'dashboard/lessons.php', "id=$lesson"];
    if ($paper) $pages[] = [$lect, 'dashboard/papers.php', "id=$paper"];
    foreach (['index', 'decks', 'materials', 'guides', 'progress', 'notes', 'games', 'ai', 'practice'] as $p) $blocked[] = [$lect, "dashboard/$p.php", ''];
    foreach (['index', 'users', 'staff', 'teaching', 'papers', 'announcements', 'security', 'settings'] as $p) $blocked[] = [$lect, "admin/$p.php", ''];
    $pages[] = [$lect, 'staff/courses.php', 'mine=1'];
}
$php = PHP_BINARY; $render = __DIR__ . '/render.php'; $fail = 0;
foreach ($pages as [$uid, $page, $qs]) {
    $p = proc_open([$php, $render, (string)$uid, $page, $qs], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $out = stream_get_contents($pipes[1]); $err = trim(stream_get_contents($pipes[2])); fclose($pipes[1]); fclose($pipes[2]); proc_close($p);
    if ($err !== '' || strlen($out) < 3000 || !str_contains($out, '</html>')) { $fail++; echo "FAIL  user $uid  $page" . ($qs ? "?$qs" : '') . "\n      " . str_replace("\n", "\n      ", mb_strimwidth($err ?: 'page did not finish rendering (' . strlen($out) . ' bytes)', 0, 600)) . "\n"; }
}
// pages a user is sent away from: the redirect ends the request before any HTML is printed
foreach ($blocked as [$uid, $page, $qs]) {
    $p = proc_open([$php, $render, (string)$uid, $page, $qs], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $out = stream_get_contents($pipes[1]); $err = trim(stream_get_contents($pipes[2])); fclose($pipes[1]); fclose($pipes[2]); proc_close($p);
    if ($err !== '' || str_contains($out, '<html')) { $fail++; echo "FAIL  user $uid  $page should be closed to this user\n" . ($err !== '' ? '      ' . mb_strimwidth($err, 0, 600) . "\n" : ''); }
}
echo count($pages) . ' pages rendered, ' . count($blocked) . ' blocked, ' . $fail . " failed\n";
exit($fail ? 1 : 0);
