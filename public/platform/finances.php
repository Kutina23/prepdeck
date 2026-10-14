<?php
// Platform owner: the money side of every school. Plus revenue and how it is changing, recurring revenue, conversion,
// refunds and pending payments, side by side per school, with a drill-down for one school (?school=<id>) and a CSV export.
require __DIR__ . '/../../app/bootstrap.php';
$u = require_super(); $platformArea = true; $active = 'p-fin'; $title = 'Finances'; $scripts = [];
Billing::sweep();
$ranges = [30 => '30 days', 90 => '90 days', 365 => '12 months'];
$days = (int)($_GET['days'] ?? 30); if (!isset($ranges[$days])) $days = 30;
$cur = Stats::currency(); $m = fn($v) => money((float)$v, $cur);
$rec = Stats::recurring();
$sid = (int)($_GET['school'] ?? 0);
$W0 = Stats::win('t.created_at', $days); $W1 = Stats::win('t.created_at', $days, 1);

/* ---------- one school ---------- */
if ($sid) {
    $s = row('SELECT * FROM schools WHERE id=?', [$sid]);
    if (!$s) { flash('bad', 'School not found.'); redirect('platform/finances.php'); }
    $title = $s['name'] . ' finances';
    $r0 = Stats::revenue($days, 0, $sid); $r1 = Stats::revenue($days, 1, $sid);
    $life = (float)val("SELECT COALESCE(SUM(amount),0) FROM transactions WHERE status='paid' AND school_id=?", [$sid]);
    $pend = row("SELECT COUNT(*) n, COALESCE(SUM(amount),0) a FROM transactions WHERE status='pending' AND school_id=?", [$sid]);
    $refund = (float)val("SELECT COALESCE(SUM(amount),0) FROM transactions t WHERE t.status='refunded' AND t.school_id=? AND " . Stats::win('t.refunded_at', $days), [$sid]);
    $students = (int)val('SELECT COUNT(*) FROM users WHERE school_id=? AND role_id=1', [$sid]);
    $plusN = (int)val('SELECT COUNT(*) FROM users WHERE school_id=? AND role_id=1 AND plus=1 AND (plus_expires IS NULL OR plus_expires>NOW())', [$sid]);
    $payers = (int)val("SELECT COUNT(DISTINCT user_id) FROM transactions t WHERE t.status='paid' AND t.school_id=? AND $W0", [$sid]);
    $rc = $rec[$sid] ?? ['subs' => 0, 'paying' => 0, 'given' => 0, 'mrr' => 0.0];
    $byMonth = Stats::revenueByMonth(12, $sid);
    $byPeriod = rows("SELECT period, COUNT(*) n, SUM(amount) a FROM transactions t WHERE t.status='paid' AND t.school_id=? AND $W0 GROUP BY period ORDER BY a DESC", [$sid]);
    $subSt = rows("SELECT status, COUNT(*) n FROM subscriptions WHERE school_id=? GROUP BY status ORDER BY n DESC", [$sid]);
    $recent = rows('SELECT t.*,u.full_name,u.email FROM transactions t JOIN users u ON u.id=t.user_id WHERE t.school_id=? ORDER BY t.id DESC LIMIT 12', [$sid]);
    $coupons = rows('SELECT code,percent_off,used_count,max_uses,is_active FROM coupons WHERE school_id=? OR school_id IS NULL ORDER BY used_count DESC LIMIT 6', [$sid]);
    $ownPrices = rows('SELECT period,amount,currency FROM plan_prices WHERE plan_id=2 AND is_enabled=1 AND school_id=? ORDER BY FIELD(period,"month","semester","year")', [$sid]);
    include ROOT . '/includes/dashboard_header.php';
    echo page_head($s['name'], 'Finances · ' . ($s['region'] ?: 'No region') . ' · licence ' . ucfirst($s['plan']) . ($s['plan_expires'] ? ' until ' . fdate($s['plan_expires']) : ''),
        '<a class="btn sm line" href="' . e(url('platform/finances.php?days=' . $days)) . '">All schools</a><a class="btn sm line" href="' . e(url('platform/schools.php?id=' . $sid)) . '">School profile</a>'
        . '<a class="btn sm" href="' . e(url('platform/subscriptions.php?tab=txn&school=' . $sid)) . '">' . ic('coin') . 'Payments</a>');
    if ($s['plan_expires'] && strtotime($s['plan_expires'] . ' UTC') < time()) echo '<div class="banner warn">This school\'s licence ended on ' . e(fdate($s['plan_expires'])) . '. <a href="' . e(url('platform/subscriptions.php?tab=licences&q=' . urlencode($s['name']))) . '">Renew it</a>.</div>';
    echo range_tabs($ranges, $days, 'days', ['school' => $sid]);
    ?>
    <section class="kpis six" style="margin-top:14px">
      <?= stat_tile(e($m($r0)), 'Revenue, last ' . $ranges[$days], delta_badge($r0, $r1), 'Previous: ' . $m($r1)) ?>
      <?= stat_tile(e($m($rc['mrr'])), 'Monthly recurring revenue', '', $rc['paying'] . ' paying · ' . $rc['given'] . ' given free') ?>
      <?= stat_tile(number_format($plusN), 'Students with Plus', '', ($students ? round(100 * $plusN / $students, 1) : 0) . '% of ' . number_format($students) . ' students') ?>
      <?= stat_tile(e($m($payers ? $r0 / $payers : 0)), 'Average per paying student', '', number_format($payers) . ' paid in this period') ?>
      <?= stat_tile(e($m($refund)), 'Refunded in this period', '', 'Lifetime revenue ' . $m($life)) ?>
      <?= stat_tile(number_format((int)$pend['n']), 'Payments waiting', '', $m($pend['a']) . ' not confirmed yet') ?>
    </section>
    <div class="two">
      <section class="card"><div class="chartcap"><h2>Revenue by month</h2><span class="muted">Last 12 months · paid</span></div>
        <?= bar_chart($byMonth, 'Revenue by month for ' . $s['name'], ['fmt' => $m, 'xfmt' => fn($k) => gmdate('M Y', strtotime($k . '-01 UTC'))]) ?></section>
      <section class="card"><h2>How students pay</h2><p class="sub">Paid in the last <?= e($ranges[$days]) ?>, by plan length</p>
        <?= $byPeriod ? hbars(array_map(fn($r) => [Billing::LABEL[$r['period']] ?? ($r['period'] ?: 'Other'), (float)$r['a'], $m($r['a']) . ' · ' . (int)$r['n']], $byPeriod)) : empty_state('No payments in this period.') ?>
        <h2 style="margin-top:18px">Subscriptions</h2>
        <?= $subSt ? hbars(array_map(fn($r) => [ucfirst(str_replace('_', ' ', $r['status'])), (int)$r['n'], number_format((int)$r['n'])], $subSt)) : empty_state('No subscriptions yet.') ?></section>
    </div>
    <div class="two">
      <section class="card dt"><?= dt_head('Recent payments', 'Last 12', '<a class="btn sm line" href="' . e(url('platform/subscriptions.php?tab=txn&school=' . $sid)) . '">All payments</a>') ?>
        <div class="dtwrap"><table class="dtable"><thead><tr><th>Member</th><th class="num">Amount</th><th>Plan</th><th>Date</th><th>Status</th></tr></thead><tbody>
        <?php foreach ($recent as $t): ?><tr><td class="dmain"><a class="dtt" href="<?= e(url('platform/users.php?id=' . $t['user_id'])) ?>"><?= e($t['full_name']) ?></a><span class="ts"><?= e($t['email']) ?></span></td>
          <td class="num"><b><?= e(money($t['amount'], $t['currency'])) ?></b></td><td><?= e(Billing::LABEL[$t['period']] ?? '-') ?></td><td class="nw muted" data-v="<?= e($t['created_at']) ?>"><?= e(fdate($t['created_at'], 'j M Y H:i')) ?></td><td><?= status_pill($t['status']) ?></td></tr>
        <?php endforeach; if (!$recent) echo dt_empty(5, 'No payments from this school yet.'); ?></tbody></table></div></section>
      <section class="card"><h2>Prices &amp; coupons</h2>
        <p class="sub"><?= $ownPrices ? 'Own price list: ' . e(implode(' · ', array_map(fn($p) => (Billing::LABEL[$p['period']] ?? $p['period']) . ' ' . money($p['amount'], $p['currency']), $ownPrices))) : 'Uses the default Plus prices.' ?> <a href="<?= e(url('platform/subscriptions.php?tab=prices')) ?>">Prices</a></p>
        <div class="dtwrap" style="margin-top:10px"><table class="dtable"><thead><tr><th>Coupon</th><th class="num">Off</th><th class="num">Used</th><th>Status</th></tr></thead><tbody>
        <?php foreach ($coupons as $c): ?><tr><td><span class="code"><?= e($c['code']) ?></span></td><td class="num"><?= (int)$c['percent_off'] ?>%</td><td class="num"><?= (int)$c['used_count'] ?><?= $c['max_uses'] ? ' / ' . (int)$c['max_uses'] : '' ?></td><td><?= status_pill($c['is_active'] ? 'active' : 'hidden') ?></td></tr>
        <?php endforeach; if (!$coupons) echo dt_empty(4, 'No coupons for this school.'); ?></tbody></table></div></section>
    </div>
    <?php include ROOT . '/includes/dashboard_footer.php'; exit;
}

