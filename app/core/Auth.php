<?php
final class Auth {
    /** Only the platform owner holds these: running the platform, and everything about Plus (prices, payments, coupons, giving Plus, free/Plus limits). */
    public const OWNER_ONLY = ['platform', 'plans'];
    /** Permissions that open the school admin area. A lecturer never holds them, whatever an admin or the matrix grants: lecturers work from the lecturer dashboard only. */
    public const ADMIN_AREA = ['admin', 'approve', 'decks', 'users', 'plans', 'reports', 'settings'];
    public const LECTURER = 3;
    private static ?array $perms = null;
    private static $user = false;
    /** A signed-in session ends after this long without a request. */
    public const IDLE_SECONDS = 8 * 3600;
    public static function start(): void {
        if (session_status() === PHP_SESSION_NONE) {
            ini_set('session.use_strict_mode', '1');      // never adopt a session id the server did not create (fixation)
            ini_set('session.use_only_cookies', '1');
            ini_set('session.cookie_httponly', '1');
            ini_set('session.gc_maxlifetime', (string)self::IDLE_SECONDS);
            session_set_cookie_params(['path' => cfg('base'), 'httponly' => true, 'samesite' => 'Lax', 'secure' => is_https()]);
            session_name('prepdeck'); session_start();
        }
        // idle timeout: a session left alone for IDLE_SECONDS is signed out on its next request
        if (!empty($_SESSION['uid']) && !empty($_SESSION['seen']) && time() - (int)$_SESSION['seen'] > self::IDLE_SECONDS) { $_SESSION = []; session_regenerate_id(true); }
        if (!empty($_SESSION['uid'])) $_SESSION['seen'] = time();
    }
    /** Fingerprint of the stored password hash: a session made before a password change no longer matches it. */
    private static function pwMark(string $hash): string { return substr(hash('sha256', $hash), 0, 20); }
    /** After the signed-in person changes their own password, keep THIS session (every other one ends). */
    public static function keepSession(): void {
        if (!empty($_SESSION['uid'])) $_SESSION['pwm'] = self::pwMark((string)val('SELECT password_hash FROM users WHERE id=?', [$_SESSION['uid']]));
        session_regenerate_id(true);
    }
    /** Email + password. Email is unique across all schools, so no school is needed to sign in; a closed school blocks its members. */
    public static function attempt(string $email, string $pass): bool {
        $st = db()->prepare('SELECT u.*, s.is_active school_active FROM users u LEFT JOIN schools s ON s.id=u.school_id WHERE u.email=? LIMIT 1'); $st->execute([strtolower(trim($email))]);
        $u = $st->fetch();
        if (!$u || $u['status'] !== 'active' || !password_verify($pass, $u['password_hash'])) return false;
        if ((int)$u['role_id'] !== Tenant::SUPER && $u['school_id'] !== null && !(int)$u['school_active']) return false;
        session_regenerate_id(true);
        $_SESSION['uid'] = (int)$u['id']; $_SESSION['pwm'] = self::pwMark($u['password_hash']); $_SESSION['seen'] = time(); unset($_SESSION['act_school']);
        self::reset(); Tenant::forget();
        db()->prepare('UPDATE users SET last_login_at=NOW() WHERE id=?')->execute([$u['id']]);
        Audit::log('signed in', 'user', (int)$u['id']);
        return true;
    }
    /** Does this email belong to a member of a school that is switched off? (login shows a clearer message) */
    public static function schoolClosed(string $email): bool {
        return (bool)val("SELECT COUNT(*) FROM users u JOIN schools s ON s.id=u.school_id WHERE u.email=? AND u.role_id<>5 AND s.is_active=0", [strtolower(trim($email))]);
    }
    public static function user(): ?array {
        if (self::$user === false) {
            self::$user = null;
            if (!empty($_SESSION['uid'])) {
                $st = db()->prepare("SELECT u.*, r.name AS role, d.name AS department, s.name AS school_name, s.short_name AS school_short, s.logo AS school_logo, s.is_active AS school_active
                    FROM users u JOIN roles r ON r.id=u.role_id LEFT JOIN departments d ON d.id=u.department_id LEFT JOIN schools s ON s.id=u.school_id WHERE u.id=? AND u.status='active'");
                $st->execute([$_SESSION['uid']]); self::$user = $st->fetch() ?: null;
                // the password changed since this session signed in (reset link, admin reset, changed elsewhere): sign it out
                if (self::$user) {
                    $mark = self::pwMark((string)self::$user['password_hash']);
                    if (!isset($_SESSION['pwm'])) $_SESSION['pwm'] = $mark;            // sessions from before this check existed
                    elseif (!hash_equals($_SESSION['pwm'], $mark)) { self::$user = null; $_SESSION = []; }
                }
                // a school switched off by the platform owner signs its members out on their next request
                if (self::$user && (int)self::$user['role_id'] !== Tenant::SUPER && self::$user['school_id'] !== null && !(int)self::$user['school_active']) { self::$user = null; unset($_SESSION['uid']); }
            }
        }
        return self::$user;
    }
    public static function reset(): void { self::$user = false; self::$perms = null; }

    /** Permission codes a role has inside a school: the platform owner's per-school matrix, else the global defaults. */
    public static function rolePerms(int $roleId, int $schoolId): array {
        if ($schoolId) {
            $st = db()->prepare('SELECT p.code FROM school_role_permissions sp JOIN permissions p ON p.id=sp.permission_id WHERE sp.school_id=? AND sp.role_id=?');
            $st->execute([$schoolId, $roleId]); $own = $st->fetchAll(PDO::FETCH_COLUMN);
            if ($own || self::schoolHasMatrix($schoolId, $roleId)) return array_values(array_diff($own, self::OWNER_ONLY));
        }
        $st = db()->prepare('SELECT p.code FROM role_permissions rp JOIN permissions p ON p.id=rp.permission_id WHERE rp.role_id=?');
        $st->execute([$roleId]); return array_values(array_diff($st->fetchAll(PDO::FETCH_COLUMN), $roleId === Tenant::SUPER ? [] : self::OWNER_ONLY));
    }
    /** A school + role whose permissions were all switched off still counts as "set" (marker row in site_settings). */
    private static function schoolHasMatrix(int $schoolId, int $roleId): bool {
        return (bool)val('SELECT COUNT(*) FROM site_settings WHERE school_id=? AND k=?', [$schoolId, 'perm_matrix_' . $roleId]);
    }
    public static function perms(): array {
        if (self::$perms === null) {
            self::$perms = [];
            if ($u = self::user()) {
                if ((int)$u['role_id'] === Tenant::SUPER) return self::$perms = db()->query('SELECT code FROM permissions')->fetchAll(PDO::FETCH_COLUMN);
                $sid = Tenant::id();
                $home = (int)$u['school_id'] === $sid;
                // in another school (multi-school staff) a person has the role given there (lecturer or class rep), never admin power
                $role = $home ? (int)$u['role_id'] : min(3, (int)val('SELECT role_id FROM user_schools WHERE user_id=? AND school_id=?', [$u['id'], $sid]) ?: 1);
                self::$perms = self::rolePerms($role, $sid);
                if ($home) {
                    // extra grants an admin gave this person, but never more than the school's admins may do (the ceiling set by the platform owner)
                    $x = db()->prepare('SELECT p.code FROM user_permissions up JOIN permissions p ON p.id=up.permission_id WHERE up.user_id=?');
                    $x->execute([$u['id']]);
                    $extra = array_intersect($x->fetchAll(PDO::FETCH_COLUMN), self::rolePerms(4, $sid));
                    self::$perms = array_values(array_unique(array_merge(self::$perms, $extra)));
                }
                self::$perms = array_values(array_diff(self::$perms, self::OWNER_ONLY, $role === self::LECTURER ? self::ADMIN_AREA : []));
            }
        }
        return self::$perms;
    }
    public static function can(string $perm): bool { return in_array($perm, self::perms(), true); }
    public static function logout(): void { Audit::log('signed out'); $_SESSION = []; if (session_status() === PHP_SESSION_ACTIVE) session_destroy(); self::reset(); }
}
