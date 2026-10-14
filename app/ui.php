<?php
/* Small UI helpers shared by every page. */
function flash(string $type, string $msg): void { $_SESSION['flash'][] = [$type, $msg]; }
function is_ajax(): bool { return !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest'; }
function ajax_flashes(): array { $f = $_SESSION['flash'] ?? []; unset($_SESSION['flash']); return $f; }
function redirect_self_or_ajax(): void {
    if (is_ajax()) {
        $fl = ajax_flashes();
        foreach ($fl as $x) { if ($x[0] === 'bad') Response::error($x[1]); }
        $msg = $fl ? end($fl)[1] ?? '' : '';
        Response::ok(['reload' => true, 'msg' => $msg, 'flash' => $fl]);
    }
    header('Location: ' . $_SERVER['REQUEST_URI']);
    exit;
}
function redirect_or_ajax(string $to): void {
    if (is_ajax()) {
        $fl = ajax_flashes();
        foreach ($fl as $x) { if ($x[0] === 'bad') Response::error($x[1]); }
        $msg = $fl ? end($fl)[1] ?? '' : '';
        Response::ok(['redirect' => url($to), 'msg' => $msg, 'flash' => $fl]);
    }
    redirect($to);
}
function flash_render(): string {
    $out = ''; foreach ($_SESSION['flash'] ?? [] as [$t, $d]) $out .= '<div class="banner ' . ($t === 'bad' ? 'warn' : ($t === 'ok' ? 'okb' : '')) . '" role="status">' . e($d) . '</div>';
    unset($_SESSION['flash']); return $out;
}
function form_guard(): void { Csrf::form(); }
function csrf_field(): string { return Csrf::field(); }
function pill(string $t, string $cls = ''): string { return '<span class="pill ' . e($cls) . '">' . e($t) . '</span>'; }
function status_pill(string $s): string {
    $ok = ['published', 'active', 'done', 'paid', 'ready', 'resolved', 'open_ok']; $bad = ['rejected', 'failed', 'suspended', 'hidden', 'refunded', 'unpublished', 'expired'];
    $c = in_array($s, $ok, true) ? 'ok' : (in_array($s, $bad, true) ? 'bad' : 'wait'); return '<span class="pill ' . $c . '">' . e(ucfirst(str_replace('_', ' ', $s))) . '</span>';
}
function page_head(string $title, string $sub = '', string $right = ''): string {
    return '<div class="vh1"><div><h1>' . e($title) . '</h1>' . ($sub !== '' ? '<p>' . e($sub) . '</p>' : '') . '</div>' . $right . '</div>';
}
function empty_state(string $msg, string $cta = ''): string { return '<div class="empty">' . e($msg) . ($cta ? '<div style="margin-top:12px">' . $cta . '</div>' : '') . '</div>'; }
function page_no(string $k = 'p'): int { return max(1, (int)($_GET[$k] ?? 1)); }
function per_page(int $def = 15): int { $p = (int)($_GET['per'] ?? 0); return in_array($p, [10, 15, 25, 50, 100], true) ? $p : $def; }
function paginate(int $total, int $per, int $page, array $keep = []): string {
    $pages = (int)ceil($total / max(1, $per)); if ($pages <= 1) return '';
    if (isset($_GET['per'])) $keep += ['per' => per_page($per)];
    $link = function (int $p, string $label, bool $cur = false, bool $off = false) use ($keep) {
        $q = http_build_query(array_filter($keep, fn($v) => $v !== '' && $v !== null) + ['p' => $p]);
        return $off ? '<span class="pg off">' . $label . '</span>' : '<a class="pg' . ($cur ? ' cur' : '') . '" href="?' . e($q) . '"' . ($cur ? ' aria-current="page"' : '') . '>' . $label . '</a>';
    };
    $o = '<nav class="pager" aria-label="Pages">' . $link($page - 1, '&lsaquo; Prev', false, $page <= 1);
    foreach (array_unique(array_filter([1, $page - 1, $page, $page + 1, $pages], fn($p) => $p >= 1 && $p <= $pages)) as $p) $o .= $link($p, (string)$p, $p === $page);
    return $o . $link($page + 1, 'Next &rsaquo;', false, $page >= $pages) . '</nav>';
}

/* ---------- data tables: <section class="card dt"> dt_head() [toolbar] <div class="dtwrap"><table class="dtable"> ... dt_foot() </section> ---------- */
function dt_head(string $title, string $sub = '', string $actions = ''): string {
    return '<div class="dthead"><div><h2>' . e($title) . '</h2>' . ($sub !== '' ? '<p class="sub">' . e($sub) . '</p>' : '') . '</div>' . ($actions !== '' ? '<div class="dtacts">' . $actions . '</div>' : '') . '</div>';
}
function dt_empty(int $cols, string $msg): string { return '<tr class="dtempty"><td colspan="' . $cols . '">' . e($msg) . '</td></tr>'; }
/** "Showing 16-30 of 87", rows-per-page picker and page links. $keep = the filters to carry over. */
function dt_foot(int $total, int $per, int $page, array $keep = []): string {
    static $n = 0; $n++; $from = $total ? ($page - 1) * $per + 1 : 0; $to = min($total, $page * $per);
    $hidden = ''; foreach ($keep as $k => $v) if ($v !== '' && $v !== null && $k !== 'per' && $k !== 'p') $hidden .= '<input type="hidden" name="' . e((string)$k) . '" value="' . e((string)$v) . '">';
    $sel = ''; foreach ([10, 15, 25, 50, 100] as $n) $sel .= '<option value="' . $n . '"' . ($n === $per ? ' selected' : '') . '>' . $n . '</option>';
    return '<div class="dtfoot"><span class="dtinfo">' . ($total ? 'Showing <b>' . $from . '&ndash;' . $to . '</b> of <b>' . number_format($total) . '</b>' : 'No records') . '</span>'
        . '<form class="dtper" method="get">' . $hidden . '<label for="dtper' . $n . '">Rows</label><select class="in" id="dtper' . $n . '" name="per" data-autosubmit>' . $sel . '</select></form>'
        . paginate($total, $per, $page, $keep) . '</div>';
}
/* ---------- stats: change badges, period picker, charts (single series, brand colour; every value also in a hover title and a screen-reader table) ---------- */
/** "▲ 12% vs previous" badge. $goodUp = false for numbers where up is bad (refunds, failures). Arrow + words, never colour alone. */
function delta_badge(float $now, float $prev, bool $goodUp = true): string {
    $c = Stats::change($now, $prev);
    if ($c === null) return '<span class="dl flat">' . ($now > 0 ? 'new' : 'no change') . '</span>';
    $r = (int)round($c); if ($r === 0) return '<span class="dl flat">&ndash; 0%</span>';
    $good = ($r > 0) === $goodUp;
    return '<span class="dl ' . ($good ? 'up' : 'down') . '" title="' . ($r > 0 ? 'Up' : 'Down') . ' from ' . e(number_format($prev, 2)) . '">' . ($r > 0 ? '&#9650; ' : '&#9660; ') . abs($r) . '%</span>';
}
/** Tabs that switch a ?$key= period (in days), keeping the other query values. */
function range_tabs(array $opts, int $cur, string $key = 'days', array $keep = []): string {
    $o = '<div class="tabs" role="navigation" aria-label="Period">';
    foreach ($opts as $d => $l) $o .= '<a class="tab" href="?' . e(http_build_query(array_filter($keep, fn($v) => $v !== '' && $v !== null) + [$key => $d])) . '" aria-selected="' . ($d === $cur ? 'true' : 'false') . '">' . e($l) . '</a>';
    return $o . '</div>';
}
/**
 * Vertical bar chart, one series. $data = [key => value] in time order. Options: fmt (value -> text), xfmt (key -> axis text),
 * label (what the bars are, for the screen-reader table). The newest bar is highlighted; each bar has a hover area as tall as the chart.
 */
function bar_chart(array $data, string $label, array $o = []): string {
    $fmt = $o['fmt'] ?? fn($v) => number_format((float)$v); $xfmt = $o['xfmt'] ?? fn($k) => (string)$k;
    // the SVG stretches to the card (fixed height in CSS); text lives in HTML around it so it never scales with the chart
    $n = max(1, count($data)); $max = max(1, (float)max($data ?: [0])); $W = 600; $H = 100; $slot = $W / $n; $bw = max(2, min($slot * .72, $slot - 2));
    $svg = '<div class="bchart"><span class="bmax">' . e($fmt($max)) . '</span><svg class="chart bars" viewBox="0 0 ' . $W . ' ' . $H . '" preserveAspectRatio="none" role="img" aria-label="' . e($label) . '">'
        . '<line class="gl" x1="0" y1="0.5" x2="' . $W . '" y2="0.5" stroke-dasharray="3 5" vector-effect="non-scaling-stroke"/>';
    $i = 0; $keys = array_keys($data);
    foreach ($data as $k => $v) {
        $h = $H * (float)$v / $max; $x = $i * $slot + ($slot - $bw) / 2;
        $svg .= '<g class="bh"><rect class="hit" x="' . round($i * $slot, 2) . '" y="0" width="' . round($slot, 2) . '" height="' . $H . '"/>'
            . ($v > 0 ? '<rect class="bar' . ($i === $n - 1 ? ' last' : '') . '" style="--i:' . (int)round(10 * $i / $n) . '" x="' . round($x, 2) . '" y="' . round($H - max(2, $h), 2) . '" width="' . round($bw, 2) . '" height="' . round(max(2, $h), 2) . '"/>' : '')
            . '<title>' . e($xfmt($k)) . ': ' . e($fmt($v)) . '</title></g>';
        $i++;
    }
    $svg .= '<line class="gl" x1="0" y1="' . ($H - .5) . '" x2="' . $W . '" y2="' . ($H - .5) . '" vector-effect="non-scaling-stroke"/></svg>'
        . '<div class="bx"><span>' . e($xfmt($keys[0] ?? '')) . '</span><span>' . e($xfmt(end($keys) ?: '')) . '</span></div></div>';
    $tbl = '<table class="vh"><caption>' . e($label) . '</caption><tbody>';
    foreach ($data as $k => $v) $tbl .= '<tr><th scope="row">' . e($xfmt($k)) . '</th><td>' . e($fmt($v)) . '</td></tr>';
    return $svg . $tbl . '</tbody></table>';
}
/** Horizontal bars: rows of [label, value, shown text, optional link]. Bar length is relative to the largest value (or $of when given). */
function hbars(array $rows, ?float $of = null): string {
    $max = $of ?? max(1, (float)max(array_column($rows, 1) ?: [0])); $o = '<ul class="hbars">';
    foreach ($rows as $r) {
        [$l, $v, $t] = $r; $href = $r[3] ?? null; $pct = $max > 0 ? max(0, min(100, 100 * (float)$v / $max)) : 0;
        $name = $href ? '<a href="' . e($href) . '">' . e($l) . '</a>' : e($l);
        $o .= '<li><span class="hl">' . $name . '</span><span class="hv">' . e($t) . '</span><span class="ht" aria-hidden="true"><i style="width:' . round($pct, 1) . '%"></i></span></li>';
    }
    return $o . '</ul>';
}
/** KPI tile with an optional change badge and a sub line. */
function stat_tile(string $value, string $label, string $badge = '', string $sub = ''): string {
    return '<div class="card kpi stat"><div><b>' . $value . '</b>' . ($badge !== '' ? ' ' . $badge : '') . '<small>' . e($label) . '</small>' . ($sub !== '' ? '<small class="ss">' . e($sub) . '</small>' : '') . '</div></div>';
}

/** Page an array in memory, for short tables that are loaded in one query. Returns [rows on this page, total]. */
function page_slice(array $rows, int $per, int $page): array { return [array_slice($rows, ($page - 1) * $per, $per), count($rows)]; }
/** A button that opens <template id="$tpl"> in the modal overlay. $size 'wide' gives a larger sheet. */
function modal_btn(string $tpl, string $title, string $label, string $cls = 'btn', string $icon = '', string $size = ''): string {
    return '<button type="button" class="' . e($cls) . '" data-sheet="' . e($tpl) . '" data-title="' . e($title) . '"' . ($size ? ' data-size="' . e($size) . '"' : '') . '>' . ($icon ? ic($icon) : '') . $label . '</button>';
}
/** One-button POST form for a table row (status changes, deletes). $confirm adds the confirm overlay. */
function row_form(string $action, array $fields, string $label, string $cls = 'btn sm line', string $confirm = ''): string {
    $h = csrf_field(); foreach ($fields as $k => $v) $h .= '<input type="hidden" name="' . e($k) . '" value="' . e((string)$v) . '">';
    return '<form method="post" data-action="' . e($action) . '">' . $h . '<button class="' . e($cls) . '"' . ($confirm !== '' ? ' data-confirm="' . e($confirm) . '"' : '') . '>' . $label . '</button></form>';
}
/** Live AI panel for the newest processing job of a paper / material / recording (shown while it is queued, running or failed). */
function ai_live(string $type, int $sourceId): string {
    $j = row('SELECT id,status FROM processing_jobs WHERE source_type=? AND source_id=? ORDER BY id DESC LIMIT 1', [$type, $sourceId]);
    return $j && in_array($j['status'], ['queued', 'running', 'failed'], true) ? '<div data-live-job="' . (int)$j['id'] . '"></div>' : '';
}
/** Total size of a folder (for the System check). */
function dir_bytes(string $dir): int {
    $n = 0; if (!is_dir($dir)) return 0;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $f) $n += $f->getSize();
    return $n;
}
function options(array $rows, $sel = null, string $val = 'id', string $lab = 'name', ?string $blank = null): string {
    $o = $blank !== null ? '<option value="">' . e($blank) . '</option>' : '';
    foreach ($rows as $r) $o .= '<option value="' . e((string)$r[$val]) . '"' . ((string)$sel === (string)$r[$val] ? ' selected' : '') . '>' . e((string)$r[$lab]) . '</option>';
    return $o;
}
function q(string $sql, array $p = []): PDOStatement { $s = db()->prepare($sql); $s->execute($p); return $s; }
function rows(string $sql, array $p = []): array { return q($sql, $p)->fetchAll(); }
function row(string $sql, array $p = []): ?array { return q($sql, $p)->fetch() ?: null; }
function val(string $sql, array $p = []) { return q($sql, $p)->fetchColumn(); }
/** Tiny safe markdown for AI-written guides and lesson notes: # headings, - and 1. lists, **bold**, `code`. Everything is escaped first. */
function guide_html(string $t): string {
    $out = ''; $list = '';
    foreach (preg_split('/\R/', $t) as $l) {
        $h = e($l); $h = preg_replace(['/\*\*([^*]+)\*\*/', '/`([^`]+)`/'], ['<b>$1</b>', '<code>$1</code>'], $h);
        $li = preg_match('/^\s*[-*] (.*)$/', $h, $m) ? 'ul' : (preg_match('/^\s*\d+[.)] (.*)$/', $h, $m) ? 'ol' : '');
        if ($list && $li !== $list) { $out .= '</' . $list . '>'; $list = ''; }
        if ($li) { if (!$list) { $out .= '<' . $li . '>'; $list = $li; } $out .= '<li>' . $m[1] . '</li>'; continue; }
        if (preg_match('/^(#{1,4})\s+(.*)$/', $h, $m)) $out .= '<h' . (strlen($m[1]) + 1) . '>' . $m[2] . '</h' . (strlen($m[1]) + 1) . '>';
        elseif (trim($h) !== '') $out .= '<p>' . $h . '</p>';
    }
    return $out . ($list ? '</' . $list . '>' : '');
}
function lock_note(string $what = 'This'): string { return '<div class="lockbox">' . ic('lock', 'i') . '<div><b>' . e($what) . ' is part of PrepDeck Plus</b><p class="note">Upgrade to unlock it.</p></div><a class="btn sm" href="' . e(url('dashboard/plan.php')) . '">See Plus</a></div>'; }

