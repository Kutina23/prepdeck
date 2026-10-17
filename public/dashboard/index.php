<?php
require __DIR__ . '/../../app/bootstrap.php';
$u = require_login(); $plus = is_plus($u); $uid = (int)$u['id'];
$active = 'home'; $title = 'Dashboard';
$dep = (int)$u['department_id'];

$greet = (int)gmdate('G') < 12 ? 'Good morning' : ((int)gmdate('G') < 17 ? 'Good afternoon' : 'Good evening');
$streak = 0; $days = array_column(rows('SELECT DISTINCT DATE(d) d FROM (SELECT started_at d FROM focus_sessions WHERE user_id=? AND ' . in_school() . ' UNION ALL SELECT viewed_at FROM paper_views WHERE user_id=? AND ' . in_school() . ' UNION ALL SELECT created_at FROM game_scores WHERE user_id=? AND ' . in_school() . ') x ORDER BY d DESC LIMIT 60', [$uid, $uid, $uid]), 'd');
$cursor = strtotime(gmdate('Y-m-d')); if ($days && $days[0] !== gmdate('Y-m-d')) $cursor -= 86400;
foreach ($days as $d) { if ($d === gmdate('Y-m-d', $cursor)) { $streak++; $cursor -= 86400; } else break; }
$known = (int)val('SELECT COUNT(*) FROM card_progress WHERE user_id=? AND known=1', [$uid]);
$practised = (int)val('SELECT COUNT(DISTINCT paper_id) FROM paper_views WHERE user_id=? AND ' . in_school(), [$uid]);
$mins = (int)val('SELECT COALESCE(SUM(minutes),0) FROM focus_sessions WHERE user_id=? AND ' . in_school() . ' AND started_at>=DATE_SUB(NOW(),INTERVAL 7 DAY)', [$uid]);
$week = []; for ($i = 6; $i >= 0; $i--) $week[gmdate('Y-m-d', time() - $i * 86400)] = 0;
foreach (rows('SELECT DATE(started_at) d, SUM(minutes) m FROM focus_sessions WHERE user_id=? AND ' . in_school() . ' AND started_at>=DATE_SUB(CURDATE(),INTERVAL 6 DAY) GROUP BY d', [$uid]) as $r) $week[$r['d']] = (int)$r['m'];
$maxw = max(60, max($week));

