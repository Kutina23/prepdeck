<?php
/**
 * Time-based one-time codes (RFC 6238, the 6-digit codes of Google Authenticator, Microsoft Authenticator, Authy, 1Password ...)
 * and the one-time recovery codes that stand in for the phone when it is lost.
 *   $secret = Totp::secret();                       // show Totp::uri($secret, $email) as a QR code
 *   $step = Totp::verify($secret, $code, $lastStep); // the matched time step, or null
 */
final class Totp {
    public const DIGITS = 6;
    public const PERIOD = 30;
    private const B32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** A new random secret, base32 (160 bits, the size RFC 4226 recommends). */
    public static function secret(): string { return self::b32encode(random_bytes(20)); }

    public static function b32encode(string $bin): string {
        $bits = ''; foreach (str_split($bin) as $c) $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
        $out = ''; foreach (str_split($bits, 5) as $chunk) $out .= self::B32[bindec(str_pad($chunk, 5, '0'))];
        return $out;
    }
    public static function b32decode(string $b32): string {
        $b32 = strtoupper(preg_replace('/[\s=-]/', '', $b32)); $bits = '';
        foreach (str_split($b32) as $c) { $i = strpos(self::B32, $c); if ($i === false) return ''; $bits .= str_pad(decbin($i), 5, '0', STR_PAD_LEFT); }
        $out = ''; foreach (str_split($bits, 8) as $byte) if (strlen($byte) === 8) $out .= chr(bindec($byte));
        return $out;
    }

    /** The code for one 30-second time step. */
    public static function code(string $secret, int $step): string {
        $h = hash_hmac('sha1', pack('J', $step), self::b32decode($secret), true);
        $o = ord($h[19]) & 0x0f;
        $n = ((ord($h[$o]) & 0x7f) << 24) | (ord($h[$o + 1]) << 16) | (ord($h[$o + 2]) << 8) | ord($h[$o + 3]);
        return str_pad((string)($n % 10 ** self::DIGITS), self::DIGITS, '0', STR_PAD_LEFT);
    }
    public static function step(?int $now = null): int { return intdiv($now ?? time(), self::PERIOD); }

    /** Accepts the current code and one either side (phone clocks drift). A step at or before $after was already used and is refused. */
    public static function verify(string $secret, string $code, ?int $after = null, ?int $now = null): ?int {
        $code = preg_replace('/\D/', '', $code);
        if (strlen($code) !== self::DIGITS || $secret === '') return null;
        $t = self::step($now);
        foreach ([$t, $t - 1, $t + 1] as $s) if (($after === null || $s > $after) && hash_equals(self::code($secret, $s), $code)) return $s;
        return null;
    }

    /** otpauth:// link that authenticator apps read from the QR code. */
    public static function uri(string $secret, string $account, string $issuer = 'PrepDeck'): string {
        return 'otpauth://totp/' . rawurlencode($issuer) . ':' . rawurlencode($account) . '?' . http_build_query(['secret' => $secret, 'issuer' => $issuer, 'algorithm' => 'SHA1', 'digits' => self::DIGITS, 'period' => self::PERIOD], '', '&', PHP_QUERY_RFC3986);
    }

    /** Ten recovery codes like "k3f9-x2mq-7hd4" (no 0/o/1/l to misread). Store only recovery_hash() of each. */
    public static function recoveryCodes(int $n = 10): array {
        $abc = '23456789abcdefghjkmnpqrstuvwxyz'; $out = [];
        for ($i = 0; $i < $n; $i++) { $c = ''; for ($j = 0; $j < 12; $j++) $c .= $abc[random_int(0, strlen($abc) - 1)]; $out[] = implode('-', str_split($c, 4)); }
        return $out;
    }
    /** Typed codes are matched without dashes, spaces or case. */
    public static function recoveryHash(string $code): string { return hash('sha256', strtolower(preg_replace('/[^0-9a-z]/i', '', $code))); }

    /** The secret is stored encrypted with APP_KEY when one is set ("enc:" prefix), so a database dump alone cannot make codes. */
    public static function seal(string $secret): string {
        $key = (string)env('APP_KEY', ''); if ($key === '') return $secret;
        $iv = random_bytes(12); $ct = openssl_encrypt($secret, 'aes-256-gcm', hash('sha256', 'totp|' . $key, true), OPENSSL_RAW_DATA, $iv, $tag);
        return 'enc:' . base64_encode($iv . $tag . $ct);
    }
    /** '' when it cannot be opened (APP_KEY changed or missing): the account then needs a recovery code or an admin reset, never no check at all. */
    public static function open(?string $stored): string {
        $stored = (string)$stored; if (!str_starts_with($stored, 'enc:')) return $stored;
        $key = (string)env('APP_KEY', ''); $raw = base64_decode(substr($stored, 4), true);
        if ($key === '' || $raw === false || strlen($raw) < 29) return '';
        $pt = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', hash('sha256', 'totp|' . $key, true), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        return $pt === false ? '' : $pt;
    }
}
