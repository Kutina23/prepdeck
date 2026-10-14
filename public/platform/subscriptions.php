<?php
// Platform owner: money across every school. Student Plus subscriptions and payments, school licences,
// the default Plus price list (and which schools have their own) and platform-wide coupons.
require __DIR__ . '/../../app/bootstrap.php';
$u = require_super(); $platformArea = true; $active = 'p-subs'; $title = 'Subscriptions'; $scripts = ['forms.js'];
$act = 'platform/subscriptions.php';
if (is_post()) {
    form_guard(); $a = $_POST['a'] ?? ''; $id = (int)($_POST['id'] ?? 0);
    if ($a === 'sub_extend') { $s = row('SELECT user_id FROM subscriptions WHERE id=?', [$id]); $p = Validator::in($_POST['period'] ?? '', ['month', 'semester', 'year']) ?? 'month';
        if ($s) { $end = Billing::activate((int)$s['user_id'], $p); Audit::log("extended Plus of user {$s['user_id']} by $p", 'subscription', $id); flash('ok', 'Extended until ' . fdate($end) . '.'); } }
    elseif ($a === 'sub_end') { $s = row('SELECT user_id FROM subscriptions WHERE id=?', [$id]); if ($s) { Billing::revoke((int)$s['user_id']); Audit::log("ended subscription $id", 'subscription', $id); flash('ok', 'Subscription ended.'); } }
    elseif ($a === 'paid') { $ok = Billing::complete($id); flash($ok ? 'ok' : 'bad', $ok ? 'Payment confirmed and Plus activated.' : 'That payment is not pending.'); }
    elseif ($a === 'refund') { $t = row("SELECT * FROM transactions WHERE id=? AND status='paid'", [$id]); if ($t) { q("UPDATE transactions SET status='refunded', refunded_at=NOW() WHERE id=?", [$id]); if (!empty($_POST['revoke'])) Billing::revoke((int)$t['user_id']); Audit::log("refunded payment $id", 'transaction', $id); flash('ok', 'Marked refunded.'); } }
    elseif ($a === 'licence') {
        q('UPDATE schools SET plan=?, plan_expires=?, max_students=? WHERE id=?', [Validator::in($_POST['plan'] ?? '', ['trial', 'basic', 'standard', 'premium']) ?? 'basic', ($_POST['plan_expires'] ?? '') ?: null, ((int)($_POST['max_students'] ?? 0)) ?: null, $id]);
        Audit::log("changed licence of school $id", 'school', $id); flash('ok', 'Licence saved.');
    } elseif ($a === 'licence_extend') {
        $m = max(1, min(36, (int)($_POST['months'] ?? 12)));
        q('UPDATE schools SET plan_expires=DATE_ADD(GREATEST(COALESCE(plan_expires,CURDATE()),CURDATE()), INTERVAL ? MONTH) WHERE id=?', [$m, $id]); Audit::log("extended licence of school $id by $m months", 'school', $id); flash('ok', 'Licence extended.');
    } elseif ($a === 'prices') {
        foreach (['month', 'semester', 'year'] as $p) { $amt = (float)($_POST['amount'][$p] ?? 0); if ($amt < 0) continue;
            q('INSERT INTO plan_prices(school_id,plan_id,period,amount,currency,is_enabled) VALUES (0,2,?,?,?,?) ON DUPLICATE KEY UPDATE amount=VALUES(amount),currency=VALUES(currency),is_enabled=VALUES(is_enabled)', [$p, $amt, strtoupper(substr(trim($_POST['currency'] ?? 'GHS'), 0, 3)) ?: 'GHS', isset($_POST['enabled'][$p]) ? 1 : 0]); }
        Audit::log('updated the default Plus prices', 'plan', 2); flash('ok', 'Default prices saved. Schools without their own price list use them.');
    } elseif ($a === 'prices_reset') { q('DELETE FROM plan_prices WHERE school_id=? AND school_id<>0', [$id]); Audit::log("school $id back to default prices", 'plan', 2); flash('ok', 'That school uses the default prices again.'); }
    elseif ($a === 'cp_add') { $code = strtoupper(preg_replace('/[^A-Za-z0-9_-]/', '', (string)($_POST['code'] ?? ''))); $pc = (int)($_POST['percent_off'] ?? 0); $cs = (int)($_POST['school_id'] ?? 0) ?: null;
        if (strlen($code) < 3 || $pc < 1 || $pc > 100) flash('bad', 'Use a code of 3+ characters and a discount from 1 to 100.');
        else try { q('INSERT INTO coupons(school_id,code,percent_off,max_uses,expires_at,is_active) VALUES (?,?,?,?,?,1)', [$cs, $code, $pc, ((int)($_POST['max_uses'] ?? 0)) ?: null, ($_POST['expires_at'] ?? '') ?: null]); Audit::log("created coupon $code", 'coupon'); flash('ok', 'Coupon created.'); } catch (PDOException) { flash('bad', 'That code already exists.'); } }
    elseif ($a === 'cp_toggle') { q('UPDATE coupons SET is_active=1-is_active WHERE id=?', [$id]); flash('ok', 'Coupon updated.'); }
    elseif ($a === 'cp_delete') { q('DELETE FROM coupons WHERE id=?', [$id]); flash('ok', 'Coupon deleted.'); }
    redirect_self_or_ajax();
}
$tab = Validator::in($_GET['tab'] ?? 'subs', ['subs', 'txn', 'licences', 'prices', 'coupons']) ?? 'subs';
$q = trim((string)($_GET['q'] ?? '')); $sf = (int)($_GET['school'] ?? 0); $st = (string)($_GET['status'] ?? ''); $per = per_page(20); $page = page_no();
$schools = rows('SELECT id,name n FROM schools WHERE EXISTS (SELECT 1 FROM users x WHERE x.school_id=schools.id) OR is_active=1 ORDER BY name');
$sum = row("SELECT COALESCE(SUM(CASE WHEN status='paid' AND created_at>=DATE_FORMAT(NOW(),'%Y-%m-01') THEN amount END),0) month, COALESCE(SUM(CASE WHEN status='paid' THEN amount END),0) paid, SUM(status='pending') pend FROM transactions");
$subsN = (int)val("SELECT COUNT(*) FROM subscriptions WHERE status IN ('active','cancelled') AND ends_at>NOW()");
$licEnd = (int)val('SELECT COUNT(*) FROM schools WHERE is_active=1 AND plan_expires IS NOT NULL AND plan_expires<CURDATE()');
include ROOT . '/includes/dashboard_header.php';
echo page_head('Subscriptions', 'Plus subscriptions and payments in every school, school licences, prices and coupons.');
?>
<section class="kpis"><div class="card kpi"><b><?= e(number_format((float)$sum['month'], 2)) ?></b><small>Paid this month, all schools</small></div><div class="card kpi"><b><?= e(number_format((float)$sum['paid'], 2)) ?></b><small>Paid in total</small></div>
  <div class="card kpi"><b><?= number_format($subsN) ?></b><small>Active Plus subscriptions</small></div><div class="card kpi"><b style="color:<?= $licEnd ? 'var(--bad)' : 'inherit' ?>"><?= $licEnd ?></b><small>School licences ended</small></div></section>