// Resume: the deck with the most recent progress, else the newest shared deck for the department
$resume = row('SELECT d.id,d.title,d.card_count, (SELECT COUNT(*) FROM card_progress p JOIN cards c ON c.id=p.card_id WHERE c.deck_id=d.id AND p.user_id=? AND p.known=1) k
    FROM decks d WHERE d.status="active" AND ' . in_school('d') . ' AND (d.owner_id=? OR d.visibility="shared") AND d.card_count>0
    ORDER BY (SELECT MAX(p.due_at) FROM card_progress p JOIN cards c ON c.id=p.card_id WHERE c.deck_id=d.id AND p.user_id=?) DESC, d.id DESC LIMIT 1', [$uid, $uid, $uid]);
$pct = $resume ? (int)round(100 * (int)$resume['k'] / max(1, (int)$resume['card_count'])) : 0;

$exams = rows('SELECT e.exam_date,e.exam_time,e.venue,c.code,c.title FROM exam_timetable e JOIN courses c ON c.id=e.course_id WHERE e.exam_date>=CURDATE() AND ' . in_school('c') . ' AND (c.department_id=? OR ?=0) ORDER BY e.exam_date,e.exam_time LIMIT 4', [$dep, $dep]);
$papers = rows('SELECT p.id,p.title,p.exam_year,p.semester,c.code,p.has_answers FROM papers p JOIN courses c ON c.id=p.course_id WHERE p.status="published" AND ' . in_school('p') . ' AND (c.department_id=? OR ?=0) ORDER BY p.created_at DESC LIMIT 5', [$dep, $dep]);
$seen = array_column(rows('SELECT DISTINCT paper_id FROM paper_views WHERE user_id=?', [$uid]), 'paper_id');
$news = rows('SELECT title,body,publish_at FROM announcements WHERE ' . in_school() . ' AND publish_at<=NOW() AND audience IN ("all",?) ORDER BY publish_at DESC LIMIT 2', [$plus ? 'plus' : 'free']);
$courses = rows('SELECT id,code,title FROM courses WHERE ' . in_school() . ' AND (department_id=? OR ?=0) ORDER BY code', [$dep, $dep]);
$banner = setting('banner_on', '0') === '1' ? (string)setting('banner_text', '') : '';
include ROOT . '/includes/dashboard_header.php';
?>
<div class="top">
  <div><h1><?= e($greet) ?>, <?= $firstName ?>.</h1>
    <p><?= $papers ? count($papers) . ' recent past papers for ' . e($u['department'] ?? 'your department') . ' are ready.' : 'Your department has no papers yet. Be the first to upload one.' ?></p></div>
  <div class="acts">
    <form class="search" action="<?= e(url('dashboard/papers.php')) ?>" method="get" role="search"><?= ic('search', 'i') ?><label class="vh" for="sq">Search past questions</label><input id="sq" name="q" type="search" placeholder="Search past questions" autocomplete="off"></form>
  </div>
</div>
<?php if ($banner !== ''): ?><div class="banner"><?= e($banner) ?></div><?php endif; ?>
<?php foreach ($news as $n): ?><div class="banner" style="background:var(--surface);color:var(--ink)"><b><?= e($n['title']) ?></b> &middot; <?= e(mb_strimwidth($n['body'], 0, 140, '…')) ?></div><?php endforeach; ?>

<div class="qrow" aria-label="Study tools">
<?php foreach ([['ai', 'Ask AI', 'spark', 'var(--pop)', 'ai.php', 1], ['decks', 'Flashcards', 'cards', 'var(--lemon)', 'decks.php', 0], ['guides', 'Study guide', 'book', 'var(--lemon)', 'guides.php', 1], ['rec', 'Record', 'mic', 'var(--brand);color:var(--brand-ink)', 'recordings.php', 1], ['games', 'Games', 'game', 'var(--lemon)', 'games.php', 0], ['mats', 'Upload', 'upload', 'var(--lemon)', 'materials.php', 0], ['notes', 'Notes', 'note', 'var(--surface);color:var(--ink)', 'notes.php', 0]] as [$k, $l, $i, $bg, $h, $lock]): ?>
  <a class="q" href="<?= e(url('dashboard/' . $h)) ?>"><?php if ($lock && !$plus): ?><span class="lk"><?= ic('lock', '') ?></span><?php endif; ?><span class="ic" style="background:<?= $bg ?>"><?= ic($i) ?></span><?= e($l) ?></a>
<?php endforeach; ?>
</div>

<section class="ai" aria-labelledby="aih">
  <div class="hdr"><span class="spark"><?= ic('spark') ?></span><h2 id="aih">PrepDeck AI</h2><span class="badge">Plus</span></div>
  <p>Get a plain explanation, a practice quiz or a study plan for any course.</p>
  <form class="ask" action="<?= e(url('dashboard/ai.php')) ?>" method="get">
    <label class="vh" for="askin">Ask PrepDeck AI</label>
    <input id="askin" name="q" type="text" placeholder="Ask a question" autocomplete="off" enterkeyhint="send">
    <button class="send" type="submit" aria-label="Send"><?= ic('send') ?></button>
  </form>
  <div class="sugg">
    <?php foreach (['Explain this topic in simple words' => 'Explain a topic', 'Quiz me with 5 exam-style questions' => 'Quiz me', 'Make 6 flashcards for the key ideas' => 'Make flashcards', 'Make a 5 day study plan for my exam' => '5 day plan'] as $q => $l): ?>
    <a href="<?= e(url('dashboard/ai.php?q=' . urlencode($q))) ?>"><button type="button"><?= e($l) ?></button></a>
    <?php endforeach; ?>
  </div>
  <?php if (!$plus): ?><div class="veil" style="display:grid"><?= ic('lock') ?><b>PrepDeck AI is part of Plus</b><p>Your AI study partner for every course.</p><a class="btn" href="<?= e(url('dashboard/plan.php')) ?>"<?= plus_lock('ai') ?>>See Plus</a></div><?php endif; ?>
</section>

<section class="card upl" aria-labelledby="uph">
  <div><span class="mono" style="opacity:.8">Upload notes</span><h2 id="uph" style="margin-top:8px">Turn your lecture notes into study material</h2>
  <p style="margin-top:6px">Upload a PDF. PrepDeck builds a study guide, flashcards, games and likely exam questions. <?= $plus ? 'Unlimited on Plus.' : 'Free: ' . (int)setting('free_upload_max', 3) . ' documents of ' . (int)setting('free_upload_pages', 1) . ' page.' ?></p></div>
  <div class="uplgo"><a class="btn dark" href="<?= e(url('dashboard/materials.php')) ?>"><?= ic('upload') ?>Upload notes</a></div>
</section>

<?php if ($resume): ?>
<section class="resume" aria-label="Continue studying">
  <div><span class="mono" style="opacity:.85">Pick up where you left off</span><h2><?= e($resume['title']) ?></h2>
  <p><?= (int)$resume['k'] ?> of <?= (int)$resume['card_count'] ?> cards known.</p></div>
  <div class="go"><a class="btn" href="<?= e(url('dashboard/decks.php?id=' . $resume['id'])) ?>">Resume deck</a>
    <div class="ring" style="--off:<?= round(326.7 * (1 - $pct / 100), 1) ?>" role="img" aria-label="<?= $pct ?> percent known"><svg viewBox="0 0 120 120" aria-hidden="true"><circle class="tr" cx="60" cy="60" r="52"/><circle class="pg" cx="60" cy="60" r="52"/></svg><b><?= $pct ?>%</b></div></div>
</section>
<?php endif; ?>

<?= community_card($u) ?>
<section class="stats" aria-label="Your numbers">
  <div class="card stat"><span class="ic" style="background:var(--pop)"><?= ic('flame') ?></span><div><b><?= $streak ?> day<?= $streak === 1 ? '' : 's' ?></b><small>Study streak</small></div></div>
  <div class="card stat"><span class="ic" style="background:var(--brand);color:var(--brand-ink)"><?= ic('check') ?></span><div><b><?= $known ?></b><small>Cards known</small></div></div>
  <div class="card stat"><span class="ic" style="background:var(--lemon)"><?= ic('docchk') ?></span><div><b><?= $practised ?></b><small>Papers opened</small></div></div>
  <div class="card stat"><span class="ic" style="background:var(--lemon)"><?= ic('clock') ?></span><div><b><?= round($mins / 60, 1) ?></b><small>Hours this week</small></div></div>
</section>

<div class="two">
  <section class="card" aria-labelledby="ph">
    <div class="hd"><div><h2 id="ph">Study time this week</h2><p class="sub">Focus minutes per day</p></div></div>
    <svg class="chart" viewBox="0 0 320 150" role="img" aria-label="Focus minutes in the last 7 days">
      <?php $i = 0; foreach ($week as $d => $m): $h = $m ? max(4, round(110 * $m / $maxw)) : 2; $x = 10 + $i * 44; ?>
      <rect class="bar<?= $d === gmdate('Y-m-d') ? ' today' : '' ?>" style="--i:<?= $i ?>" x="<?= $x ?>" y="<?= 120 - $h ?>" width="30" height="<?= $h ?>" rx="6"/>
      <text x="<?= $x + 15 ?>" y="142" text-anchor="middle"><?= e(gmdate('D', strtotime($d))[0]) ?></text>
      <?php $i++; endforeach; ?>
      <line class="gl" x1="0" y1="121" x2="320" y2="121"/>
    </svg>
  </section>
  <section class="card" aria-labelledby="eh">
    <div class="hd"><div><h2 id="eh">Upcoming exams</h2><p class="sub">From your department timetable</p></div></div>
    <?php if (!$exams): ?><div class="empty">No exams on the timetable yet.</div><?php else: ?>
    <ul class="exams"><?php foreach ($exams as $x): $dl = (int)ceil((strtotime($x['exam_date']) - strtotime(gmdate('Y-m-d'))) / 86400); ?>
      <li class="exam"><span class="cal" aria-hidden="true"><b><?= e(gmdate('j', strtotime($x['exam_date']))) ?></b><small><?= e(gmdate('M', strtotime($x['exam_date']))) ?></small></span>
        <div><strong><?= e($x['code']) ?> &middot; <?= e($x['title']) ?></strong><small><?= $x['exam_time'] ? e(substr($x['exam_time'], 0, 5)) . ' &middot; ' : '' ?><?= e($x['venue'] ?: 'Venue to be announced') ?></small></div>
        <span class="days"><?= $dl === 0 ? 'Today' : $dl . ' day' . ($dl === 1 ? '' : 's') ?></span></li><?php endforeach; ?></ul><?php endif; ?>
  </section>
</div>

<section class="card dt" aria-labelledby="rh">
  <div class="dthead"><div><h2 id="rh">Recent past questions</h2><p class="sub"><?= e($u['department'] ?? 'All departments') ?></p></div><div class="dtacts"><a class="btn sm line" href="<?= e(url('dashboard/papers.php')) ?>">Browse all</a></div></div>
  <div class="dtwrap"><table class="dtable"><thead><tr><th>Course</th><th>Paper</th><th>Year</th><th>Answers</th><th data-nosort><span class="vh">Actions</span></th></tr></thead><tbody>
  <?php foreach ($papers as $p): $href = url('dashboard/papers.php?id=' . $p['id']); ?><tr data-href="<?= e($href) ?>">
    <td class="nw"><span class="code"><?= e($p['code']) ?></span></td><td class="dmain"><a class="dtt" href="<?= e($href) ?>"><?= e($p['title']) ?></a></td><td class="nw"><?= (int)$p['exam_year'] ?> &middot; S<?= (int)$p['semester'] ?></td>
    <td><?= $p['has_answers'] ? '<span class="pill plus">Solved</span>' : '<span class="pill new">Questions</span>' ?></td>
    <td class="act"><a class="btn sm<?= in_array($p['id'], $seen) ? ' line' : '' ?>" href="<?= e($href) ?>"><?= in_array($p['id'], $seen) ? 'Open again' : 'Open' ?></a></td></tr>
  <?php endforeach; if (!$papers) echo dt_empty(5, 'No papers yet.'); ?></tbody></table></div>
</section>
<?php include ROOT . '/includes/dashboard_footer.php'; ?>
