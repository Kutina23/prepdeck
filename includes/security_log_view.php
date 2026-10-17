<?php
/**
 * Security log table, shared by admin/security.php (one school) and platform/security.php (every school).
 * Set before including: $platform (bool). Reads its filters from the query string.
 */
$platform = !empty($platform);
$q = trim((string)($_GET['q'] ?? '')); $ev = (string)($_GET['event'] ?? ''); $sev = (string)($_GET['sev'] ?? ''); $sf = (string)($_GET['school'] ?? '');
$days = (int)($_GET['days'] ?? 30); if (!in_array($days, [1, 7, 30, 90, 0], true)) $days = 30;
$w = []; $a = [];
if (!$platform) $w[] = in_school('l');
elseif ($sf === 'none') $w[] = 'l.school_id IS NULL'; elseif ((int)$sf) { $w[] = 'l.school_id=?'; $a[] = (int)$sf; }
if ($q !== '') { $w[] = '(l.email LIKE ? OR l.ip LIKE ? OR u.full_name LIKE ?)'; array_push($a, "%$q%", "$q%", "%$q%"); }
if (isset(SecurityLog::EVENTS[$ev])) { $w[] = 'l.event=?'; $a[] = $ev; }
if (in_array($sev, SecurityLog::SEVERITY, true)) { $w[] = 'l.severity=?'; $a[] = $sev; }
if ($days) $w[] = 'l.created_at > NOW() - INTERVAL ' . $days . ' DAY';
$W = $w ? implode(' AND ', $w) : '1=1'; $per = per_page(25); $page = page_no();
$total = (int)val("SELECT COUNT(*) FROM security_log l LEFT JOIN users u ON u.id=l.user_id WHERE $W", $a);
$list = rows("SELECT l.*,u.full_name,s.short_name,s.name school FROM security_log l LEFT JOIN users u ON u.id=l.user_id LEFT JOIN schools s ON s.id=l.school_id WHERE $W ORDER BY l.id DESC LIMIT $per OFFSET " . (($page - 1) * $per), $a);

// last 24 hours at a glance, and how many accounts have two-step sign-in
$scope = $platform ? '1=1' : in_school();
$day = row("SELECT SUM(event='login_failed') failed, SUM(event IN ('login_blocked','twofa_blocked')) blocked, SUM(event='twofa_failed') badcode, SUM(event='login_ok') ok FROM security_log WHERE $scope AND created_at > NOW() - INTERVAL 1 DAY") ?: [];
$users = row("SELECT COUNT(*) n, SUM(twofa_enabled_at IS NOT NULL) on2, SUM(role_id IN (2,3,4,5)) staff, SUM(role_id IN (2,3,4,5) AND twofa_enabled_at IS NOT NULL) staff2 FROM users WHERE status='active' AND " . ($platform ? '1=1' : in_school())) ?: [];
$pct = fn($x, $n) => (int)$n ? (int)round(100 * (int)$x / (int)$n) . '%' : '–';
$schools = $platform ? array_merge([['id' => 'none', 'n' => 'No school (unknown emails)']], rows('SELECT id,name n FROM schools ORDER BY name')) : [];
$events = []; foreach (SecurityLog::EVENTS as $k => $l) $events[] = ['id' => $k, 'n' => $l];
$sevs = [['id' => 'info', 'n' => 'Info'], ['id' => 'warning', 'n' => 'Warning'], ['id' => 'critical', 'n' => 'Critical']];
$ranges = [['id' => 1, 'n' => 'Last 24 hours'], ['id' => 7, 'n' => 'Last 7 days'], ['id' => 30, 'n' => 'Last 30 days'], ['id' => 90, 'n' => 'Last 90 days'], ['id' => 0, 'n' => 'All time']];
$sevPill = fn($s) => '<span class="pill ' . ($s === 'critical' ? 'bad' : ($s === 'warning' ? 'wait' : 'new')) . '">' . e(ucfirst($s)) . '</span>';
echo page_head($platform ? 'Platform security' : 'Security log', $platform ? 'Sign-ins, failures and account-security changes across every school. Nothing here can be edited.' : 'Sign-ins, failures and account-security changes for members of this school. Nothing here can be edited.');
?>
<section class="kpis" style="margin-bottom:14px" aria-label="At a glance">
  <?= stat_tile(number_format((int)($day['failed'] ?? 0)), 'Failed sign-ins, 24 h') ?>
  <?= stat_tile(number_format((int)($day['blocked'] ?? 0)), 'Lockouts, 24 h', '', number_format((int)($day['badcode'] ?? 0)) . ' wrong two-step codes') ?>
  <?= stat_tile($pct($users['on2'] ?? 0, $users['n'] ?? 0), 'Accounts with two-step', '', number_format((int)($users['on2'] ?? 0)) . ' of ' . number_format((int)($users['n'] ?? 0))) ?>
  <?= stat_tile($pct($users['staff2'] ?? 0, $users['staff'] ?? 0), 'Staff with two-step', '', 'Admins, lecturers and reps') ?>