<div class="tabs" role="navigation"><?php foreach (['subs' => 'Plus subscriptions', 'txn' => 'Payments', 'licences' => 'School licences', 'prices' => 'Prices', 'coupons' => 'Coupons'] as $k => $l): ?><a class="tab" href="?tab=<?= $k ?>" aria-selected="<?= $tab === $k ? 'true' : 'false' ?>"><?= $l ?></a><?php endforeach; ?></div>
<?php
$filter = function (string $tab, array $statuses) use ($q, $sf, $st, $schools) {
    $o = '<form class="toolbar" method="get"><input type="hidden" name="tab" value="' . $tab . '"><label class="search">' . ic('search', 'i') . '<span class="vh">Search</span><input name="q" value="' . e($q) . '" type="search" placeholder="Name, email or reference"></label>'
        . '<select class="in" name="school" aria-label="School">' . options($schools, $sf, 'id', 'n', 'All schools') . '</select>';
    if ($statuses) { $o .= '<select class="in" name="status" aria-label="Status"><option value="">Any status</option>'; foreach ($statuses as $s) $o .= '<option value="' . $s . '"' . ($st === $s ? ' selected' : '') . '>' . ucfirst($s) . '</option>'; $o .= '</select>'; }
    return $o . '<button class="btn sm">Filter</button></form>';
};
if ($tab === 'subs'):
    $w = ['1=1']; $a = []; if ($q !== '') { $w[] = '(u.full_name LIKE ? OR u.email LIKE ?)'; array_push($a, "%$q%", "%$q%"); } if ($sf) { $w[] = 's.school_id=?'; $a[] = $sf; }
    if (in_array($st, ['active', 'cancelled', 'expired', 'past_due'], true)) { $w[] = 's.status=?'; $a[] = $st; } $W = implode(' AND ', $w);
    $total = (int)val("SELECT COUNT(*) FROM subscriptions s JOIN users u ON u.id=s.user_id WHERE $W", $a);
    $list = rows("SELECT s.*,u.full_name,u.email,sc.short_name,sc.name school FROM subscriptions s JOIN users u ON u.id=s.user_id LEFT JOIN schools sc ON sc.id=s.school_id WHERE $W ORDER BY s.id DESC LIMIT $per OFFSET " . (($page - 1) * $per), $a); ?>
