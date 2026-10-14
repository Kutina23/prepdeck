<?php
/**
 * Which school this request works in. Every school-owned query filters on sid().
 *  - students: always their own school (users.school_id)
 *  - staff listed in user_schools: their home school, or another school they picked in the header switcher
 *  - the platform owner (super admin): any school picked in the switcher or in Platform > Schools ("Open dashboard")
 *  - visitors: the school picked in the landing page directory (only used for the public library)
 * No school = 0, which matches no rows, so a missing context can never leak another school's data.
 */
final class Tenant {
    public const SUPER = 5;
    private static ?array $school = null;
    private static bool $loaded = false;
    private static ?int $id = null;
    private static ?int $forced = null;

    public static function isSuper(?array $u = null): bool { $u ??= Auth::user(); return $u && (int)$u['role_id'] === self::SUPER; }

    public static function id(): int { return self::$forced ?? (self::$id ??= self::resolve()); }
    private static function resolve(): int {
        $u = Auth::user();
        if (!$u) return (int)($_SESSION['visit_school'] ?? 0);
        $want = (int)($_SESSION['act_school'] ?? 0);
        if ($want && $want !== (int)$u['school_id'] && (self::isSuper($u) || self::isMember((int)$u['id'], $want))) return $want;
        return (int)($u['school_id'] ?? 0);
    }
    public static function school(): ?array {
        if (!self::$loaded) { self::$loaded = true; $id = self::id(); self::$school = $id ? self::find($id) : null; }
        return self::$school;
    }
    public static function find(int $id): ?array {
        $s = db()->prepare('SELECT * FROM schools WHERE id=?'); $s->execute([$id]); return $s->fetch() ?: null;
    }
    public static function isMember(int $uid, int $sid): bool {
        $s = db()->prepare('SELECT COUNT(*) FROM user_schools WHERE user_id=? AND school_id=?'); $s->execute([$uid, $sid]); return (bool)$s->fetchColumn();
    }
    /** Schools this person can switch between (home school first). The platform owner gets an empty list here and uses Platform > Schools. */
    public static function choices(?array $u = null): array {
        $u ??= Auth::user(); if (!$u || self::isSuper($u)) return [];
        $s = db()->prepare('SELECT s.id,s.name,s.short_name FROM schools s WHERE s.is_active=1 AND (s.id=? OR s.id IN (SELECT school_id FROM user_schools WHERE user_id=?)) ORDER BY s.id=? DESC, s.name');
        $s->execute([(int)$u['school_id'], (int)$u['id'], (int)$u['school_id']]); return $s->fetchAll();
    }
    /** Act inside another school. Returns false when this person may not. */
    public static function switchTo(int $sid): bool {
        $u = Auth::user(); if (!$u || !$sid) return false;
        $s = self::find($sid); if (!$s) return false;
        if (!self::isSuper($u) && $sid !== (int)$u['school_id'] && (!self::isMember((int)$u['id'], $sid) || !$s['is_active'])) return false;
        $_SESSION['act_school'] = $sid; self::forget(); Auth::reset();
        return true;
    }
    public static function visit(int $sid): bool {
        $s = self::find($sid); if (!$s || !$s['is_active']) return false;
        $_SESSION['visit_school'] = $sid; self::forget(); return true;
    }
    /** Background work (AI worker, cron) runs as the school that owns the job. null = back to the request's own school. */
    public static function force(?int $sid): void { self::$forced = $sid; self::$loaded = false; Settings::flush(); }
    public static function forget(): void { self::$id = null; self::$loaded = false; Settings::flush(); }
    /** Save an uploaded logo (PNG, JPG or WebP, up to 1 MB) for a school. Returns an error message, or null when saved. */
    public static function saveLogo(int $sid, array $f): ?string {
        if (($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
        if (($f['error'] ?? 1) !== UPLOAD_ERR_OK || $f['size'] > 1048576) return 'The logo must be an image up to 1 MB.';
        $ext = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'][(new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name'])] ?? null;
        if (!$ext) return 'Upload the logo as PNG, JPG or WebP.';
        $name = 'logo-' . bin2hex(random_bytes(8)) . '.' . $ext;
        if (!move_uploaded_file($f['tmp_name'], upload_dir('logos', $sid) . '/' . $name)) return 'The logo could not be saved.';
        $old = (string)val('SELECT logo FROM schools WHERE id=?', [$sid]); if ($old !== '') @unlink(upload_path('logos', $old));
        q('UPDATE schools SET logo=? WHERE id=?', [upload_ref($name, $sid), $sid]); self::$loaded = false;
        return null;
    }
    public static function name(): string { return (string)(self::school()['name'] ?? ''); }
    public static function slug(string $s): string { return trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($s)), '-'); }
    public const TYPES = ['university' => 'University', 'technical_university' => 'Technical university', 'polytechnic' => 'Polytechnic', 'college_of_education' => 'College of education',
        'nursing_college' => 'Nursing & midwifery college', 'senior_high' => 'Senior high school', 'other' => 'Other'];
    public const REGIONS = ['Ahafo', 'Ashanti', 'Bono', 'Bono East', 'Central', 'Eastern', 'Greater Accra', 'North East', 'Northern', 'Oti', 'Savannah', 'Upper East', 'Upper West', 'Volta', 'Western', 'Western North'];
}
