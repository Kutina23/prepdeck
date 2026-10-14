<?php
/**
 * Email that reaches the inbox:
 *  - SMTP with SSL/STARTTLS + AUTH (Hostinger: smtp.hostinger.com:465), PHP mail() as a fallback, or a log file on a dev box
 *  - multipart text + HTML, RFC 2047 subjects, quoted-printable bodies, Message-ID/Date on the sending domain
 *  - envelope sender = From address (SPF/DMARC alignment); optional DKIM signing (rsa-sha256, relaxed/relaxed)
 *  - List-Unsubscribe + one-click unsubscribe on notification emails (Gmail/Yahoo bulk-sender rules)
 *  - every message is logged in email_log; temporary failures are retried by bin/cron.php
 * Deliverability also needs DNS: SPF, DKIM and DMARC records for the From domain (see README > Email).
 */
final class Mailer {
    public static function transport(): string {
        $t = strtolower((string)env('MAIL_TRANSPORT', ''));
        if (in_array($t, ['smtp', 'mail', 'log'], true)) return $t;
        return env('SMTP_HOST', '') ? 'smtp' : (cfg('debug') ? 'log' : 'mail');
    }
    public static function fromAddress(): string {
        return (string)(env('MAIL_FROM', '') ?: 'no-reply@' . preg_replace('/^www\./', '', preg_replace('/[^a-z0-9.\-]/i', '', (string)(parse_url((string)env('APP_URL', ''), PHP_URL_HOST) ?: ($_SERVER['HTTP_HOST'] ?? 'localhost')))));
    }
    private static function fromDomain(): string { $f = self::fromAddress(); return strtolower(substr($f, strrpos($f, '@') + 1)); }

    /**
     * Send one email. $opt: template (name for the log), kind ('transactional' always sends; 'notification' respects the opt-out),
     * user_id, reply_to. Returns true when the server accepted it (or it was queued for a retry after a temporary error).
     */
    public static function send(string $to, string $subject, string $html, string $text = '', array $opt = []): bool {
        $kind = ($opt['kind'] ?? 'transactional') === 'notification' ? 'notification' : 'transactional';
        $uid = isset($opt['user_id']) ? (int)$opt['user_id'] : ((int)val('SELECT id FROM users WHERE email=? LIMIT 1', [strtolower(trim($to))]) ?: null);
        if ($kind === 'notification' && $uid && (int)val('SELECT email_opt_out FROM users WHERE id=?', [$uid])) return false;
        if ($text === '') $text = self::toText($html);
        try {
            q('INSERT INTO email_log(user_id,to_email,subject,template,kind,status,html,text) VALUES (?,?,?,?,?,?,?,?)',
                [$uid, mb_substr(trim($to), 0, 254), mb_substr($subject, 0, 255), $opt['template'] ?? 'general', $kind, 'queued', $html, $text]);
            $id = (int)db()->lastInsertId();
        } catch (Throwable $e) { $id = 0; }                  // the log table is missing (migration not run yet): still send
        return self::deliver($id, ['to' => trim($to), 'subject' => $subject, 'html' => $html, 'text' => $text, 'kind' => $kind, 'user_id' => $uid, 'reply_to' => $opt['reply_to'] ?? null]);
    }

    /** Retry messages that failed for a temporary reason (server busy, network). Called from bin/cron.php. */
    public static function retryQueued(int $limit = 30): int {
        $n = 0;
        foreach (rows("SELECT * FROM email_log WHERE status='queued' AND attempts BETWEEN 1 AND 4 AND created_at > NOW() - INTERVAL 2 DAY ORDER BY id LIMIT $limit") as $m)
            if (self::deliver((int)$m['id'], ['to' => $m['to_email'], 'subject' => $m['subject'], 'html' => (string)$m['html'], 'text' => (string)$m['text'], 'kind' => $m['kind'], 'user_id' => $m['user_id']])) $n++;
        return $n;
    }