<section class="card dt"><?= dt_head('Plus subscriptions', number_format($total) . ' in this view') ?><?= $filter('subs', ['active', 'cancelled', 'expired']) ?>
  <div class="dtwrap"><table class="dtable"><thead><tr><th>Member</th><th>School</th><th>Plan</th><th>Starts</th><th>Ends</th><th>Status</th><th data-nosort><span class="vh">Actions</span></th></tr></thead><tbody>
  <?php foreach ($list as $s): ?><tr><td class="dmain"><a class="dtt" href="<?= e(url('platform/users.php?id=' . $s['user_id'])) ?>"><?= e($s['full_name']) ?></a><span class="ts"><?= e($s['email']) ?></span></td><td><?= e($s['short_name'] ?: ($s['school'] ?: '-')) ?></td>
    <td><?= e(Billing::LABEL[$s['period']] ?? $s['period']) ?></td><td class="nw"><?= e(fdate($s['starts_at'])) ?></td><td class="nw"><?= e(fdate($s['ends_at'])) ?></td><td><?= status_pill($s['status']) ?></td>
    <td class="act"><div class="rowact"><?= modal_btn('tpl-x' . $s['id'], 'Extend Plus', 'Extend', 'btn sm line') ?><?php if (in_array($s['status'], ['active', 'cancelled'], true)) echo row_form($act, ['a' => 'sub_end', 'id' => $s['id']], 'End now', 'btn sm danger', 'End this subscription now?'); ?></div>
      <template id="tpl-x<?= $s['id'] ?>"><form class="card mform" method="post" data-action="<?= $act ?>"><?= csrf_field() ?><input type="hidden" name="a" value="sub_extend"><input type="hidden" name="id" value="<?= $s['id'] ?>"><h2>Extend Plus</h2><p class="note">Adds time on top of what is left. No payment is recorded.</p>
        <div class="field"><label for="xp<?= $s['id'] ?>">Add</label><select class="in" id="xp<?= $s['id'] ?>" name="period"><?php foreach (Billing::LABEL as $k => $l): ?><option value="<?= $k ?>"><?= e($l) ?> (<?= Billing::DAYS[$k] ?> days)</option><?php endforeach; ?></select></div><button class="btn">Extend</button></form></template></td></tr>
  <?php endforeach; if (!$list) echo dt_empty(7, 'No subscriptions.'); ?></tbody></table></div><?= dt_foot($total, $per, $page, ['tab' => 'subs', 'q' => $q, 'school' => $sf ?: '', 'status' => $st]) ?></section>
<?php elseif ($tab === 'txn'):
    $w = ['1=1']; $a = []; if ($q !== '') { $w[] = '(u.full_name LIKE ? OR u.email LIKE ? OR t.gateway_ref LIKE ?)'; array_push($a, "%$q%", "%$q%", "%$q%"); } if ($sf) { $w[] = 't.school_id=?'; $a[] = $sf; }
    if (in_array($st, ['paid', 'pending', 'failed', 'refunded'], true)) { $w[] = 't.status=?'; $a[] = $st; } $W = implode(' AND ', $w);
    $total = (int)val("SELECT COUNT(*) FROM transactions t JOIN users u ON u.id=t.user_id WHERE $W", $a);
    $list = rows("SELECT t.*,u.full_name,u.email,sc.short_name,sc.name school FROM transactions t JOIN users u ON u.id=t.user_id LEFT JOIN schools sc ON sc.id=t.school_id WHERE $W ORDER BY t.id DESC LIMIT $per OFFSET " . (($page - 1) * $per), $a);
    $bySchool = rows("SELECT sc.name, COUNT(*) n, COALESCE(SUM(t.amount),0) amt FROM transactions t LEFT JOIN schools sc ON sc.id=t.school_id WHERE t.status='paid' AND t.created_at>=DATE_FORMAT(NOW(),'%Y-%m-01') GROUP BY t.school_id ORDER BY amt DESC LIMIT 10"); ?>