/* ---------- every school ---------- */
$sort = Validator::in($_GET['sort'] ?? 'rev', ['rev', 'growth', 'mrr', 'plus', 'conv', 'pend', 'life']) ?? 'rev';
$q = trim((string)($_GET['q'] ?? '')); $lic = (string)($_GET['lic'] ?? '');
$w = ['1=1']; $a = [];
if ($q !== '') { $w[] = '(s.name LIKE ? OR s.short_name LIKE ? OR s.region LIKE ?)'; array_push($a, "%$q%", "%$q%", "%$q%"); }
if ($lic === 'ended') $w[] = 's.plan_expires<CURDATE()'; elseif ($lic === 'soon') $w[] = 's.plan_expires BETWEEN CURDATE() AND CURDATE()+INTERVAL 30 DAY'; elseif (in_array($lic, ['trial', 'basic', 'standard', 'premium'], true)) { $w[] = 's.plan=?'; $a[] = $lic; }
$W = implode(' AND ', $w);
$list = rows("SELECT s.id,s.name,s.short_name,s.region,s.plan,s.plan_expires,s.is_active,
    (SELECT COUNT(*) FROM users x WHERE x.school_id=s.id AND x.role_id=1) students,
    (SELECT COUNT(*) FROM users x WHERE x.school_id=s.id AND x.role_id=1 AND x.plus=1 AND (x.plus_expires IS NULL OR x.plus_expires>NOW())) plus,
    (SELECT COALESCE(SUM(t.amount),0) FROM transactions t WHERE t.school_id=s.id AND t.status='paid' AND $W0) rev,
    (SELECT COALESCE(SUM(t.amount),0) FROM transactions t WHERE t.school_id=s.id AND t.status='paid' AND $W1) prev,
    (SELECT COALESCE(SUM(t.amount),0) FROM transactions t WHERE t.school_id=s.id AND t.status='paid') life,
    (SELECT COALESCE(SUM(t.amount),0) FROM transactions t WHERE t.school_id=s.id AND t.status='refunded' AND " . Stats::win('t.refunded_at', $days) . ") refunds,
    (SELECT COUNT(*) FROM transactions t WHERE t.school_id=s.id AND t.status='pending') pend,
    (SELECT MAX(t.created_at) FROM transactions t WHERE t.school_id=s.id AND t.status='paid') lastpay
    FROM schools s WHERE $W" . ($q === '' && $lic === '' ? ' HAVING students>0 OR life>0 OR pend>0' : ''), $a);   // unfiltered: only schools in use; a search or licence filter shows every match
foreach ($list as &$r) {
    $rc = $rec[(int)$r['id']] ?? ['subs' => 0, 'paying' => 0, 'given' => 0, 'mrr' => 0.0];
    $r['mrr'] = $rc['mrr']; $r['subs'] = $rc['subs']; $r['conv'] = $r['students'] ? 100 * $r['plus'] / $r['students'] : 0;
    $r['growth'] = Stats::change((float)$r['rev'], (float)$r['prev']) ?? ((float)$r['rev'] > 0 ? 1e9 : -1e9);
}
unset($r);
usort($list, fn($x, $y) => [(float)$y[$sort], $x['name']] <=> [(float)$x[$sort], $y['name']]);

if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="prepdeck-finances-' . gmdate('Y-m-d') . '-' . $days . 'd.csv"');
    $f = fopen('php://output', 'w');
    fputcsv($f, ['School', 'Region', 'Licence', 'Licence ends', 'Students', 'Plus students', 'Conversion %', 'Active subscriptions', "MRR ($cur)", "Revenue last $days days ($cur)", "Previous $days days ($cur)", "Refunds ($cur)", 'Pending payments', "Lifetime revenue ($cur)", 'Last payment']);
    $txt = fn($v) => preg_match('/^[=+\-@\t\r]/', (string)$v) ? "'" . $v : (string)$v;   // a name starting with = + - @ would run as a spreadsheet formula
    foreach ($list as $r) fputcsv($f, [$txt($r['name']), $txt($r['region']), $r['plan'], $r['plan_expires'], $r['students'], $r['plus'], round($r['conv'], 1), $r['subs'], round($r['mrr'], 2), $r['rev'], $r['prev'], $r['refunds'], $r['pend'], $r['life'], $r['lastpay']]);
    Audit::log("exported finances ($days days)", 'settings'); exit;
}

