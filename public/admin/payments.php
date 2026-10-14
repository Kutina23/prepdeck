<?php
require __DIR__ . '/../../app/bootstrap.php';
$u = require_perm('plans'); $adminArea = true; $active = 'payments'; $title = 'Payments'; $scripts = ['forms.js'];
if (is_post()) {
    form_guard(); $a = $_POST['a'] ?? ''; $id = (int)($_POST['id'] ?? 0);
    // transactions and subscriptions of this school only; a school edits its own coupons, never the platform-wide ones
    $own = in_array($a, ['paid', 'verify', 'fail', 'refund'], true) ? in_my_school('transactions', $id) : ($a === 'sub_end' ? in_my_school('subscriptions', $id) : (in_array($a, ['cp_toggle', 'cp_delete'], true) ? in_my_school('coupons', $id) : true));
    if (!$own) { flash('bad', 'Not found in this school.'); redirect_self_or_ajax(); }
    if ($a === 'paid') { if (Billing::complete($id)) flash('ok', 'Payment confirmed and Plus activated.'); else flash('bad', 'That payment is not pending.'); }
    elseif ($a === 'verify') { $t = row('SELECT gateway_ref FROM transactions WHERE id=?', [$id]); if ($t && Paystack::enabled()) { [$ok, $msg] = Paystack::settle($t['gateway_ref']); flash($ok ? 'ok' : 'bad', $msg); } else flash('bad', 'Paystack is not configured.'); }
    elseif ($a === 'fail') { q("UPDATE transactions SET status='failed' WHERE id=? AND status='pending'", [$id]); Audit::log("marked payment $id failed", 'transaction', $id); flash('ok', 'Payment marked failed.'); }
    elseif ($a === 'refund') { $t = row("SELECT * FROM transactions WHERE id=? AND status='paid'", [$id]); if ($t) { q("UPDATE transactions SET status='refunded', refunded_at=NOW() WHERE id=?", [$id]); if (!empty($_POST['revoke'])) Billing::revoke((int)$t['user_id']); Audit::log("refunded payment $id", 'transaction', $id); flash('ok', 'Marked refunded' . (!empty($_POST['revoke']) ? ' and Plus removed.' : '. Plus stays until it ends.')); } }
    elseif ($a === 'sub_end') { $s = row('SELECT user_id FROM subscriptions WHERE id=?', [$id]); if ($s) { Billing::revoke((int)$s['user_id']); Audit::log("ended subscription $id", 'subscription', $id); flash('ok', 'Subscription ended.'); } }
    elseif ($a === 'cp_add') { $code = strtoupper(preg_replace('/[^A-Za-z0-9_-]/', '', (string)($_POST['code'] ?? ''))); $pc = (int)($_POST['percent_off'] ?? 0);
        if (strlen($code) < 3 || $pc < 1 || $pc > 100) flash('bad', 'Use a code of 3+ characters and a discount from 1 to 100.');
        else try { q('INSERT INTO coupons(school_id,code,percent_off,max_uses,expires_at,is_active) VALUES (?,?,?,?,?,1)', [sid(), $code, $pc, ((int)($_POST['max_uses'] ?? 0)) ?: null, $_POST['expires_at'] ?: null]); Audit::log("created coupon $code", 'coupon'); flash('ok', 'Coupon created.'); } catch (PDOException) { flash('bad', 'That code already exists.'); } }
    elseif ($a === 'cp_toggle') { q('UPDATE coupons SET is_active=1-is_active WHERE id=?', [$id]); flash('ok', 'Coupon updated.'); }
    elseif ($a === 'cp_delete') { q('DELETE FROM coupons WHERE id=?', [$id]); flash('ok', 'Coupon deleted.'); }
    redirect_self_or_ajax();
}
$tab = $_GET['tab'] ?? 'txn'; $st = $_GET['status'] ?? ''; $q = trim((string)($_GET['q'] ?? '')); $per = per_page(15); $page = page_no();
$act = 'admin/payments.php';
$sum = row("SELECT COALESCE(SUM(CASE WHEN status='paid' THEN amount END),0) paid, COALESCE(SUM(CASE WHEN status='paid' AND created_at>=DATE_FORMAT(NOW(),'%Y-%m-01') THEN amount END),0) month, SUM(status='pending') pend, COALESCE(SUM(CASE WHEN status='refunded' THEN amount END),0) ref FROM transactions WHERE " . in_school());
$active_subs = (int)val("SELECT COUNT(*) FROM subscriptions WHERE status IN ('active','cancelled') AND ends_at>NOW() AND " . in_school());
include ROOT . '/includes/dashboard_header.php';
echo page_head('Payments', 'Confirm payments, manage subscriptions and coupons.');
$search = fn(string $tab, string $ph) => '<form class="toolbar" method="get"><input type="hidden" name="tab" value="' . $tab . '">' . ($tab === 'txn' ? '<input type="hidden" name="status" value="' . e($st) . '">' : '') . '<label class="search">' . ic('search', 'i') . '<span class="vh">Search</span><input name="q" value="' . e($q) . '" type="search" placeholder="' . e($ph) . '"></label><button class="btn sm">Search</button></form>';
?>
<section class="kpis"><div class="card kpi"><b><?= e(number_format($sum['month'], 2)) ?></b><small>Paid this month</small></div><div class="card kpi"><b><?= e(number_format($sum['paid'], 2)) ?></b><small>Paid in total</small></div><div class="card kpi"><b style="color:<?= $sum['pend'] ? 'var(--bad)' : 'inherit' ?>"><?= (int)$sum['pend'] ?></b><small>Waiting to confirm</small></div><div class="card kpi"><b><?= $active_subs ?></b><small>Active subscriptions</small></div></section>
<div class="tabs" role="navigation"><?php foreach (['txn' => 'Transactions', 'subs' => 'Subscriptions', 'coupons' => 'Coupons'] as $k => $l): ?><a class="tab" href="?tab=<?= $k ?>" aria-selected="<?= $tab === $k ? 'true' : 'false' ?>"><?= $l ?></a><?php endforeach; ?></div>
<?php if ($tab === 'txn'):
    $w = [in_school('t')]; $a = []; if (in_array($st, ['paid', 'pending', 'failed', 'refunded'], true)) { $w[] = 't.status=?'; $a[] = $st; }
    if ($q !== '') { $w[] = '(u.full_name LIKE ? OR u.email LIKE ? OR t.gateway_ref LIKE ?)'; array_push($a, "%$q%", "%$q%", "%$q%"); }
    $W = implode(' AND ', $w); $total = (int)val("SELECT COUNT(*) FROM transactions t JOIN users u ON u.id=t.user_id WHERE $W", $a);
    $list = rows("SELECT t.*,u.full_name,u.email FROM transactions t JOIN users u ON u.id=t.user_id WHERE $W ORDER BY t.id DESC LIMIT $per OFFSET " . (($page - 1) * $per), $a); ?>
