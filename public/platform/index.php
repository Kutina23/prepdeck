<?php
// Platform owner overview: how PrepDeck is doing across every school. Growth, activity, money, engagement, AI and content,
// each compared with the period before, and the schools side by side.
require __DIR__ . '/../../app/bootstrap.php';
$u = require_super(); $platformArea = true; $active = 'p-home'; $title = 'Platform overview'; $scripts = [];
Billing::sweep();
$ranges = [7 => '7 days', 30 => '30 days', 90 => '90 days'];
$days = (int)($_GET['days'] ?? 30); if (!isset($ranges[$days])) $days = 30;
$num = fn(string $sql, array $p = []) => (int)val($sql, $p);
$cur = Stats::currency(); $m = fn($v) => money((float)$v, $cur);
$w = fn(string $col, int $which = 0) => Stats::win($col, $days, $which);

/* ----- headline numbers, now vs the period before ----- */
$users = $num('SELECT COUNT(*) FROM users WHERE role_id<>5');
$new0 = $num('SELECT COUNT(*) FROM users WHERE role_id<>5 AND ' . $w('created_at')); $new1 = $num('SELECT COUNT(*) FROM users WHERE role_id<>5 AND ' . $w('created_at', 1));
$act0 = Stats::activeUsers($days); $act1 = Stats::activeUsers($days, 1);
$rev0 = Stats::revenue($days); $rev1 = Stats::revenue($days, 1);
$rec = Stats::recurring()[0];
$students = $num('SELECT COUNT(*) FROM users WHERE role_id=1');
$plusN = $num('SELECT COUNT(*) FROM users WHERE role_id=1 AND plus=1 AND (plus_expires IS NULL OR plus_expires>NOW())');
$tokSql = fn(int $which) => $num('SELECT COALESCE(SUM(tokens_in+tokens_out),0) FROM processing_jobs WHERE ' . $w('created_at', $which)) + $num('SELECT COALESCE(SUM(tokens),0) FROM ai_usage WHERE ' . $w('created_at', $which));
$tok0 = $tokSql(0); $tok1 = $tokSql(1);

/* ----- needs attention ----- */
$att = [
    'requests' => $num("SELECT COUNT(*) FROM school_requests WHERE status='pending'"),
    'pending' => $num("SELECT COUNT(*) FROM transactions WHERE status='pending'"),
    'licEnded' => $num('SELECT COUNT(*) FROM schools WHERE is_active=1 AND plan_expires<CURDATE()'),
    'licSoon' => $num('SELECT COUNT(*) FROM schools WHERE is_active=1 AND plan_expires BETWEEN CURDATE() AND CURDATE()+INTERVAL 30 DAY'),
    'failed' => $num("SELECT COUNT(*) FROM processing_jobs WHERE status='failed' AND finished_at>NOW()-INTERVAL 1 DAY"),
    'review' => $num("SELECT COUNT(*) FROM papers WHERE status='pending'"),
];

/* ----- trends ----- */
$chartDays = min($days, 90);
$activeDaily = Stats::activePerDay($chartDays);
$signups = Stats::perDay('users', 'created_at', $chartDays, 'AND role_id<>5');
$byMonth = Stats::revenueByMonth(12);
$dayFmt = fn($k) => gmdate('D j M', strtotime($k . ' UTC'));

/* ----- student funnel and retention ----- */
$verified = $num('SELECT COUNT(*) FROM users WHERE role_id=1 AND email_verified_at IS NOT NULL');
$activeStudents = (int)val('SELECT COUNT(DISTINCT a.user_id) FROM (' . Stats::activitySql(Stats::win('{at}', $days)) . ') a JOIN users x ON x.id=a.user_id AND x.role_id=1');
$cohort = $num('SELECT COUNT(*) FROM users WHERE role_id=1 AND created_at>=NOW()-INTERVAL 60 DAY AND created_at<NOW()-INTERVAL 30 DAY');
$kept = $cohort ? (int)val('SELECT COUNT(DISTINCT a.user_id) FROM (' . Stats::activitySql(Stats::win('{at}', 30)) . ') a JOIN users x ON x.id=a.user_id AND x.role_id=1 AND x.created_at>=NOW()-INTERVAL 60 DAY AND x.created_at<NOW()-INTERVAL 30 DAY') : 0;

