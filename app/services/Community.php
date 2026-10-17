<?php
/**
 * PrepDeck Community: a messenger across schools. Joining, and opening it again in a new sign-in session, needs the
 * account password (re-entered, never a separate one). People find each other by name, send a friend request,
 * and can message only once it is accepted. Blocking hides both people from each other; reports go to the platform owner.
 * Nothing here exposes an email address.
 */
final class Community {
    /** The community stays unlocked while used; this long without a community request locks it again. */
    public const IDLE_SECONDS = 3600;
    public const MAX_MESSAGE = 2000;
    /** Largest file (before encryption overhead) and the most one person may share in 24 hours. */
    public const MAX_FILE = 25 << 20;
    public const DAILY_BYTES = 300 << 20;

    /* ---------- end-to-end encrypted files (the server only ever handles ciphertext) ---------- */
    /** Tag for this sign-in session. The browser keeps unlocked keys only while the tag matches, so they die with the session. */
    public static function esid(): string { return substr(hash('sha256', 'e2ee|' . session_id()), 0, 24); }
    public static function filePath(string $name): string { return cfg('upload_dir') . '/community/' . substr($name, 0, 2) . '/' . $name . '.bin'; }
    /** Is $s standard base64 between $min and $max characters? */
    public static function b64(string $s, int $min, int $max): bool { $l = strlen($s); return $l >= $min && $l <= $max && preg_match('#^[A-Za-z0-9+/]+={0,2}$#', $s) && base64_decode($s, true) !== false; }
    /** Validate a key upload: public key (P-256 SPKI), wrapped private key, PBKDF2 salt and iterations. Returns the clean fields or an error. */
    public static function checkKeyFields(array $b, bool $withPublic): array|string {
        $pub = (string)($b['public_key'] ?? ''); $wr = (string)($b['wrapped'] ?? ''); $salt = (string)($b['salt'] ?? ''); $it = (int)($b['iterations'] ?? 0);
        if ($withPublic && (!self::b64($pub, 100, 200) || strlen(base64_decode($pub)) !== 91)) return 'The public key is not valid.';
        if (!self::b64($wr, 100, 600) || !self::b64($salt, 20, 64) || $it < 100000 || $it > 5000000) return 'The protected key is not valid.';
        return ['public_key' => $pub, 'wrapped' => $wr, 'salt' => $salt, 'iterations' => $it];
    }
    /** Messages as JSON, with each file's ciphertext metadata, plus the public keys the browser needs to open them ([messages, keys]). */
    public static function messagesOut(array $list, int $me): array {
        $fids = array_values(array_filter(array_map(fn($m) => $m['kind'] === 'file' ? (int)$m['file_id'] : 0, $list)));
        $files = []; $kids = [];
        if ($fids) foreach (rows('SELECT * FROM community_files WHERE id IN (' . implode(',', $fids) . ')') as $f) { $files[(int)$f['id']] = $f; $kids[] = (int)$f['sender_key_id']; $kids[] = (int)$f['recipient_key_id']; }
        $keys = []; if ($kids) foreach (rows('SELECT id,public_key FROM community_keys WHERE id IN (' . implode(',', array_unique($kids)) . ')') as $k) $keys[(int)$k['id']] = $k['public_key'];
        $out = array_map(function ($m) use ($me, $files) {
            $o = ['id' => (int)$m['id'], 'mine' => (int)$m['sender_id'] === $me, 'body' => $m['body'], 'at' => str_replace(' ', 'T', $m['created_at']) . 'Z'];
            $f = $m['kind'] === 'file' ? ($files[(int)$m['file_id']] ?? null) : null;
            if ($f) $o['file'] = ['id' => (int)$f['id'], 'size' => (int)$f['size'], 'meta' => $f['meta'], 'iv' => $f['file_iv'], 'wrapped' => $f['wrapped_key'], 'sk' => (int)$f['sender_key_id'], 'rk' => (int)$f['recipient_key_id'], 'from' => (int)$f['sender_id']];
            elseif ($m['kind'] === 'file') $o['body'] = '(file no longer available)';
            return $o;
        }, $list);
        return [$out, (object)$keys];
    }

    /** Youngest age that may join (platform setting community_min_age, default 18: people from other schools are strangers). */
    public static function minAge(): int { return max(13, (int)Settings::global('community_min_age', '18')); }
    public static function open(): bool { return Settings::global('community_open', '1') === '1'; }

