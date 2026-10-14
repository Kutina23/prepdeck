<?php
function cfg(string $k) { return $GLOBALS['cfg'][$k] ?? null; }
function db(): PDO { return Database::pdo(); }
function e(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
/** URL for a path under /public, e.g. url('dashboard/papers.php?id=3') -> /prepdeck/public/dashboard/papers?id=3 */
function url(string $p = ''): string {
    $p = ltrim($p, '/');
    // clean URLs for pages (public/.htaccess maps them back to the .php file); api/ endpoints and files keep their real names
    if (clean_urls() && !str_starts_with($p, 'api/') && !str_starts_with($p, 'assets/')) {
        $p = preg_replace('#(^|/)index\.php(?=$|[?\#])#', '$1', $p);
        $p = preg_replace('#\.php(?=$|[?\#])#', '', $p, 1);
    }
    return cfg('base') . $p;
}
/** Clean URLs need Apache's rewrite rules; PHP's built-in server (used in CI) ignores .htaccess, so links keep .php there. CLEAN_URLS=0 turns them off. */
function clean_urls(): bool { return PHP_SAPI !== 'cli-server' && env('CLEAN_URLS', '1') === '1'; }
function asset(string $p): string { $f = ROOT . '/public/assets/' . $p; return url('assets/' . $p) . (is_file($f) ? '?v=' . filemtime($f) : ''); }
function redirect(string $to): never { header('Location: ' . (preg_match('#^(https?:)?//#', $to) ? $to : url($to))); exit; }
function redirect_self(): never { header('Location: ' . $_SERVER['REQUEST_URI']); exit; }
function is_post(): bool { return $_SERVER['REQUEST_METHOD'] === 'POST'; }
function setting(string $k, $d = null) { return Settings::get($k, $d); }

/* ---------- schools (multi-tenant) ---------- */
/** The school this request works in (0 = none, which matches no rows). */
function sid(): int { return Tenant::id(); }
/** SQL condition for the current school, e.g. in_school('p') => "p.school_id=4". The id is an int, so inlining it is safe. */
function in_school(string $alias = '', ?int $school = null): string { return ($alias !== '' ? $alias . '.' : '') . 'school_id=' . (int)($school ?? sid()); }
/** Does row $id of $table belong to the current school? Use before acting on an id that came from the browser. */
function in_my_school(string $table, int $id): bool { return $id > 0 && (bool)val("SELECT COUNT(*) FROM `$table` WHERE id=? AND school_id=?", [$id, sid()]); }
/** exam_questions carry no school: they belong to a paper / material / recording, which does. SQL condition for "this school's questions". */
function questions_in_school(string $alias = 'q'): string {
    $S = (int)sid(); $x = $alias !== '' ? $alias . '.' : '';
    return "(({$x}source_type='paper' AND {$x}source_id IN (SELECT id FROM papers WHERE school_id=$S)) OR ({$x}source_type='material' AND {$x}source_id IN (SELECT id FROM materials WHERE school_id=$S))"
        . " OR ({$x}source_type='recording' AND {$x}source_id IN (SELECT id FROM recordings WHERE school_id=$S)))";
}
/** The visitor's IP address. X-Forwarded-For is trusted only when the request comes from TRUSTED_PROXY (a load balancer you run). */
function client_ip(): string {
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    $proxies = array_filter(array_map('trim', explode(',', (string)env('TRUSTED_PROXY', ''))));
    if ($proxies && in_array($ip, $proxies, true) && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $hops = array_map('trim', explode(',', (string)$_SERVER['HTTP_X_FORWARDED_FOR']));
        $first = end($hops); if (filter_var($first, FILTER_VALIDATE_IP)) $ip = $first;   // the address our proxy saw
    }
    return $ip;
}
/** Is this request over HTTPS (directly, or through a trusted proxy that says so)? */
function is_https(): bool {
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') return true;
    $proxies = array_filter(array_map('trim', explode(',', (string)env('TRUSTED_PROXY', ''))));
    return $proxies && in_array((string)($_SERVER['REMOTE_ADDR'] ?? ''), $proxies, true) && strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
}
function school_name(): string { return Tenant::name() ?: 'your school'; }
/** School the home page shows visitors before they pick one: set in Platform settings, else the platform owner's own school. 0 = none. */
function home_default_school(): int {
    $set = Settings::global('home_school');   // never saved = use the owner's school; saved as 0 = no default
    $id = $set !== null && $set !== '' ? (int)$set : (int)val('SELECT school_id FROM users WHERE role_id=' . Tenant::SUPER . ' AND school_id IS NOT NULL ORDER BY id LIMIT 1');
    return $id && val('SELECT COUNT(*) FROM schools WHERE id=? AND is_active=1', [$id]) ? $id : 0;
}
function is_super(?array $u = null): bool { return Tenant::isSuper($u); }
/** Admin role of a school, or the platform owner. */
function is_owner(?array $u = null): bool { $u ??= Auth::user(); return $u && in_array((int)$u['role_id'], [4, Tenant::SUPER], true); }
/** Maintenance: the platform owner can close the whole platform, a school admin only their own school. The platform owner always gets in. */
function in_maintenance(?array $u = null): bool {
    $u ??= Auth::user(); if (is_super($u)) return false;
    if (Settings::global('platform_maintenance', '0') === '1') return true;
    return setting('maintenance', '0') === '1' && !($u && (int)$u['role_id'] === 4);
}
function role_label(string $role): string { return $role === 'super_admin' ? 'Platform owner' : ucfirst(str_replace('_', ' ', $role)); }

/* ---------- uploads: storage/uploads/<kind>/<school id>/<file>; file_path stores "<school id>/<file>" (older rows: just "<file>") ---------- */
/** Folder for a new upload of this kind in the current school (created when missing). */
function upload_dir(string $kind, ?int $school = null): string {
    $d = cfg('upload_dir') . '/' . $kind . '/' . (int)($school ?? sid()); if (!is_dir($d)) @mkdir($d, 0775, true); return $d;
}
/** Value to store in file_path for a new upload. */
function upload_ref(string $name, ?int $school = null): string { return (int)($school ?? sid()) . '/' . basename($name); }
/** Absolute path of a stored upload. Only "<digits>/<name>" or "<name>" are accepted, so a stored value can never climb out of the folder. */
function upload_path(string $kind, ?string $ref): string {
    $ref = (string)$ref;
    $rel = preg_match('#^(\d+)/([^/\\\\]+)$#', $ref, $m) ? $m[1] . '/' . basename($m[2]) : basename($ref);
    return cfg('upload_dir') . '/' . $kind . '/' . $rel;
}

/** Plus = users.plus flag that has not expired. Admins always have full access. */
function is_plus(?array $u = null): bool {
    $u ??= Auth::user(); if (!$u) return false;
    $role = (int)($u['role_id'] ?? 0);
    if ($role === 4 || $role === Tenant::SUPER) return true;
    if (($role === 2 && setting('rep_plus', '0') === '1') || ($role === 3 && setting('lecturer_plus', '1') === '1')) return true;   // staff benefit set by the admin
    if (!array_key_exists('plus', $u)) { $s = db()->prepare('SELECT plus,plus_expires FROM users WHERE id=?'); $s->execute([$u['id']]); $u += (array)$s->fetch(); }
    return (int)$u['plus'] === 1 && (empty($u['plus_expires']) || strtotime($u['plus_expires'] . ' UTC') > time());
}
function age_from_dob(?string $dob): ?int { if (!$dob) return null; try { return (new DateTime($dob))->diff(new DateTime('today'))->y; } catch (Throwable) { return null; } }
function money($amt, string $cur = 'GHS'): string { return $cur . ' ' . number_format((float)$amt, 2); }
function fmt_bytes($b): string { $b = (float)$b; return $b >= 1048576 ? round($b / 1048576, 1) . ' MB' : max(1, round($b / 1024)) . ' KB'; }
function ago(?string $dt): string {
    if (!$dt) return 'never'; $t = strtotime($dt . ' UTC'); $d = time() - $t;
    if ($d < 60) return 'just now'; if ($d < 3600) return floor($d / 60) . ' min ago'; if ($d < 86400) return floor($d / 3600) . ' h ago';
    if ($d < 86400 * 30) return floor($d / 86400) . ' d ago'; return gmdate('j M Y', $t);
}
function fdate(?string $dt, string $f = 'j M Y'): string { return $dt ? gmdate($f, strtotime($dt . ' UTC')) : '-'; }
function initials(string $n): string { return mb_strtoupper(mb_substr(trim($n), 0, 2)); }
function first_name(string $n): string { return explode(' ', trim($n))[0]; }
function level_label(?string $l): string { return $l === 'postgraduate' ? 'Postgraduate' : ($l ? $l . ' level' : ''); }

/** Course ids this person may upload to / manage. null = every course (admin). */
function staff_course_ids(?array $u = null): ?array {
    $u ??= Auth::user(); if (!$u) return [];
    if (Auth::can('admin')) return null;
    return array_map('intval', array_column(rows('SELECT s.course_id FROM staff_courses s JOIN courses c ON c.id=s.course_id WHERE s.user_id=? AND ' . in_school('c'), [$u['id']]), 'course_id'));
}
/** SQL condition limiting a course id column to what this person may manage, always inside the current school. */
function staff_course_sql(string $col = 'course_id', ?array $u = null): string {
    $ids = staff_course_ids($u);
    if ($ids === null) return "$col IN (SELECT id FROM courses WHERE " . in_school() . ')';
    return $ids ? "$col IN (" . implode(',', $ids) . ')' : '0=1';
}
/** Courses the person can pick in a form: [id, label]. */
function staff_courses(?array $u = null): array {
    $ids = staff_course_ids($u);
    if ($ids === []) return [];
    return rows('SELECT id,CONCAT(code," · ",title) n FROM courses WHERE ' . in_school() . ($ids === null ? '' : ' AND id IN (' . implode(',', $ids) . ')') . ' ORDER BY code');
}
function abs_url(string $p = ''): string {
    $root = env('APP_URL', '');
    if (!$root) { $https = !empty($_SERVER['HTTPS']) || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'; $root = ($https ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'localhost'); }
    return rtrim($root, '/') . url($p);
}