</section>
<section class="card dt"><?= dt_head('Events', 'Newest first') ?>
  <form class="toolbar" method="get"><label class="search"><?= ic('search', 'i') ?><span class="vh">Search</span><input name="q" value="<?= e($q) ?>" type="search" placeholder="Email, name or IP"></label>
    <?php if ($platform): ?><select class="in" name="school" aria-label="School"><?= options($schools, $sf, 'id', 'n', 'Every school') ?></select><?php endif; ?>
    <select class="in" name="event" aria-label="Event"><?= options($events, $ev, 'id', 'n', 'Any event') ?></select>
    <select class="in" name="sev" aria-label="Severity"><?= options($sevs, $sev, 'id', 'n', 'Any severity') ?></select>
    <select class="in" name="days" aria-label="Period"><?= options($ranges, $days, 'id', 'n') ?></select><button class="btn sm">Filter</button></form>
  <div class="dtwrap"><table class="dtable"><thead><tr><th>When</th><?= $platform ? '<th>School</th>' : '' ?><th>Severity</th><th>Event</th><th>Account</th><th>Detail</th><th>IP address</th><th>Device</th></tr></thead><tbody>
  <?php foreach ($list as $l): ?><tr>
    <td class="nw mono" data-v="<?= e($l['created_at']) ?>"><?= e(fdate($l['created_at'], 'j M Y H:i')) ?></td>
    <?php if ($platform): ?><td class="nw"><?= e($l['short_name'] ?: ($l['school'] ?: '–')) ?></td><?php endif; ?>
    <td><?= $sevPill($l['severity']) ?></td><td class="dmain"><?= e(SecurityLog::label($l['event'])) ?></td>
    <td><?php if ($l['user_id'] && $l['full_name']): ?><a href="<?= e(url(($platform ? 'platform' : 'admin') . '/users.php?id=' . (int)$l['user_id'])) ?>"><?= e($l['full_name']) ?></a><span class="ts"><?= e($l['email']) ?></span><?php else: ?><span class="muted"><?= e($l['email'] ?: '–') ?></span><?php endif; ?></td>
    <td class="muted"><?= e($l['detail'] ?: '–') ?></td><td class="nw"><a class="muted" href="?<?= e(http_build_query(['q' => $l['ip'], 'days' => $days] + ($platform ? ['school' => $sf] : []))) ?>" title="Everything from this address"><?= e($l['ip'] ?: '–') ?></a></td>
    <td class="nw muted" title="<?= e($l['user_agent']) ?>"><?= e(SecurityLog::device($l['user_agent'])) ?></td></tr>
  <?php endforeach; if (!$list) echo dt_empty($platform ? 8 : 7, 'Nothing logged for these filters.'); ?></tbody></table></div>
  <?= dt_foot($total, $per, $page, ['q' => $q, 'event' => $ev, 'sev' => $sev, 'days' => $days, 'school' => $sf]) ?></section>
