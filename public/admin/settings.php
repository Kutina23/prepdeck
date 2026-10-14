<?php
require __DIR__ . '/../../app/bootstrap.php';
$u = require_perm('settings'); $adminArea = true; $active = 'settings'; $title = 'Settings'; $scripts = ['forms.js'];
$isAdmin = Auth::can('admin'); $super = is_super(); $school = Tenant::school();
if (is_post()) {
    form_guard(); $a = $_POST['a'] ?? '';
    // email, connection tests and the AI engine are platform-wide: only the platform owner touches them
    if (in_array($a, ['mail_test', 'mail_retry', 'ai_test', 'ai'], true) && !$super) { flash('bad', 'Only the platform owner can do that.'); redirect_self_or_ajax(); }
    if ($a === 'general') {
        if (!$school) { flash('bad', 'Pick a school first.'); redirect_self_or_ajax(); }
        foreach (['year_label', 'semester_label'] as $k) Settings::set($k, mb_substr(trim((string)($_POST[$k] ?? '')), 0, 120));
        foreach (['reg_open', 'upload_review', 'maintenance'] as $k) Settings::set($k, isset($_POST[$k]) ? '1' : '0');
        // the school's own profile; the name and web address are set by the platform owner
        $em = strtolower(trim((string)($_POST['contact_email'] ?? '')));
        q('UPDATE schools SET contact_email=?, phone=?, website=?, color=? WHERE id=?', [Validator::email($em) ? $em : null, mb_substr(trim((string)($_POST['phone'] ?? '')), 0, 40) ?: null,
            mb_substr(trim((string)($_POST['website'] ?? '')), 0, 190) ?: null, preg_match('/^#[0-9a-fA-F]{6}$/', (string)($_POST['color'] ?? '')) ? $_POST['color'] : null, sid()]);
        if ($super && ($n = Validator::str($_POST['school_name'] ?? '', 3, 160))) q('UPDATE schools SET name=? WHERE id=?', [$n, sid()]);
        if ($err = Tenant::saveLogo(sid(), $_FILES['logo'] ?? [])) flash('bad', $err);
        Audit::log('changed school settings', 'settings'); flash('ok', 'Settings saved.');
    } elseif ($a === 'ai' && $super) {
        if ($super) {   // engine and cost limits: platform-wide
            foreach (['ai_model' => 80, 'ai_tier' => 20, 'stt_language' => 20] as $k => $n) Settings::set($k, mb_substr(trim((string)($_POST[$k] ?? '')), 0, $n));
            foreach (['ai_max_pages', 'ai_max_chars', 'ai_predicted_count', 'rec_keep_audio_days', 'mat_max', 'mat_mb'] as $k) if (isset($_POST[$k])) Settings::set($k, (string)max(0, (int)$_POST[$k]));
        }
        Settings::set('ai_autopublish', isset($_POST['ai_autopublish']) ? '1' : '0'); Audit::log('changed AI settings', 'settings'); flash('ok', 'AI settings saved.');
    } elseif ($a === 'mail_test') {
        $to = trim((string)($_POST['to'] ?? ''));
        if (!Validator::email($to)) flash('bad', 'Enter a valid email address.');
        else {
            Mailer::test($to); $last = row('SELECT status,error FROM email_log ORDER BY id DESC LIMIT 1');
            if (($last['status'] ?? '') === 'sent') flash('ok', 'Sent to ' . $to . (Mailer::transport() === 'log' ? ' (development: written to storage/mail.log)' : '. Check the inbox and the spam folder.'));
            else flash('bad', 'Not sent: ' . ($last['error'] ?? 'unknown error'));
        }
    } elseif ($a === 'mail_retry') {
        $mid = (int)($_POST['id'] ?? 0);
        q("UPDATE email_log SET status='queued', attempts=GREATEST(attempts,1) WHERE id=? AND status<>'sent'", [$mid]); Mailer::retryQueued(5);
        $m = row('SELECT status,error FROM email_log WHERE id=?', [$mid]);
        flash(($m['status'] ?? '') === 'sent' ? 'ok' : 'bad', ($m['status'] ?? '') === 'sent' ? 'Sent.' : 'Still not sent: ' . ($m['error'] ?? ''));
    } elseif ($a === 'ai_test') {
        [$ok, $msg] = AiClient::test(); Settings::set('ai_last_test', gmdate('Y-m-d H:i') . ' UTC: ' . ($ok ? 'OK' : 'FAILED') . ' - ' . $msg); flash($ok ? 'ok' : 'bad', $msg);
    }
    redirect_self_or_ajax();
}
$tab = $_GET['tab'] ?? 'general';
$yes = fn(bool $b, string $y = 'On', string $n = 'Off') => $b ? '<span class="pill ok">' . $y . '</span>' : '<span class="pill free">' . $n . '</span>';
include ROOT . '/includes/dashboard_header.php';
echo page_head('Settings', 'School details, registration and staff permissions for ' . school_name() . '. PrepDeck AI and the public site are managed by the platform owner.');
// PrepDeck AI and the public site are run by the platform owner (Platform > Settings, Platform > Site content)
$tabs = ['general' => 'School', 'roles' => 'Roles & permissions'] + ($super ? ['email' => 'Email', 'system' => 'System'] : []);
if (!isset($tabs[$tab])) $tab = 'general';
?>
<div class="tabs" role="navigation"><?php foreach ($tabs as $k => $l): ?><a class="tab" href="?tab=<?= $k ?>" aria-selected="<?= $tab === $k ? 'true' : 'false' ?>"><?= $l ?></a><?php endforeach; ?></div>
 <?php if ($tab === 'general'): ?>
  <section class="card dt"><?= dt_head('School', 'Shown across the site and in the student dashboard.', $school ? modal_btn('tpl-gen', 'Edit school settings', 'Edit', 'btn sm', 'edit', 'wide') : '') ?>
    <div class="dtwrap"><table class="dtable"><thead><tr><th>Setting</th><th>Value</th></tr></thead><tbody>
      <tr><td class="dmain"><span class="dtt">School name</span><span class="ts">Set by the platform owner</span></td><td><?= $school ? e($school['name']) . ($school['short_name'] ? ' (' . e($school['short_name']) . ')' : '') : '<span class="muted">No school selected</span>' ?></td></tr>
      <?php if ($school): ?><tr><td class="dmain"><span class="dtt">Logo</span></td><td><?= $school['logo'] ? '<img src="' . e(url('api/schools.php?a=logo&id=' . $school['id'])) . '" alt="" style="height:40px;border-radius:8px">' : '<span class="muted">None (initials are shown)</span>' ?></td></tr>
      <tr><td class="dmain"><span class="dtt">Contact</span></td><td><?= e(implode(' · ', array_filter([$school['contact_email'], $school['phone'], $school['website']]))) ?: '<span class="muted">Not set</span>' ?></td></tr>
      <tr><td class="dmain"><span class="dtt">Region</span></td><td><?= e(trim(($school['city'] ?? '') . ', ' . ($school['region'] ?? ''), ', ')) ?: '-' ?> · <?= e(Tenant::TYPES[$school['type']] ?? $school['type']) ?></td></tr>
      <tr><td class="dmain"><span class="dtt">PrepDeck licence</span><span class="ts">Managed by the platform owner</span></td><td><?= e(ucfirst($school['plan'])) ?><?= $school['plan_expires'] ? ' · until ' . e(fdate($school['plan_expires'])) : '' ?><?= $school['max_students'] ? ' · up to ' . number_format((int)$school['max_students']) . ' students' : '' ?></td></tr><?php endif; ?>
      <tr><td class="dmain"><span class="dtt">Academic year</span></td><td><?= e(setting('year_label', '')) ?: '<span class="muted">Not set</span>' ?></td></tr>
      <tr><td class="dmain"><span class="dtt">Semester</span></td><td><?= e(setting('semester_label', '')) ?: '<span class="muted">Not set</span>' ?></td></tr>
      <tr><td class="dmain"><span class="dtt">New students can register</span></td><td><?= $yes(setting('reg_open', '1') === '1', 'Open', 'Closed') ?></td></tr>
      <tr><td class="dmain"><span class="dtt">Rep and lecturer uploads need review</span></td><td><?= $yes(setting('upload_review', '1') === '1', 'Yes', 'No') ?></td></tr>
      <tr><td class="dmain"><span class="dtt">Maintenance mode</span><span class="ts">Students see a "back soon" page; admins still get in.</span></td><td><?= setting('maintenance', '0') === '1' ? '<span class="pill wait">On</span>' : '<span class="pill free">Off</span>' ?></td></tr>
    </tbody></table></div></section>
  <template id="tpl-gen">
  <form class="card mform" method="post" data-action="admin/settings.php" enctype="multipart/form-data"><?= csrf_field() ?><input type="hidden" name="a" value="general"><h2>School</h2>
  <?php if ($super): ?><div class="field"><label for="sc">School name</label><input class="in" id="sc" name="school_name" value="<?= e($school['name'] ?? '') ?>"></div><?php endif; ?>
  <div class="f2"><div class="field"><label for="sce">Contact email</label><input class="in" id="sce" type="email" name="contact_email" value="<?= e($school['contact_email'] ?? '') ?>"></div><div class="field"><label for="scp">Phone</label><input class="in" id="scp" name="phone" value="<?= e($school['phone'] ?? '') ?>"></div></div>
  <div class="f2"><div class="field"><label for="scw">Website</label><input class="in" id="scw" name="website" value="<?= e($school['website'] ?? '') ?>" placeholder="https://"></div><div class="field"><label for="scc">Brand colour</label><input class="in" id="scc" type="color" name="color" value="<?= e($school['color'] ?? '#0F766E') ?>"></div></div>
  <div class="field"><label for="scl">Logo (PNG, JPG or WebP, up to 1 MB)</label><input class="in" id="scl" type="file" name="logo" accept="image/png,image/jpeg,image/webp" style="padding-top:11px"></div>
  <div class="f2"><div class="field"><label for="yl">Academic year</label><input class="in" id="yl" name="year_label" value="<?= e(setting('year_label', '')) ?>"></div><div class="field"><label for="sl">Semester</label><input class="in" id="sl" name="semester_label" value="<?= e(setting('semester_label', '')) ?>"></div></div>
  <label class="chk"><input type="checkbox" name="reg_open" value="1"<?= setting('reg_open', '1') === '1' ? ' checked' : '' ?>>New students can register</label>
  <label class="chk"><input type="checkbox" name="upload_review" value="1"<?= setting('upload_review', '1') === '1' ? ' checked' : '' ?>>Papers uploaded by class reps and lecturers need review first</label>
  <label class="chk"><input type="checkbox" name="maintenance" value="1"<?= setting('maintenance', '0') === '1' ? ' checked' : '' ?>>Maintenance mode for this school (its students see a "back soon" page; admins still get in)</label>
  <button class="btn">Save</button></form>
  </template>
 <?php elseif ($tab === 'ai'): ?>
  <section class="card dt"><?= dt_head('PrepDeck AI & document processing', $super ? 'API keys live in the server .env file, never in the database.' : 'The AI engine and its limits are set by the platform owner for every school.', ($super ? row_form('admin/settings.php', ['a' => 'ai_test'], 'Test AI connection', 'btn sm line') : '') . modal_btn('tpl-ai', 'Edit AI settings', 'Edit', 'btn sm', 'edit', 'wide')) ?>
    <div class="dtwrap"><table class="dtable"><thead><tr><th>Setting</th><th>Value</th></tr></thead><tbody>
      <tr><td class="dmain"><span class="dtt">AI key</span><span class="ts">AI_API_KEY in .env</span></td><td><?= AiClient::configured() ? '<span class="pill ok">Set</span>' : '<span class="pill bad">Missing</span>' ?></td></tr>
      <tr><td class="dmain"><span class="dtt">Last connection test</span></td><td><?= e(setting('ai_last_test', '') ?: 'Not tested yet. Press Test AI connection.') ?></td></tr>
      <tr><td class="dmain"><span class="dtt">PDF text reader</span><span class="ts">poppler pdftotext; scanned PDFs are read by the AI</span></td><td><?= PdfText::has('pdftotext') ? '<span class="pill ok">Found</span>' : '<span class="pill wait">Not found: AI reads every PDF (uses more credit)</span>' ?></td></tr>
    <?php foreach ([['AI model', AiClient::defaultModel()], ['Processing depth', ucfirst((string)setting('ai_tier', 'quick'))], ['Max pages read per document', setting('ai_max_pages', '80')], ['Max characters sent to the AI', number_format((int)setting('ai_max_chars', '60000'))],
        ['Predicted questions per document', setting('ai_predicted_count', '12')], ['Lecture language', setting('stt_language', 'auto')], ['Keep recording audio', ((int)setting('rec_keep_audio_days', '90')) ? (int)setting('rec_keep_audio_days', '90') . ' days' : 'Forever'], ['Max note upload size', (int)setting('mat_mb', '15') . ' MB']] as [$l, $v]): ?>
      <tr><td class="dmain"><span class="dtt"><?= e($l) ?></span></td><td><?= e((string)$v) ?></td></tr><?php endforeach; ?>
      <tr><td class="dmain"><span class="dtt">Publish AI answers automatically</span><span class="ts">Otherwise they wait in AI processing for review.</span></td><td><?= $yes(setting('ai_autopublish', '0') === '1', 'Yes', 'No') ?></td></tr>
    </tbody></table></div></section>
  <template id="tpl-ai">
  <form class="card mform" method="post" data-action="admin/settings.php"><?= csrf_field() ?><input type="hidden" name="a" value="ai"><h2>PrepDeck AI &amp; document processing</h2><p class="note">API keys live in the server <code>.env</code> file, never in the database.</p>
  <?php if ($super): ?><p class="note">These limits apply to every school.</p>
  <div class="f2"><div class="field"><label for="am">AI model</label><input class="in" id="am" name="ai_model" value="<?= e(setting('ai_model', '')) ?>"></div><div class="field"><label for="at">Processing depth</label><select class="in" id="at" name="ai_tier"><?php foreach (['quick' => 'Quick', 'standard' => 'Standard', 'deep' => 'Deep'] as $k => $l): ?><option value="<?= $k ?>"<?= setting('ai_tier', 'quick') === $k ? ' selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div></div>
  <div class="f2"><div class="field"><label for="mp">Max pages read per document</label><input class="in" id="mp" type="number" name="ai_max_pages" value="<?= e(setting('ai_max_pages', '80')) ?>"></div><div class="field"><label for="mc">Max characters sent to the AI</label><input class="in" id="mc" type="number" name="ai_max_chars" value="<?= e(setting('ai_max_chars', '60000')) ?>"></div></div>
  <div class="f2"><div class="field"><label for="pq">Predicted questions per document</label><input class="in" id="pq" type="number" name="ai_predicted_count" value="<?= e(setting('ai_predicted_count', '12')) ?>"></div><div class="field"><label for="sl">Lecture language (auto = detect)</label><input class="in" id="sl" name="stt_language" value="<?= e(setting('stt_language', 'auto')) ?>"></div></div>
  <div class="f2"><div class="field"><label for="ka">Keep recording audio (days, 0 = forever)</label><input class="in" id="ka" type="number" name="rec_keep_audio_days" value="<?= e(setting('rec_keep_audio_days', '90')) ?>"></div><div class="field"><label for="mm">Max note uploads size (MB)</label><input class="in" id="mm" type="number" name="mat_mb" value="<?= e(setting('mat_mb', '15')) ?>"></div></div>
  <?php endif; ?>
  <label class="chk"><input type="checkbox" name="ai_autopublish" value="1"<?= setting('ai_autopublish', '0') === '1' ? ' checked' : '' ?>>Publish AI answers automatically in this school (otherwise they wait in Processing for review)</label><button class="btn">Save</button></form>
  </template>
 <?php elseif ($tab === 'email'):
    $from = Mailer::fromAddress(); $dom = substr($from, strrpos($from, '@') + 1); $tr = Mailer::transport();
    $txt = function (string $host): array { $o = []; foreach (@dns_get_record($host, DNS_TXT) ?: [] as $r) $o[] = $r['txt'] ?? implode('', $r['entries'] ?? []); return $o; };
    $spf = array_values(array_filter($txt($dom), fn($t) => stripos($t, 'v=spf1') === 0)); $dmarc = array_values(array_filter($txt('_dmarc.' . $dom), fn($t) => stripos($t, 'v=DMARC1') === 0));
    $sels = array_filter(array_unique(array_merge([(string)env('DKIM_SELECTOR', '')], array_map('trim', explode(',', (string)env('DKIM_CHECK_SELECTORS', 'hostingermail1,hostingermail2,hostingermail3,default,mail,google,selector1'))))));
    $dkim = []; foreach ($sels as $sl) if (array_filter($txt("$sl._domainkey.$dom"), fn($t) => stripos($t, 'p=') !== false)) $dkim[] = $sl;
    $mx = @dns_get_record($dom, DNS_MX) ?: [];
    $dns = [['SPF (who may send for ' . $dom . ')', $spf ? $spf[0] : 'Missing. Add the TXT record your mail provider gives you, e.g. v=spf1 include:_spf.mail.hostinger.com ~all', (bool)$spf],
            ['DKIM (signature)', $dkim ? 'Found for selector: ' . implode(', ', $dkim) : (env('DKIM_PRIVATE_KEY', '') ? 'The app signs with selector ' . env('DKIM_SELECTOR') . ', but its public key is not in DNS yet' : 'Not found. Turn DKIM on in hPanel > Emails, or set DKIM_SELECTOR to the selector your provider uses'), (bool)$dkim],
            ['DMARC (policy)', $dmarc ? $dmarc[0] : 'Missing. Start with TXT _dmarc.' . $dom . ' = v=DMARC1; p=none; rua=mailto:postmaster@' . $dom, (bool)$dmarc],
            ['MX (receives replies and bounces)', $mx ? implode(', ', array_column($mx, 'target')) : 'Missing', (bool)$mx]];
    $st = $_GET['st'] ?? ''; $per = per_page(15); $page = page_no();
    $w = in_array($st, ['queued', 'sent', 'failed'], true) ? 'WHERE status=' . db()->quote($st) : '';
    $total = (int)val("SELECT COUNT(*) FROM email_log $w"); $log = rows("SELECT id,to_email,subject,template,kind,status,attempts,error,created_at,sent_at FROM email_log $w ORDER BY id DESC LIMIT $per OFFSET " . (($page - 1) * $per));
    $sum = row("SELECT SUM(status='sent') s, SUM(status='failed') f, SUM(status='queued') q FROM email_log WHERE created_at > NOW() - INTERVAL 7 DAY");
    $smtp = env('SMTP_HOST', '') ? env('SMTP_HOST') . ':' . env('SMTP_PORT', 465) . ' · ' . strtoupper((string)env('SMTP_SECURE', (int)env('SMTP_PORT', 465) === 465 ? 'ssl' : 'tls')) . ' · ' . (env('SMTP_USER', '') ? 'login set' : 'no login') : ''; ?>
  <section class="kpis"><div class="card kpi"><b><?= (int)$sum['s'] ?></b><small>Sent (7 days)</small></div><div class="card kpi"><b style="color:<?= $sum['f'] ? 'var(--bad)' : 'inherit' ?>"><?= (int)$sum['f'] ?></b><small>Failed (7 days)</small></div><div class="card kpi"><b><?= (int)$sum['q'] ?></b><small>Waiting to retry</small></div><div class="card kpi"><b><?= e(strtoupper($tr)) ?></b><small>Sending method</small></div></section>
  <div class="grid2">
  <section class="card dt"><?= dt_head('Sending', 'Mail settings live in .env, so passwords never sit in the database.', modal_btn('tpl-mailtest', 'Send a test email', 'Send test', 'btn sm', 'mega')) ?>
    <div class="dtwrap"><table class="dtable"><thead><tr><th>Setting</th><th>Value</th></tr></thead><tbody>
      <tr><td class="dmain"><span class="dtt">Method</span></td><td><?= $tr === 'smtp' ? '<span class="pill ok">SMTP</span>' : ($tr === 'mail' ? '<span class="pill wait">PHP mail()</span><span class="ts">Works, but SMTP reaches the inbox more often</span>' : '<span class="pill free">Log file only</span><span class="ts">Development: nothing leaves this computer (storage/mail.log)</span>') ?></td></tr>
      <tr><td class="dmain"><span class="dtt">From</span></td><td><?= e((string)env('MAIL_FROM_NAME', 'PrepDeck')) ?> &lt;<?= e($from) ?>&gt;<?= env('MAIL_FROM', '') ? '' : ' <span class="pill wait">Set MAIL_FROM</span>' ?></td></tr>
      <tr><td class="dmain"><span class="dtt">SMTP server</span></td><td><?= $smtp !== '' ? e($smtp) : '<span class="muted">Not set</span>' ?></td></tr>
      <tr><td class="dmain"><span class="dtt">App DKIM signing</span><span class="ts">Optional when your provider signs (Hostinger does)</span></td><td><?= env('DKIM_PRIVATE_KEY', '') ? 'On · selector ' . e((string)env('DKIM_SELECTOR')) : '<span class="muted">Off</span>' ?></td></tr>
    </tbody></table></div></section>
  <section class="card dt"><?= dt_head('Inbox, not spam', 'DNS records for ' . $dom . '. All four OK gives the best delivery.') ?>
    <div class="dtwrap"><table class="dtable"><thead><tr><th>Record</th><th>Found</th><th>Result</th></tr></thead><tbody>
    <?php foreach ($dns as [$l, $v, $ok]): ?><tr><td class="dmain"><span class="dtt"><?= e($l) ?></span></td><td class="muted" style="overflow-wrap:anywhere;font-size:13px"><?= e($v) ?></td><td><span class="pill <?= $ok ? 'ok' : 'wait' ?>"><?= $ok ? 'OK' : 'Fix' ?></span></td></tr><?php endforeach; ?>
    </tbody></table></div></section>
  </div>
  <section class="card dt"><?= dt_head('Delivery log', 'Every email PrepDeck sent. Temporary failures are retried automatically by the cron job.') ?>
    <div class="tabs"><?php foreach (['' => 'All', 'sent' => 'Sent', 'queued' => 'Retrying', 'failed' => 'Failed'] as $k => $l): ?><a class="tab" href="?tab=email&st=<?= $k ?>" aria-selected="<?= $st === $k ? 'true' : 'false' ?>"><?= $l ?></a><?php endforeach; ?></div>
    <div class="dtwrap"><table class="dtable"><thead><tr><th>To</th><th>Subject</th><th>Type</th><th>Status</th><th>When</th><th data-nosort><span class="vh">Actions</span></th></tr></thead><tbody>
    <?php foreach ($log as $m): ?><tr>
      <td class="dmain"><span class="dtt"><?= e($m['to_email']) ?></span></td><td><span class="clip"><?= e($m['subject']) ?></span><?= $m['error'] ? '<span class="ts" style="color:var(--bad)">' . e(mb_strimwidth($m['error'], 0, 140, '…')) . '</span>' : '' ?></td>
      <td><span class="pill free"><?= e($m['template']) ?></span></td><td><?= status_pill($m['status'] === 'queued' ? 'pending' : ($m['status'] === 'sent' ? 'done' : 'failed')) ?><?= $m['attempts'] > 1 ? '<span class="ts">' . (int)$m['attempts'] . ' tries</span>' : '' ?></td>
      <td class="nw muted" data-v="<?= e($m['created_at']) ?>"><?= e(ago($m['created_at'])) ?></td>
      <td class="act"><?php if ($m['status'] !== 'sent') echo row_form('admin/settings.php', ['a' => 'mail_retry', 'id' => $m['id']], 'Send again', 'btn sm line'); ?></td></tr>
    <?php endforeach; if (!$log) echo dt_empty(6, 'No emails yet.'); ?></tbody></table></div>
    <?= dt_foot($total, $per, $page, ['tab' => 'email', 'st' => $st]) ?></section>
  <template id="tpl-mailtest"><form class="card mform" method="post" data-action="admin/settings.php"><?= csrf_field() ?><input type="hidden" name="a" value="mail_test"><h2>Test</h2>
    <p class="note">Send to a Gmail and an Outlook address you can open. If it lands in spam, fix the DNS records marked Fix.</p>
    <div class="field"><label for="mt">Send to</label><input class="in" id="mt" type="email" name="to" value="<?= e($u['email']) ?>" required></div><button class="btn" type="submit">Send test email</button></form></template>
 <?php elseif ($tab === 'roles'):
    // what each role can do in THIS school (the platform owner's per-school matrix, else the platform defaults)
    $perms = rows("SELECT * FROM permissions WHERE code NOT IN ('platform','plans') ORDER BY id"); $roles = rows('SELECT * FROM roles WHERE id<>5 ORDER BY id'); $has = [];
    foreach ($roles as $r) foreach (Auth::rolePerms((int)$r['id'], sid()) as $code) foreach ($perms as $p) if ($p['code'] === $code) $has[$r['id']][$p['id']] = 1;
    $desc = ['view' => 'Browse papers', 'upload' => 'Upload papers and share recordings', 'approve' => 'Review ANY paper, AI output, recordings (whole site)', 'review' => 'Review papers and AI answers for assigned courses only', 'decks' => 'Moderate study content', 'users' => 'Manage users', 'plans' => 'Prices, payments, coupons', 'reports' => 'Handle reports', 'announce' => 'Post announcements', 'settings' => 'Site content and settings', 'admin' => 'Departments, audit log, delete things']; ?>
  <section class="card dt"><?= dt_head('Roles & permissions', 'What each role can do in ' . school_name() . '. Set by the platform owner.', $super ? '<a class="btn sm" href="' . e(url('platform/permissions.php?school=' . sid())) . '">' . ic('edit') . 'Edit for this school</a>' : '') ?>
    <div class="dtwrap"><table class="dtable pm"><thead><tr><th>Permission</th><?php foreach ($roles as $r): ?><th><?= e(ucfirst(str_replace('_', ' ', $r['name']))) ?></th><?php endforeach; ?></tr></thead><tbody>
    <?php foreach ($perms as $p): ?><tr><td class="dmain"><span class="dtt"><?= e($p['code']) ?></span><span class="ts"><?= e($desc[$p['code']] ?? '') ?></span></td>
      <?php foreach ($roles as $r): $on = !empty($has[$r['id']][$p['id']]); ?><td><?= $on ? '<span class="pill ok">Yes</span>' : '<span class="muted">&ndash;</span>' ?></td><?php endforeach; ?></tr><?php endforeach; ?>
    </tbody></table></div><?php if (!$super): ?><p class="note" style="padding:12px 18px">Only the platform owner can change what each role may do. Give one lecturer or class rep extra permissions in Lecturers &amp; reps.</p><?php endif; ?></section>