$r0 = Stats::revenue($days); $r1 = Stats::revenue($days, 1);
$ref0 = (float)val("SELECT COALESCE(SUM(amount),0) FROM transactions t WHERE t.status='refunded' AND " . Stats::win('t.refunded_at', $days));
$ref1 = (float)val("SELECT COALESCE(SUM(amount),0) FROM transactions t WHERE t.status='refunded' AND " . Stats::win('t.refunded_at', $days, 1));
$pay0 = (int)val("SELECT COUNT(DISTINCT user_id) FROM transactions t WHERE t.status='paid' AND $W0"); $pay1 = (int)val("SELECT COUNT(DISTINCT user_id) FROM transactions t WHERE t.status='paid' AND $W1");
$pend = row("SELECT COUNT(*) n, COALESCE(SUM(amount),0) a FROM transactions WHERE status='pending'");
$fail0 = (int)val("SELECT COUNT(*) FROM transactions t WHERE t.status='failed' AND $W0");
$students = (int)val('SELECT COUNT(*) FROM users WHERE role_id=1'); $plusN = (int)val('SELECT COUNT(*) FROM users WHERE role_id=1 AND plus=1 AND (plus_expires IS NULL OR plus_expires>NOW())');
$life = (float)val("SELECT COALESCE(SUM(amount),0) FROM transactions WHERE status='paid'");
$byMonth = Stats::revenueByMonth(12);
$byPeriod = rows("SELECT period, COUNT(*) n, SUM(amount) a FROM transactions t WHERE t.status='paid' AND $W0 GROUP BY period ORDER BY a DESC");
$byRegion = rows("SELECT COALESCE(s.region,'Not set') region, SUM(t.amount) a FROM transactions t LEFT JOIN schools s ON s.id=t.school_id WHERE t.status='paid' AND $W0 GROUP BY region ORDER BY a DESC LIMIT 8");
$licences = rows("SELECT plan, COUNT(*) n, SUM(plan_expires<CURDATE()) ended FROM schools WHERE is_active=1 GROUP BY plan ORDER BY FIELD(plan,'premium','standard','basic','trial')");
$per = per_page(25); $page = page_no(); [$pList, $total] = page_slice($list, $per, $page);
$keep = ['days' => $days, 'sort' => $sort, 'q' => $q, 'lic' => $lic];
include ROOT . '/includes/dashboard_header.php';
echo page_head('Finances', 'Plus revenue, recurring revenue and payments for every school, side by side.',
    '<a class="btn sm line" href="?' . e(http_build_query(array_filter($keep) + ['export' => 'csv'])) . '">' . ic('download') . 'Export CSV</a><a class="btn sm" href="' . e(url('platform/subscriptions.php?tab=txn')) . '">' . ic('coin') . 'Payments</a>');
