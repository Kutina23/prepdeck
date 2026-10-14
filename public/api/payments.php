<?php
// Student checkout. With Paystack configured (PAY_PROVIDER=paystack + PAY_SECRET_KEY) the student pays on Paystack and
// the payment is verified server-side. Without it, manual confirmation still works:
//  - the payment is recorded as "pending" and an admin confirms it (Admin > Payments > Mark as paid), or
//  - in development (APP_ENV=development) the "Simulate payment" button completes it so you can test Plus.
// To connect Paystack/Flutterwave/Stripe: create the charge in 'checkout' and let 'webhook' call Billing::complete().
require __DIR__ . '/../../app/bootstrap.php';
$a = $_GET['a'] ?? '';

if ($a === 'webhook') {                                         // Paystack -> server. The signature is ALWAYS checked, then we re-verify with Paystack.
    $raw = file_get_contents('php://input'); $sig = $_SERVER['HTTP_X_PAYSTACK_SIGNATURE'] ?? '';
    if (!Paystack::validSignature($raw, $sig)) { http_response_code(401); exit; }
    $ev = json_decode($raw, true); $ref = (string)($ev['data']['reference'] ?? '');
    if (($ev['event'] ?? '') === 'charge.success' && $ref !== '') Paystack::settle($ref);
    http_response_code(200); exit;
}
$u = require_login(true); require_post(); $b = Response::body();

if ($a === 'quote') {
    [$amt, $cp, $err] = Billing::quote((string)($b['period'] ?? ''), $b['coupon'] ?? null);
    $p = Billing::price((string)($b['period'] ?? ''));
    Response::ok(['amount' => $amt, 'currency' => $p['currency'] ?? 'GHS', 'percent_off' => $cp['percent_off'] ?? 0, 'coupon_error' => $err]);
}
if ($a === 'checkout') {
    $period = Validator::in($b['period'] ?? '', ['month', 'semester', 'year']); if (!$period) Response::error('Choose a billing option.');
    [$amt, $cp, $err] = Billing::quote($period, $b['coupon'] ?? null); if ($err) Response::error($err);
    $p = Billing::price($period);
    $ref = 'PD' . strtoupper(bin2hex(random_bytes(6)));
    q("INSERT INTO transactions(school_id,user_id,coupon_id,gateway_ref,amount,currency,status,period) VALUES (?,?,?,?,?,?,'pending',?)", [(int)$u['school_id'] ?: null, $u['id'], $cp['id'] ?? null, $ref, $amt, $p['currency'], $period]);
    Audit::log("started checkout $ref ($period)", 'transaction', (int)db()->lastInsertId());
    if (Paystack::enabled()) {
        try { $init = Paystack::initialize((string)$u['email'], $amt, $p['currency'], $ref, abs_url('pay/callback.php'), ['user_id' => (int)$u['id'], 'period' => $period]); }
        catch (RuntimeException $e) { q("UPDATE transactions SET status='failed' WHERE gateway_ref=?", [$ref]); Response::error('Payment could not be started: ' . $e->getMessage(), 502); }
        Response::ok(['reference' => $ref, 'amount' => $amt, 'currency' => $p['currency'], 'authorization_url' => $init['authorization_url'] ?? '', 'message' => 'Taking you to Paystack…']);
    }
    Response::ok(['reference' => $ref, 'amount' => $amt, 'currency' => $p['currency'], 'dev' => $GLOBALS['cfg']['debug'],
        'message' => 'Payment started. Pay using the details your school gave you and quote the reference ' . $ref . '. Your Plus turns on as soon as it is confirmed.']);
}
if ($a === 'verify') {                                          // "I have paid, check now"
    $ref = (string)($b['reference'] ?? ''); $own = row('SELECT id FROM transactions WHERE gateway_ref=? AND user_id=?', [$ref, $u['id']]);
    if (!$own) Response::error('Payment not found.', 404);
    if (!Paystack::enabled()) Response::error('Online verification is not set up. An admin will confirm your payment.');
    [$ok, $msg] = Paystack::settle($ref); Response::ok(['paid' => $ok, 'message' => $msg]);
}
if ($a === 'abandon') { q("UPDATE transactions SET status='failed' WHERE gateway_ref=? AND user_id=? AND status='pending'", [(string)($b['reference'] ?? ''), $u['id']]); Response::ok(); }
if ($a === 'simulate') {                                        // development only
    if (!$GLOBALS['cfg']['debug'] || Paystack::enabled()) Response::error('Not available.', 403);
    $t = row("SELECT id FROM transactions WHERE gateway_ref=? AND user_id=? AND status='pending'", [(string)($b['reference'] ?? ''), $u['id']]);
    if (!$t || !Billing::complete((int)$t['id'])) Response::error('Payment not found.', 404);
    Response::ok();
}
if ($a === 'cancel') { Billing::cancel((int)$u['id']); Audit::log('cancelled renewal', 'user', (int)$u['id']); Response::ok(); }
Response::error('Unknown action.', 404);
