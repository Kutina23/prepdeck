<?php
/** Every protected page / endpoint starts with one of these. Server-side only: hiding a button is not security. */
function require_login(bool $json = false): array {
    $u = Auth::user();
    if (!$u) { $json ? Response::error('Please sign in.', 401) : redirect('login.php?next=' . urlencode($_SERVER['REQUEST_URI'])); }
    if (!$json) lecturer_page_check($u);
    return $u;
}
/** Student dashboard pages a lecturer may still open (inside the lecturer shell): their account pages, lecture recordings (in the lecturer menu),
 * and the paper / lesson note viewers that the review queue, uploads and insights link to. */
const LECTURER_DASHBOARD_PAGES = ['plan.php', 'settings.php', 'help.php', 'recordings.php', 'papers.php', 'lessons.php'];
/** Lecturers work from /lecturer/ (plus the shared staff pages). The school admin area and the student dashboard are closed to them. */
function lecturer_page_check(array $u): void {
    if (!is_lecturer($u)) return;
    $file = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $area = basename(dirname($file));
    if ($area === 'admin' || ($area === 'dashboard' && !in_array(basename($file), LECTURER_DASHBOARD_PAGES, true))) redirect('lecturer/');
}
function require_perm(string $perm, bool $json = false): array {
    $u = require_login($json);
    if (!Auth::can($perm)) { if ($json) Response::error('You do not have access.', 403); http_response_code(403); exit('<!doctype html><meta name=viewport content="width=device-width,initial-scale=1"><body style="font:16px system-ui;padding:32px"><h1>No access</h1><p>Your account cannot open this page.</p><p><a href="' . e(url('dashboard/')) . '">Back to my dashboard</a></p>'); }
    return $u;
}
function require_plus(bool $json = true): array {
    $u = require_login($json);
    if (!is_plus($u)) { if ($json) Response::error('This feature needs PrepDeck Plus.', 402); flash('wait', 'That is part of PrepDeck Plus.'); redirect('dashboard/plan.php'); }
    return $u;
}
function require_post(): void { if ($_SERVER['REQUEST_METHOD'] !== 'POST') Response::error('POST required.', 405); Csrf::check(); }
/** Is any admin-area permission held? (admin, approve, decks, announce ...) */
function admin_area_user(): array {
    $u = require_login();
    if (is_lecturer($u)) redirect('lecturer/');
    foreach (Auth::ADMIN_AREA as $p) if (Auth::can($p)) return $u;
    redirect('dashboard/');
}

/** Lecturer / class rep desk: anyone who can upload, review assigned courses or announce, but is not a full admin. */
function staff_area_ok(): bool { return Auth::user() && (Auth::can('upload') || Auth::can('review') || Auth::can('announce')); }
function staff_area_user(): array {
    $u = require_login();
    if (!staff_area_ok()) redirect('dashboard/');
    return $u;
}
/** Lecturer dashboard (/lecturer/): lecturers, plus admins so they can see what lecturers see. Class reps and other PrepDeck staff keep the staff desk. */
function is_lecturer(?array $u = null): bool { $u ??= Auth::user(); return $u && (int)$u['role_id'] === 3; }
function is_class_rep(?array $u = null): bool { $u ??= Auth::user(); return $u && (int)$u['role_id'] === 2; }
/** Lecturers and class reps pick the courses they work on themselves (staff/courses.php). */
function picks_own_courses(?array $u = null): bool { return is_lecturer($u) || is_class_rep($u); }
function staff_home(?array $u = null): string { return is_lecturer($u) ? 'lecturer/' : 'staff/'; }
function lecturer_area_user(): array {
    $u = require_login();
    if (!is_lecturer($u) && !Auth::can('admin')) redirect(staff_area_ok() ? 'staff/' : 'dashboard/');
    return $u;
}
/** Platform owner pages (/platform/): the super admin only. */
function require_super(bool $json = false): array {
    $u = require_login($json);
    if (!is_super($u)) { if ($json) Response::error('Only the platform owner can do that.', 403); http_response_code(403); exit('<!doctype html><meta name=viewport content="width=device-width,initial-scale=1"><body style="font:16px system-ui;padding:32px"><h1>No access</h1><p>Only the platform owner can open this page.</p><p><a href="' . e(url('dashboard/')) . '">Back to my dashboard</a></p>'); }
    return $u;
}
