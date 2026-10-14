<?php
require __DIR__ . '/../../app/bootstrap.php';
$u = admin_area_user(); $adminArea = true; $active = 'overview'; $title = 'Overview'; $scripts = [];
Billing::sweep();
$n = fn($sql, $p = []) => (int)val($sql, $p); $S = in_school();   // every number is for the school being managed
$users = $n("SELECT COUNT(*) FROM users WHERE $S"); $newUsers = $n("SELECT COUNT(*) FROM users WHERE $S AND created_at>=DATE_SUB(NOW(),INTERVAL 7 DAY)");
$plusN = $n("SELECT COUNT(*) FROM users WHERE $S AND plus=1 AND (plus_expires IS NULL OR plus_expires>NOW()) AND role_id NOT IN (4,5)");
$papers = $n("SELECT COUNT(*) FROM papers WHERE $S AND status='published'"); $pending = $n("SELECT COUNT(*) FROM papers WHERE $S AND status='pending'");
$rev = (float)val("SELECT COALESCE(SUM(amount),0) FROM transactions WHERE $S AND status='paid' AND created_at>=DATE_FORMAT(NOW(),'%Y-%m-01')");
$revAll = (float)val("SELECT COALESCE(SUM(amount),0) FROM transactions WHERE $S AND status='paid'");
$failed = $n("SELECT COUNT(*) FROM processing_jobs WHERE $S AND status='failed'"); $running = $n("SELECT COUNT(*) FROM processing_jobs WHERE $S AND status IN ('queued','running')");
$reports = $n("SELECT COUNT(*) FROM reports WHERE $S AND status='open'"); $payWait = $n("SELECT COUNT(*) FROM transactions WHERE $S AND status='pending'");
$drafts = $n("SELECT COUNT(*) FROM exam_questions q WHERE q.status='draft' AND " . questions_in_school('q')); $recs = $n("SELECT COUNT(*) FROM recordings WHERE $S");
$outFailed = $n("SELECT COUNT(*) FROM course_outlines WHERE $S AND ai_status='failed'"); $lessonsMonth = $n("SELECT COUNT(*) FROM lesson_notes WHERE $S AND generated_at>=DATE_FORMAT(NOW(),'%Y-%m-01')"); $lessonsLive = $n("SELECT COUNT(*) FROM lesson_notes WHERE $S AND status='published'");
$pend = rows("SELECT p.id,p.title,c.code,u.full_name FROM papers p JOIN courses c ON c.id=p.course_id JOIN users u ON u.id=p.uploaded_by WHERE p.status='pending' AND " . in_school('p') . " ORDER BY p.id LIMIT 5");
$top = rows("SELECT p.id,p.title,p.views,c.code FROM papers p JOIN courses c ON c.id=p.course_id WHERE p.status='published' AND " . in_school('p') . " ORDER BY p.views DESC LIMIT 5");
$school = Tenant::school();
$signups = []; for ($i = 13; $i >= 0; $i--) $signups[gmdate('Y-m-d', time() - $i * 86400)] = 0;
foreach (rows("SELECT DATE(created_at) d,COUNT(*) c FROM users WHERE $S AND created_at>=DATE_SUB(CURDATE(),INTERVAL 13 DAY) GROUP BY d") as $r) $signups[$r['d']] = (int)$r['c'];
$mx = max(3, max($signups));
$log = rows('SELECT a.action,a.created_at,u.full_name FROM audit_log a LEFT JOIN users u ON u.id=a.actor_id WHERE ' . in_school('a') . ' ORDER BY a.id DESC LIMIT 6');
$defaultPw = Auth::can('admin') && val("SELECT password_hash FROM users WHERE email='admin@prepdeck.local'") && password_verify('Admin@12345', (string)val("SELECT password_hash FROM users WHERE email='admin@prepdeck.local'"));
include ROOT . '/includes/dashboard_header.php';
echo page_head('Overview', 'How ' . school_name() . ' is doing on PrepDeck right now.', is_super() ? '<a class="btn sm line" href="' . e(url('platform/')) . '">' . ic('chart') . 'All schools</a>' : '');
if (!$school) echo '<div class="banner warn">No school is selected. ' . (is_super() ? 'Pick one in the school switcher above or in <a href="' . e(url('platform/schools.php')) . '">Platform &gt; Schools</a>.' : 'Ask the platform owner to link your account to a school.') . '</div>';
elseif ($school['plan_expires'] && strtotime($school['plan_expires']) < time()) echo '<div class="banner warn">The PrepDeck licence for ' . e($school['name']) . ' ended on ' . e(fdate($school['plan_expires'])) . '. Contact the platform owner to renew it.</div>';
if ($defaultPw) echo '<div class="banner warn">The default admin password is still in use. <a href="' . e(url('dashboard/settings.php')) . '"><b>Change it now</b></a>.</div>';
?>
<section class="kpis">
  <div class="card kpi"><span class="ic" style="background:var(--lemon)"><?= ic('users') ?></span><div><b><?= number_format($users) ?></b><small>Students &amp; staff (+<?= $newUsers ?> this week)</small></div></div>
  <div class="card kpi"><span class="ic" style="background:var(--pop)"><?= ic('spark') ?></span><div><b><?= number_format($plusN) ?></b><small>Plus members</small></div></div>
  <?php if (is_super()): ?><div class="card kpi"><span class="ic" style="background:var(--brand);color:var(--brand-ink)"><?= ic('coin') ?></span><div><b><?= e(number_format($rev, 2)) ?></b><small>Revenue this month (<?= e(number_format($revAll, 2)) ?> total)</small></div></div>
  <?php else: ?><div class="card kpi"><span class="ic" style="background:var(--brand);color:var(--brand-ink)"><?= ic('docchk') ?></span><div><b><?= number_format($pending) ?></b><small>Papers waiting for review</small></div></div><?php endif; ?>
  <div class="card kpi"><span class="ic" style="background:var(--lemon)"><?= ic('file') ?></span><div><b><?= number_format($papers) ?></b><small>Published papers</small></div></div>
