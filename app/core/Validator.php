<?php
final class Validator {
    /** Any valid address: plus-addressing, long and new TLDs, international domains (bücher.de) and Unicode local parts. */
    public static function email(string $v): bool { return self::emailAscii($v) !== null; }

    /** The address in the form mail servers accept (domain in punycode), or null when it is not a valid address. */
    public static function emailAscii(string $v): ?string {
        $v = trim($v);
        if ($v === '' || strlen($v) > 254 || substr_count($v, '@') !== 1) return null;
        [$local, $domain] = explode('@', $v);
        if ($local === '' || strlen($local) > 64 || $domain === '') return null;
        $domain = rtrim(mb_strtolower($domain), '.');
        $ascii = preg_match('/[^\x20-\x7e]/', $domain) ? self::punycodeDomain($domain) : $domain;
        if ($ascii === null || strlen($ascii) > 253 || !str_contains($ascii, '.')) return null;
        foreach (explode('.', $ascii) as $label) if (!preg_match('/^(?!-)[a-z0-9-]{1,63}(?<!-)$/', $label)) return null;
        if (!preg_match('/^(xn--[a-z0-9-]+|[a-z]{2,63})$/', substr($ascii, strrpos($ascii, '.') + 1))) return null;   // TLD
        $flags = preg_match('/[^\x20-\x7e]/', $local) ? FILTER_FLAG_EMAIL_UNICODE : 0;
        return filter_var("$local@$ascii", FILTER_VALIDATE_EMAIL, $flags) ? "$local@$ascii" : null;
    }

    /** True when the domain can receive mail (MX record, or an A/AAAA record as RFC 5321 allows). Blocks typos such as gmail.con before they bounce. */
    public static function emailDomainWorks(string $email): bool {
        $a = self::emailAscii($email); if ($a === null) return false;
        $domain = substr($a, strrpos($a, '@') + 1);
        if (!function_exists('checkdnsrr')) return true;
        static $dnsUp = null, $cache = [];
        if (isset($cache[$domain])) return $cache[$domain];
        if (checkdnsrr($domain, 'MX') || checkdnsrr($domain, 'A') || checkdnsrr($domain, 'AAAA')) return $cache[$domain] = true;
        $dnsUp ??= checkdnsrr('gmail.com', 'MX');      // no DNS at all (offline dev box): do not lock people out
        return $cache[$domain] = !$dnsUp;
    }

    /** "Did you mean …?" for the common misspellings of big mail providers. */
    public static function emailTypo(string $email): ?string {
        $email = trim(mb_strtolower($email)); if (!str_contains($email, '@')) return null;
        [$local, $domain] = explode('@', $email, 2);
        $known = ['gmail.com', 'yahoo.com', 'hotmail.com', 'outlook.com', 'icloud.com', 'live.com', 'aol.com', 'proton.me', 'protonmail.com', 'yahoo.co.uk', 'ymail.com', 'googlemail.com'];
        if (in_array($domain, $known, true)) return null;
        $fix = ['gmial.com' => 'gmail.com', 'gmai.com' => 'gmail.com', 'gmal.com' => 'gmail.com', 'gamil.com' => 'gmail.com', 'gnail.com' => 'gmail.com', 'gmail.co' => 'gmail.com', 'gmail.con' => 'gmail.com', 'gmail.cm' => 'gmail.com', 'gmail.om' => 'gmail.com', 'gmail.cmo' => 'gmail.com', 'gmail.ocm' => 'gmail.com', 'yahoo.cmo' => 'yahoo.com', 'hotmail.cmo' => 'hotmail.com', 'outlook.cmo' => 'outlook.com',
            'yaho.com' => 'yahoo.com', 'yahooo.com' => 'yahoo.com', 'yahoo.con' => 'yahoo.com', 'yahoo.co' => 'yahoo.com', 'hotmal.com' => 'hotmail.com', 'hotmial.com' => 'hotmail.com', 'hotmail.con' => 'hotmail.com', 'hotmail.co' => 'hotmail.com',
            'outlok.com' => 'outlook.com', 'outlook.con' => 'outlook.com', 'outlook.co' => 'outlook.com', 'iclod.com' => 'icloud.com', 'icloud.con' => 'icloud.com'];
        if (isset($fix[$domain])) return "$local@{$fix[$domain]}";
        foreach ($known as $k) if (levenshtein($domain, $k) === 1) return "$local@$k";
        return null;
    }

    /** IDNA domain -> punycode (xn--…). Uses intl when installed, otherwise a built-in RFC 3492 encoder. */
    private static function punycodeDomain(string $domain): ?string {
        if (function_exists('idn_to_ascii')) { $r = idn_to_ascii($domain, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46); return $r === false ? null : strtolower($r); }
        $out = [];
        foreach (explode('.', $domain) as $label) {
            if (!preg_match('/[^\x00-\x7f]/', $label)) { $out[] = $label; continue; }
            $cps = mb_str_split($label); $cps = array_map('mb_ord', $cps);
            $basic = array_filter($cps, fn($c) => $c < 0x80); $o = implode('', array_map('chr', $basic));
            $h = $b = count($basic); if ($b) $o .= '-';
            $n = 128; $delta = 0; $bias = 72; $len = count($cps);
            while ($h < $len) {
                $m = min(array_filter($cps, fn($c) => $c >= $n));
                $delta += ($m - $n) * ($h + 1); $n = $m;
                foreach ($cps as $c) {
                    if ($c < $n) $delta++;
                    if ($c === $n) {
                        $q = $delta;
                        for ($k = 36; ; $k += 36) {
                            $t = $k <= $bias ? 1 : ($k >= $bias + 26 ? 26 : $k - $bias);
                            if ($q < $t) break;
                            $d = $t + ($q - $t) % (36 - $t); $o .= chr($d < 26 ? $d + 97 : $d + 22); $q = intdiv($q - $t, 36 - $t);
                        }
                        $o .= chr($q < 26 ? $q + 97 : $q + 22);
                        $delta2 = $h === $b ? intdiv($delta, 700) : intdiv($delta, 2); $delta2 += intdiv($delta2, $h + 1); $kk = 0;
                        while ($delta2 > 455) { $delta2 = intdiv($delta2, 35); $kk += 36; }
                        $bias = $kk + intdiv(36 * $delta2, $delta2 + 38); $delta = 0; $h++;
                    }
                }
                $delta++; $n++;
            }
            $out[] = 'xn--' . $o;
        }
        return implode('.', $out);
    }
    public static function str($v, int $min = 1, int $max = 255): ?string {
        $v = trim((string)$v); $n = mb_strlen($v); return ($n >= $min && $n <= $max) ? $v : null;
    }
    public static function in($v, array $allowed) { return in_array($v, $allowed, true) ? $v : null; }
    /** Checks a real PDF: size, MIME sniffed from content (not the filename) and %PDF header. */
    public static function pdf(array $f): ?string {
        if (($f['error'] ?? 1) !== UPLOAD_ERR_OK) return 'Upload failed.';
        if ($f['size'] > cfg('max_pdf_bytes')) return 'File is too large.';
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
        if ($mime !== 'application/pdf') return 'Only PDF files are allowed.';
        return null;
    }
}
