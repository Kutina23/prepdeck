<?php
require __DIR__ . '/../../app/bootstrap.php';
$u = require_perm('admin'); $adminArea = true; $active = 'teaching'; $title = 'Lecturer tools'; $scripts = ['forms.js', 'process.js'];
$act = 'admin/teaching.php';
$settings = [   // key => [label, hint]
    'lesson_quota' => ['AI lesson notes per lecturer per month', '0 = no limit. Rewrites count once per note per month.'],
    'outline_max_topics' => ['Most topics read from one outline', 'Longer outlines are cut off after this many topics.'],
    'outline_max_pages' => ['Longest outline PDF (pages)', 'Outlines only need topics and weeks, not the full course pack.'],
];
$flags = [
    'lesson_tools_on' => ['Lecturers can turn course outlines into lesson notes', 'Needs PrepDeck Plus (paid, granted, or the lecturer role benefit).'],
    'lecturer_plus' => ['Lecturers get Plus features free (role benefit)', 'Off = each lecturer needs their own Plus subscription for the outline tool.'],
    'lesson_autopublish' => ['AI lesson notes go to students straight away', 'Off = they are saved as drafts until the lecturer publishes them.'],
    'lesson_students_plus' => ['Students need Plus to read lesson notes', 'Off = every signed-in student can read published lesson notes.'],
];
// who gets Plus is decided by the platform owner, so those switches are not shown to school admins
if (!is_super()) unset($flags['lecturer_plus'], $flags['lesson_students_plus']);
if (is_post()) {
    form_guard(); $a = $_POST['a'] ?? ''; $id = (int)($_POST['id'] ?? 0);
    $own = ['grant' => 'users', 'revoke' => 'users', 'note_status' => 'lesson_notes', 'note_delete' => 'lesson_notes', 'outline_retry' => 'course_outlines', 'outline_delete' => 'course_outlines'][$a] ?? null;
    if ($own && !in_my_school($own, $id)) { flash('bad', 'Not found in this school.'); redirect_self_or_ajax(); }
    if ($a === 'settings' && !is_super()) { flash('bad', 'PrepDeck AI settings are managed by the platform owner.'); redirect_self_or_ajax(); }
    if ($a === 'settings') {
        foreach ($settings as $k => $_) if (isset($_POST[$k])) Settings::set($k, (string)max(0, min(10000, (int)$_POST[$k])));
        foreach ($flags as $k => $_) Settings::set($k, isset($_POST[$k]) ? '1' : '0');
        Audit::log('changed lecturer tool settings', 'settings'); flash('ok', 'Lecturer tool settings saved.');
    } elseif (($a === 'grant' || $a === 'revoke') && !is_super()) { flash('bad', 'Plus is managed by the platform owner.');
    } elseif ($a === 'grant' || $a === 'revoke') {
        $t = row('SELECT id,email FROM users WHERE id=? AND role_id=3', [$id]);
        if (!$t) flash('bad', 'Lecturer not found.');
        elseif ($a === 'grant') { $p = Validator::in($_POST['period'] ?? '', array_keys(Billing::DAYS)) ?? 'semester'; $end = Billing::activate($id, $p); Audit::log("granted Plus ($p) to lecturer {$t['email']}", 'user', $id); flash('ok', 'Plus granted until ' . fdate($end) . '.'); }
        else { Billing::revoke($id); Audit::log("revoked Plus from lecturer {$t['email']}", 'user', $id); flash('ok', 'Plus removed. The role benefit still applies if it is switched on.'); }
    } elseif ($a === 'note_status') {
        $s = Validator::in($_POST['status'] ?? '', ['published', 'hidden', 'draft']);
        if ($s && val('SELECT COUNT(*) FROM lesson_notes WHERE id=?', [$id])) { Lessons::publish($id, $s); Audit::log("lesson note $id set to $s (admin)", 'lesson', $id); flash('ok', 'Lesson note ' . ($s === 'hidden' ? 'hidden from students' : $s) . '.'); }
    } elseif ($a === 'note_delete') {
        $n = row('SELECT topic_id FROM lesson_notes WHERE id=?', [$id]);
        if ($n) { q('DELETE FROM lesson_notes WHERE id=?', [$id]); if ($n['topic_id']) q("UPDATE outline_topics SET note_status='none' WHERE id=?", [$n['topic_id']]); Audit::log("deleted lesson note $id (admin)", 'lesson', $id); flash('ok', 'Lesson note deleted.'); }
    } elseif ($a === 'outline_retry') {
        $o = row('SELECT * FROM course_outlines WHERE id=?', [$id]);
        if (!$o) flash('bad', 'Outline not found.');
        elseif (!AiClient::configured()) flash('bad', 'AI_API_KEY is not set in .env.');
        else { $failed = array_map('intval', array_column(rows("SELECT id FROM outline_topics WHERE outline_id=? AND note_status='failed'", [$id]), 'id'));
            Lessons::enqueue($o, $failed, (int)$o['user_id']); Audit::log("re-ran course outline $id (admin)", 'outline', $id); flash('ok', $failed ? 'Retrying ' . count($failed) . ' failed note(s).' : 'Reading the outline again.'); }
    } elseif ($a === 'outline_delete') {
        Lessons::deleteOutline($id, !empty($_POST['with_notes'])); Audit::log("deleted course outline $id (admin)", 'outline', $id); flash('ok', 'Outline deleted.'); redirect_or_ajax('admin/teaching.php?tab=outlines');
    }
    redirect_self_or_ajax();
}
$tab = Validator::in($_GET['tab'] ?? 'lecturers', ['lecturers', 'outlines', 'notes', 'settings']) ?? 'lecturers';
$yes = fn(bool $b, string $y = 'On', string $n = 'Off') => '<span class="pill ' . ($b ? 'ok' : 'free') . '">' . ($b ? $y : $n) . '</span>';
$S = in_school();
$k = row("SELECT (SELECT COUNT(*) FROM users WHERE role_id=3 AND $S) lect, (SELECT COUNT(*) FROM course_outlines WHERE $S) outl, (SELECT COUNT(*) FROM lesson_notes WHERE status='published' AND $S) pub,
  (SELECT COUNT(*) FROM lesson_notes WHERE status='draft' AND $S) dr, (SELECT COUNT(*) FROM lesson_notes WHERE generated_at>=DATE_FORMAT(NOW(),'%Y-%m-01') AND $S) month,
  (SELECT COALESCE(SUM(tokens_in+tokens_out),0) FROM processing_jobs WHERE source_type='outline' AND created_at>=DATE_FORMAT(NOW(),'%Y-%m-01') AND $S) tok,
  (SELECT COUNT(*) FROM course_outlines WHERE ai_status='failed' AND $S) failed");
$benefit = setting('lecturer_plus', '1') === '1';
$id = (int)($_GET['id'] ?? 0);
if ($tab === 'outlines' && $id) {
    $o = row('SELECT o.*,c.code,c.title course,u.full_name,u.email FROM course_outlines o JOIN courses c ON c.id=o.course_id JOIN users u ON u.id=o.user_id WHERE o.id=? AND ' . in_school('o'), [$id]);
    if (!$o) { flash('bad', 'Outline not found.'); redirect('admin/teaching.php?tab=outlines'); }
    $title = $o['title'];
    $topics = rows('SELECT t.*,n.id note_id,n.status nstatus,n.word_count,n.views FROM outline_topics t LEFT JOIN lesson_notes n ON n.topic_id=t.id WHERE t.outline_id=? ORDER BY t.position,t.id', [$id]);
    $jobs = rows("SELECT id,status,message,tokens_in+tokens_out tok,created_at FROM processing_jobs WHERE source_type='outline' AND source_id=? ORDER BY id DESC LIMIT 5", [$id]);
    include ROOT . '/includes/dashboard_header.php';
    echo page_head($o['title'], $o['code'] . ' · ' . $o['full_name'] . ' · ' . count($topics) . ' topics', '<a class="btn sm line" href="' . e(url($act . '?tab=outlines')) . '">All outlines</a><a class="btn sm line" href="' . e(url('api/outlines.php?a=download&id=' . $id)) . '" target="_blank" rel="noopener">' . ic('file') . 'PDF</a>'
        . ($o['ai_status'] === 'failed' || array_filter($topics, fn($t) => $t['note_status'] === 'failed') ? row_form($act, ['a' => 'outline_retry', 'id' => $id], 'Retry failed', 'btn sm line', 'Run PrepDeck AI again for the failed parts of this outline? It uses AI credit.') : ''));
    echo ai_live('outline', $id); ?>
    <section class="card"><h2>Outline</h2><p class="note" style="margin-top:6px"><?= e($o['email']) ?> · <?= e($o['course']) ?> · <?= e(Lessons::DEPTH[$o['depth']]) ?> · uploaded <?= e(ago($o['created_at'])) ?> · <?= status_pill($o['ai_status'] === 'none' ? 'queued' : $o['ai_status']) ?></p>
      <?php if ($o['summary']): ?><p style="margin-top:8px;line-height:1.6"><?= e($o['summary']) ?></p><?php endif; ?><?php if ($o['instructions']): ?><p class="note" style="margin-top:6px">Lecturer's instructions: “<?= e($o['instructions']) ?>”</p><?php endif; ?></section>
    <section class="card dt"><?= dt_head('Topics and lesson notes', count($topics) . ' topics') ?>
      <div class="dtwrap"><table class="dtable"><thead><tr><th class="num">#</th><th>Week</th><th>Topic</th><th>Note</th><th class="num">Words</th><th class="num">Reads</th></tr></thead><tbody>
      <?php foreach ($topics as $i => $t): ?><tr><td class="num"><?= $i + 1 ?></td><td class="nw muted"><?= e($t['week'] ?: '–') ?></td><td class="dmain"><span class="dtt"><?= e($t['title']) ?></span></td>
        <td><?= $t['note_id'] ? '<a href="' . e(url($act . '?tab=notes&view=' . $t['note_id'])) . '">' . status_pill($t['nstatus']) . '</a>' : status_pill($t['note_status'] === 'none' ? 'not written' : $t['note_status']) ?></td><td class="num"><?= $t['note_id'] ? number_format((int)$t['word_count']) : '–' ?></td><td class="num"><?= $t['note_id'] ? number_format((int)$t['views']) : '–' ?></td></tr>
      <?php endforeach; if (!$topics) echo dt_empty(6, 'No topics yet.'); ?></tbody></table></div></section>
    <section class="card dt"><?= dt_head('AI jobs', 'Last 5 for this outline', '<a class="btn sm line" href="' . e(url('admin/processing.php?src=outline')) . '">AI processing</a>') ?>
      <div class="dtwrap"><table class="dtable"><thead><tr><th class="num">Job</th><th>Result</th><th class="num">Tokens</th><th>Status</th><th>When</th></tr></thead><tbody>
      <?php foreach ($jobs as $j): ?><tr><td class="num">#<?= (int)$j['id'] ?></td><td class="dmain"><span class="clip"><?= e($j['message'] ?: '–') ?></span></td><td class="num"><?= number_format((int)$j['tok']) ?></td><td><?= status_pill($j['status']) ?></td><td class="nw muted"><?= e(ago($j['created_at'])) ?></td></tr>
      <?php endforeach; if (!$jobs) echo dt_empty(5, 'No jobs.'); ?></tbody></table></div></section>
    <section class="card"><h2>Delete</h2><form method="post" data-action="<?= $act ?>" style="margin-top:8px"><?= csrf_field() ?><input type="hidden" name="a" value="outline_delete"><input type="hidden" name="id" value="<?= $id ?>"><label class="chk"><input type="checkbox" name="with_notes" value="1">Also delete its lesson notes</label><button class="btn sm danger" data-confirm="Delete this outline?" style="margin-top:8px">Delete outline</button></form></section>
    <?php include ROOT . '/includes/dashboard_footer.php'; exit;
}
include ROOT . '/includes/dashboard_header.php';
echo page_head('Lecturer tools', 'Course outlines and AI lesson notes in ' . school_name() . '.', is_super() ? modal_btn('tpl-lt-settings', 'Lecturer tool settings', 'Settings', 'btn line', 'gear', 'wide') : '');
if (!Lessons::enabled()) echo '<div class="banner warn">Lesson note generation is switched off. Lecturers can still edit and publish the notes they have.</div>';
if (!AiClient::configured()) echo '<div class="banner warn">PrepDeck AI is not connected yet, so outlines cannot be read. The platform owner sets this up.</div>';
?>
<section class="kpis">
  <div class="card kpi"><span class="ic" style="background:var(--lemon)"><?= ic('users2') ?></span><div><b><?= (int)$k['lect'] ?></b><small>Lecturers</small></div></div>
  <div class="card kpi"><span class="ic" style="background:var(--pop)"><?= ic('book') ?></span><div><b><?= (int)$k['outl'] ?></b><small>Course outlines<?= $k['failed'] ? ' · <span style="color:var(--bad)">' . (int)$k['failed'] . ' failed</span>' : '' ?></small></div></div>
  <div class="card kpi"><span class="ic" style="background:var(--brand);color:var(--brand-ink)"><?= ic('lesson') ?></span><div><b><?= (int)$k['pub'] ?></b><small>Lesson notes live (<?= (int)$k['dr'] ?> drafts)</small></div></div>
  <div class="card kpi"><span class="ic" style="background:var(--lemon)"><?= ic('spark') ?></span><div><b><?= (int)$k['month'] ?></b><small>Written by AI this month · <?= number_format($k['tok'] / 1000, 1) ?>k tokens</small></div></div>
</section>
<div class="tabs" role="navigation"><?php foreach (['lecturers' => 'Lecturers', 'outlines' => 'Course outlines', 'notes' => 'Lesson notes', 'settings' => 'Settings'] as $kk => $l): ?><a class="tab" href="?tab=<?= $kk ?>" aria-selected="<?= $tab === $kk ? 'true' : 'false' ?>"><?= $l ?></a><?php endforeach; ?></div>
<?php
$per = per_page(15); $page = page_no(); $q = trim((string)($_GET['q'] ?? ''));
if ($tab === 'lecturers'):
    $w = 'u.role_id=3 AND ' . in_school('u'); $a = []; if ($q !== '') { $w .= ' AND (u.full_name LIKE ? OR u.email LIKE ?)'; array_push($a, "%$q%", "%$q%"); }
    $pf = $_GET['plus'] ?? ''; if ($pf === 'paid') $w .= ' AND u.plus=1 AND (u.plus_expires IS NULL OR u.plus_expires>NOW())'; if ($pf === 'none') $w .= ' AND NOT (u.plus=1 AND (u.plus_expires IS NULL OR u.plus_expires>NOW()))';
    $total = (int)val("SELECT COUNT(*) FROM users u WHERE $w", $a);
    $list = rows("SELECT u.id,u.full_name,u.email,u.plus,u.plus_expires,u.last_login_at,d.name dept,
      (SELECT COUNT(*) FROM staff_courses s JOIN courses sc ON sc.id=s.course_id WHERE s.user_id=u.id AND sc.$S) courses, (SELECT COUNT(*) FROM course_outlines o WHERE o.user_id=u.id AND o.$S) outl,
      (SELECT COUNT(*) FROM lesson_notes n WHERE n.user_id=u.id AND n.status='published' AND n.$S) pub, (SELECT COUNT(*) FROM lesson_notes n WHERE n.user_id=u.id AND n.status='draft' AND n.$S) dr,
      (SELECT COUNT(*) FROM lesson_notes n WHERE n.user_id=u.id AND n.generated_at>=DATE_FORMAT(NOW(),'%Y-%m-01') AND n.$S) month,
      (SELECT COALESCE(SUM(j.tokens_in+j.tokens_out),0) FROM processing_jobs j WHERE j.owner_id=u.id AND j.source_type='outline' AND j.$S) tok
      FROM users u LEFT JOIN departments d ON d.id=u.department_id WHERE $w ORDER BY u.full_name LIMIT $per OFFSET " . (($page - 1) * $per), $a);
    $quota = Lessons::quota(); ?>
  <section class="card dt"><?= dt_head('Lecturers', number_format($total) . ' in this view · ' . ($benefit ? 'Plus is included with the lecturer role' : 'each lecturer needs their own Plus'), '<a class="btn sm line" href="' . e(url('admin/staff.php?role=3')) . '">Access &amp; courses</a>') ?>
    <form class="toolbar" method="get"><input type="hidden" name="tab" value="lecturers"><label class="search"><?= ic('search', 'i') ?><span class="vh">Search</span><input name="q" value="<?= e($q) ?>" type="search" placeholder="Name or email"></label>
      <select class="in" name="plus" aria-label="Plus"><option value="">Any plan</option><option value="paid"<?= $pf === 'paid' ? ' selected' : '' ?>>Own Plus (paid or granted)</option><option value="none"<?= $pf === 'none' ? ' selected' : '' ?>>No own Plus</option></select><button class="btn sm">Filter</button></form>
    <div class="dtwrap"><table class="dtable"><thead><tr><th>Lecturer</th><th class="num">Courses</th><th>Plus</th><th class="num">Outlines</th><th class="num">Live notes</th><th class="num">Drafts</th><th class="num">This month</th><th class="num">Tokens</th><th>Last seen</th><th data-nosort><span class="vh">Actions</span></th></tr></thead><tbody>
    <?php foreach ($list as $r): $own = (int)$r['plus'] === 1 && (empty($r['plus_expires']) || strtotime($r['plus_expires'] . ' UTC') > time()); ?><tr>
      <td class="dmain"><div class="ucell"><span class="av"><?= e(initials($r['full_name'])) ?></span><div style="min-width:0"><a class="dtt" href="<?= e(url('admin/staff.php?id=' . $r['id'])) ?>"><?= e($r['full_name']) ?></a><span class="ts"><?= e($r['email']) ?><?= $r['dept'] ? ' · ' . e($r['dept']) : '' ?></span></div></div></td>
      <td class="num"><span class="pill <?= $r['courses'] ? 'ok' : 'wait' ?>"><?= (int)$r['courses'] ?></span></td>
      <td><?= $own ? '<span class="pill plus">Own' . ($r['plus_expires'] ? ' · ' . e(fdate($r['plus_expires'], 'j M')) : '') . '</span>' : ($benefit ? '<span class="pill ok">Role benefit</span>' : '<span class="pill free">Free</span>') ?></td>
      <td class="num"><?= (int)$r['outl'] ?></td><td class="num"><?= (int)$r['pub'] ?></td><td class="num"><?= (int)$r['dr'] ?></td>
      <td class="num"><?= (int)$r['month'] ?><?= $quota ? '<span class="muted">/' . $quota . '</span>' : '' ?></td><td class="num"><?= number_format($r['tok'] / 1000, 1) ?>k</td>
      <td class="nw muted"><?= e(ago($r['last_login_at'])) ?></td>
      <td class="act"><div class="rowact"><a class="btn sm line" href="<?= e(url($act . '?tab=notes&user=' . $r['id'])) ?>">Notes</a>
        <?php if (is_super()): ?><?= $own ? row_form($act, ['a' => 'revoke', 'id' => $r['id']], 'Remove Plus', 'btn sm danger', 'Remove the Plus subscription from ' . $r['full_name'] . '?') : modal_btn('tpl-g' . $r['id'], 'Give Plus to ' . $r['full_name'], 'Give Plus', 'btn sm') ?></div>
        <?php endif; if (!$own && is_super()): ?><template id="tpl-g<?= $r['id'] ?>"><form class="card mform" method="post" data-action="<?= $act ?>"><?= csrf_field() ?><input type="hidden" name="a" value="grant"><input type="hidden" name="id" value="<?= $r['id'] ?>"><h2>Give Plus</h2><p class="note">No payment is taken. Use this for staff your school pays for.</p>
          <div class="field"><label for="gp<?= $r['id'] ?>">Period</label><select class="in" id="gp<?= $r['id'] ?>" name="period"><?php foreach (Billing::LABEL as $pk => $pl): ?><option value="<?= $pk ?>"<?= $pk === 'semester' ? ' selected' : '' ?>><?= e($pl) ?> (<?= Billing::DAYS[$pk] ?> days)</option><?php endforeach; ?></select></div><button class="btn">Give Plus</button></form></template><?php endif; ?></td></tr>
    <?php endforeach; if (!$list) echo dt_empty(10, 'No lecturers yet. Add them in Lecturers & reps.'); ?></tbody></table></div>
    <?= dt_foot($total, $per, $page, ['tab' => 'lecturers', 'q' => $q, 'plus' => $pf]) ?></section>
<?php elseif ($tab === 'outlines'):
    $st = Validator::in($_GET['status'] ?? '', ['queued', 'running', 'done', 'failed']) ?? '';
    $w = [in_school('o')]; $a = []; if ($st) { $w[] = 'o.ai_status=?'; $a[] = $st; } if ($q !== '') { $w[] = '(o.title LIKE ? OR c.code LIKE ? OR u.full_name LIKE ?)'; array_push($a, "%$q%", "%$q%", "%$q%"); }
    $W = implode(' AND ', $w); $total = (int)val("SELECT COUNT(*) FROM course_outlines o JOIN courses c ON c.id=o.course_id JOIN users u ON u.id=o.user_id WHERE $W", $a);
    $list = rows("SELECT o.id,o.title,o.ai_status,o.created_at,c.code,u.full_name,(SELECT COUNT(*) FROM outline_topics t WHERE t.outline_id=o.id) topics,(SELECT COUNT(*) FROM lesson_notes n WHERE n.outline_id=o.id) notes,
      (SELECT COALESCE(SUM(j.tokens_in+j.tokens_out),0) FROM processing_jobs j WHERE j.source_type='outline' AND j.source_id=o.id) tok
      FROM course_outlines o JOIN courses c ON c.id=o.course_id JOIN users u ON u.id=o.user_id WHERE $W ORDER BY o.id DESC LIMIT $per OFFSET " . (($page - 1) * $per), $a); ?>
  <section class="card dt"><?= dt_head('Course outlines', number_format($total) . ' in this view') ?>
    <form class="toolbar" method="get"><input type="hidden" name="tab" value="outlines"><label class="search"><?= ic('search', 'i') ?><span class="vh">Search</span><input name="q" value="<?= e($q) ?>" type="search" placeholder="Title, course or lecturer"></label>
      <select class="in" name="status" aria-label="AI status"><option value="">Any status</option><?php foreach (['queued' => 'Queued', 'running' => 'Running', 'done' => 'Done', 'failed' => 'Failed'] as $kk => $l): ?><option value="<?= $kk ?>"<?= $st === $kk ? ' selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select><button class="btn sm">Filter</button></form>
    <div class="dtwrap"><table class="dtable"><thead><tr><th>Outline</th><th>Course</th><th>Lecturer</th><th class="num">Topics</th><th class="num">Notes</th><th class="num">Tokens</th><th>AI</th><th>Uploaded</th><th data-nosort><span class="vh">Actions</span></th></tr></thead><tbody>
    <?php foreach ($list as $r): $href = url($act . '?tab=outlines&id=' . $r['id']); ?><tr data-href="<?= e($href) ?>">
      <td class="dmain"><a class="dtt" href="<?= e($href) ?>"><?= e($r['title']) ?></a></td><td class="nw"><span class="code"><?= e($r['code']) ?></span></td><td><?= e($r['full_name']) ?></td>
      <td class="num"><?= (int)$r['topics'] ?></td><td class="num"><?= (int)$r['notes'] ?></td><td class="num"><?= number_format($r['tok'] / 1000, 1) ?>k</td><td><?= status_pill($r['ai_status'] === 'none' ? 'queued' : $r['ai_status']) ?></td>
      <td class="nw muted" data-v="<?= e($r['created_at']) ?>"><?= e(ago($r['created_at'])) ?></td><td class="act"><a class="btn sm line" href="<?= e($href) ?>">Open</a></td></tr>
    <?php endforeach; if (!$list) echo dt_empty(9, 'No course outlines yet.'); ?></tbody></table></div>
    <?= dt_foot($total, $per, $page, ['tab' => 'outlines', 'q' => $q, 'status' => $st]) ?></section>
<?php elseif ($tab === 'notes'):
    $view = (int)($_GET['view'] ?? 0);
    if ($view && ($n = row('SELECT n.*,c.code,u.full_name FROM lesson_notes n JOIN courses c ON c.id=n.course_id JOIN users u ON u.id=n.user_id WHERE n.id=? AND ' . in_school('n'), [$view]))): ?>
  <section class="card"><div class="hd"><div><h2><?= e($n['title']) ?></h2><p class="sub"><?= e($n['code']) ?><?= $n['week'] ? ' · ' . e($n['week']) : '' ?> · <?= e($n['full_name']) ?> · <?= number_format((int)$n['word_count']) ?> words · <?= number_format((int)$n['views']) ?> reads · <?= status_pill($n['status']) ?></p></div>
    <div class="rowact"><a class="btn sm line" href="<?= e(url($act . '?tab=notes')) ?>">All notes</a><?= $n['status'] !== 'hidden' ? row_form($act, ['a' => 'note_status', 'id' => $n['id'], 'status' => 'hidden'], 'Hide from students', 'btn sm danger', 'Hide this lesson note? The lecturer cannot publish it again until you do.') : row_form($act, ['a' => 'note_status', 'id' => $n['id'], 'status' => 'published'], 'Publish', 'btn sm') ?></div></div></section>
  <article class="card guide"><?= guide_html($n['body']) ?></article>
<?php else:
    $st = Validator::in($_GET['status'] ?? '', ['draft', 'published', 'hidden']) ?? ''; $uf = (int)($_GET['user'] ?? 0);
    $w = [in_school('n')]; $a = []; if ($st) { $w[] = 'n.status=?'; $a[] = $st; } if ($uf) { $w[] = 'n.user_id=?'; $a[] = $uf; }
    if ($q !== '') { $w[] = '(n.title LIKE ? OR c.code LIKE ? OR u.full_name LIKE ?)'; array_push($a, "%$q%", "%$q%", "%$q%"); }
    $W = implode(' AND ', $w); $total = (int)val("SELECT COUNT(*) FROM lesson_notes n JOIN courses c ON c.id=n.course_id JOIN users u ON u.id=n.user_id WHERE $W", $a);
    $list = rows("SELECT n.id,n.title,n.week,n.status,n.word_count,n.views,n.ai_generated,n.updated_at,c.code,u.full_name FROM lesson_notes n JOIN courses c ON c.id=n.course_id JOIN users u ON u.id=n.user_id WHERE $W ORDER BY n.id DESC LIMIT $per OFFSET " . (($page - 1) * $per), $a);
    $who = $uf ? (string)val('SELECT full_name FROM users WHERE id=? AND ' . in_school(), [$uf]) : ''; ?>
  <section class="card dt"><?= dt_head('Lesson notes', number_format($total) . ' in this view' . ($who ? ' · ' . $who : ''), $uf ? '<a class="btn sm line" href="' . e(url($act . '?tab=notes')) . '">All lecturers</a>' : '') ?>
    <form class="toolbar" method="get"><input type="hidden" name="tab" value="notes"><?php if ($uf): ?><input type="hidden" name="user" value="<?= $uf ?>"><?php endif; ?><label class="search"><?= ic('search', 'i') ?><span class="vh">Search</span><input name="q" value="<?= e($q) ?>" type="search" placeholder="Title, course or lecturer"></label>
      <select class="in" name="status" aria-label="Status"><option value="">Any status</option><?php foreach (['draft' => 'Drafts', 'published' => 'Published', 'hidden' => 'Hidden'] as $kk => $l): ?><option value="<?= $kk ?>"<?= $st === $kk ? ' selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select><button class="btn sm">Filter</button></form>
    <div class="dtwrap"><table class="dtable"><thead><tr><th>Lesson</th><th>Course</th><th>Lecturer</th><th class="num">Words</th><th class="num">Reads</th><th>Status</th><th>Updated</th><th data-nosort><span class="vh">Actions</span></th></tr></thead><tbody>
    <?php foreach ($list as $r): $href = url($act . '?tab=notes&view=' . $r['id']); ?><tr data-href="<?= e($href) ?>">
      <td class="dmain"><a class="dtt" href="<?= e($href) ?>"><?= e($r['title']) ?></a><span class="ts"><?= $r['week'] ? e($r['week']) . ' · ' : '' ?><?= $r['ai_generated'] ? 'PrepDeck AI' : 'Written by the lecturer' ?></span></td><td class="nw"><span class="code"><?= e($r['code']) ?></span></td><td><?= e($r['full_name']) ?></td>
      <td class="num"><?= number_format((int)$r['word_count']) ?></td><td class="num"><?= number_format((int)$r['views']) ?></td><td><?= status_pill($r['status']) ?></td><td class="nw muted" data-v="<?= e($r['updated_at']) ?>"><?= e(ago($r['updated_at'])) ?></td>
      <td class="act"><div class="rowact"><a class="btn sm line" href="<?= e($href) ?>">Read</a>
        <?= $r['status'] !== 'hidden' ? row_form($act, ['a' => 'note_status', 'id' => $r['id'], 'status' => 'hidden'], 'Hide', 'btn sm line', 'Hide this lesson note from students?') : row_form($act, ['a' => 'note_status', 'id' => $r['id'], 'status' => 'published'], 'Publish', 'btn sm') ?>
        <?= row_form($act, ['a' => 'note_delete', 'id' => $r['id']], 'Delete', 'btn sm danger', 'Delete this lesson note for good?') ?></div></td></tr>
    <?php endforeach; if (!$list) echo dt_empty(8, 'No lesson notes here.'); ?></tbody></table></div>
    <?= dt_foot($total, $per, $page, ['tab' => 'notes', 'q' => $q, 'status' => $st, 'user' => $uf ?: '']) ?></section>
<?php endif;
else: ?>
  <section class="card dt"><?= dt_head('Lecturer tool settings', 'Set by the platform owner for every school.', is_super() ? modal_btn('tpl-lt-settings', 'Lecturer tool settings', 'Edit', 'btn sm', 'edit', 'wide') : '') ?>
    <div class="dtwrap"><table class="dtable"><thead><tr><th>Setting</th><th>Value</th></tr></thead><tbody>
    <?php foreach ($flags as $kk => [$l, $h]): ?><tr><td class="dmain"><span class="dtt"><?= e($l) ?></span><span class="ts"><?= e($h) ?></span></td><td><?= $yes(setting($kk, in_array($kk, ['lesson_tools_on', 'lecturer_plus'], true) ? '1' : '0') === '1', 'Yes', 'No') ?></td></tr><?php endforeach; ?>
    <?php foreach ($settings as $kk => [$l, $h]): ?><tr><td class="dmain"><span class="dtt"><?= e($l) ?></span><span class="ts"><?= e($h) ?></span></td><td><?= e((string)setting($kk, ['lesson_quota' => '60', 'outline_max_topics' => '30', 'outline_max_pages' => '30'][$kk])) ?></td></tr><?php endforeach; ?>
      <tr><td class="dmain"><span class="dtt">AI model</span><span class="ts">Set by the platform owner in Platform settings.</span></td><td><?= e(AiClient::defaultModel()) ?></td></tr>
    </tbody></table></div></section>
<?php endif; ?>
<template id="tpl-lt-settings">
<form class="card mform" method="post" data-action="<?= $act ?>"><?= csrf_field() ?><input type="hidden" name="a" value="settings"><h2>Lecturer tools</h2>
  <?php foreach ($flags as $kk => [$l, $h]): ?><label class="chk" style="align-items:flex-start"><input type="checkbox" name="<?= $kk ?>" value="1"<?= setting($kk, in_array($kk, ['lesson_tools_on', 'lecturer_plus'], true) ? '1' : '0') === '1' ? ' checked' : '' ?>><span><?= e($l) ?><br><small class="note"><?= e($h) ?></small></span></label><?php endforeach; ?>
  <div class="fgrid2"><?php foreach ($settings as $kk => [$l, $h]): ?><div class="field"><label for="s-<?= $kk ?>"><?= e($l) ?></label><input class="in" id="s-<?= $kk ?>" name="<?= $kk ?>" type="number" min="0" max="10000" value="<?= e((string)setting($kk, ['lesson_quota' => '60', 'outline_max_topics' => '30', 'outline_max_pages' => '30'][$kk])) ?>"><span class="hint note"><?= e($h) ?></span></div><?php endforeach; ?></div>
  <button class="btn" type="submit">Save settings</button></form>
</template>
<?php include ROOT . '/includes/dashboard_footer.php'; ?>