</section>
<section class="card"><h2>Needs attention</h2>
  <div class="attn" style="margin-top:12px">
    <?php foreach ([['papers', $pending, 'papers waiting for review', 'admin/papers.php?status=pending', 'approve'], ['pay', $payWait, 'payments waiting to be confirmed', 'admin/payments.php?tab=txn&status=pending', 'plans'], ['jobs', $failed, 'AI jobs failed', 'admin/processing.php?status=failed', 'approve'], ['drafts', $drafts, 'AI answers waiting to be published', 'admin/processing.php', 'approve'], ['reports', $reports, 'open reports', 'admin/reports.php', 'reports'], ['outlines', $outFailed, 'course outlines failed', 'admin/teaching.php?tab=outlines&status=failed', 'admin']] as [$k, $c, $t, $h, $perm]): if (!Auth::can($perm)) continue; ?>
    <a class="it" href="<?= e(url($h)) ?>" style="color:inherit;text-decoration:none"><span class="n" style="color:<?= $c ? 'var(--bad)' : 'var(--ok)' ?>"><?= $c ?></span><span class="t"><?= e($t) ?></span><span aria-hidden="true">&rsaquo;</span></a>
    <?php endforeach; ?>
    <div class="it"><span class="n"><?= $running ?></span><span class="t">AI jobs running or queued &middot; <?= $recs ?> recordings in total</span></div>
    <?php if (Auth::can('admin')): ?><a class="it" href="<?= e(url('admin/teaching.php')) ?>" style="color:inherit;text-decoration:none"><span class="n"><?= $lessonsMonth ?></span><span class="t">lesson notes written by AI this month &middot; <?= $lessonsLive ?> live for students</span><span aria-hidden="true">&rsaquo;</span></a><?php endif; ?>
  </div></section>
<div class="two">
  <section class="card"><div class="hd"><div><h2>Sign-ups</h2><p class="sub">Last 14 days</p></div></div>
    <svg class="chart" viewBox="0 0 320 120" role="img" aria-label="Sign-ups per day"><?php $i = 0; foreach ($signups as $d => $c): $h = $c ? max(4, round(80 * $c / $mx)) : 2; ?><rect class="bar<?= $d === gmdate('Y-m-d') ? ' today' : '' ?>" style="--i:<?= $i ?>" x="<?= 4 + $i * 22.5 ?>" y="<?= 90 - $h ?>" width="16" height="<?= $h ?>" rx="4"><title><?= e($d) ?>: <?= $c ?></title></rect><?php $i++; endforeach; ?><line class="gl" x1="0" y1="91" x2="320" y2="91"/><text x="4" y="110"><?= e(gmdate('j M', strtotime(array_key_first($signups)))) ?></text><text x="316" y="110" text-anchor="end">Today</text></svg></section>
  <section class="card dt"><?= dt_head('Most viewed papers', 'Top 5 published') ?>
    <div class="dtwrap"><table class="dtable"><thead><tr><th>Course</th><th>Paper</th><th class="num">Views</th></tr></thead><tbody>
    <?php foreach ($top as $p): ?><tr data-href="<?= e(url('admin/papers.php?id=' . $p['id'])) ?>"><td class="nw"><span class="code"><?= e($p['code']) ?></span></td><td class="dmain"><a class="dtt" href="<?= e(url('admin/papers.php?id=' . $p['id'])) ?>"><?= e($p['title']) ?></a></td><td class="num"><?= (int)$p['views'] ?></td></tr>
    <?php endforeach; if (!$top) echo dt_empty(3, 'No views yet.'); ?></tbody></table></div></section>
</div>
<div class="two">
  <?php if (Auth::can('approve')): ?><section class="card dt"><?= dt_head('Papers to review', $pending . ' waiting', '<a class="btn sm line" href="' . e(url('admin/papers.php')) . '">Open queue</a>') ?>
    <div class="dtwrap"><table class="dtable"><thead><tr><th>Paper</th><th>Course</th><th>Uploaded by</th><th data-nosort><span class="vh">Actions</span></th></tr></thead><tbody>
    <?php foreach ($pend as $p): ?><tr data-href="<?= e(url('admin/papers.php?id=' . $p['id'])) ?>"><td class="dmain"><a class="dtt" href="<?= e(url('admin/papers.php?id=' . $p['id'])) ?>"><?= e($p['title']) ?></a></td><td class="nw"><span class="code"><?= e($p['code']) ?></span></td><td><?= e($p['full_name']) ?></td>
      <td class="act"><a class="btn sm line" href="<?= e(url('admin/papers.php?id=' . $p['id'])) ?>">Review</a></td></tr>
    <?php endforeach; if (!$pend) echo dt_empty(4, 'The queue is empty.'); ?></tbody></table></div></section><?php endif; ?>
  <section class="card"><div class="hd"><h2>Recent activity</h2><?php if (Auth::can('admin')): ?><a class="btn sm line" href="<?= e(url('admin/audit.php')) ?>">Audit log</a><?php endif; ?></div>
    <ul class="feed"><?php foreach ($log as $l): ?><li><span class="d"></span><div><?= e($l['action']) ?><small><?= e($l['full_name'] ?? 'System') ?> &middot; <?= e(ago($l['created_at'])) ?></small></div></li><?php endforeach; ?></ul></section>
</div>
<?php include ROOT . '/includes/dashboard_footer.php'; ?>
