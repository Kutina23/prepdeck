<?php
/** Paystack (https://paystack.com) for Plus subscriptions. Secret key stays on the server. */
final class Paystack {
    public static function enabled(): bool { return env('PAY_PROVIDER', '') === 'paystack' && (string)env('PAY_SECRET_KEY', '') !== ''; }
    private static function base(): string { return rtrim((string)env('PAY_API_URL', 'https://api.paystack.co'), '/'); }
    /** Amounts go to Paystack in the smallest unit (pesewas for GHS, kobo for NGN, cents for ZAR/USD). */
    public static function minor(float $amount): int { return (int)round($amount * 100); }

    private static function call(string $method, string $path, ?array $body = null): array {
        $ch = curl_init(self::base() . $path);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . env('PAY_SECRET_KEY'), 'Content-Type: application/json']]);
        if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        $raw = curl_exec($ch); $err = curl_error($ch); curl_close($ch);
        if ($raw === false) throw new RuntimeException('Could not reach Paystack: ' . $err);
        $j = json_decode((string)$raw, true);
        if (!is_array($j)) throw new RuntimeException('Paystack sent an unreadable reply.');
        if (empty($j['status'])) throw new RuntimeException($j['message'] ?? 'Paystack refused the request.');
        return $j['data'] ?? [];
    }
    /** Start a payment. Returns ['authorization_url'=>..., 'reference'=>...]. */
    public static function initialize(string $email, float $amount, string $currency, string $reference, string $callback, array $meta = []): array {
        return self::call('POST', '/transaction/initialize', ['email' => $email, 'amount' => self::minor($amount), 'currency' => $currency, 'reference' => $reference, 'callback_url' => $callback, 'metadata' => $meta]);
    }
    public static function verify(string $reference): array { return self::call('GET', '/transaction/verify/' . rawurlencode($reference)); }

    /** Webhooks are signed with HMAC SHA512 of the raw body using the secret key. */
    public static function validSignature(string $raw, string $sig): bool {
        $k = (string)env('PAY_SECRET_KEY', ''); return $k !== '' && $sig !== '' && hash_equals(hash_hmac('sha512', $raw, $k), $sig);
    }
    /**
     * Confirm a transaction with Paystack itself (never trust the browser or the redirect) and, only if the paid
     * amount and currency match what we asked for, activate Plus. Returns [bool paid, string message].
     */
    public static function settle(string $reference): array {
        $t = row('SELECT * FROM transactions WHERE gateway_ref=?', [$reference]);
        if (!$t) return [false, 'We do not know that payment reference.'];
        if ($t['status'] === 'paid') return [true, 'Payment already confirmed.'];
        if (!in_array($t['status'], ['pending', 'failed'], true)) return [false, 'That payment is ' . $t['status'] . '.'];
        try { $d = self::verify($reference); } catch (RuntimeException $e) { return [false, $e->getMessage()]; }
        $status = $d['status'] ?? '';
        if ($status === 'success') {
            if ((int)($d['amount'] ?? 0) !== self::minor((float)$t['amount']) || strtoupper((string)($d['currency'] ?? '')) !== strtoupper((string)$t['currency'])) {
                Audit::log("paystack amount mismatch on {$reference}", 'transaction', (int)$t['id']); return [false, 'The amount paid does not match this order. Contact support with reference ' . $reference . '.'];
            }
            return Billing::complete((int)$t['id']) ? [true, 'Payment confirmed. Plus is on.'] : [false, 'Could not activate Plus. Contact support with reference ' . $reference . '.'];
        }
        if (in_array($status, ['failed', 'reversed'], true)) { q("UPDATE transactions SET status='failed' WHERE id=? AND status='pending'", [$t['id']]); return [false, 'The payment failed. You can start again.']; }
        return [false, 'The payment has not gone through yet. If you already paid, wait a minute and check again.'];   // abandoned / ongoing / pending
    }
}