    private static function deliver(int $logId, array $m): bool {
        $to = Validator::emailAscii($m['to']);
        if ($to === null) return self::mark($logId, 'failed', 'Not a valid email address', true);
        try {
            [$headers, $body, $msgId] = self::build($to, $m);
            $t = self::transport();
            if ($t === 'smtp') self::smtp($to, $headers, $body);
            elseif ($t === 'mail') {
                $hs = $headers; unset($hs['To'], $hs['Subject']);
                if (!@mail($to, $headers['Subject'], $body, self::headerString($hs), '-f' . self::fromAddress())) throw new RuntimeException('PHP mail() refused the message (no mail server configured?)');
            } else @file_put_contents(ROOT . '/storage/mail.log', '[' . gmdate('c') . "] to: $to\n" . self::headerString($headers) . "\r\n\r\n" . $m['text'] . "\n\n", FILE_APPEND | LOCK_EX);
            if ($logId) q("UPDATE email_log SET status='sent', attempts=attempts+1, sent_at=NOW(), error=NULL, message_id=? WHERE id=?", [$msgId, $logId]);
            return true;
        } catch (Throwable $e) {
            $permanent = $e instanceof MailPermanentError;
            error_log('[prepdeck-mail] ' . $e->getMessage());
            self::mark($logId, $permanent ? 'failed' : 'queued', $e->getMessage(), $permanent);
            return !$permanent && $logId > 0;               // a temporary failure will be retried by cron
        }
    }
    private static function mark(int $id, string $status, string $err, bool $final): bool {
        if ($id) q('UPDATE email_log SET status=?, attempts=attempts+1, error=? WHERE id=?', [$final ? 'failed' : $status, mb_substr($err, 0, 500), $id]);
        return false;
    }

    /** Headers (assoc) and the MIME body (CRLF line endings), DKIM-signed when configured. */
    private static function build(string $to, array $m): array {
        $domain = self::fromDomain(); $from = self::fromAddress(); $name = (string)env('MAIL_FROM_NAME', 'PrepDeck');
        $msgId = '<' . bin2hex(random_bytes(12)) . '.' . time() . '@' . $domain . '>';
        $boundary = '=_pd_' . bin2hex(random_bytes(10));
        $h = ['Date' => date('r'), 'From' => self::encodeName($name) . " <$from>", 'To' => $to, 'Subject' => self::encodeHeader($m['subject']), 'Message-ID' => $msgId, 'MIME-Version' => '1.0'];
        $reply = $m['reply_to'] ?? env('MAIL_REPLY_TO', ''); if ($reply && Validator::email((string)$reply)) $h['Reply-To'] = (string)$reply;
        if ($m['kind'] === 'notification' && !empty($m['user_id'])) {
            $u = abs_url('unsubscribe.php?u=' . (int)$m['user_id'] . '&t=' . self::unsubToken((int)$m['user_id']));
            $h['List-Unsubscribe'] = "<$u>, <mailto:$from?subject=unsubscribe>"; $h['List-Unsubscribe-Post'] = 'List-Unsubscribe=One-Click';
        } else $h['Auto-Submitted'] = 'auto-generated';
        $h['Content-Type'] = "multipart/alternative; boundary=\"$boundary\"";
        $part = fn(string $type, string $s) => "--$boundary\r\nContent-Type: $type; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n" . self::qp($s) . "\r\n";
        $body = "This is a multi-part message in MIME format.\r\n\r\n" . $part('text/plain', $m['text']) . $part('text/html', $m['html']) . "--$boundary--\r\n";
        if (env('DKIM_PRIVATE_KEY', '') && env('DKIM_SELECTOR', '')) $h = ['DKIM-Signature' => self::dkim($h, $body)] + $h;
        return [$h, $body, trim($msgId, '<>')];
    }