/* ----- engagement, AI, content: now vs before ----- */
$pair = fn(string $table, string $col, string $extra = '') => [$num("SELECT COUNT(*) FROM $table WHERE " . $w($col) . " $extra"), $num("SELECT COUNT(*) FROM $table WHERE " . $w($col, 1) . " $extra")];
$sumPair = fn(string $expr, string $table, string $col) => [$num("SELECT COALESCE(SUM($expr),0) FROM $table WHERE " . $w($col)), $num("SELECT COALESCE(SUM($expr),0) FROM $table WHERE " . $w($col, 1))];
$engage = [
    ['Paper views', $pair('paper_views', 'viewed_at')],
    ['Practice exams taken', $pair('practice_attempts', 'created_at')],
    ['AI chat messages', $pair('ai_usage', 'created_at')],
    ['Focus minutes', $sumPair('minutes', 'focus_sessions', 'started_at')],
    ['Games played', $pair('game_scores', 'created_at')],
];
$score = row('SELECT ROUND(100*SUM(correct)/NULLIF(SUM(total),0)) s, COUNT(*) n FROM practice_attempts WHERE ' . $w('created_at'));
$content = [
    ['Papers uploaded', $pair('papers', 'created_at')],
    ['Lesson notes published', $pair('lesson_notes', 'published_at', "AND status='published'")],
    ['Lectures recorded', $pair('recordings', 'created_at')],
    ['Notes uploaded', $pair('materials', 'created_at')],
];
$aiKinds = rows('SELECT kind, COUNT(*) n, COALESCE(SUM(tokens),0) t FROM ai_usage WHERE ' . $w('created_at') . ' GROUP BY kind ORDER BY t DESC');
$docTok = $num('SELECT COALESCE(SUM(tokens_in+tokens_out),0) FROM processing_jobs WHERE ' . $w('created_at'));
$jobs = row("SELECT COUNT(*) n, COALESCE(SUM(status='done'),0) ok, COALESCE(SUM(status='failed'),0) bad, COALESCE(SUM(status IN ('queued','running')),0) waiting,
    ROUND(AVG(CASE WHEN status='done' THEN TIMESTAMPDIFF(SECOND, COALESCE(started_at,created_at), finished_at) END)) secs FROM processing_jobs WHERE " . $w('created_at'));
$kindNames = ['chat' => 'Chat', 'quiz' => 'Quiz', 'cards' => 'Flashcards', 'guide' => 'Study plans'];

/* ----- schools ----- */
$sort = Validator::in($_GET['sort'] ?? 'active', ['active', 'students', 'new', 'revenue', 'papers', 'tokens']) ?? 'active';
$activeBySchool = array_column(rows('SELECT school_id, COUNT(DISTINCT user_id) n FROM (' . Stats::activitySql(Stats::win('{at}', $days)) . ') a GROUP BY school_id'), 'n', 'school_id');
$top = rows("SELECT s.id,s.name,s.region,s.plan,s.plan_expires,s.is_active,
    (SELECT COUNT(*) FROM users x WHERE x.school_id=s.id AND x.role_id=1) students,
    (SELECT COUNT(*) FROM users x WHERE x.school_id=s.id AND " . $w('x.created_at') . ") new,
    (SELECT COUNT(*) FROM users x WHERE x.school_id=s.id AND x.role_id=1 AND x.plus=1 AND (x.plus_expires IS NULL OR x.plus_expires>NOW())) plus,
    (SELECT COUNT(*) FROM papers p WHERE p.school_id=s.id AND p.status='published') papers,
    (SELECT COALESCE(SUM(t.amount),0) FROM transactions t WHERE t.school_id=s.id AND t.status='paid' AND " . $w('t.created_at') . ") revenue,
    (SELECT COALESCE(SUM(t.amount),0) FROM transactions t WHERE t.school_id=s.id AND t.status='paid' AND " . $w('t.created_at', 1) . ") prev,
    (SELECT COALESCE(SUM(j.tokens_in+j.tokens_out),0) FROM processing_jobs j WHERE j.school_id=s.id AND " . $w('j.created_at') . ") tokens
    FROM schools s HAVING students>0 OR papers>0 OR revenue>0");
foreach ($top as &$s) $s['active'] = (int)($activeBySchool[$s['id']] ?? 0);
unset($s);
usort($top, fn($a, $b) => [(float)$b[$sort], $a['name']] <=> [(float)$a[$sort], $b['name']]);
$schoolsLive = count(array_filter($top, fn($s) => $s['active'] > 0));
$top = array_slice($top, 0, 25);
$regions = rows("SELECT COALESCE(s.region,'Not set') region, COUNT(DISTINCT s.id) schools, COUNT(u.id) students FROM schools s LEFT JOIN users u ON u.school_id=s.id AND u.role_id=1 WHERE s.is_active=1 GROUP BY s.region HAVING students>0 ORDER BY students DESC LIMIT 8");
$schoolsN = $num('SELECT COUNT(*) FROM schools WHERE is_active=1');
$expiring = rows('SELECT id,name,plan,plan_expires FROM schools WHERE is_active=1 AND plan_expires IS NOT NULL AND plan_expires<=CURDATE()+INTERVAL 30 DAY ORDER BY plan_expires LIMIT 8');
$reqs = rows("SELECT * FROM school_requests WHERE status='pending' ORDER BY id DESC LIMIT 5");
$pct = fn($a, $b) => $b ? number_format(100 * $a / $b, 1) . '%' : '0%';

include ROOT . '/includes/dashboard_header.php';
echo page_head('Platform overview', 'How PrepDeck is doing across every school, compared with the ' . $ranges[$days] . ' before.', '<a class="btn sm line" href="' . e(url('platform/finances.php?days=' . max(30, $days))) . '">' . ic('chart') . 'Finances</a><a class="btn sm" href="' . e(url('platform/schools.php')) . '">' . ic('cap') . 'Schools</a>');
// the install's published default password must never stay on an admin account (checked on the few admin and owner accounts only)
$weak = array_filter(rows("SELECT email,password_hash FROM users WHERE role_id IN (4,5) AND status='active'"), fn($r) => password_verify('Admin@12345', (string)$r['password_hash']));
if ($weak) echo '<div class="banner warn"><b>Security:</b> ' . e(implode(', ', array_column($weak, 'email'))) . ' still ' . (count($weak) === 1 ? 'uses' : 'use') . ' the default password from the install guide. Change it now in Settings (or reset it in All users).</div>';
if (Settings::global('platform_maintenance', '0') === '1') echo '<div class="banner warn">Platform maintenance is on: students in every school see a maintenance page. <a href="' . e(url('platform/settings.php')) . '">Turn it off</a>.</div>';
echo range_tabs($ranges, $days, 'days', ['sort' => $sort]);
?>
<section class="kpis six" style="margin-top:14px">
  <?= stat_tile(number_format($act0), 'Active people', delta_badge($act0, $act1), $pct($act0, $users) . ' of ' . number_format($users) . ' accounts') ?>
  <?= stat_tile(number_format($new0), 'New accounts', delta_badge($new0, $new1), number_format($new1) . ' in the ' . $ranges[$days] . ' before') ?>
  <?= stat_tile(e($m($rev0)), 'Plus revenue', delta_badge($rev0, $rev1), 'Previous: ' . $m($rev1)) ?>
  <?= stat_tile(e($m($rec['mrr'])), 'Monthly recurring revenue', '', $rec['subs'] . ' Plus subscriptions running (' . $rec['paying'] . ' paying)') ?>
  <?= stat_tile($pct($plusN, $students), 'Students on Plus', '', number_format($plusN) . ' of ' . number_format($students) . ' students') ?>
  <?= stat_tile(number_format($tok0 / 1000, 1) . 'k', 'AI tokens used', delta_badge($tok0, $tok1, false), 'Documents and chat') ?>
</section>
<?= community_card($u) ?>
<section class="card"><h2>Needs attention</h2>
  <div class="attn" style="margin-top:12px">
    <?php foreach ([
      [$att['requests'], 'schools waiting to be added', 'platform/schools.php?tab=requests'],
      [$att['pending'], 'payments waiting to be confirmed', 'platform/subscriptions.php?tab=txn&status=pending'],
      [$att['licEnded'], 'school licences ended · ' . $att['licSoon'] . ' ending within 30 days', 'platform/subscriptions.php?tab=licences&status=ended'],
      [$att['review'], 'papers waiting for review (all schools)', null],
      [$att['failed'], 'AI jobs failed in the last 24 hours', null],
    ] as [$cnt, $txt, $href]): $tag = $href ? 'a' : 'div'; ?>
    <<?= $tag ?> class="it"<?= $href ? ' href="' . e(url($href)) . '"' : '' ?>><span class="n" style="color:<?= $cnt ? 'var(--bad)' : 'var(--ok)' ?>"><?= $cnt ?></span><span class="t"><?= e($txt) ?></span><?= $href ? '<span aria-hidden="true">&rsaquo;</span>' : '' ?></<?= $tag ?>>
    <?php endforeach; ?>
  </div></section>
<div class="two">
  <section class="card"><div class="chartcap"><h2>Active people per day</h2><span class="muted">Last <?= $chartDays ?> days · peak <?= number_format(max($activeDaily ?: [0])) ?></span></div>
    <?= bar_chart($activeDaily, 'Active people per day', ['xfmt' => $dayFmt]) ?>
    <p class="note">Anyone who opened a paper, practised, used AI chat, the focus timer or a game that day.</p></section>
  <section class="card"><div class="chartcap"><h2>Sign-ups per day</h2><span class="muted"><?= number_format(array_sum($signups)) ?> in <?= $chartDays ?> days</span></div>
    <?= bar_chart($signups, 'Sign-ups per day', ['xfmt' => $dayFmt]) ?></section>
</div>
<div class="two">
  <section class="card"><div class="chartcap"><h2>Plus revenue by month</h2><a href="<?= e(url('platform/finances.php')) ?>">By school &rsaquo;</a></div>
    <?= bar_chart($byMonth, 'Plus revenue by month', ['fmt' => $m, 'xfmt' => fn($k) => gmdate('M Y', strtotime($k . '-01 UTC'))]) ?></section>
  <section class="card"><h2>Student funnel</h2><p class="sub">All schools · active = used PrepDeck in the last <?= e($ranges[$days]) ?></p>
    <?= hbars([
      ['Registered', $students, number_format($students)],
      ['Confirmed their email', $verified, number_format($verified) . ' · ' . $pct($verified, $students)],
      ['Active', $activeStudents, number_format($activeStudents) . ' · ' . $pct($activeStudents, $students)],
      ['On Plus', $plusN, number_format($plusN) . ' · ' . $pct($plusN, $students)],
    ], (float)max(1, $students)) ?>
    <p class="note" style="margin-top:14px"><b>30-day retention:</b> <?= $cohort ? $pct($kept, $cohort) . ' of the ' . number_format($cohort) . ' students who joined 31 to 60 days ago used PrepDeck in the last 30 days.' : 'not enough students joined 31 to 60 days ago yet.' ?></p></section>
</div>
<div class="three">
  <section class="card dt"><?= dt_head('Engagement', 'Last ' . $ranges[$days] . ' vs the period before') ?>
    <div class="dtwrap"><table class="dtable"><thead><tr><th>What</th><th class="num">Now</th><th class="num">Before</th><th>Change</th></tr></thead><tbody>
    <?php foreach ($engage as [$l, [$a, $b]]): ?><tr><td class="dmain"><?= e($l) ?></td><td class="num"><b><?= number_format($a) ?></b></td><td class="num muted"><?= number_format($b) ?></td><td><?= delta_badge($a, $b) ?></td></tr><?php endforeach; ?>
    <tr><td class="dmain">Average practice score</td><td class="num"><b><?= $score['s'] === null ? '&ndash;' : (int)$score['s'] . '%' ?></b></td><td class="num muted" colspan="2"><?= number_format((int)$score['n']) ?> exams</td></tr>
    </tbody></table></div></section>
  <section class="card"><h2>AI use</h2><p class="sub">Last <?= e($ranges[$days]) ?> · <?= number_format(($docTok + array_sum(array_column($aiKinds, 't'))) / 1000, 1) ?>k tokens</p>
    <?= hbars(array_merge([['Documents (papers, notes, lectures, outlines)', $docTok, number_format($docTok / 1000, 1) . 'k']], array_map(fn($r) => [($kindNames[$r['kind']] ?? ucfirst($r['kind'])) . ' · ' . number_format((int)$r['n']) . ' messages', (int)$r['t'], number_format($r['t'] / 1000, 1) . 'k'], $aiKinds))) ?>
    <p class="note" style="margin-top:14px"><b><?= number_format((int)$jobs['n']) ?></b> documents processed · <?= $jobs['n'] ? $pct((int)$jobs['ok'], (int)$jobs['n']) : '&ndash;' ?> succeeded · <?= (int)$jobs['bad'] ?> failed · <?= (int)$jobs['waiting'] ?> waiting<?= $jobs['secs'] !== null ? ' · ' . e(gmdate((int)$jobs['secs'] >= 3600 ? 'G\h i\m' : 'i:s', (int)$jobs['secs'])) . ' average' : '' ?></p></section>
  <section class="card dt"><?= dt_head('Content', 'Added in the last ' . $ranges[$days]) ?>
    <div class="dtwrap"><table class="dtable"><thead><tr><th>What</th><th class="num">Now</th><th class="num">Before</th><th>Change</th></tr></thead><tbody>
    <?php foreach ($content as [$l, [$a, $b]]): ?><tr><td class="dmain"><?= e($l) ?></td><td class="num"><b><?= number_format($a) ?></b></td><td class="num muted"><?= number_format($b) ?></td><td><?= delta_badge($a, $b) ?></td></tr><?php endforeach; ?>
    <tr><td class="dmain">Papers live in total</td><td class="num" colspan="3"><b><?= number_format($num("SELECT COUNT(*) FROM papers WHERE status='published'")) ?></b></td></tr>
    </tbody></table></div></section>
</div>
<section class="card dt"><?= dt_head('Schools in use', $schoolsLive . ' of ' . number_format($schoolsN) . ' active schools had activity in the last ' . $ranges[$days]) ?>
    <div class="tabs"><?php foreach (['active' => 'Active people', 'students' => 'Students', 'new' => 'New', 'revenue' => 'Revenue', 'papers' => 'Papers', 'tokens' => 'AI use'] as $kk => $l): ?><a class="tab" href="?<?= e(http_build_query(['days' => $days, 'sort' => $kk])) ?>" aria-selected="<?= $sort === $kk ? 'true' : 'false' ?>"><?= $l ?></a><?php endforeach; ?></div>
    <div class="dtwrap"><table class="dtable"><thead><tr><th>School</th><th>Licence</th><th class="num">Active</th><th class="num">Students</th><th class="num">New</th><th class="num">Plus</th><th class="num">Papers</th><th class="num">Revenue</th><th>Change</th><th class="num">AI tokens</th><th data-nosort><span class="vh">Actions</span></th></tr></thead><tbody>
    <?php foreach ($top as $s): $href = url('platform/schools.php?id=' . $s['id']); $ended = $s['plan_expires'] && strtotime($s['plan_expires'] . ' UTC') < time(); ?><tr data-href="<?= e($href) ?>">
      <td class="dmain"><a class="dtt" href="<?= e($href) ?>"><?= e($s['name']) ?></a><span class="ts"><?= e($s['region'] ?: '') ?><?= $s['is_active'] ? '' : ' · switched off' ?></span></td>
      <td><span class="pill <?= $ended ? 'bad' : 'free' ?>"><?= e(ucfirst($s['plan'])) ?></span><?= $ended ? '<span class="ts">Ended</span>' : '' ?></td>
      <td class="num"><b><?= number_format($s['active']) ?></b></td><td class="num"><?= number_format((int)$s['students']) ?></td><td class="num"><?= (int)$s['new'] ?></td><td class="num"><?= (int)$s['plus'] ?></td><td class="num"><?= (int)$s['papers'] ?></td>
      <td class="num"><a href="<?= e(url('platform/finances.php?school=' . $s['id'])) ?>"><?= e(number_format((float)$s['revenue'], 2)) ?></a></td><td><?= delta_badge((float)$s['revenue'], (float)$s['prev']) ?></td><td class="num"><?= number_format($s['tokens'] / 1000, 1) ?>k</td>
      <td class="act"><div class="rowact"><?= row_form('platform/schools.php', ['a' => 'open', 'id' => $s['id']], 'Open dashboard', 'btn sm line') ?></div></td></tr>
    <?php endforeach; if (!$top) echo dt_empty(11, 'No school has students or papers yet.'); ?></tbody></table></div></section>
<div class="three">
  <section class="card"><h2>Students by region</h2><p class="sub">Active schools</p>
    <?= $regions ? hbars(array_map(fn($r) => [$r['region'], (int)$r['students'], number_format((int)$r['students']) . ' · ' . (int)$r['schools'] . ' school' . ((int)$r['schools'] === 1 ? '' : 's'), url('platform/schools.php?region=' . urlencode($r['region']))], $regions)) : empty_state('No students yet.') ?></section>
  <section class="card dt"><?= dt_head('School requests', 'From "my school is not listed"', '<a class="btn sm line" href="' . e(url('platform/schools.php?tab=requests')) . '">All requests</a>') ?>
    <div class="dtwrap"><table class="dtable"><thead><tr><th>School</th><th>Asked by</th><th>When</th></tr></thead><tbody>
    <?php foreach ($reqs as $r): ?><tr><td class="dmain"><span class="dtt"><?= e($r['name']) ?></span><span class="ts"><?= e(trim(($r['city'] ?? '') . ', ' . ($r['region'] ?? ''), ', ')) ?></span></td><td><?= e($r['requester_email']) ?></td><td class="nw muted"><?= e(ago($r['created_at'])) ?></td></tr><?php endforeach; if (!$reqs) echo dt_empty(3, 'No requests waiting.'); ?></tbody></table></div></section>
  <section class="card dt"><?= dt_head('Licences ending soon', 'Ended, or ending in the next 30 days') ?>
    <div class="dtwrap"><table class="dtable"><thead><tr><th>School</th><th>Plan</th><th>Ends</th></tr></thead><tbody>
    <?php foreach ($expiring as $s): ?><tr data-href="<?= e(url('platform/finances.php?school=' . $s['id'])) ?>"><td class="dmain"><a class="dtt" href="<?= e(url('platform/finances.php?school=' . $s['id'])) ?>"><?= e($s['name']) ?></a></td><td><?= e(ucfirst($s['plan'])) ?></td><td class="nw"><?= status_pill(strtotime($s['plan_expires'] . ' UTC') < time() ? 'expired' : 'active') ?> <?= e(fdate($s['plan_expires'])) ?></td></tr><?php endforeach; if (!$expiring) echo dt_empty(3, 'Nothing ending soon.'); ?></tbody></table></div></section>
</div>
<?php include ROOT . '/includes/dashboard_footer.php'; ?>