if ((int)$pend['n']) echo '<a class="banner" style="display:block;color:inherit;text-decoration:none" href="' . e(url('platform/subscriptions.php?tab=txn&status=pending')) . '"><b>' . (int)$pend['n'] . ' payment' . ((int)$pend['n'] === 1 ? '' : 's') . ' (' . e($m($pend['a'])) . ') waiting to be confirmed</b> &rsaquo;</a>';
echo range_tabs($ranges, $days);
?>
<section class="kpis six" style="margin-top:14px">
  <?= stat_tile(e($m($r0)), 'Revenue, last ' . $ranges[$days], delta_badge($r0, $r1), 'Previous: ' . $m($r1)) ?>
  <?= stat_tile(e($m($rec[0]['mrr'])), 'Monthly recurring revenue', '', 'Annual run rate ' . $m($rec[0]['mrr'] * 12)) ?>
  <?= stat_tile(number_format($rec[0]['subs']), 'Plus subscriptions running', '', $rec[0]['paying'] . ' paying · ' . $rec[0]['given'] . ' given free') ?>
  <?= stat_tile(($students ? number_format(100 * $plusN / $students, 1) : '0') . '%', 'Students on Plus', '', number_format($plusN) . ' of ' . number_format($students)) ?>
  <?= stat_tile(e($m($pay0 ? $r0 / $pay0 : 0)), 'Average per paying student', delta_badge($pay0 ? $r0 / $pay0 : 0, $pay1 ? $r1 / $pay1 : 0), number_format($pay0) . ' paid in this period') ?>
  <?= stat_tile(e($m($ref0)), 'Refunded', delta_badge($ref0, $ref1, false), $fail0 . ' failed payment' . ($fail0 === 1 ? '' : 's') . ' · lifetime ' . $m($life)) ?>