<section class="card dt">
  <?= dt_head('Transactions', Paystack::enabled() ? 'Paystack is connected. Payments confirm automatically (callback and webhook).' : 'Paystack is not connected. Add PAY_PROVIDER=paystack and PAY_SECRET_KEY to .env, or confirm payments by hand.') ?>
  <div class="tabs"><?php foreach (['' => 'All', 'pending' => 'Pending', 'paid' => 'Paid', 'failed' => 'Failed', 'refunded' => 'Refunded'] as $k => $l): ?><a class="tab" href="?tab=txn&status=<?= $k ?>" aria-selected="<?= $st === $k ? 'true' : 'false' ?>"><?= $l ?></a><?php endforeach; ?></div>
  <?= $search('txn', 'Member, email or reference') ?>
  <div class="dtwrap"><table class="dtable"><thead><tr><th>Member</th><th class="num">Amount</th><th>Plan</th><th>Reference</th><th>Date</th><th>Status</th><th data-nosort><span class="vh">Actions</span></th></tr></thead><tbody>
  <?php foreach ($list as $t): ?><tr>
    <td class="dmain"><span class="dtt"><?= e($t['full_name']) ?></span><span class="ts"><?= e($t['email']) ?></span></td>
    <td class="num" data-v="<?= e($t['amount']) ?>"><b><?= e(money($t['amount'], $t['currency'])) ?></b></td><td><?= e(Billing::LABEL[$t['period']] ?? 'Plus') ?></td>
    <td class="nw"><span class="code"><?= e($t['gateway_ref']) ?></span></td><td class="nw muted" data-v="<?= e($t['created_at']) ?>"><?= e(fdate($t['created_at'], 'j M Y H:i')) ?></td><td><?= status_pill($t['status']) ?></td>
    <td class="act"><div class="rowact">
      <?php if ($t['status'] === 'pending'): ?>
        <?php if (Paystack::enabled()) echo row_form($act, ['a' => 'verify', 'id' => $t['id']], 'Check with Paystack', 'btn sm line'); ?>
        <?= row_form($act, ['a' => 'paid', 'id' => $t['id']], 'Mark as paid', 'btn sm', 'Confirm you received this payment? Plus turns on for this member.') ?>
        <?= row_form($act, ['a' => 'fail', 'id' => $t['id']], 'Mark failed', 'btn sm line', 'Mark this payment as failed?') ?>
      <?php elseif ($t['status'] === 'paid'): ?><?= modal_btn('tpl-ref' . $t['id'], 'Refund payment', 'Refund', 'btn sm danger') ?>
      <template id="tpl-ref<?= $t['id'] ?>">
      <form class="card mform" method="post" data-action="<?= $act ?>"><?= csrf_field() ?><input type="hidden" name="a" value="refund"><input type="hidden" name="id" value="<?= $t['id'] ?>"><h2>Refund</h2>
        <p><b><?= e(money($t['amount'], $t['currency'])) ?></b> from <?= e($t['full_name']) ?> &middot; ref <span class="code"><?= e($t['gateway_ref']) ?></span></p>
        <p class="note">This records the refund in PrepDeck. Send the money back in your Paystack dashboard or by hand.</p>
        <label class="chk"><input type="checkbox" name="revoke" value="1">Remove Plus from this member now</label>
        <button class="btn" type="submit">Mark refunded</button></form>
      </template>
      <?php else: ?><span class="muted">&ndash;</span><?php endif; ?></div></td></tr>
  <?php endforeach; if (!$list) echo dt_empty(7, 'No transactions.'); ?></tbody></table></div>
  <?= dt_foot($total, $per, $page, ['tab' => 'txn', 'status' => $st, 'q' => $q]) ?>