<section class="card dt"><?= dt_head('Payments', number_format($total) . ' in this view · ' . (int)$sum['pend'] . ' waiting') ?><?= $filter('txn', ['pending', 'paid', 'failed', 'refunded']) ?>
  <div class="dtwrap"><table class="dtable"><thead><tr><th>Member</th><th>School</th><th class="num">Amount</th><th>Reference</th><th>Date</th><th>Status</th><th data-nosort><span class="vh">Actions</span></th></tr></thead><tbody>
  <?php foreach ($list as $t): ?><tr><td class="dmain"><span class="dtt"><?= e($t['full_name']) ?></span><span class="ts"><?= e($t['email']) ?></span></td><td><?= e($t['short_name'] ?: ($t['school'] ?: '-')) ?></td>
    <td class="num"><b><?= e(money($t['amount'], $t['currency'])) ?></b></td><td class="nw"><span class="code"><?= e($t['gateway_ref']) ?></span></td><td class="nw muted"><?= e(fdate($t['created_at'], 'j M Y H:i')) ?></td><td><?= status_pill($t['status']) ?></td>
    <td class="act"><div class="rowact"><?php if ($t['status'] === 'pending') echo row_form($act, ['a' => 'paid', 'id' => $t['id']], 'Mark as paid', 'btn sm', 'Confirm this payment? Plus turns on.');
      elseif ($t['status'] === 'paid') echo row_form($act, ['a' => 'refund', 'id' => $t['id'], 'revoke' => 1], 'Refund + remove Plus', 'btn sm danger', 'Record a refund and remove Plus? Send the money back in Paystack or by hand.'); ?></div></td></tr>
  <?php endforeach; if (!$list) echo dt_empty(7, 'No payments.'); ?></tbody></table></div><?= dt_foot($total, $per, $page, ['tab' => 'txn', 'q' => $q, 'school' => $sf ?: '', 'status' => $st]) ?></section>
<section class="card dt"><?= dt_head('This month by school', 'Paid') ?><div class="dtwrap"><table class="dtable"><thead><tr><th>School</th><th class="num">Payments</th><th class="num">Amount</th></tr></thead><tbody>
  <?php foreach ($bySchool as $b): ?><tr><td class="dmain"><?= e($b['name'] ?: 'No school') ?></td><td class="num"><?= (int)$b['n'] ?></td><td class="num"><?= e(number_format((float)$b['amt'], 2)) ?></td></tr><?php endforeach; if (!$bySchool) echo dt_empty(3, 'Nothing paid this month.'); ?></tbody></table></div></section>
<?php elseif ($tab === 'licences'):
    $w = ['s.is_active=1']; $a = []; if ($q !== '') { $w[] = '(s.name LIKE ? OR s.short_name LIKE ?)'; array_push($a, "%$q%", "%$q%"); }
    if ($st === 'ended') $w[] = 's.plan_expires<CURDATE()'; elseif ($st === 'soon') $w[] = 's.plan_expires BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 30 DAY)'; elseif ($st === 'used') $w[] = 'EXISTS (SELECT 1 FROM users x WHERE x.school_id=s.id)';
    $W = implode(' AND ', $w); $total = (int)val("SELECT COUNT(*) FROM schools s WHERE $W", $a);
    $list = rows("SELECT s.*,(SELECT COUNT(*) FROM users x WHERE x.school_id=s.id AND x.role_id=1) students FROM schools s WHERE $W ORDER BY s.plan_expires IS NULL, s.plan_expires, students DESC, s.name LIMIT $per OFFSET " . (($page - 1) * $per), $a);
    $planOpts = array_map(fn($p) => ['id' => $p, 'n' => ucfirst($p)], ['trial', 'basic', 'standard', 'premium']); ?>