/* ---------- navigation (the "external sidebar" reads these) ---------- */
function student_nav(): array {
    return [
        ['Study', [
            ['home', 'Dashboard', 'home', 'dashboard/index.php', false],
            ['ai', 'PrepDeck AI', 'spark', 'dashboard/ai.php', true],
            ['papers', 'Past questions', 'file', 'dashboard/papers.php', false],
            ['decks', 'My decks', 'cards', 'dashboard/decks.php', false],
            ['practice', 'Practice exams', 'docchk', 'dashboard/practice.php', true],
            ['notes', 'My notes', 'note', 'dashboard/notes.php', false],
            ['materials', 'My materials', 'upload', 'dashboard/materials.php', false],
            ['guides', 'Study guides', 'book', 'dashboard/guides.php', true],
            ['lessons', 'Lesson notes', 'lesson', 'dashboard/lessons.php', setting('lesson_students_plus', '0') === '1'],
            ['recordings', 'Recordings', 'mic', 'dashboard/recordings.php', true],
            ['games', 'Games', 'game', 'dashboard/games.php', false],
            ['progress', 'Progress', 'chart', 'dashboard/progress.php', false],
        ]],
        ['Account', [
            ['plan', 'Plan & billing', 'card', 'dashboard/plan.php', false],
            ['settings', 'Settings', 'gear', 'dashboard/settings.php', false],
            ['help', 'Help', 'help', 'dashboard/help.php', false],
            ['out', 'Sign out', 'logout', 'logout.php', false],
        ]],
    ];
}
function admin_nav(): array {   // key, label, icon, href, permission needed (any of), badge
    return [
        ['Manage', [
            ['overview', 'Overview', 'grid', 'admin/index.php', ['admin', 'approve', 'decks', 'users', 'plans', 'reports', 'settings'], null],
            ['users', 'Users', 'users', 'admin/users.php', ['users'], null],
            ['staff', 'Lecturers & reps', 'users2', 'admin/staff.php', ['admin'], null],
            ['teaching', 'Lecturer tools', 'lesson', 'admin/teaching.php', ['admin'], 'outlines'],
            ['papers', 'Past questions', 'file', 'admin/papers.php', ['approve'], 'papers'],
            ['study', 'Study content', 'book', 'admin/study.php', ['decks'], null],
            ['recordings', 'Recordings', 'mic', 'admin/recordings.php', ['approve'], null],
            ['processing', 'AI processing', 'spark', 'admin/processing.php', ['approve'], 'jobs'],
            ['academics', 'Departments & exams', 'cap', 'admin/academics.php', ['admin'], null],
        ]],
        ['Business', [
            ['plans', 'Plans & access', 'card', 'admin/plans.php', ['plans'], null],
            ['payments', 'Payments', 'coin', 'admin/payments.php', ['plans'], 'pay'],
        ]],
        ['Community', [
            ['reports', 'Reports', 'flag', 'admin/reports.php', ['reports'], 'reports'],
            ['announcements', 'Announcements', 'mega', 'admin/announcements.php', ['announce'], null],
        ]],
        ['System', [
            ['audit', 'Audit log', 'list', 'admin/audit.php', ['admin'], null],
            ['settings', 'Settings', 'gear', 'admin/settings.php', ['settings'], null],
        ]],
    ];
}
/** Platform owner menu (super admin only). key, label, icon, href, permission needed, badge */
function platform_nav(): array {
    return [
        ['Platform', [
            ['p-home', 'Platform overview', 'grid', 'platform/index.php', ['platform'], null],
            ['p-schools', 'Schools', 'cap', 'platform/schools.php', ['platform'], 'requests'],
            ['p-users', 'All users', 'users', 'platform/users.php', ['platform'], null],
            ['p-perms', 'Permissions', 'lock', 'platform/permissions.php', ['platform'], null],
            ['p-fin', 'Finances', 'chart', 'platform/finances.php', ['platform'], null],
            ['p-subs', 'Subscriptions', 'coin', 'platform/subscriptions.php', ['platform'], null],
            ['p-site', 'Site content', 'globe', 'platform/site.php', ['platform'], null],
            ['p-settings', 'Platform settings', 'gear', 'platform/settings.php', ['platform'], null],
            ['p-audit', 'Platform audit', 'list', 'platform/audit.php', ['platform'], null],
        ]],
    ];
}
function platform_badges(): array {
    static $b = null; return $b ??= ['requests' => (int)val("SELECT COUNT(*) FROM school_requests WHERE status='pending'")];
}
function admin_allowed(array $perms): bool { foreach ($perms as $p) if (Auth::can($p)) return true; return false; }
function admin_badges(): array {
    static $b = null; if ($b === null) $b = [
        'papers' => (int)val("SELECT COUNT(*) FROM papers WHERE status='pending' AND " . in_school()),
        'jobs' => (int)val("SELECT COUNT(*) FROM processing_jobs WHERE status='failed' AND " . in_school()),
        'reports' => (int)val("SELECT COUNT(*) FROM reports WHERE status='open' AND " . in_school()),
        'pay' => (int)val("SELECT COUNT(*) FROM transactions WHERE status='pending' AND " . in_school()),
        'outlines' => (int)val("SELECT COUNT(*) FROM course_outlines WHERE ai_status='failed' AND " . in_school()),
    ];
    return $b;
}