</section>
<?php elseif ($tab === 'subs'):
    $w = in_school('s'); $a = []; if ($q !== '') { $w .= ' AND (u.full_name LIKE ? OR u.email LIKE ?)'; $a = ["%$q%", "%$q%"]; }
    $total = (int)val("SELECT COUNT(*) FROM subscriptions s JOIN users u ON u.id=s.user_id WHERE $w", $a);
    $list = rows("SELECT s.*,u.full_name,u.email FROM subscriptions s JOIN users u ON u.id=s.user_id WHERE $w ORDER BY s.id DESC LIMIT $per OFFSET " . (($page - 1) * $per), $a); ?>
<section class="card dt">
  <?= dt_head('Subscriptions', number_format($total) . ' in this view') ?>
  <?= $search('subs', 'Member or email') ?>
  <div class="dtwrap"><table class="dtable"><thead><tr><th>Member</th><th>Plan</th><th>Starts</th><th>Ends</th><th>Status</th><th data-nosort><span class="vh">Actions</span></th></tr></thead><tbody>
  <?php foreach ($list as $s): ?><tr>
    <td class="dmain"><a class="dtt" href="<?= e(url('admin/users.php?id=' . $s['user_id'])) ?>"><?= e($s['full_name']) ?></a><span class="ts"><?= e($s['email']) ?></span></td><td><?= e(Billing::LABEL[$s['period']] ?? $s['period']) ?></td>
    <td class="nw" data-v="<?= e($s['starts_at']) ?>"><?= e(fdate($s['starts_at'])) ?></td><td class="nw" data-v="<?= e($s['ends_at']) ?>"><?= e(fdate($s['ends_at'])) ?></td><td><?= status_pill($s['status']) ?></td>
    <td class="act"><div class="rowact"><?php if (in_array($s['status'], ['active', 'cancelled'], true)) echo row_form($act, ['a' => 'sub_end', 'id' => $s['id']], 'End now', 'btn sm danger', 'End this subscription now? The member goes back to Free.'); ?></div></td></tr>
  <?php endforeach; if (!$list) echo dt_empty(6, 'No subscriptions yet.'); ?></tbody></table></div>
  <?= dt_foot($total, $per, $page, ['tab' => 'subs', 'q' => $q]) ?>
