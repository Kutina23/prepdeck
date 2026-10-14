<?php
/** Plus subscriptions. users.plus + users.plus_expires drive access; subscriptions + transactions keep the history. */
final class Billing {
    const DAYS = ['month' => 30, 'semester' => 120, 'year' => 365];
    const LABEL = ['month' => 'Monthly', 'semester' => 'Per semester', 'year' => 'Yearly'];

    /** Which price list a school uses: its own when the platform owner or school admin set one, else the platform default (school 0). */
    public static function priceSchool(?int $school = null): int {
        $school ??= sid();
        return $school && (int)val('SELECT COUNT(*) FROM plan_prices WHERE plan_id=2 AND school_id=?', [$school]) ? $school : 0;
    }
    public static function prices(bool $onlyEnabled = true, ?int $school = null): array {
        $q = 'SELECT period,amount,currency,is_enabled FROM plan_prices WHERE plan_id=2 AND school_id=' . self::priceSchool($school) . ($onlyEnabled ? ' AND is_enabled=1' : '') . " ORDER BY FIELD(period,'month','semester','year')";
        return db()->query($q)->fetchAll();
    }
    public static function price(string $period): ?array {
        $s = db()->prepare('SELECT amount,currency FROM plan_prices WHERE plan_id=2 AND school_id=? AND period=? AND is_enabled=1'); $s->execute([self::priceSchool(), $period]); return $s->fetch() ?: null;
    }
    /** Give a school its own price list, starting from the default prices. */
    public static function ownPrices(int $school): void {
        if ($school && !(int)val('SELECT COUNT(*) FROM plan_prices WHERE plan_id=2 AND school_id=?', [$school]))
            q('INSERT INTO plan_prices(school_id,plan_id,period,amount,currency,is_enabled) SELECT ?,plan_id,period,amount,currency,is_enabled FROM plan_prices WHERE plan_id=2 AND school_id=0', [$school]);
    }
    /** Price after an optional coupon code. Returns [amount, coupon row or null, error or null]. */
    public static function quote(string $period, ?string $code): array {
        $p = self::price($period); if (!$p) return [0.0, null, 'That billing option is not available.'];
        $amt = (float)$p['amount']; $cp = null;
        if ($code !== null && trim($code) !== '') {
            $s = db()->prepare("SELECT * FROM coupons WHERE code=? AND (school_id IS NULL OR school_id=" . sid() . ") AND is_active=1 AND (expires_at IS NULL OR expires_at>=CURDATE()) AND (max_uses IS NULL OR used_count<max_uses)");
            $s->execute([strtoupper(trim($code))]); $cp = $s->fetch() ?: null;
            if (!$cp) return [$amt, null, 'That coupon is not valid.'];
            $amt = round($amt * (100 - (int)$cp['percent_off']) / 100, 2);
        }
        return [$amt, $cp, null];
    }
    /** Give a user Plus for a period, stacking on any time left. Optionally link a transaction. */
    public static function activate(int $uid, string $period, ?int $txn = null): string {
        $days = self::DAYS[$period] ?? 30; $pdo = db();
        $cur = $pdo->prepare('SELECT plus,plus_expires FROM users WHERE id=?'); $cur->execute([$uid]); $u = $cur->fetch();
        $from = ($u && $u['plus'] && $u['plus_expires'] && strtotime($u['plus_expires'] . ' UTC') > time()) ? strtotime($u['plus_expires'] . ' UTC') : time();
        $end = gmdate('Y-m-d H:i:s', $from + $days * 86400);
        $pdo->prepare('UPDATE users SET plus=1, plus_expires=? WHERE id=?')->execute([$end, $uid]);
        $pdo->prepare("UPDATE subscriptions SET status='expired' WHERE user_id=? AND status IN ('active','cancelled') AND ends_at<=NOW()")->execute([$uid]);
        $pdo->prepare("INSERT INTO subscriptions(school_id,user_id,plan_id,period,status,starts_at,renews_at,ends_at) VALUES ((SELECT school_id FROM users WHERE id=?),?,2,?,'active',NOW(),NULL,?)")->execute([$uid, $uid, $period, $end]);
        $sub = (int)$pdo->lastInsertId();
        if ($txn) $pdo->prepare('UPDATE transactions SET subscription_id=? WHERE id=?')->execute([$sub, $txn]);
        return $end;
    }
    /** A pending payment is confirmed (by the gateway webhook, an admin, or the dev simulator): mark paid and give Plus. */
    public static function complete(int $txnId): bool {
        $t = row("SELECT * FROM transactions WHERE id=? AND status IN ('pending','failed')", [$txnId]);
        if (!$t || !$t['period']) return false;
        q("UPDATE transactions SET status='paid' WHERE id=?", [$txnId]);
        self::activate((int)$t['user_id'], $t['period'], $txnId);
        if ($t['coupon_id']) q('UPDATE coupons SET used_count=used_count+1 WHERE id=?', [$t['coupon_id']]);
        Audit::log("payment {$t['gateway_ref']} confirmed; Plus {$t['period']} activated", 'transaction', $txnId);
        try { $u = row('SELECT id,full_name,email,plus_expires FROM users WHERE id=?', [$t['user_id']]); if ($u) Mailer::receipt($u, $t); } catch (Throwable $e) { error_log('[prepdeck-mail] receipt: ' . $e->getMessage()); }
        return true;
    }
    /** Keep access until the paid period ends, but mark the subscription as not renewing. */
    public static function cancel(int $uid): void {
        db()->prepare("UPDATE subscriptions SET status='cancelled', cancel_at_period_end=1 WHERE user_id=? AND status='active'")->execute([$uid]);
    }
    public static function revoke(int $uid): void {
        db()->prepare('UPDATE users SET plus=0, plus_expires=NULL WHERE id=?')->execute([$uid]);
        db()->prepare("UPDATE subscriptions SET status='expired', ends_at=NOW() WHERE user_id=? AND status IN ('active','cancelled')")->execute([$uid]);
    }
    /** Housekeeping: flip lapsed members back to Free. Safe to call often (runs from admin overview and bin/cron.php). */
    public static function sweep(): int {
        $n = db()->exec("UPDATE users SET plus=0 WHERE plus=1 AND plus_expires IS NOT NULL AND plus_expires<=NOW()");
        db()->exec("UPDATE subscriptions SET status='expired' WHERE status IN ('active','cancelled') AND ends_at IS NOT NULL AND ends_at<=NOW()");
        return (int)$n;
    }
    public static function currentSub(int $uid): ?array {
        $s = db()->prepare("SELECT * FROM subscriptions WHERE user_id=? AND status IN ('active','cancelled') AND (ends_at IS NULL OR ends_at>NOW()) ORDER BY id DESC LIMIT 1"); $s->execute([$uid]); return $s->fetch() ?: null;
    }
}
