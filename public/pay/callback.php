<?php
// Paystack sends the student back here after paying. We never trust the redirect: the payment is re-checked with Paystack.
require __DIR__ . '/../../app/bootstrap.php';
$u = require_login();
$ref = (string)($_GET['reference'] ?? $_GET['trxref'] ?? '');
$own = $ref !== '' ? row('SELECT id FROM transactions WHERE gateway_ref=? AND user_id=?', [$ref, $u['id']]) : null;
if (!$own) { flash('bad', 'We could not find that payment.'); redirect('dashboard/plan.php'); }
[$ok, $msg] = Paystack::settle($ref);
flash($ok ? 'ok' : 'wait', $msg);
redirect('dashboard/plan.php');