</section>
<?php else:
    $w = '(' . in_school() . ' OR school_id IS NULL)'; $a = []; if ($q !== '') { $w .= ' AND code LIKE ?'; $a = ["%$q%"]; }
    $total = (int)val("SELECT COUNT(*) FROM coupons WHERE $w", $a); $cps = rows("SELECT * FROM coupons WHERE $w ORDER BY id DESC LIMIT $per OFFSET " . (($page - 1) * $per), $a); ?>
<section class="card dt">
  <?= dt_head('Coupons', 'Students enter these at checkout.', modal_btn('tpl-cp-add', 'New coupon', 'New coupon', 'btn sm', 'plus')) ?>
  <?= $search('coupons', 'Coupon code') ?>
  <div class="dtwrap"><table class="dtable"><thead><tr><th>Code</th><th class="num">Discount</th><th class="num">Used</th><th>Expires</th><th>Status</th><th data-nosort><span class="vh">Actions</span></th></tr></thead><tbody>
  <?php foreach ($cps as $c): ?><tr>
    <td class="dmain"><span class="code"><?= e($c['code']) ?></span></td><td class="num"><?= (int)$c['percent_off'] ?>%</td>
    <td class="num"><?= (int)$c['used_count'] ?><?= $c['max_uses'] ? ' / ' . (int)$c['max_uses'] : '' ?></td>
    <td class="nw" data-v="<?= e((string)$c['expires_at']) ?>"><?= $c['expires_at'] ? e(fdate($c['expires_at'])) : 'No expiry' ?></td><td><?= status_pill($c['is_active'] ? 'active' : 'hidden') ?></td>
    <td class="act"><div class="rowact"><?php if ($c['school_id'] === null): ?><span class="pill free">Platform-wide</span><?php else: ?><?= row_form($act, ['a' => 'cp_toggle', 'id' => $c['id']], $c['is_active'] ? 'Turn off' : 'Turn on', 'btn sm line') ?><?= row_form($act, ['a' => 'cp_delete', 'id' => $c['id']], 'Delete', 'btn sm danger', 'Delete this coupon?') ?><?php endif; ?></div></td></tr>
  <?php endforeach; if (!$cps) echo dt_empty(6, 'No coupons yet.'); ?></tbody></table></div>
  <?= dt_foot($total, $per, $page, ['tab' => 'coupons', 'q' => $q]) ?>
</section>
<template id="tpl-cp-add">
<form class="card mform" method="post" data-action="<?= $act ?>"><?= csrf_field() ?><input type="hidden" name="a" value="cp_add"><h2>New coupon</h2><div class="f2"><div class="field"><label for="cc">Code</label><input class="in" id="cc" name="code" placeholder="WELCOME20" required style="text-transform:uppercase"></div><div class="field"><label for="cpc">Percent off</label><input class="in" id="cpc" type="number" name="percent_off" min="1" max="100" required></div></div>
  <div class="f2"><div class="field"><label for="cm">Max uses (blank = unlimited)</label><input class="in" id="cm" type="number" name="max_uses" min="1"></div><div class="field"><label for="ce">Expires</label><input class="in" id="ce" type="date" name="expires_at"></div></div><button class="btn">Create coupon</button></form>
</template>
<?php endif; ?>
<?php include ROOT . '/includes/dashboard_footer.php'; ?>
