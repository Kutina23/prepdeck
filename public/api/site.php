<?php
// Public: course-alert sign-up from the footer.
require __DIR__ . '/../../app/bootstrap.php';
$a = $_GET['a'] ?? '';
if ($a === 'subscribe') {
    require_post(); $b = Response::body(); $em = strtolower(trim((string)($b['email'] ?? '')));
    if (Throttle::over('alerts-ip:' . client_ip(), 10, 60)) Response::error('Too many sign-ups from this connection. Try again later.', 429);
    if (!Validator::email($em)) Response::error('Enter a valid email.');
    if ($fix = Validator::emailTypo($em)) Response::error("Did you mean $fix?");
    if (!Validator::emailDomainWorks($em)) Response::error('That email domain cannot receive mail.');
    if (!sid()) Response::error('Choose your school first, then sign up for its alerts.');
    $dept = (int)($b['department_id'] ?? 0) ?: null; if ($dept && !in_my_school('departments', $dept)) $dept = null;
    q('INSERT IGNORE INTO alert_subscribers(school_id,email,department_id) VALUES (?,?,?)', [sid(), $em, $dept]); Response::ok();
}
Response::error('Unknown action.', 404);
