<?php
require __DIR__ . '/../../app/bootstrap.php';
$u = require_login(); $plus = is_plus($u); $uid = (int)$u['id'];
$active = 'plan'; $title = 'Plan & billing'; $scripts = ['forms.js', 'plan.js'];
$prices = Billing::prices(); $sub = row("SELECT * FROM subscriptions WHERE user_id=? AND status IN ('active','cancelled') ORDER BY id DESC LIMIT 1", [$uid]);
$tx = rows('SELECT * FROM transactions WHERE user_id=? ORDER BY id DESC LIMIT 10', [$uid]);
$pending = row("SELECT * FROM transactions WHERE user_id=? AND status='pending' ORDER BY id DESC LIMIT 1", [$uid]);
$perks = ['PrepDeck AI study partner', 'Solved answers on every past question', 'Study guides and predicted exam questions', 'Record lectures with transcripts', 'Unlimited note uploads, any length', 'Every game, your own decks and progress tracking', 'Focus timer up to ' . (int)setting('plus_max_timer', 180) . ' minutes', 'Unlimited notes'];
$sel = $_GET['period'] ?? ($prices[0]['period'] ?? 'month');
include ROOT . '/includes/dashboard_header.php';
echo page_head('Plan & billing', $plus ? 'You have PrepDeck Plus.' : 'Free plan. Upgrade when you want the full toolkit.');
if (is_owner($u)) echo '<div class="banner">Admin accounts have every Plus feature included.</div>';
?>
<div class="grid2">
  <section class="plcard<?= $plus ? ' plus' : '' ?>"><div class="top2"><h2><?= $plus ? 'Plus' : 'Free' ?></h2><span class="pill <?= $plus ? 'ok' : 'free' ?>"><?= $plus ? 'Active' : 'Current plan' ?></span></div>
    <?php if ($plus && $u['plus_expires']): ?><p>Plus until <b><?= e(fdate($u['plus_expires'])) ?></b><?= $sub && $sub['status'] === 'cancelled' ? ' (not renewing)' : '' ?>.</p><?php endif; ?>
    <ul class="stack" style="padding-left:18px;margin:0"><?php foreach ($perks as $p): ?><li><?= e($p) ?></li><?php endforeach; ?></ul>
    <?php if ($plus && $sub && $sub['status'] === 'active'): ?><button class="btn danger sm" data-api="payments.php?a=cancel" data-confirm="Stop renewing? You keep Plus until it ends.">Stop renewing</button><?php endif; ?>
  </section>
  <section class="card stack" id="checkout" data-plus="<?= $plus ? 1 : 0 ?>">
    <h2><?= $plus ? 'Add more time' : 'Get Plus' ?></h2><p class="note">Extra time is added on top of what you have left.</p>
    <?php if (!$prices): echo empty_state('Plus is not on sale right now.'); else: ?>
    <div class="pbill"><div class="seg" role="group" aria-label="Billing period">
      <?php foreach ($prices as $p): ?><button type="button" data-period="<?= e($p['period']) ?>" data-amount="<?= e($p['amount']) ?>" data-cur="<?= e($p['currency']) ?>" aria-pressed="<?= $p['period'] === $sel ? 'true' : 'false' ?>"><?= e(Billing::LABEL[$p['period']]) ?></button><?php endforeach; ?></div></div>
    <div class="pprice" id="pp"></div>
    <div class="field"><label for="cp">Coupon code</label><div class="inline" style="flex-wrap:nowrap"><input class="in" id="cp" autocomplete="off" style="text-transform:uppercase"><button class="btn sm line" type="button" id="cpb">Apply</button></div><span class="hint note" id="cpm"></span></div>
    <button class="btn" id="pay" type="button">Continue to payment</button>
    <div class="banner" id="paymsg" hidden></div>
    <?php endif; ?>
    <?php if ($pending): ?><div class="banner"><b>Payment <?= e($pending['gateway_ref']) ?> is waiting</b> &middot; <?= e(money($pending['amount'], $pending['currency'])) ?>.
      <?php if (Paystack::enabled()): ?> If you already paid on Paystack, press check. Otherwise start again.<div class="rowact" style="margin-top:8px"><button class="btn sm" id="vfy" data-ref="<?= e($pending['gateway_ref']) ?>">I have paid, check now</button><button class="btn sm line" data-api="payments.php?a=abandon" data-body='{"reference":"<?= e($pending['gateway_ref']) ?>"}'>Cancel this payment</button></div><p class="note" id="vfym"></p>
      <?php else: ?> Plus turns on as soon as it is confirmed.
      <?php if ($GLOBALS['cfg']['debug']): ?><br><button class="btn sm" data-api="payments.php?a=simulate" data-body='{"reference":"<?= e($pending['gateway_ref']) ?>"}'>Simulate payment (development)</button><?php endif; endif; ?></div><?php endif; ?>
  </section>
</div>
<?php if ($tx): ?><section class="card dt"><?= dt_head('Payments', 'Your last 10 payments') ?>
  <div class="dtwrap"><table class="dtable"><thead><tr><th class="num">Amount</th><th>Plan</th><th>Reference</th><th>Date</th><th>Status</th></tr></thead><tbody>
  <?php foreach ($tx as $t): ?><tr><td class="num dmain" data-v="<?= e($t['amount']) ?>"><b><?= e(money($t['amount'], $t['currency'])) ?></b></td><td><?= e(Billing::LABEL[$t['period']] ?? 'Plus') ?></td><td class="nw"><span class="code"><?= e($t['gateway_ref']) ?></span></td><td class="nw muted" data-v="<?= e($t['created_at']) ?>"><?= e(fdate($t['created_at'])) ?></td><td><?= status_pill($t['status']) ?></td></tr><?php endforeach; ?>
  </tbody></table></div></section><?php endif; ?>
<?php include ROOT . '/includes/dashboard_footer.php'; ?>