    /** Why this account cannot use the community, or null when it can. */
    public static function blockedReason(array $u): ?string {
        if (!self::open()) return 'The community is closed right now. Please check back later.';
        if ((int)$u['role_id'] === Tenant::SUPER) return null;
        if (empty($u['school_id'])) return 'Your account needs a school before you can join the community.';
        $age = !empty($u['date_of_birth']) ? age_from_dob((string)$u['date_of_birth']) : null;
        if ((int)$u['role_id'] === 1 && ($age === null || $age < self::minAge())) return 'The PrepDeck Community is for students aged ' . self::minAge() . ' and over. You can still study with everything else on PrepDeck.';
        $p = self::profile((int)$u['id']);
        if ($p && $p['banned_at']) return 'Your community access was removed' . ($p['ban_reason'] ? ': ' . $p['ban_reason'] : '.') . ' Contact PrepDeck support if you think this is a mistake.';
        return null;
    }
    public static function profile(int $uid): ?array { return row('SELECT * FROM community_profiles WHERE user_id=?', [$uid]); }

    /* ---------- password gate ---------- */
    public static function unlocked(int $uid): bool {
        $s = $_SESSION['community'] ?? null;
        return $s && (int)$s['uid'] === $uid && time() - (int)$s['at'] < self::IDLE_SECONDS;
    }
    public static function touch(int $uid): void { $_SESSION['community'] = ['uid' => $uid, 'at' => time()]; q('UPDATE community_profiles SET last_seen_at=NOW() WHERE user_id=?', [$uid]); }
    public static function lock(): void { unset($_SESSION['community']); }
    /** Check the account password. 'ok', 'bad' or 'blocked' (too many tries: 5 per 15 minutes per account). */
    public static function checkPassword(array $u, string $pass): string {
        $k = 'community-pw:' . $u['id'];
        if (Throttle::blocked($k, 5, 15)) { SecurityLog::record('community_blocked', 'critical', $u); return 'blocked'; }
        $hash = (string)val('SELECT password_hash FROM users WHERE id=?', [$u['id']]);
        if (!password_verify($pass, $hash)) { Throttle::hit($k); SecurityLog::record('community_unlock_failed', 'warning', $u); return 'bad'; }
        Throttle::clear($k);
        return 'ok';
    }

    /* ---------- people ---------- */
    /** Public card for one person: never the email. */
    public static function card(array $r): array {
        $role = ['1' => 'Student', '2' => 'Class rep', '3' => 'Lecturer', '4' => 'School admin', '5' => 'PrepDeck team'][(string)($r['role_id'] ?? 1)] ?? 'Member';
        $lvl = (int)($r['role_id'] ?? 1) === 1 && !empty($r['level']) ? level_label($r['level']) : '';
        return ['id' => (int)$r['user_id'], 'name' => $r['display_name'], 'initials' => initials($r['display_name']), 'school' => $r['school_short'] ?: ($r['school_name'] ?? ''),
            'school_name' => $r['school_name'] ?? '', 'role' => $role, 'level' => $lvl, 'department' => $r['department'] ?? '', 'bio' => $r['bio'] ?? '', 'hue' => crc32((string)$r['user_id']) % 360,
            'online' => !empty($r['last_seen_at']) && strtotime($r['last_seen_at'] . ' UTC') > time() - 120];
    }
    /** SELECT list + joins used by every people query (alias p = community_profiles). */
    public const PEOPLE = 'SELECT p.user_id,p.display_name,p.bio,p.last_seen_at,u.role_id,u.level,s.short_name school_short,s.name school_name,d.name department FROM community_profiles p JOIN users u ON u.id=p.user_id AND u.status=\'active\' LEFT JOIN schools s ON s.id=u.school_id LEFT JOIN departments d ON d.id=u.department_id';
    /** SQL: neither person blocked the other ($col is the other person's id column). */
    public static function notBlocked(string $col, int $me): string {
        return "$col NOT IN (SELECT blocked_id FROM user_blocks WHERE user_id=$me) AND $col NOT IN (SELECT user_id FROM user_blocks WHERE blocked_id=$me)";
    }
    public static function person(int $me, int $id): ?array {
        $r = row(self::PEOPLE . ' WHERE p.user_id=? AND p.banned_at IS NULL AND ' . self::notBlocked('p.user_id', $me), [$id]);
        return $r ? self::card($r) + ['relation' => self::relation($me, $id)] : null;
    }
    /** 'self', 'friends', 'sent' (I asked), 'received' (they asked) or 'none'. */
    public static function relation(int $me, int $other): string {
        if ($me === $other) return 'self';
        $f = row("SELECT requester_id,status FROM friendships WHERE ((requester_id=? AND addressee_id=?) OR (requester_id=? AND addressee_id=?)) AND status IN ('pending','accepted') ORDER BY status='accepted' DESC LIMIT 1", [$me, $other, $other, $me]);
        if (!$f) return 'none';
        return $f['status'] === 'accepted' ? 'friends' : ((int)$f['requester_id'] === $me ? 'sent' : 'received');
    }
    public static function areFriends(int $a, int $b): bool { return self::relation($a, $b) === 'friends'; }
    public static function friendIds(int $me): array {
        return array_map('intval', array_column(rows("SELECT IF(requester_id=?,addressee_id,requester_id) id FROM friendships WHERE status='accepted' AND (requester_id=? OR addressee_id=?)", [$me, $me, $me]), 'id'));
    }
}
