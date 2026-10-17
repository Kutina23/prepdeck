<?php
require __DIR__ . '/../../app/bootstrap.php';
require_post();
$b = Response::body(); $act = $_GET['a'] ?? '';
$ip = client_ip();
if ($act === 'login') {
    // limits that a new session cannot reset: 5 failures per account and 20 per IP address in 15 minutes
    $em = strtolower(trim((string)($b['email'] ?? '')));
    if (Throttle::blocked("login-email:$em", 5, 15) || Throttle::blocked("login-ip:$ip", 20, 15)) {
        Audit::log('sign-in blocked by rate limit');
        SecurityLog::record('login_blocked', 'critical', row('SELECT id,school_id,email FROM users WHERE email=?', [$em]), $em, Throttle::blocked("login-ip:$ip", 20, 15) ? 'too many attempts from this IP address' : 'too many attempts on this account');
        Response::error('Too many attempts. Wait 15 minutes and try again, or reset your password.', 429);
    }
    if (!Auth::attempt($em, (string)($b['password'] ?? ''))) {
        Throttle::hit("login-email:$em"); Throttle::hit("login-ip:$ip");
        if (Auth::schoolClosed((string)($b['email'] ?? ''))) Response::error('Your school is not active on PrepDeck right now. Please contact your school or PrepDeck support.', 403);
        Response::error('Wrong email or password.', 401);
    }
    Throttle::clear("login-email:$em");
    if (Auth::awaitingTwoFactor()) Response::ok(['twofa' => true]);   // password right; the authenticator code comes next (a=twofa)
    Response::ok(['redirect' => after_login((string)($b['next'] ?? ''))]);
}
if ($act === 'twofa') {
    // second step of sign-in. Wrong codes count per account and per IP, and are NOT cleared by typing the password again,
    // so knowing the password does not give unlimited guesses at the 6-digit code.
    $p = Auth::awaitingTwoFactor();
    if (!$p) Response::error('That sign-in has expired. Enter your email and password again.', 401);
    if (Throttle::blocked("twofa-user:{$p['id']}", 10, 15) || Throttle::blocked("twofa-ip:$ip", 30, 15)) {
        unset($_SESSION['twofa']);
        SecurityLog::record('twofa_blocked', 'critical', $p, null, 'password was right, then too many wrong codes');
        Response::error('Too many wrong codes. Wait 15 minutes, then sign in again.', 429);
    }
    $r = Auth::twoFactor((string)($b['code'] ?? ''));
    if ($r === 'expired') Response::error('That sign-in has expired. Enter your email and password again.', 401);
    if ($r === 'bad') {
        Throttle::hit("twofa-user:{$p['id']}"); Throttle::hit("twofa-ip:$ip");
        if (!Auth::awaitingTwoFactor()) Response::error('Too many wrong codes. Enter your email and password again.', 401);
        Response::error('That code is not right. Check the newest code in your authenticator app, or use a recovery code.');
    }
    Throttle::clear("twofa-user:{$p['id']}");
    Response::ok(['redirect' => after_login((string)($b['next'] ?? ''))]);
}
if ($act === 'register') {
    if (Settings::global('platform_reg_open', '1') !== '1') Response::error('Registration is closed right now.', 403);
    if (Throttle::over("register-ip:$ip", 10, 60)) Response::error('Too many sign-ups from this connection. Try again in an hour.', 429);
    // step 1: the school (searchable picker); step 2: department, level and the rest
    $school = Tenant::find((int)($b['school_id'] ?? 0));
    if (!$school || !$school['is_active']) Response::error('Choose your school from the list. If it is missing, use "My school is not listed".');
    $sch = (int)$school['id'];
    $open = val("SELECT v FROM site_settings WHERE school_id=? AND k='reg_open'", [$sch]);
    if (($open === false ? Settings::global('reg_open', '1') : $open) !== '1') Response::error('Registration is closed for ' . $school['name'] . ' right now.', 403);
    if ($school['max_students'] && (int)val('SELECT COUNT(*) FROM users WHERE school_id=? AND role_id=1', [$sch]) >= (int)$school['max_students']) Response::error($school['name'] . ' has reached its student limit on PrepDeck. Please ask your school to contact us.', 403);
    $name = Validator::str($b['name'] ?? '', 2, 120); $email = strtolower(trim((string)($b['email'] ?? ''))); $pass = (string)($b['password'] ?? '');
    $dept = (int)($b['department_id'] ?? 0); $newDept = Validator::str($b['department_name'] ?? '', 2, 120); $level = Validator::in($b['level'] ?? '', ['100', '200', '300', '400', '500', 'postgraduate']);
    $dob = (string)($b['date_of_birth'] ?? ''); $age = age_from_dob($dob);
    if (!$name || !Validator::email($email) || strlen($pass) < 8 || (!$dept && !$newDept) || !$level) Response::error('Please check your details. The password needs 8 or more characters.');
    if ($age === null || $age < 10 || $age > 90) Response::error('Enter a valid date of birth.');
    if ($dept && !val('SELECT COUNT(*) FROM departments WHERE id=? AND school_id=? AND is_active=1', [$dept, $sch])) Response::error('Choose your department.');
    if ($fix = Validator::emailTypo($email)) Response::error("Did you mean $fix? Check the email address and try again.");
    if (!Validator::emailDomainWorks($email)) Response::error('That email domain cannot receive mail. Check the part after the @.');
    if (val('SELECT COUNT(*) FROM users WHERE email=?', [$email])) Response::error('That email is already registered. Try signing in.', 409);
    if (!$dept) {   // "my department is not listed": reuse a department with the same name, else add it (the school admin can rename or merge it later)
        $dept = (int)val('SELECT id FROM departments WHERE school_id=? AND LOWER(name)=LOWER(?)', [$sch, $newDept]);
        if (!$dept) { q('INSERT INTO departments(school_id,name,is_active) VALUES (?,?,1)', [$sch, $newDept]); $dept = (int)db()->lastInsertId(); }
    }
    try { q('INSERT INTO users(school_id,role_id,department_id,full_name,email,password_hash,date_of_birth,level) VALUES (?,1,?,?,?,?,?,?)', [$sch, $dept, $name, $email, password_hash($pass, PASSWORD_DEFAULT), $dob, $level]); }
    catch (PDOException $e) { Response::error('That email is already registered. Try signing in.', 409); }
    Auth::attempt($email, $pass);
    try { Mailer::verifyEmail(row('SELECT id,full_name,email FROM users WHERE email=?', [$email]), true); } catch (Throwable $e) { error_log('[prepdeck-mail] welcome: ' . $e->getMessage()); }
    Response::ok(['redirect' => url('dashboard/')]);
}
if ($act === 'resend_verify') {             // "Send a new link" on the dashboard banner
    $u = require_login(true);
    if (!empty($u['email_verified_at'])) Response::ok(['message' => 'Your email is already confirmed.']);
    if ((int)val('SELECT COUNT(*) FROM email_tokens WHERE user_id=? AND created_at > NOW() - INTERVAL 2 MINUTE', [$u['id']])) Response::error('A link was just sent. Check your inbox and spam folder, or try again in two minutes.', 429);
    Mailer::verifyEmail($u);
    Response::ok(['message' => 'Sent. Check your inbox for a message from PrepDeck.']);
}
if ($act === 'forgot') {
    $email = strtolower(trim((string)($b['email'] ?? '')));
    if (!Validator::email($email)) Response::error('Enter a valid email address.');
    if (Throttle::over("forgot-ip:$ip", 10, 60)) Response::error('Too many requests from this connection. Try again in an hour.', 429);
    // the same answer whether or not an account exists, so this form cannot be used to find out who has one
    $out = ['message' => "If an account uses $email, a reset link is on its way. The link works once and expires in 1 hour."];
    $u = row("SELECT id,full_name,email FROM users WHERE email=? AND status='active'", [$email]);
    // one link at a time: a link sent in the last few minutes is not sent again (silently)
    if ($u && (int)val('SELECT COUNT(*) FROM password_resets WHERE user_id=? AND used_at IS NULL AND expires_at > NOW() + INTERVAL 55 MINUTE', [$u['id']])) $u = null;
    if ($u) {
        db()->prepare('UPDATE password_resets SET used_at=NOW() WHERE user_id=? AND used_at IS NULL')->execute([$u['id']]);
        $raw = bin2hex(random_bytes(32));
        q('INSERT INTO password_resets(user_id,token_hash,expires_at) VALUES (?,?,DATE_ADD(NOW(), INTERVAL 60 MINUTE))', [$u['id'], hash('sha256', $raw)]);
        $link = abs_url('reset.php?token=' . $raw);
        Mailer::passwordReset($u['email'], (string)$u['full_name'], $link, 60);
        Audit::log('asked for a password reset', 'user', (int)$u['id']);
        SecurityLog::record('password_reset_asked', 'info', row('SELECT id,school_id,email FROM users WHERE id=?', [$u['id']]));
        if (cfg('debug')) $out['link'] = $link;   // a local install has no mail server, so show the link on the page
    }
    Response::ok($out);
}
if ($act === 'reset') {
    $token = (string)($b['token'] ?? ''); $pass = (string)($b['password'] ?? ''); $again = (string)($b['password2'] ?? '');
    if (Throttle::blocked("reset-ip:$ip", 10, 60)) Response::error('Too many attempts from this connection. Try again in an hour.', 429);
    if (strlen($token) !== 64) { Throttle::hit("reset-ip:$ip"); Response::error('That reset link is not valid. Ask for a new one.'); }
    if (strlen($pass) < 8) Response::error('Use at least 8 characters for the new password.');
    if ($pass !== $again) Response::error('The two passwords do not match.');
    $r = row('SELECT id,user_id FROM password_resets WHERE token_hash=? AND used_at IS NULL AND expires_at>NOW() LIMIT 1', [hash('sha256', $token)]);
    if (!$r) { Throttle::hit("reset-ip:$ip"); Response::error('That reset link has expired or was already used. Ask for a new one.', 400); }
    q('UPDATE users SET password_hash=? WHERE id=?', [password_hash($pass, PASSWORD_DEFAULT), $r['user_id']]);
    q('UPDATE password_resets SET used_at=NOW() WHERE id=?', [$r['id']]);
    Audit::log('reset the password from an email link', 'user', (int)$r['user_id']);
    // two-step sign-in stays on: a reset link proves the mailbox, not the phone
    SecurityLog::record('password_reset', 'warning', row('SELECT id,school_id,email FROM users WHERE id=?', [$r['user_id']]));
    Response::ok(['redirect' => url('login.php?reset=1')]);
}
if ($act === 'logout') { Auth::logout(); Response::ok(['redirect' => url()]); }
Response::error('Unknown action.', 404);
/** Where a fresh sign-in goes: the page they were sent away from (same site only), else their home area. */
function after_login(string $next): string {
    $u = Auth::user();
    $dest = ($next !== '' && str_starts_with($next, cfg('base')) && !str_contains($next, '//')) ? $next : url(is_super($u) ? 'platform/' : (admin_area_ok() ? 'admin/' : (staff_area_ok() ? staff_home() : 'dashboard/')));
    if (!admin_area_ok() && str_contains($dest, '/admin/')) $dest = url(staff_area_ok() ? staff_home() : 'dashboard/');
    return $dest;
}
function admin_area_ok(): bool { if (is_super()) return true; foreach (['admin', 'approve', 'decks', 'users', 'plans', 'reports', 'settings'] as $p) if (Auth::can($p)) return true; return false; }
