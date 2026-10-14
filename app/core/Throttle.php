<?php
/**
 * Rate limits that survive a new session: attempts are logged in auth_throttle under a hashed key
 * ("login-ip:1.2.3.4", "login-email:a@b.c", ...) and counted over a sliding window.
 *   if (Throttle::blocked('login-ip:' . client_ip(), 20, 15)) Response::error(..., 429);
 *   Throttle::hit('login-ip:' . client_ip());
 */
final class Throttle {
    private static function key(string $k): string { return hash('sha256', strtolower($k)); }
    /** Has this key been hit $max times or more in the last $minutes minutes? */
    public static function blocked(string $k, int $max, int $minutes): bool {
        return (int)val('SELECT COUNT(*) FROM auth_throttle WHERE k=? AND at>NOW()-INTERVAL ' . max(1, $minutes) . ' MINUTE', [self::key($k)]) >= $max;
    }
    public static function hit(string $k): void { q('INSERT INTO auth_throttle(k) VALUES (?)', [self::key($k)]); }
    /** Forget a key (a successful sign-in clears that account's failures). */
    public static function clear(string $k): void { q('DELETE FROM auth_throttle WHERE k=?', [self::key($k)]); }
    /** Check and count in one go: true when the caller must stop. */
    public static function over(string $k, int $max, int $minutes): bool { if (self::blocked($k, $max, $minutes)) return true; self::hit($k); return false; }
    /** Old rows are removed by bin/cleanup.php. */
    public static function prune(int $days = 2): int { return q('DELETE FROM auth_throttle WHERE at<NOW()-INTERVAL ' . max(1, $days) . ' DAY')->rowCount(); }
}