    // ---------------------------------------------------------------- SMTP
    private static function smtp(string $to, array $headers, string $body): void {
        $host = (string)env('SMTP_HOST'); $port = (int)env('SMTP_PORT', 465); $sec = strtolower((string)env('SMTP_SECURE', $port === 465 ? 'ssl' : ($port === 587 ? 'tls' : 'none')));
        $ctx = stream_context_create(['ssl' => ['verify_peer' => env('SMTP_VERIFY_TLS', '1') !== '0', 'verify_peer_name' => env('SMTP_VERIFY_TLS', '1') !== '0', 'SNI_enabled' => true]]);
        $s = @stream_socket_client(($sec === 'ssl' ? 'ssl://' : 'tcp://') . "$host:$port", $errno, $err, 20, STREAM_CLIENT_CONNECT, $ctx);
        if (!$s) throw new RuntimeException("Cannot reach the mail server $host:$port ($err)");
        stream_set_timeout($s, 30);
        try {
            self::expect($s, null, [220]);
            $ehlo = self::expect($s, 'EHLO ' . self::fromDomain(), [250]);
            if ($sec === 'tls') {
                self::expect($s, 'STARTTLS', [220]);
                if (!stream_socket_enable_crypto($s, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) throw new RuntimeException('STARTTLS failed');
                $ehlo = self::expect($s, 'EHLO ' . self::fromDomain(), [250]);
            }
            if (($user = (string)env('SMTP_USER', '')) !== '') {
                if (stripos($ehlo, 'PLAIN') !== false) self::expect($s, 'AUTH PLAIN ' . base64_encode("\0$user\0" . env('SMTP_PASS', '')), [235]);
                else { self::expect($s, 'AUTH LOGIN', [334]); self::expect($s, base64_encode($user), [334]); self::expect($s, base64_encode((string)env('SMTP_PASS', '')), [235]); }
            }
            $utf8 = preg_match('/[^\x00-\x7f]/', $to) && stripos($ehlo, 'SMTPUTF8') !== false ? ' SMTPUTF8' : '';
            self::expect($s, 'MAIL FROM:<' . self::fromAddress() . '>' . $utf8, [250]);
            self::expect($s, "RCPT TO:<$to>", [250, 251], true);
            self::expect($s, 'DATA', [354]);
            $data = self::headerString($headers) . "\r\n\r\n" . $body;
            $data = preg_replace('/^\./m', '..', str_replace(["\r\n", "\n"], ["\n", "\r\n"], $data));   // dot-stuffing
            self::expect($s, $data . "\r\n.", [250]);
            @fwrite($s, "QUIT\r\n");
        } finally { fclose($s); }
    }
    /** Send a command (or nothing) and check the reply code. 5xx on RCPT means the address does not exist: do not retry. */
    private static function expect($s, ?string $cmd, array $ok, bool $rcpt = false): string {
        if ($cmd !== null) fwrite($s, $cmd . "\r\n");
        $resp = '';
        while (($line = fgets($s, 1024)) !== false) { $resp .= $line; if (strlen($line) < 4 || $line[3] === ' ') break; }
        $code = (int)substr($resp, 0, 3);
        if (!in_array($code, $ok, true)) {
            $what = $cmd === null ? 'connect' : strtok(preg_match('/^AUTH /', $cmd) ? 'AUTH' : $cmd, ' ');
            $msg = "Mail server said $code to $what: " . trim($resp ?: 'no reply');
            if ($code >= 500 && ($rcpt || $code === 550 || $code === 553)) throw new MailPermanentError($msg);
            if ($code === 535) throw new MailPermanentError('SMTP login failed: check SMTP_USER and SMTP_PASS in .env');
            throw new RuntimeException($msg);
        }
        return $resp;
    }

    // ---------------------------------------------------------------- encoding + DKIM
    private static function headerString(array $h): string { $o = []; foreach ($h as $k => $v) $o[] = "$k: $v"; return implode("\r\n", $o); }
    private static function encodeHeader(string $s): string { return preg_match('/[^\x20-\x7e]/', $s) ? mb_encode_mimeheader($s, 'UTF-8', 'B', "\r\n", 9) : $s; }
    private static function encodeName(string $n): string { return preg_match('/[^\x20-\x7e]/', $n) ? self::encodeHeader($n) : '"' . addcslashes($n, '"\\') . '"'; }
    private static function qp(string $s): string { return quoted_printable_encode(str_replace(["\r\n", "\r", "\n"], ["\n", "\n", "\r\n"], $s)); }
    private static function dkim(array $h, string $body): string {
        $key = (string)env('DKIM_PRIVATE_KEY'); if (!str_contains($key, 'PRIVATE KEY')) $key = (string)@file_get_contents(str_starts_with($key, '/') || preg_match('/^[A-Z]:/i', $key) ? $key : ROOT . '/' . $key);
        $pk = openssl_pkey_get_private($key); if (!$pk) throw new RuntimeException('DKIM_PRIVATE_KEY could not be read');
        $b = preg_replace(["/[ \t]+\r\n/", "/[ \t]+/"], ["\r\n", ' '], $body); $b = rtrim($b, "\r\n") . "\r\n";      // relaxed body
        $names = array_values(array_intersect(['From', 'To', 'Subject', 'Date', 'Message-ID', 'MIME-Version', 'Content-Type', 'Reply-To', 'List-Unsubscribe', 'List-Unsubscribe-Post'], array_keys($h)));
        $canon = fn(string $k, string $v) => strtolower($k) . ':' . trim(preg_replace('/\s+/', ' ', str_replace("\r\n", '', $v)));
        $sig = 'v=1; a=rsa-sha256; c=relaxed/relaxed; d=' . (env('DKIM_DOMAIN', '') ?: self::fromDomain()) . '; s=' . env('DKIM_SELECTOR') . '; t=' . time()
            . '; h=' . implode(':', array_map('strtolower', $names)) . '; bh=' . base64_encode(hash('sha256', $b, true)) . '; b=';
        $data = implode("\r\n", array_map(fn($n) => $canon($n, $h[$n]), $names)) . "\r\n" . $canon('DKIM-Signature', $sig);
        openssl_sign($data, $signed, $pk, OPENSSL_ALGO_SHA256);
        return $sig . rtrim(chunk_split(base64_encode($signed), 64, "\r\n\t"), "\r\n\t");
    }
    public static function unsubToken(int $uid): string { return substr(hash_hmac('sha256', 'unsub:' . $uid, app_key()), 0, 32); }

    // ---------------------------------------------------------------- templates
    /** Branded, table-based HTML that renders in Gmail, Outlook and Apple Mail. $paras are plain strings (escaped here). */
    public static function layout(string $heading, array $paras, ?array $button = null, string $foot = '', string $preheader = ''): string {
        $e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        $p = ''; foreach ($paras as $x) $p .= '<p style="margin:0 0 16px;font-size:16px;line-height:1.6;color:#0D2B2E">' . nl2br($e($x)) . '</p>';
        $btn = $button ? '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:8px 0 22px"><tr><td style="border-radius:999px;background:#FFD23F;border:2px solid #0D2B2E"><a href="' . $e($button[1]) . '" style="display:inline-block;padding:13px 26px;font:600 16px Arial,sans-serif;color:#0D2B2E;text-decoration:none">' . $e($button[0]) . '</a></td></tr></table>'
            . '<p style="margin:0 0 16px;font-size:13px;line-height:1.5;color:#4A6468">If the button does not work, copy this link into your browser:<br><a href="' . $e($button[1]) . '" style="color:#0F766E;word-break:break-all">' . $e($button[1]) . '</a></p>' : '';
        try { $school = class_exists('Tenant', false) ? Tenant::name() : ''; } catch (Throwable $x) { $school = ''; }   // no database (unit tests)
        return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="light"><title>' . $e($heading) . '</title></head>'
            . '<body style="margin:0;padding:0;background:#F2F7F5;font-family:Arial,Helvetica,sans-serif">'
            . '<div style="display:none;max-height:0;overflow:hidden;opacity:0">' . $e($preheader ?: ($paras[0] ?? '')) . '</div>'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F2F7F5"><tr><td align="center" style="padding:28px 12px">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px">'
            . '<tr><td style="padding:0 4px 16px;font:800 22px Arial,sans-serif;color:#0D2B2E"><span style="display:inline-block;width:14px;height:18px;background:#FF7A1A;border:2px solid #0D2B2E;border-radius:4px;vertical-align:-2px;margin-right:8px"></span>PrepDeck</td></tr>'
            . '<tr><td style="background:#FFFFFF;border:1.5px solid #D3E0DD;border-radius:18px;padding:28px 26px">'
            . '<h1 style="margin:0 0 18px;font:800 24px/1.25 Arial,sans-serif;color:#0D2B2E">' . $e($heading) . '</h1>' . $p . $btn
            . ($foot !== '' ? '<p style="margin:0;font-size:13px;line-height:1.5;color:#4A6468">' . $e($foot) . '</p>' : '') . '</td></tr>'
            . '<tr><td style="padding:16px 6px;font-size:12px;line-height:1.5;color:#4A6468">PrepDeck' . ($school ? ' for ' . $e($school) : '') . '. You get this email because you have a PrepDeck account.</td></tr>'
            . '</table></td></tr></table></body></html>';
    }
    /** Plain-text part from the HTML (links kept in brackets). */
    public static function toText(string $html): string {
        $t = preg_replace(['/<head>.*?<\/head>/s', '/<div style="display:none[^>]*>.*?<\/div>/s'], '', $html);
        $t = preg_replace('/<a [^>]*href="([^"]+)"[^>]*>(.*?)<\/a>/si', '$2 [$1]', $t);
        $t = preg_replace(['/<br\s*\/?>/i', '/<\/(p|h1|h2|tr|div|td)>/i'], ["\n", "\n\n"], $t);
        $t = html_entity_decode(strip_tags($t), ENT_QUOTES, 'UTF-8');
        return trim(preg_replace(["/[ \t]+/", "/\n\s*\n\s*\n+/"], [' ', "\n\n"], $t));
    }

    public static function passwordReset(string $to, string $name, string $link, int $minutes): bool {
        return self::send($to, 'Reset your PrepDeck password', self::layout('Reset your password', ['Hi ' . self::first($name) . ',', 'Someone asked to reset the password for your PrepDeck account. Choose a new password with the button below.'],
            ['Choose a new password', $link], "The link works once and expires in $minutes minutes. If you did not ask for this, ignore this email and your password stays the same."), '', ['template' => 'password_reset']);
    }
    public static function verifyEmail(array $u, bool $welcome = false): bool {
        $raw = bin2hex(random_bytes(32));
        q('UPDATE email_tokens SET used_at=NOW() WHERE user_id=? AND used_at IS NULL', [$u['id']]);
        q('INSERT INTO email_tokens(user_id,purpose,token_hash,expires_at) VALUES (?,?,?,DATE_ADD(NOW(), INTERVAL 3 DAY))', [$u['id'], 'verify', hash('sha256', $raw)]);
        $link = abs_url('verify.php?token=' . $raw);
        $paras = $welcome ? ['Hi ' . self::first($u['full_name']) . ', welcome to PrepDeck.', 'Past questions for your department, flashcards, study guides and games are ready for you. Please confirm this is your email address so we can send password resets and receipts to you.']
                          : ['Hi ' . self::first($u['full_name']) . ',', 'Please confirm this is your email address. We use it for password resets, receipts and nothing you did not ask for.'];
        return self::send($u['email'], $welcome ? 'Welcome to PrepDeck: confirm your email' : 'Confirm your email for PrepDeck', self::layout($welcome ? 'Welcome to PrepDeck' : 'Confirm your email', $paras, ['Confirm my email', $link], 'This link expires in 3 days.'), '', ['template' => 'verify', 'user_id' => (int)$u['id']]);
    }
    public static function receipt(array $u, array $t): bool {
        return self::send($u['email'], 'Your PrepDeck Plus receipt (' . $t['gateway_ref'] . ')', self::layout('Thanks, Plus is on', ['Hi ' . self::first($u['full_name']) . ',',
            'We received your payment of ' . money($t['amount'], $t['currency']) . ' for PrepDeck Plus (' . (Billing::LABEL[$t['period']] ?? $t['period']) . ').',
            'Reference: ' . $t['gateway_ref'] . "\nDate: " . gmdate('j M Y', strtotime((string)$t['created_at'])) . ($u['plus_expires'] ? "\nPlus until: " . gmdate('j M Y', strtotime((string)$u['plus_expires'])) : '')],
            ['Open PrepDeck', abs_url('dashboard/')], 'Keep this email as your receipt. Questions about a payment? Reply to this email.'), '', ['template' => 'receipt', 'user_id' => (int)$u['id']]);
    }
    /** "Your study material is ready" / "processing stopped" after an upload. */
    public static function aiFinished(int $jobId): bool {
        $j = row('SELECT j.*,u.email,u.full_name FROM processing_jobs j JOIN users u ON u.id=j.owner_id WHERE j.id=?', [$jobId]); if (!$j) return false;
        $title = (string)val('SELECT title FROM ' . JobQueue::table($j['source_type']) . ' WHERE id=?', [$j['source_id']]);
        $link = abs_url(['outline' => 'lecturer/outlines.php?id=', 'material' => 'dashboard/materials.php?id=', 'paper' => 'dashboard/papers.php?id=', 'recording' => 'dashboard/recordings.php?id='][$j['source_type']] . $j['source_id']);
        $ok = $j['status'] === 'done';
        return self::send($j['email'], ($ok ? 'Ready to study: ' : 'Processing stopped: ') . mb_strimwidth($title, 0, 80, '…'), self::layout($ok ? 'Your study material is ready' : 'We could not finish this one',
            ['Hi ' . self::first($j['full_name']) . ',', $ok ? 'PrepDeck AI finished "' . $title . '". ' . $j['message'] : 'PrepDeck AI stopped while working on "' . $title . '": ' . $j['message']],
            [$ok ? 'Start studying' : 'See what happened', $link], 'You can turn these emails off in Settings.'), '', ['template' => 'ai_' . ($ok ? 'done' : 'failed'), 'kind' => 'notification', 'user_id' => (int)$j['owner_id']]);
    }
    /** "Your school is on PrepDeck now" to the person who asked for it on the landing page. */
    public static function schoolAdded(string $to, string $school, int $schoolId): bool {
        return self::send($to, $school . ' is on PrepDeck', self::layout('Your school is on PrepDeck', ['Hi,', 'You asked us to add ' . $school . '. It is ready: you can create your account now and invite your classmates.'],
            ['Create my account', abs_url('register.php?school=' . $schoolId)]), '', ['template' => 'school_added']);
    }
    /** A school admin account made by the platform owner. */
    public static function schoolAdminWelcome(array $u, string $school, string $tempPassword): bool {
        return self::send($u['email'], 'You are the PrepDeck admin for ' . $school, self::layout('Welcome to PrepDeck', ['Hi ' . self::first((string)$u['full_name']) . ',',
            'You now manage ' . $school . ' on PrepDeck: departments, courses, past questions, lecturers and class reps.', 'Sign in with this email and the temporary password: ' . $tempPassword, 'Please change the password after your first sign-in (Settings).'],
            ['Sign in', abs_url('login.php')]), '', ['template' => 'school_admin', 'user_id' => (int)$u['id']]);
    }
    public static function test(string $to): bool {
        return self::send($to, 'PrepDeck test email', self::layout('Email is working', ['This test was sent from Admin > Settings > Email at ' . gmdate('j M Y H:i') . ' UTC using ' . self::transport() . '.', 'If it arrived in your inbox (not spam), email is set up correctly.']), '', ['template' => 'test']);
    }
    private static function first(string $name): string { $n = trim($name); return $n !== '' ? explode(' ', $n)[0] : 'there'; }
}

/** The mail server rejected the message for good (unknown mailbox, bad login): do not retry. */
final class MailPermanentError extends RuntimeException {}
