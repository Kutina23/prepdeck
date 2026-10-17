<?php
/**
 * Security events (sign-ins, failures, lockouts, two-step and password changes), kept apart from the audit log of admin work.
 * Each row belongs to the school of the account it is about, so a school admin sees attacks on their own members;
 * events for unknown emails have no school and only the platform owner sees them.
 *   SecurityLog::record('login_failed', 'warning', $userRowOrNull, $typedEmail, 'wrong password');
 */
final class SecurityLog {
    /** event => label shown in the log pages */
    public const EVENTS = [
        'login_ok' => 'Signed in',
        'login_failed' => 'Failed sign-in',
        'login_blocked' => 'Sign-in blocked (too many attempts)',
        'login_inactive' => 'Sign-in to a suspended account',
        'twofa_ok' => 'Two-step code accepted',
        'twofa_failed' => 'Wrong two-step code',
        'twofa_blocked' => 'Two-step blocked (too many attempts)',
        'recovery_used' => 'Signed in with a recovery code',
        'twofa_enabled' => 'Two-step sign-in turned on',
        'twofa_disabled' => 'Two-step sign-in turned off',
        'twofa_reset' => 'Two-step sign-in reset by an admin',
        'recovery_new' => 'New recovery codes made',
        'password_changed' => 'Password changed',
        'password_reset_asked' => 'Password reset requested',
        'password_reset' => 'Password reset from email link',
        'password_reset_admin' => 'Password reset by an admin',
        'logout' => 'Signed out',
        'community_join' => 'Joined the community',
        'community_unlock' => 'Unlocked the community',
        'community_unlock_failed' => 'Wrong password for the community',
        'community_blocked' => 'Community locked (too many password tries)',
        'community_banned' => 'Removed from the community',
        'community_keys' => 'New encryption keys for community files',
    ];
    public const SEVERITY = ['info', 'warning', 'critical'];

    /** $user: the account row (id, school_id, email) when known; $email: what was typed, for unknown accounts. */
    public static function record(string $event, string $severity = 'info', ?array $user = null, ?string $email = null, ?string $detail = null): void {
        try {
            q('INSERT INTO security_log(school_id,user_id,email,event,severity,detail,ip,user_agent) VALUES (?,?,?,?,?,?,?,?)', [
                isset($user['school_id']) ? (int)$user['school_id'] : null, isset($user['id']) ? (int)$user['id'] : null,
                mb_substr(strtolower(trim((string)($user['email'] ?? $email ?? ''))), 0, 190) ?: null,
                $event, in_array($severity, self::SEVERITY, true) ? $severity : 'info', $detail !== null ? mb_substr($detail, 0, 255) : null,
                client_ip(), mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255) ?: null,
            ]);
        } catch (PDOException $e) { error_log('[prepdeck-security] ' . $e->getMessage()); }   // a missing table (migration not run) must never stop a sign-in
    }
    public static function label(string $event): string { return self::EVENTS[$event] ?? ucfirst(str_replace('_', ' ', $event)); }
    /** Short browser + system name from a user agent, for the tables. */
    public static function device(?string $ua): string {
        $ua = (string)$ua; if ($ua === '') return '–';
        $b = preg_match('/Edg\//', $ua) ? 'Edge' : (preg_match('/OPR\/|Opera/', $ua) ? 'Opera' : (preg_match('/Chrome\//', $ua) ? 'Chrome' : (preg_match('/Firefox\//', $ua) ? 'Firefox' : (preg_match('/Safari\//', $ua) ? 'Safari' : (preg_match('/curl|python|wget|bot/i', $ua) ? 'Script / bot' : 'Browser')))));
        $o = preg_match('/Android/', $ua) ? 'Android' : (preg_match('/iPhone|iPad/', $ua) ? 'iOS' : (preg_match('/Windows/', $ua) ? 'Windows' : (preg_match('/Mac OS X/', $ua) ? 'macOS' : (preg_match('/Linux/', $ua) ? 'Linux' : ''))));
        return $o ? "$b on $o" : $b;
    }
    /** Rows older than this are removed by bin/cleanup.php. */
    public static function prune(int $days = 365): int { return q('DELETE FROM security_log WHERE created_at<NOW()-INTERVAL ' . max(30, $days) . ' DAY')->rowCount(); }
}
