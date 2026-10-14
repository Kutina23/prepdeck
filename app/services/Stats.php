<?php
/**
 * Numbers for the platform owner: activity, Plus revenue and recurring revenue, across every school or for one.
 * Windows are counted back from now: "current" = the last $days days, "previous" = the $days before that.
 * Every method takes an optional school id; null = all schools.
 */
final class Stats {
    /** SQL condition for a time column inside the current (0) or previous (1) window of $days days. */
    public static function win(string $col, int $days, int $which = 0): string {
        $days = max(1, $days);
        return $which === 0 ? "$col>=NOW()-INTERVAL $days DAY" : "$col>=NOW()-INTERVAL " . (2 * $days) . " DAY AND $col<NOW()-INTERVAL $days DAY";
    }
    private static function sch(?int $school, string $col = 'school_id'): string { return $school ? " AND $col=" . (int)$school : ''; }

    /** Everything that counts as "using PrepDeck": opening a paper, practising, AI chat, focus timer, games. Rows: user_id, school_id, at. */
    public static function activitySql(string $where): string {
        $parts = [];
        foreach (['paper_views' => 'viewed_at', 'practice_attempts' => 'created_at', 'ai_usage' => 'created_at', 'focus_sessions' => 'started_at', 'game_scores' => 'created_at'] as $t => $c)
            $parts[] = "SELECT user_id, school_id, $c at FROM $t WHERE " . str_replace('{at}', $c, $where);
        return implode(' UNION ALL ', $parts);
    }
    /** Distinct active people in a window. */
    public static function activeUsers(int $days, int $which = 0, ?int $school = null): int {
        return (int)val('SELECT COUNT(DISTINCT user_id) FROM (' . self::activitySql(self::win('{at}', $days, $which) . self::sch($school)) . ') a');
    }
    /** Active people per day for the last $days days: ['Y-m-d' => n], oldest first, every day present. */
    public static function activePerDay(int $days, ?int $school = null): array {
        $out = self::days($days);
        foreach (rows('SELECT DATE(at) d, COUNT(DISTINCT user_id) n FROM (' . self::activitySql('{at}>=CURDATE()-INTERVAL ' . ($days - 1) . ' DAY' . self::sch($school)) . ') a GROUP BY d') as $r)
            if (isset($out[$r['d']])) $out[$r['d']] = (int)$r['n'];
        return $out;
    }
    /** Count of rows per day for a table/time column: ['Y-m-d' => n]. */
    public static function perDay(string $table, string $col, int $days, string $extra = ''): array {
        $out = self::days($days);
        foreach (rows("SELECT DATE($col) d, COUNT(*) n FROM $table WHERE $col>=CURDATE()-INTERVAL " . ($days - 1) . " DAY $extra GROUP BY d") as $r)
            if (isset($out[$r['d']])) $out[$r['d']] = (int)$r['n'];
        return $out;
    }
    /** Empty day buckets in the database's calendar (so "today" matches CURDATE()). */
    public static function days(int $days): array {
        $today = strtotime((string)val('SELECT CURDATE()') . ' UTC'); $out = [];
        for ($i = $days - 1; $i >= 0; $i--) $out[gmdate('Y-m-d', $today - $i * 86400)] = 0;
        return $out;
    }

    /** Paid Plus revenue per calendar month for the last $months months: ['Y-m' => amount], oldest first. Refunded payments are not counted. */
    public static function revenueByMonth(int $months = 12, ?int $school = null): array {
        $first = (string)val("SELECT DATE_FORMAT(CURDATE() - INTERVAL " . ($months - 1) . " MONTH, '%Y-%m-01')");
        $out = []; $t = strtotime($first . ' UTC');
        for ($i = 0; $i < $months; $i++) $out[gmdate('Y-m', strtotime("+$i month", $t))] = 0.0;
        foreach (rows("SELECT DATE_FORMAT(created_at,'%Y-%m') m, SUM(amount) a FROM transactions WHERE status='paid' AND created_at>=?" . self::sch($school) . ' GROUP BY m', [$first]) as $r)
            if (isset($out[$r['m']])) $out[$r['m']] = (float)$r['a'];
        return $out;
    }
    /** Paid revenue in a window. */
    public static function revenue(int $days, int $which = 0, ?int $school = null): float {
        return (float)val("SELECT COALESCE(SUM(amount),0) FROM transactions WHERE status='paid' AND " . self::win('created_at', $days, $which) . self::sch($school));
    }

    /**
     * Plus subscriptions running right now, per school: active subs, paying subs (their last payment was money),
     * given subs (granted by an admin, no payment), and monthly recurring revenue = each paying sub's last payment spread over its period, as 30 days.
     * Returns [school_id => ['subs','paying','given','mrr']] plus key 0 for the total.
     */
    public static function recurring(): array {
        $out = [0 => ['subs' => 0, 'paying' => 0, 'given' => 0, 'mrr' => 0.0]];
        $list = rows("SELECT s.school_id, s.period, (SELECT t.amount FROM transactions t WHERE t.user_id=s.user_id AND t.status='paid' ORDER BY t.id DESC LIMIT 1) amt
            FROM subscriptions s WHERE s.status IN ('active','cancelled') AND s.ends_at>NOW()");
        foreach ($list as $r) {
            $amt = (float)$r['amt']; $m = $amt > 0 ? $amt / (Billing::DAYS[$r['period']] ?? 30) * 30 : 0.0;
            foreach ([0, (int)$r['school_id']] as $k) {
                $out[$k] ??= ['subs' => 0, 'paying' => 0, 'given' => 0, 'mrr' => 0.0];
                $out[$k]['subs']++; $out[$k][$amt > 0 ? 'paying' : 'given']++; $out[$k]['mrr'] += $m;
            }
        }
        return $out;
    }
    /** Currency shown on money figures: the default Plus price list's, else GHS. */
    public static function currency(): string { return (string)(val('SELECT currency FROM plan_prices WHERE school_id=0 AND plan_id=2 LIMIT 1') ?: 'GHS'); }

    /** Percent change from $prev to $now, or null when there is nothing to compare with. */
    public static function change(float $now, float $prev): ?float { return $prev > 0 ? ($now - $prev) / $prev * 100 : null; }
}