function staff_nav(): array {   // key, label, icon, href, permission needed (any of), badge
    return [['Desk', [
        ['overview', 'My desk', 'grid', 'staff/index.php', ['upload', 'review', 'announce'], null],
        ['papers', 'Upload papers', 'upload', 'staff/papers.php', ['upload'], null],
        ['courses', picks_own_courses() ? 'My courses' : 'Courses', 'book', 'staff/courses.php', picks_own_courses() ? ['view', 'courses'] : ['courses'], null],
        ['review', 'Review queue', 'docchk', 'staff/review.php', ['review', 'approve'], 'staffq'],
        ['recordings', 'Lecture recordings', 'mic', 'dashboard/recordings.php', ['upload'], null],
        ['announcements', 'Announcements', 'mega', 'staff/announcements.php', ['announce'], null],
    ]]];
}
/** Lecturer dashboard menu. key, label, icon, href, permission needed (any of), badge, Plus feature */
function lecturer_nav(): array {
    return [
        ['Teaching', [
            ['overview', 'Dashboard', 'grid', 'lecturer/index.php', ['view'], null, false],
            ['outlines', 'Course outlines', 'book', 'lecturer/outlines.php', ['view'], null, true],
            ['lessons', 'Lesson notes', 'lesson', 'lecturer/lessons.php', ['view'], 'drafts', false],
            ['insights', 'Student insights', 'chart', 'lecturer/insights.php', ['view'], null, false],
        ]],
        ['Course content', [
            ['papers', 'Upload papers', 'upload', 'staff/papers.php', ['upload'], null, false],
            ['courses', 'My courses', 'book', 'staff/courses.php', ['view', 'courses'], null, false],
            ['review', 'Review queue', 'docchk', 'staff/review.php', ['review', 'approve'], 'staffq', false],
            ['recordings', 'Lecture recordings', 'mic', 'dashboard/recordings.php', ['upload'], null, false],
            ['announcements', 'Announcements', 'mega', 'staff/announcements.php', ['announce'], null, false],
        ]],
    ];
}
function staff_queue_count(): int {
    if (staff_course_ids() === []) return 0;
    return (int)val("SELECT COUNT(*) FROM papers WHERE status='pending' AND " . in_school() . ' AND ' . staff_course_sql());
}