<?php else:
    $checks = [['PHP version', PHP_VERSION, version_compare(PHP_VERSION, '8.0', '>=')], ['PDO MySQL', extension_loaded('pdo_mysql') ? 'loaded' : 'missing', extension_loaded('pdo_mysql')], ['fileinfo', extension_loaded('fileinfo') ? 'loaded' : 'missing', extension_loaded('fileinfo')],
      ['Uploads folder writable', cfg('upload_dir'), is_writable(cfg('upload_dir'))], ['pdftotext (poppler)', PdfText::bin('pdftotext') ?: 'not found (the AI reads PDFs instead)', PdfText::has('pdftotext')], ['Background worker (PHP CLI)', JobQueue::phpCli(), JobQueue::phpCli() !== 'php' || PHP_OS_FAMILY !== 'Windows'], ['AI connection', setting('ai_last_test', '') ?: 'not tested (Platform settings)', str_contains((string)setting('ai_last_test', ''), ': OK')],
      ['AI key set', env('AI_API_KEY', '') ? 'yes' : 'no', (bool)env('AI_API_KEY', '')], ['Speech-to-text key set', env('STT_API_KEY', env('AI_API_KEY', '')) ? 'yes' : 'no', (bool)env('STT_API_KEY', env('AI_API_KEY', ''))],
      ['Paystack', Paystack::enabled() ? (str_starts_with((string)env('PAY_SECRET_KEY'), 'sk_live') ? 'live' : 'test mode') : 'not connected (manual confirmation)', Paystack::enabled()], ['Environment', $GLOBALS['cfg']['debug'] ? 'development (debug on)' : 'production', !$GLOBALS['cfg']['debug']], ['HTTPS', !empty($_SERVER['HTTPS']) ? 'on' : 'off', !empty($_SERVER['HTTPS'])]];
    $worker = row("SELECT COUNT(*) n, MAX(finished_at) last FROM processing_jobs WHERE status='done'"); ?>
<section class="card dt"><?= dt_head('System check', 'Jobs finished: ' . (int)$worker['n'] . ' · last ' . ago($worker['last']) . ' · uploads use ' . fmt_bytes(dir_bytes(cfg('upload_dir')))) ?>
  <div class="dtwrap"><table class="dtable"><thead><tr><th>Check</th><th>Value</th><th>Result</th></tr></thead><tbody>
  <?php foreach ($checks as [$l, $v, $ok]): ?><tr><td class="dmain"><span class="dtt"><?= e($l) ?></span></td><td class="muted" style="overflow-wrap:anywhere"><?= e((string)$v) ?></td><td><span class="pill <?= $ok ? 'ok' : 'wait' ?>"><?= $ok ? 'OK' : 'Check' ?></span></td></tr><?php endforeach; ?>
  </tbody></table></div></section>
<?php endif; ?>
<?php include ROOT . '/includes/dashboard_footer.php'; ?>