</section>
<div class="two">
  <section class="card"><div class="chartcap"><h2>Revenue by month</h2><span class="muted">All schools · last 12 months · paid</span></div>
    <?= bar_chart($byMonth, 'Revenue by month, all schools', ['fmt' => $m, 'xfmt' => fn($k) => gmdate('M Y', strtotime($k . '-01 UTC'))]) ?></section>
  <section class="card"><h2>Plan lengths</h2><p class="sub">Paid in the last <?= e($ranges[$days]) ?></p>
    <?= $byPeriod ? hbars(array_map(fn($r) => [Billing::LABEL[$r['period']] ?? ($r['period'] ?: 'Other'), (float)$r['a'], $m($r['a']) . ' · ' . (int)$r['n']], $byPeriod)) : empty_state('No payments in this period.') ?>
    <h2 style="margin-top:18px">By region</h2>
    <?= $byRegion ? hbars(array_map(fn($r) => [$r['region'], (float)$r['a'], $m($r['a'])], $byRegion)) : empty_state('No payments in this period.') ?></section>
</div>
<section class="card"><h2>School licences</h2><p class="sub">Active schools by PrepDeck plan</p>
  <?= hbars(array_map(fn($r) => [ucfirst($r['plan']), (int)$r['n'], number_format((int)$r['n']) . ((int)$r['ended'] ? ' · ' . (int)$r['ended'] . ' ended' : ''), url('platform/finances.php?' . http_build_query(['days' => $days, 'lic' => $r['plan']]))], $licences)) ?></section>