<section class="card dt"><?= dt_head('School licences', 'Each school\'s PrepDeck plan. A school whose licence ended keeps working; switch it off in Schools to block it.') ?>
  <form class="toolbar" method="get"><input type="hidden" name="tab" value="licences"><label class="search"><?= ic('search', 'i') ?><span class="vh">Search</span><input name="q" value="<?= e($q) ?>" type="search" placeholder="School"></label>
    <select class="in" name="status" aria-label="Status"><?php foreach (['' => 'All active schools', 'used' => 'Has accounts', 'soon' => 'Ending in 30 days', 'ended' => 'Ended'] as $k => $l): ?><option value="<?= $k ?>"<?= $st === $k ? ' selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select><button class="btn sm">Filter</button></form>
  <div class="dtwrap"><table class="dtable"><thead><tr><th>School</th><th>Plan</th><th>Ends</th><th class="num">Students</th><th data-nosort><span class="vh">Actions</span></th></tr></thead><tbody>
  <?php foreach ($list as $s): ?><tr><td class="dmain"><a class="dtt" href="<?= e(url('platform/schools.php?id=' . $s['id'])) ?>"><?= e($s['name']) ?></a></td><td><?= e(ucfirst($s['plan'])) ?></td>
    <td class="nw"><?= $s['plan_expires'] ? status_pill(strtotime($s['plan_expires']) < time() ? 'expired' : 'active') . ' ' . e(fdate($s['plan_expires'])) : '<span class="muted">No end</span>' ?></td>
    <td class="num"><?= number_format((int)$s['students']) ?><?= $s['max_students'] ? ' / ' . number_format((int)$s['max_students']) : '' ?></td>
    <td class="act"><div class="rowact"><?= row_form($act, ['a' => 'licence_extend', 'id' => $s['id'], 'months' => 12], '+12 months', 'btn sm line') ?><?= modal_btn('tpl-l' . $s['id'], 'Licence: ' . $s['name'], 'Edit', 'btn sm line', 'edit') ?></div>
      <template id="tpl-l<?= $s['id'] ?>"><form class="card mform" method="post" data-action="<?= $act ?>"><?= csrf_field() ?><input type="hidden" name="a" value="licence"><input type="hidden" name="id" value="<?= $s['id'] ?>"><h2>Licence</h2>
        <div class="f2"><div class="field"><label for="lp<?= $s['id'] ?>">Plan</label><select class="in" id="lp<?= $s['id'] ?>" name="plan"><?= options($planOpts, $s['plan'], 'id', 'n') ?></select></div><div class="field"><label for="le<?= $s['id'] ?>">Ends</label><input class="in" id="le<?= $s['id'] ?>" type="date" name="plan_expires" value="<?= e((string)$s['plan_expires']) ?>"></div></div>
        <div class="field"><label for="lm<?= $s['id'] ?>">Student limit (blank = none)</label><input class="in" id="lm<?= $s['id'] ?>" type="number" min="0" name="max_students" value="<?= e((string)$s['max_students']) ?>"></div><button class="btn">Save</button></form></template></td></tr>
  <?php endforeach; if (!$list) echo dt_empty(5, 'Nothing here.'); ?></tbody></table></div><?= dt_foot($total, $per, $page, ['tab' => 'licences', 'q' => $q, 'status' => $st]) ?></section>
<?php elseif ($tab === 'prices'):
    $prices = array_column(rows('SELECT * FROM plan_prices WHERE plan_id=2 AND school_id=0'), null, 'period'); $cur = $prices ? reset($prices)['currency'] : 'GHS';
    $own = rows("SELECT s.id,s.name,GROUP_CONCAT(CONCAT(p.period,': ',p.amount,' ',p.currency) ORDER BY FIELD(p.period,'month','semester','year') SEPARATOR ' · ') list FROM plan_prices p JOIN schools s ON s.id=p.school_id WHERE p.plan_id=2 AND p.is_enabled=1 GROUP BY s.id,s.name ORDER BY s.name"); ?>
<div class="grid2">
<form class="card dt" method="post" data-action="<?= $act ?>"><?= csrf_field() ?><input type="hidden" name="a" value="prices"><?= dt_head('Default Plus prices', 'Every school without its own price list.', '<button class="btn sm">Save prices</button>') ?>
  <div style="padding:0 18px 18px"><div class="field"><label for="cu">Currency</label><input class="in" id="cu" name="currency" value="<?= e($cur) ?>" maxlength="3" style="width:90px"></div>
  <?php foreach (Billing::LABEL as $p => $l): $r = $prices[$p] ?? ['amount' => 0, 'is_enabled' => 0]; ?><div class="frow"><div class="ft"><b><?= e($l) ?></b><small><?= Billing::DAYS[$p] ?> days</small></div><input class="in" style="width:110px" type="number" step="0.01" min="0" name="amount[<?= $p ?>]" value="<?= e($r['amount']) ?>" aria-label="<?= e($l) ?> price"><label class="chk"><input type="checkbox" name="enabled[<?= $p ?>]" value="1"<?= $r['is_enabled'] ? ' checked' : '' ?>>On</label></div><?php endforeach; ?></div></form>
