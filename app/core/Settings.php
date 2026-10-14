<?php
/**
 * Key/value settings. Row school_id 0 = the platform default; a row for a school overrides it for that school only,
 * so each school can have its own banner, contact details, registration switch and staff benefits.
 * PLATFORM keys (AI engine, limits that cost money, platform switches) are read and written only at school 0.
 */
final class Settings {
    public const PLATFORM = ['ai_model', 'ai_tier', 'ai_max_pages', 'ai_max_chars', 'ai_predicted_count', 'ai_last_test', 'stt_language', 'rec_keep_audio_days',
        'mat_max', 'mat_mb', 'rec_max_mb', 'ai_cap', 'platform_ai_on', 'platform_maintenance', 'platform_reg_open', 'school_requests_open', 'school_default_plan', '2fa_required',
        // the public site (home page, message bar, contact, social links) is one site for every school
        'hero_title', 'hero_sub', 'banner_on', 'banner_text', 'contact_email', 'contact_phone', 'social_whatsapp', 'social_instagram', 'social_x'];
    /** Per-school keys only the platform owner may change: everything about Plus (who gets it, what it unlocks, free-plan limits). */
    public const LOCKED = ['rep_plus', 'lecturer_plus', 'lesson_students_plus', 'free_timer', 'plus_max_timer', 'free_notes', 'free_upload_max', 'free_upload_pages',
        'rec_max_min_plus', 'rec_max_min_free',
        // and everything about PrepDeck AI: what it may do, auto-publishing, the lecturer outline tool and its limits
        'ai_on', 'ai_quiz', 'ai_cards', 'ai_guide', 'ai_rules', 'ai_autopublish', 'lesson_tools_on', 'lesson_quota', 'outline_max_topics', 'outline_max_pages', 'lesson_autopublish'];
    private static array $cache = [];
    private static function school(): int { return class_exists('Tenant', false) ? Tenant::id() : 0; }
    private static function load(int $sid): array {
        if (!isset(self::$cache[$sid])) {
            $s = db()->prepare('SELECT school_id,k,v FROM site_settings WHERE school_id IN (0,?) ORDER BY school_id'); $s->execute([$sid]);
            $g = []; $o = [];
            foreach ($s->fetchAll() as $r) { if ((int)$r['school_id'] === 0) $g[$r['k']] = $r['v']; else $o[$r['k']] = $r['v']; }
            foreach (self::PLATFORM as $k) unset($o[$k]);
            self::$cache[$sid] = ['global' => $g, 'all' => array_merge($g, $o), 'own' => $o];
        }
        return self::$cache[$sid];
    }
    /** Effective settings for the current school (school value, else platform default). */
    public static function all(): array { return self::load(self::school())['all']; }
    public static function get(string $k, $d = null) { return self::all()[$k] ?? $d; }
    /** Platform default only. */
    public static function global(string $k, $d = null) { return self::load(0)['global'][$k] ?? $d; }
    /** Does the current school override this key? */
    public static function overridden(string $k): bool { return array_key_exists($k, self::load(self::school())['own']); }
    /** Save for the current school. Platform keys go to the platform default, and only the platform owner (or a script) may change them. */
    public static function set(string $k, string $v): void {
        $owner = !Auth::user() || Tenant::isSuper();   // nobody signed in = a script (migration, cron); otherwise only the platform owner
        if (in_array($k, self::PLATFORM, true)) { if ($owner) self::put(0, $k, $v); return; }
        if ((in_array($k, self::LOCKED, true) || str_starts_with($k, 'perm_matrix_')) && !$owner) return;
        self::put(self::school(), $k, $v);
    }
    public static function setGlobal(string $k, string $v): void { self::put(0, $k, $v); }
    public static function put(int $sid, string $k, string $v): void {
        db()->prepare('INSERT INTO site_settings(school_id,k,v) VALUES (?,?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)')->execute([$sid, $k, $v]);
        self::flush();
    }
    /** Drop a school's own value so it follows the platform default again. */
    public static function reset(int $sid, string $k): void { db()->prepare('DELETE FROM site_settings WHERE school_id=? AND k=?')->execute([$sid, $k]); self::flush(); }
    public static function flush(): void { self::$cache = []; }
}