<section class="card dt"><?= dt_head('Schools', number_format($total) . ' school' . ($total === 1 ? '' : 's') . ' · revenue for the last ' . $ranges[$days]) ?>
  <form class="toolbar" method="get"><input type="hidden" name="days" value="<?= $days ?>"><input type="hidden" name="sort" value="<?= e($sort) ?>"><label class="search"><?= ic('search', 'i') ?><span class="vh">Search</span><input name="q" value="<?= e($q) ?>" type="search" placeholder="School or region"></label>
    <select class="in" name="lic" aria-label="Licence"><?php foreach (['' => 'Any licence', 'premium' => 'Premium', 'standard' => 'Standard', 'basic' => 'Basic', 'trial' => 'Trial', 'soon' => 'Ending in 30 days', 'ended' => 'Licence ended'] as $k => $l): ?><option value="<?= $k ?>"<?= $lic === $k ? ' selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select><button class="btn sm">Filter</button></form>
  <div class="tabs" aria-label="Sort by"><?php foreach (['rev' => 'Revenue', 'growth' => 'Growth', 'mrr' => 'Recurring', 'plus' => 'Plus students', 'conv' => 'Conversion', 'pend' => 'Pending', 'life' => 'Lifetime'] as $k => $l): ?><a class="tab" href="?<?= e(http_build_query(array_filter(['sort' => $k] + $keep))) ?>" aria-selected="<?= $sort === $k ? 'true' : 'false' ?>"><?= $l ?></a><?php endforeach; ?></div>
  <div class="dtwrap"><table class="dtable"><thead><tr><th>School</th><th>Licence</th><th class="num">Plus</th><th class="num">Conversion</th><th class="num">MRR</th><th class="num">Revenue</th><th>Change</th><th class="num">Refunds</th><th class="num">Pending</th><th class="num">Lifetime</th><th>Last payment</th></tr></thead><tbody>
  <?php foreach ($pList as $r): $href = url('platform/finances.php?school=' . $r['id'] . '&days=' . $days); $ended = $r['plan_expires'] && strtotime($r['plan_expires'] . ' UTC') < time(); ?><tr data-href="<?= e($href) ?>">
    <td class="dmain"><a class="dtt" href="<?= e($href) ?>"><?= e($r['name']) ?></a><span class="ts"><?= e($r['region'] ?: '') ?><?= $r['is_active'] ? '' : ' · switched off' ?></span></td>
    <td class="nw"><span class="pill <?= $ended ? 'bad' : 'free' ?>"><?= e(ucfirst($r['plan'])) ?></span><span class="ts"><?= $r['plan_expires'] ? ($ended ? 'Ended ' : 'Until ') . e(fdate($r['plan_expires'])) : 'No end date' ?></span></td>
    <td class="num"><?= number_format((int)$r['plus']) ?><span class="ts">of <?= number_format((int)$r['students']) ?></span></td><td class="num"><?= number_format($r['conv'], 1) ?>%</td>
    <td class="num"><?= e(number_format($r['mrr'], 2)) ?></td><td class="num"><b><?= e(number_format((float)$r['rev'], 2)) ?></b></td><td><?= delta_badge((float)$r['rev'], (float)$r['prev']) ?></td>
    <td class="num"><?= (float)$r['refunds'] ? e(number_format((float)$r['refunds'], 2)) : '<span class="muted">&ndash;</span>' ?></td><td class="num"><?= (int)$r['pend'] ? '<span class="pill wait">' . (int)$r['pend'] . '</span>' : '<span class="muted">&ndash;</span>' ?></td>
    <td class="num"><?= e(number_format((float)$r['life'], 2)) ?></td><td class="nw muted"><?= $r['lastpay'] ? e(ago($r['lastpay'])) : 'Never' ?></td></tr>
  <?php endforeach; if (!$pList) echo dt_empty(11, 'No school matches.'); ?></tbody></table></div>
  <?= dt_foot($total, $per, $page, $keep) ?>
  <p class="note" style="padding:0 18px 16px">Amounts in <?= e($cur) ?>. MRR spreads each running subscription's last payment over its plan length, as 30 days. Plus given free by an admin counts as a subscription but adds no revenue.</p></section>
<?php include ROOT . '/includes/dashboard_footer.php'; ?>