<section class="card dt"><?= dt_head('Schools with their own prices', 'Set in that school\'s admin area (Plans & access). Turn that off per school in Schools > Switches.') ?>
  <div class="dtwrap"><table class="dtable"><thead><tr><th>School</th><th>Prices</th><th data-nosort><span class="vh">Actions</span></th></tr></thead><tbody>
  <?php foreach ($own as $o): ?><tr><td class="dmain"><span class="dtt"><?= e($o['name']) ?></span></td><td class="muted"><?= e($o['list']) ?></td><td class="act"><?= row_form($act, ['a' => 'prices_reset', 'id' => $o['id']], 'Use defaults', 'btn sm line', 'Drop this school\'s own prices?') ?></td></tr><?php endforeach; if (!$own) echo dt_empty(3, 'Every school uses the defaults.'); ?></tbody></table></div></section>
</div>
<?php else:
    $w = ['1=1']; $a = []; if ($q !== '') { $w[] = 'c.code LIKE ?'; $a[] = "%$q%"; } if ($sf) { $w[] = 'c.school_id=?'; $a[] = $sf; } $W = implode(' AND ', $w);
    $total = (int)val("SELECT COUNT(*) FROM coupons c WHERE $W", $a); $cps = rows("SELECT c.*,s.name school FROM coupons c LEFT JOIN schools s ON s.id=c.school_id WHERE $W ORDER BY c.id DESC LIMIT $per OFFSET " . (($page - 1) * $per), $a); ?>
<section class="card dt"><?= dt_head('Coupons', 'Platform-wide coupons work in every school; school coupons only in theirs.', modal_btn('tpl-cp', 'New coupon', 'New coupon', 'btn sm', 'plus')) ?><?= $filter('coupons', []) ?>
  <div class="dtwrap"><table class="dtable"><thead><tr><th>Code</th><th>Where</th><th class="num">Discount</th><th class="num">Used</th><th>Expires</th><th>Status</th><th data-nosort><span class="vh">Actions</span></th></tr></thead><tbody>
  <?php foreach ($cps as $c): ?><tr><td class="dmain"><span class="code"><?= e($c['code']) ?></span></td><td><?= e($c['school'] ?: 'Every school') ?></td><td class="num"><?= (int)$c['percent_off'] ?>%</td><td class="num"><?= (int)$c['used_count'] ?><?= $c['max_uses'] ? ' / ' . (int)$c['max_uses'] : '' ?></td>
    <td class="nw"><?= $c['expires_at'] ? e(fdate($c['expires_at'])) : 'No expiry' ?></td><td><?= status_pill($c['is_active'] ? 'active' : 'hidden') ?></td>
    <td class="act"><div class="rowact"><?= row_form($act, ['a' => 'cp_toggle', 'id' => $c['id']], $c['is_active'] ? 'Turn off' : 'Turn on', 'btn sm line') ?><?= row_form($act, ['a' => 'cp_delete', 'id' => $c['id']], 'Delete', 'btn sm danger', 'Delete this coupon?') ?></div></td></tr>
  <?php endforeach; if (!$cps) echo dt_empty(7, 'No coupons.'); ?></tbody></table></div><?= dt_foot($total, $per, $page, ['tab' => 'coupons', 'q' => $q, 'school' => $sf ?: '']) ?></section>
<template id="tpl-cp"><form class="card mform" method="post" data-action="<?= $act ?>"><?= csrf_field() ?><input type="hidden" name="a" value="cp_add"><h2>New coupon</h2>
  <div class="f2"><div class="field"><label for="cc">Code</label><input class="in" id="cc" name="code" required style="text-transform:uppercase"></div><div class="field"><label for="cpc">Percent off</label><input class="in" id="cpc" type="number" name="percent_off" min="1" max="100" required></div></div>
  <div class="field"><label for="cs">Works in</label><select class="in" id="cs" name="school_id"><?= options($schools, null, 'id', 'n', 'Every school') ?></select></div>
  <div class="f2"><div class="field"><label for="cm">Max uses (blank = unlimited)</label><input class="in" id="cm" type="number" name="max_uses" min="1"></div><div class="field"><label for="ce">Expires</label><input class="in" id="ce" type="date" name="expires_at"></div></div><button class="btn">Create coupon</button></form></template>
<?php endif; ?>
<?php include ROOT . '/includes/dashboard_footer.php'; ?>
