<?php
require __DIR__ . '/../app/bootstrap.php';
$me = Auth::user(); $isStaff = $me && is_owner($me);
// visitors pick a school from the directory (?school=<id>, 0 = clear); members always see their own school.
// A visitor who has not picked one yet starts on the platform's default school (Platform settings > Home page).
if (!$me) {
    if (isset($_GET['school'])) { if ((int)$_GET['school']) Tenant::visit((int)$_GET['school']); else { $_SESSION['visit_school'] = 0; Tenant::forget(); } }   // 0 is kept so "Change school" is not undone by the default
    elseif (!array_key_exists('visit_school', $_SESSION) && ($def = home_default_school())) Tenant::visit($def);
}
$school = Tenant::school();
if (in_maintenance($me) && !$isStaff) { http_response_code(503); exit('<!doctype html><meta name="viewport" content="width=device-width,initial-scale=1"><body style="font:16px system-ui;padding:32px;max-width:520px;margin:auto"><h1>Back soon</h1><p>PrepDeck is down for maintenance.</p>'); }

// ----- library data (departments > published papers) -----
$depts = rows('SELECT id,name FROM departments WHERE is_active=1 AND ' . in_school() . ' ORDER BY id');
$deptIcons = ['<path d="M8 7l-5 5 5 5M16 7l5 5-5 5M14 4l-4 16"/>', '<path d="M12 4v16M4 12h16"/><rect x="3" y="3" width="18" height="18" rx="5"/>', '<rect x="3" y="7" width="18" height="13" rx="3"/><path d="M9 7V5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v2M3 13h18"/>', '<circle cx="12" cy="12" r="3.5"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3M5 5l2 2M17 17l2 2M19 5l-2 2M7 17l-2 2"/>', '<path d="M9 3h6M10 3v6l-5 9a2 2 0 0 0 2 3h10a2 2 0 0 0 2-3l-5-9V3M8 15h8"/>'];
$lib = []; $combos = [];
foreach ($depts as $d) {
    $items = [];
    foreach (rows("SELECT p.id,p.title,p.exam_year,p.semester,c.code,c.title course,c.level FROM papers p JOIN courses c ON c.id=p.course_id WHERE p.status='published' AND c.department_id=? AND " . in_school('p') . " ORDER BY c.level,c.code,p.exam_year DESC LIMIT 120", [$d['id']]) as $p) {
        $ls = ((int)$p['level'] ?: 100) . '-' . (int)$p['semester']; $combos[$ls] = 1;
        $items[] = ['code' => $p['code'], 'course' => $p['course'], 'title' => $p['title'], 'year' => $p['exam_year'], 'ls' => $ls,
                    'href' => $me ? url('api/papers.php?a=download&id=' . $p['id']) : url('login.php?next=' . urlencode(url('api/papers.php?a=download&id=' . $p['id'])))];
    }
    $lib[] = ['n' => $d['name'], 'p' => $items];
}
$combos = array_keys($combos); sort($combos);
$chips = array_column(rows("SELECT code FROM courses WHERE paper_count>0 AND " . in_school() . " ORDER BY paper_count DESC, code LIMIT 4"), 'code') ?: array_column(rows('SELECT code FROM courses WHERE ' . in_school() . ' ORDER BY code LIMIT 4'), 'code');
// the "choose your school" directory: schools with the most papers first
$dirSchools = rows("SELECT id,name,short_name,region FROM schools WHERE is_active=1 AND is_listed=1 ORDER BY region IS NULL, region, name");   // the school dropdown, grouped by region
$schoolCount = (int)val('SELECT COUNT(*) FROM schools WHERE is_active=1 AND is_listed=1');

// ----- trust numbers and FAQ -----
$cnt = fn($sql) => (int)val($sql);
$S = $school ? ' AND ' . in_school() : '';   // a chosen school shows its own numbers, otherwise the whole platform
$trust = array_values(array_filter([[$cnt("SELECT COUNT(*) FROM papers WHERE status='published'$S"), 'past papers'], [$school ? 0 : $schoolCount, 'schools'], [$cnt("SELECT COUNT(*) FROM courses WHERE 1=1$S"), 'courses'], [$cnt("SELECT COUNT(*) FROM departments WHERE is_active=1$S"), 'departments'],
    [($st = $cnt("SELECT COUNT(*) FROM users WHERE role_id=1$S")) >= 50 ? $st : 0, 'students'], [$cnt('SELECT COUNT(*) FROM cards c JOIN decks d ON d.id=c.deck_id' . ($school ? ' WHERE ' . in_school('d') : '')), 'flashcards made']], fn($t) => $t[0] > 0));
$trust = array_map(fn($t) => [number_format($t[0]) . ($t[0] >= 10 ? '+' : ''), $t[1]], array_slice($trust, 0, 4));
$faqs = rows('SELECT question,answer FROM faqs ORDER BY position,id LIMIT 8');
$social = array_filter(['Instagram' => setting('social_instagram', ''), 'WhatsApp' => setting('social_whatsapp', ''), 'X' => setting('social_x', '')]);
$metaDesc = 'PrepDeck turns past exam papers and lecture notes into model answers, flashcards, study guides, games and timed practice exams, sorted by department and semester.';

// ----- pricing -----
$prices = Billing::prices(); $first = $prices[0] ?? null;
$perLabel = ['month' => 'per month', 'semester' => 'per semester', 'year' => 'per year'];
$noteLabel = ['month' => 'Billed every month. Cancel any time.', 'semester' => 'Billed once per semester.', 'year' => 'Billed once a year. Best value.'];
$priceJs = [];
foreach ($prices as $p) $priceJs[$p['period']] = ['a' => money($p['amount'], $p['currency']), 'p' => $perLabel[$p['period']], 'n' => $noteLabel[$p['period']]];

$title = 'PrepDeck: past questions, flashcards and study guides';
include __DIR__ . '/../includes/header.php';
?>
<?php if (setting('banner_on', '0') === '1' && setting('banner_text', '') !== ''): ?><div style="background:var(--lemon);color:#0D2B2E;text-align:center;padding:10px 16px;font-weight:600"><?= e(setting('banner_text')) ?></div><?php endif; ?>
<header class="nav">
  <div class="wrap">
    <a class="logo" href="<?= e(url()) ?>" aria-label="PrepDeck home">
      <svg viewBox="0 0 40 40" aria-hidden="true">
        <rect x="9" y="4" width="23" height="29" rx="5" fill="#FFD23F" stroke="#0D2B2E" stroke-width="2.4" transform="rotate(12 20 19)"/>
        <rect x="8" y="5" width="23" height="29" rx="5" fill="#0F766E" stroke="#0D2B2E" stroke-width="2.4" transform="rotate(-8 20 19)"/>
        <rect x="8" y="7" width="23" height="29" rx="5" fill="#FF7A1A" stroke="#0D2B2E" stroke-width="2.4"/>
        <path d="M14 21.5l4.6 4.6L26.5 16" fill="none" stroke="#0D2B2E" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>
      </svg>
      PrepDeck
    </a>
    <nav aria-label="Main" id="mainnav">
      
      <a href="#library">Past questions</a>
      <a href="#features">Study tools</a>
      <a href="#ai">PrepDeck AI</a>
      <a href="#how">How it works</a>
      <a href="#pricing">Pricing</a>
    </nav>
    <div class="acts">
      <?php if ($me): ?><a class="btn pop sm" href="<?= e(url(is_super($me) ? 'platform/' : ($isStaff ? 'admin/' : 'dashboard/'))) ?>">My dashboard</a><?php else: ?><a class="btn ghost sm" href="<?= e(url('login.php')) ?>">Log in</a>
      <a class="btn pop sm" href="<?= e(url('register.php')) ?>">Get started</a><?php endif; ?>
      <button class="navbtn" type="button" id="navbtn" aria-controls="mainnav" aria-expanded="false" aria-label="Open menu"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"/></svg></button>
    </div>
  </div>
</header>

<main id="top">
<section class="hero">
  <div class="wrap grid">
    <div>
      <span class="eyebrow">Past questions &middot; Study guides &middot; Flashcards</span>
      <h1 aria-label="Walk into the exam having already seen it.">
        <span class="w" style="--i:0" aria-hidden="true">Walk</span>
        <span class="w" style="--i:1" aria-hidden="true">into</span>
        <span class="w" style="--i:2" aria-hidden="true">the</span>
        <span class="w" style="--i:3" aria-hidden="true">exam</span>
        <span class="w" style="--i:4" aria-hidden="true">having</span>
        <span class="w" style="--i:5" aria-hidden="true">already</span>
        <span class="w" style="--i:6" aria-hidden="true"><mark>seen&nbsp;it.</mark></span>
      </h1>
      <p class="lede">PrepDeck turns your school's past question PDFs into flashcards, study guides and games, sorted by department and semester.</p>
      <form class="find" id="find" role="search">
        <label for="q">Course code or title</label>
        <input id="q" type="search" placeholder="Try CSC 201 or Anatomy" autocomplete="off">
        <button class="btn pop sm" type="submit">Find papers</button>
      </form>
      <div class="chips" aria-label="Quick searches">
        <?php foreach ($chips as $c): ?><button class="chip" type="button" data-q="<?= e($c) ?>"><?= e($c) ?></button><?php endforeach; ?>
      </div>
      <?php if ($trust): ?><dl class="trust" aria-label="PrepDeck in numbers"><?php foreach ($trust as [$n, $l]): ?><div><dt><?= e($l) ?></dt><dd><?= e($n) ?></dd></div><?php endforeach; ?></dl><?php endif; ?>
    </div>

    <div class="art-wrap">
      <svg class="art" viewBox="0 0 520 470" role="img" aria-label="A past question PDF turning into a stack of flashcards">
        <circle cx="268" cy="238" r="205" fill="#FFD23F" opacity=".32"/>
        <g class="float">
          <rect class="ink" x="14" y="100" width="172" height="236" rx="14" fill="var(--surface)"/>
          <path class="ink" d="M146 100l40 40h-40z" fill="#FFD23F"/>
          <rect x="32" y="156" width="104" height="9" rx="4.5" fill="var(--line)"/>
          <rect x="32" y="178" width="124" height="9" rx="4.5" fill="var(--line)"/>
          <rect x="32" y="200" width="84" height="9" rx="4.5" fill="var(--line)"/>
          <rect x="32" y="222" width="118" height="9" rx="4.5" fill="var(--line)"/>
          <rect x="32" y="244" width="96" height="9" rx="4.5" fill="var(--line)"/>
          <rect class="ink" x="32" y="278" width="58" height="30" rx="8" fill="#FF7A1A"/>
          <text x="61" y="298" text-anchor="middle" style="font:500 14px var(--mono);fill:#0D2B2E">PDF</text>
          <text x="32" y="132" style="font:500 12px var(--mono);fill:var(--muted)">CSC 201 &middot; 2025</text>
        </g>
        <path class="dash" d="M194 222C236 190 252 188 296 224" fill="none" stroke="#0F766E" stroke-width="4" stroke-linecap="round"/>
        <path d="M288 206l20 22-28 3z" fill="#0F766E" stroke="#0F766E" stroke-width="3" stroke-linejoin="round"/>
        <g class="float b">
          <g transform="rotate(10 400 250)"><rect class="ink" x="306" y="120" width="190" height="250" rx="20" fill="#FFD23F"/></g>
          <g transform="rotate(-6 400 250)"><rect class="ink" x="306" y="120" width="190" height="250" rx="20" fill="#0F766E"/></g>
          <rect class="ink" x="306" y="120" width="190" height="250" rx="20" fill="var(--surface)"/>
          <text x="326" y="156" style="font:500 12px var(--mono);fill:var(--brand);letter-spacing:.12em">Q3 &middot; 5 MARKS</text>
          <text x="326" y="204" style="font:700 22px var(--display);fill:var(--ink)">State two</text>
          <text x="326" y="232" style="font:700 22px var(--display);fill:var(--ink)">differences between</text>
          <text x="326" y="260" style="font:700 22px var(--display);fill:var(--ink)">TCP and UDP.</text>
          <rect class="ink" x="326" y="312" width="104" height="32" rx="16" fill="#FF7A1A"/>
          <text x="378" y="333" text-anchor="middle" style="font:600 14px var(--body);fill:#0D2B2E">Tap to flip</text>
        </g>
        <g class="float c">
          <circle class="ink" cx="470" cy="98" r="26" fill="#FF7A1A"/>
          <rect x="460" y="97" width="20" height="15" rx="3" fill="#0D2B2E"/>
          <path d="M464 97v-6a6 6 0 0 1 12 0v6" fill="none" stroke="#0D2B2E" stroke-width="3" stroke-linecap="round"/>
        </g>
        <path class="twinkle" d="M80 40l6 14 14 6-14 6-6 14-6-14-14-6 14-6z" fill="#0F766E"/>
        <path class="twinkle" style="animation-delay:-.8s" d="M250 60l4 9 9 4-9 4-4 9-4-9-9-4 9-4z" fill="#FF7A1A"/>
        <path class="twinkle" style="animation-delay:-1.5s" d="M60 392l5 11 11 5-11 5-5 11-5-11-11-5 11-5z" fill="#FFD23F" stroke="#0D2B2E" stroke-width="1.5"/>
        <path class="twinkle" style="animation-delay:-2s" d="M468 410l4 9 9 4-9 4-4 9-4-9-9-4 9-4z" fill="#0F766E"/>
      </svg>
    </div>
  </div>
</section>

<div class="band" aria-hidden="true">
  <div class="track">
    <span>Computer Science</span><span>Nursing</span><span>Business Administration</span><span>Engineering</span><span>Biological Sciences</span><span>Mathematics</span><span>Education</span><span>Law</span>
    <span>Computer Science</span><span>Nursing</span><span>Business Administration</span><span>Engineering</span><span>Biological Sciences</span><span>Mathematics</span><span>Education</span><span>Law</span>
  </div>
</div>

<div class="wave" style="--from:var(--lemon);--to:var(--bg)">
  <svg viewBox="0 0 1440 100" preserveAspectRatio="none" aria-hidden="true"><path d="M0,40 C240,100 480,0 720,44 C960,88 1200,6 1440,44 L1440,100 L0,100Z"><animate attributeName="d" dur="10s" repeatCount="indefinite" values="M0,40 C240,100 480,0 720,44 C960,88 1200,6 1440,44 L1440,100 L0,100Z;M0,56 C240,0 480,90 720,40 C960,0 1200,86 1440,50 L1440,100 L0,100Z;M0,40 C240,100 480,0 720,44 C960,88 1200,6 1440,44 L1440,100 L0,100Z"/></path></svg>
</div>

<section id="schools">
  <div class="wrap">
    <div class="head reveal">
      <span class="eyebrow">Choose your school</span>
      <h2><?= $school ? 'You are browsing ' . e($school['name']) . '.' : 'PrepDeck works for every school in Ghana.' ?></h2>
      <p><?= $school ? 'Pick another school from the list, or sign up to get its past questions, flashcards and study guides.' : 'Universities, technical universities, colleges of education, nursing colleges and senior high schools: ' . number_format($schoolCount) . ' schools and counting. Find yours.' ?></p>
    </div>
    <?php if ($school): ?><div class="schosen"><b><?= e($school['name']) ?></b><span><?php if (!$me): ?><a class="btn ghost sm" href="<?= e(url('?school=0#schools')) ?>">Change school</a><?php endif; ?></span></div><?php endif; ?>
    <?php if (!$me): ?>
    <form class="sfind" id="sfind" method="get" action="<?= e(url()) ?>">
      <label class="vh" for="spick">Choose your school</label>
      <select id="spick" name="school" required>
        <option value="" disabled<?= $school ? '' : ' selected' ?>>Choose your school</option>
        <?php $grp = null; foreach ($dirSchools as $f): if ($f['region'] !== $grp): ?><?= $grp !== null ? '</optgroup>' : '' ?><optgroup label="<?= e($f['region'] ?: 'Other') ?>"><?php $grp = $f['region']; endif; ?>
        <option value="<?= (int)$f['id'] ?>"<?= $school && (int)$school['id'] === (int)$f['id'] ? ' selected' : '' ?>><?= e($f['name'] . ($f['short_name'] ? ' (' . $f['short_name'] . ')' : '')) ?></option>
        <?php endforeach; if ($grp !== null) echo '</optgroup>'; ?>
      </select>
      <button class="btn pop sm" type="submit">View papers</button>
    </form>
    <p style="margin-top:14px">School not listed? <a href="<?= e(url('register.php?missing=1')) ?>">Ask us to add it</a>.</p>
    <?php endif; ?>
  </div>
</section>

<?php if ($school): ?>
<section id="library">
  <div class="wrap">
    <div class="head reveal">
      <span class="eyebrow">Past questions library &middot; <?= e($school['short_name'] ?: $school['name']) ?></span>
      <h2>Pick your department. Pick your semester. Start with real papers.</h2>
      <p>Class reps and lecturers upload PDFs once. Everyone in <?= e($school['name']) ?> can find them by course, level and year.</p>
    </div>
    <?php if (!$depts): ?><p class="reveal">No departments have been added for <?= e($school['name']) ?> yet.<?= $me ? '' : ' <a href="' . e(url('register.php?school=' . $school['id'])) . '">Be the first to join</a> and ask your class rep to upload past questions.' ?></p><?php endif; ?>
    <div class="lib reveal zoom"<?= $depts ? '' : ' hidden' ?>>
      <div class="depts" role="tablist" aria-label="Departments">
        <h3>Department</h3>
        <?php foreach ($depts as $i => $d): ?>
        <button class="dept" role="tab" data-d="<?= (int)$i ?>" aria-selected="<?= $i === 0 ? 'true' : 'false' ?>"><svg viewBox="0 0 24 24" aria-hidden="true"><?= $deptIcons[$i % count($deptIcons)] ?></svg><?= e($d['name']) ?></button>
        <?php endforeach; ?>
      </div>
      <div class="pane">
        <div class="sems" id="sems" aria-label="Level and semester">
          <button class="sem" data-s="all" aria-pressed="true">All</button>
          <?php foreach ($combos as $c): [$lv, $sm] = explode('-', $c); ?>
          <button class="sem" data-s="<?= e($c) ?>" aria-pressed="false"><?= e($lv) ?> &middot; Sem <?= e($sm) ?></button>
          <?php endforeach; ?>
        </div>
        <div class="rows" id="rows" aria-live="polite"></div>
      </div>
    </div>
  </div>
</section>
<?php endif; ?>
<div class="wave" style="--from:var(--bg);--to:var(--deep)">
  <svg viewBox="0 0 1440 100" preserveAspectRatio="none" aria-hidden="true"><path d="M0,50 C300,0 520,100 780,46 C1040,0 1240,90 1440,40 L1440,100 L0,100Z"><animate attributeName="d" dur="11s" repeatCount="indefinite" values="M0,50 C300,0 520,100 780,46 C1040,0 1240,90 1440,40 L1440,100 L0,100Z;M0,36 C300,96 520,6 780,56 C1040,100 1240,10 1440,52 L1440,100 L0,100Z;M0,50 C300,0 520,100 780,46 C1040,0 1240,90 1440,40 L1440,100 L0,100Z"/></path></svg>
</div>

<section id="features" class="deep">
  <div class="wrap">
    <div class="head reveal">
      <span class="eyebrow">Study tools</span>
      <h2>Four ways to turn a PDF into a pass.</h2>
      <p>Try the flashcard and the match game right here. Record Lecture and Study Guides unlock with PrepDeck Plus.</p>
    </div>
    <div class="bento">
      <article class="f reveal" style="--d:0">
        <span class="badge plus"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/></svg>Plus</span>
        <h3>Record Lecture</h3>
        <p>Press record in class. PrepDeck keeps the audio, writes the transcript and files it under the course, next to its past questions.</p>
        <div class="demo">
          <div class="rec">
            <div class="dot" aria-hidden="true"><svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="#0D2B2E" stroke-width="2.4" stroke-linecap="round"><rect x="9" y="3" width="6" height="12" rx="3"/><path d="M5 11a7 7 0 0 0 14 0M12 18v3"/></svg></div>
            <svg viewBox="0 0 176 78" preserveAspectRatio="none" aria-hidden="true">
              <rect style="--i:0" x="0" y="12" width="8" height="54" rx="4"/><rect style="--i:1" x="12" y="4" width="8" height="70" rx="4"/><rect style="--i:2" x="24" y="20" width="8" height="38" rx="4"/><rect style="--i:3" x="36" y="8" width="8" height="62" rx="4"/><rect style="--i:4" x="48" y="24" width="8" height="30" rx="4"/><rect style="--i:5" x="60" y="2" width="8" height="74" rx="4"/><rect style="--i:6" x="72" y="16" width="8" height="46" rx="4"/><rect style="--i:7" x="84" y="26" width="8" height="26" rx="4"/><rect style="--i:8" x="96" y="6" width="8" height="66" rx="4"/><rect style="--i:9" x="108" y="18" width="8" height="42" rx="4"/><rect style="--i:10" x="120" y="10" width="8" height="58" rx="4"/><rect style="--i:11" x="132" y="28" width="8" height="22" rx="4"/><rect style="--i:12" x="144" y="14" width="8" height="50" rx="4"/><rect style="--i:13" x="156" y="22" width="8" height="34" rx="4"/><rect style="--i:14" x="168" y="8" width="8" height="62" rx="4"/>
            </svg>
            <small>REC 00:42</small>
          </div>
        </div>
      </article>

      <article class="f reveal" style="--d:1">
        <span class="badge plus"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/></svg>Plus</span>
        <h3>Study Guides</h3>
        <p>Every past paper for a course is read together. The guide ranks topics by how often they come back, then links each one to its questions.</p>
        <div class="demo">
          <div class="guide" role="img" aria-label="Example study guide ranking four topics by how often they appear">
            <div class="gl">Networking<i style="--w:92%;--i:0"></i></div>
            <div class="gl">Databases<i style="--w:74%;--i:1"></i></div>
            <div class="gl">Algorithms<i style="--w:58%;--i:2"></i></div>
            <div class="gl">Op. systems<i style="--w:36%;--i:3"></i></div>
          </div>
        </div>
      </article>

      <article class="f reveal" style="--d:0">
        <span class="badge free">Free</span>
        <h3>Flashcards</h3>
        <p>Each question becomes a card with its answer on the back. Browse shared decks free. Plus lets you build your own and track what you know.</p>
        <div class="demo" style="flex-direction:column;gap:22px;padding-block:26px">
          <button class="flip" id="flip" type="button" aria-pressed="false" aria-label="Flashcard: Define database normalisation. Press to flip and see the answer.">
            <span class="in">
              <span class="face front"><span class="tagq">Question</span><b>Define database normalisation.</b><span class="tap"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 12a8 8 0 0 1 14-5.3M20 4v4h-4M20 12a8 8 0 0 1-14 5.3M4 20v-4h4"/></svg>Tap to flip</span></span>
              <span class="face back"><span class="tagq">Answer</span><b>Organising tables to cut repeated data and update errors.</b><span class="tap"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 12a8 8 0 0 1 14-5.3M20 4v4h-4M20 12a8 8 0 0 1-14 5.3M4 20v-4h4"/></svg>Tap to flip back</span></span>
            </span>
          </button>
          <div class="deckmeta" aria-hidden="true">Example deck &middot; CSC 301<i class="on"></i><i></i><i></i><i></i></div>
        </div>
      </article>

      <article class="f reveal" style="--d:1">
        <span class="badge free">Match is free</span>
        <h3>Games</h3>
        <p>Match terms to meanings against the clock. Beat the Clock and Class Duel, where your course mates race you, need Plus.</p>
        <div class="demo" style="flex-direction:column;gap:12px">
          <div class="match" id="match">
            <button class="m" data-k="c" aria-pressed="false">Name lookup</button>
            <button class="m" data-k="a" aria-pressed="false">TCP</button>
            <button class="m" data-k="b" aria-pressed="false">No handshake</button>
            <button class="m" data-k="c" aria-pressed="false">DNS</button>
            <button class="m" data-k="b" aria-pressed="false">UDP</button>
            <button class="m" data-k="a" aria-pressed="false">Reliable delivery</button>
          </div>
          <p class="hint" id="mhint">Tap a term, then its meaning.</p>
        </div>
      </article>
    </div>
  </div>
</section>

<div class="wave" style="--from:var(--deep);--to:var(--bg)">
  <svg viewBox="0 0 1440 100" preserveAspectRatio="none" aria-hidden="true"><path d="M0,44 C260,100 560,0 820,50 C1080,96 1260,10 1440,52 L1440,100 L0,100Z"><animate attributeName="d" dur="12s" repeatCount="indefinite" values="M0,44 C260,100 560,0 820,50 C1080,96 1260,10 1440,52 L1440,100 L0,100Z;M0,58 C260,4 560,92 820,42 C1080,0 1260,90 1440,44 L1440,100 L0,100Z;M0,44 C260,100 560,0 820,50 C1080,96 1260,10 1440,52 L1440,100 L0,100Z"/></path></svg>
</div>

<section id="how">
  <div class="wrap">
    <div class="head reveal">
      <span class="eyebrow">How it works</span>
      <h2>From a scanned paper to a deck in three moves.</h2>
    </div>
    <div class="steps">
      <div class="step reveal" style="--d:0">
        <svg viewBox="0 0 300 180" aria-hidden="true">
          <rect class="pipe" x="40" y="128" width="220" height="34" rx="12" fill="var(--surface)"/>
          <g class="bob"><rect class="pipe" x="108" y="20" width="84" height="104" rx="10" fill="var(--surface)"/><path class="pipe arr" fill="none" d="M150 98V54M134 70l16-16 16 16"/></g>
          <rect x="124" y="136" width="52" height="8" rx="4" fill="var(--pop)"/>
        </svg>
        <span class="n">STEP 1</span>
        <h3>Upload the PDF</h3>
        <p>Choose the department, level, semester and year. Past questions, mid-sems and quizzes all work.</p>
      </div>
      <div class="step reveal" style="--d:1">
        <svg viewBox="0 0 300 180" aria-hidden="true">
          <g class="s1"><rect class="pipe" x="46" y="40" width="64" height="96" rx="10" fill="#FFD23F"/></g>
          <g class="s2"><rect class="pipe" x="118" y="30" width="64" height="96" rx="10" fill="var(--brand)"/></g>
          <g class="s3"><rect class="pipe" x="190" y="40" width="64" height="96" rx="10" fill="var(--pop)"/></g>
          <path class="pipe" fill="none" d="M70 150h160" stroke-dasharray="3 9"/>
        </svg>
        <span class="n">STEP 2</span>
        <h3>PrepDeck splits it</h3>
        <p>Each question is pulled out on its own, matched to its topic and linked to the course.</p>
      </div>
      <div class="step reveal" style="--d:2">
        <svg viewBox="0 0 300 180" aria-hidden="true">
          <rect class="pipe" x="70" y="26" width="160" height="112" rx="16" fill="var(--surface)"/>
          <circle cx="150" cy="82" r="30" fill="var(--ok)" opacity=".18"/>
          <path class="pipe tick" fill="none" d="M130 84l15 15 27-32"/>
          <rect x="106" y="150" width="88" height="8" rx="4" fill="var(--line)"/>
        </svg>
        <span class="n">STEP 3</span>
        <h3>Practise until it sticks</h3>
        <p>Flip cards, read the guide, play a round. Topics you miss come back sooner.</p>
      </div>
    </div>
  </div>
</section>

<section id="ai" class="aisec">
  <div class="wrap aigrid">
    <div class="reveal">
      <span class="eyebrow">PrepDeck AI</span>
      <h2>Upload a PDF. Watch it become a study kit.</h2>
      <p class="lede">You see every step as it happens, from the first page read to the last flashcard made. Nothing happens behind a spinner.</p>
      <ul class="aiwhat">
        <li><b>Reads every page</b>, even scanned photocopies</li>
        <li><b>Finds each question</b> and writes a model answer, showing working</li>
        <li><b>Builds flashcards, a study guide and games</b> from the same paper</li>
        <li><b>Predicts likely exam questions</b> from lecture notes and recordings</li>
        <li><b>Practice exams</b>: timed, with model answers to mark yourself against</li>
      </ul>
      <a class="btn pop" href="<?= e(url($me ? 'dashboard/materials.php' : 'register.php')) ?>"><?= $me ? 'Upload your notes' : 'Try it free' ?></a>
      <p class="note-sm">Answers stay as drafts until a lecturer or admin checks them, because AI can be wrong.</p>
    </div>
    <div class="aidemo reveal zoom" aria-label="Example of PrepDeck AI processing a past paper" role="img">
      <div class="adh"><span class="adorb"><i></i></span><div><b id="adHead">Reading the document…</b><small>CSC 201 · Data Structures · 2025 exam paper</small></div><b class="adpct" id="adPct">0%</b></div>
      <div class="adbar"><i id="adBar"></i></div>
      <div class="adbody">
        <ol class="adsteps" id="adSteps"><li>Reading the document</li><li>Finding the questions</li><li>Writing model answers</li><li>Creating flashcards</li><li>Writing the study guide</li><li>Setting up games</li><li>Ready to study</li></ol>
        <div class="adfeed" id="adFeed"></div>
      </div>
    </div>
  </div>
</section>
<script>
(function () {
  var S = [[0, 6, 'Opening the PDF and reading every page'], [0, 12, 'Read 6 pages, 2,140 words'], [1, 20, 'Looking for questions'], [1, 34, 'Found 9 questions', ['1a Define a linked list and…', '1b State two uses of a stack', '2 Trace bubble sort on…']],
    [2, 48, 'Writing model answers for questions 1-4 of 9'], [2, 70, 'Answered questions 5-9', ['Q5 · high confidence', 'Q6 · high confidence']], [3, 80, 'Made 8 flashcards'], [4, 88, 'Study guide ready with 5 sections', ['Arrays & lists', 'Stacks & queues', 'Sorting']],
    [5, 96, '3 games ready to play', ['Match', 'Beat the Clock', 'Class Duel']], [6, 100, 'All done. Ready to study']];
  var steps = document.querySelectorAll('#adSteps li'), feed = document.getElementById('adFeed'), bar = document.getElementById('adBar'), pct = document.getElementById('adPct'), head = document.getElementById('adHead'), i = 0;
  if (!feed) return;
  var reduce = matchMedia('(prefers-reduced-motion: reduce)').matches;
  function go() {
    if (i >= S.length) { setTimeout(function () { feed.innerHTML = ''; i = 0; go(); }, 3500); return; }
    var s = S[i++], li = document.createElement('div');
    steps.forEach(function (x, k) { x.className = k < s[0] || s[1] === 100 ? 'done' : k === s[0] ? 'now' : ''; });
    bar.style.width = s[1] + '%'; pct.textContent = s[1] + '%'; head.textContent = s[1] === 100 ? 'All done. Your study kit is ready' : steps[s[0]].textContent + '…';
    li.className = 'adev' + (s[1] === 100 ? ' ok' : ''); li.innerHTML = '<p></p>' + (s[3] ? '<ul>' + s[3].map(function (t) { return '<li></li>'; }).join('') + '</ul>' : '');
    li.querySelector('p').textContent = s[2]; if (s[3]) li.querySelectorAll('li').forEach(function (x, k) { x.textContent = s[3][k]; });
    feed.appendChild(li); while (feed.children.length > 5) feed.removeChild(feed.firstChild);
    setTimeout(go, reduce ? 2500 : 1400);
  }
  if ('IntersectionObserver' in window) { var o = new IntersectionObserver(function (en) { if (en[0].isIntersecting) { o.disconnect(); go(); } }); o.observe(feed); } else go();
})();
</script>

<div class="wave" style="--from:var(--bg);--to:var(--surface)">
  <svg viewBox="0 0 1440 100" preserveAspectRatio="none" aria-hidden="true"><path d="M0,52 C240,4 520,94 760,48 C1000,2 1240,86 1440,44 L1440,100 L0,100Z"><animate attributeName="d" dur="9s" repeatCount="indefinite" values="M0,52 C240,4 520,94 760,48 C1000,2 1240,86 1440,44 L1440,100 L0,100Z;M0,40 C240,92 520,6 760,54 C1000,100 1240,8 1440,54 L1440,100 L0,100Z;M0,52 C240,4 520,94 760,48 C1000,2 1240,86 1440,44 L1440,100 L0,100Z"/></path></svg>
</div>

<section id="pricing" class="price-wrap" style="border-block:0">
  <div class="wrap">
    <div class="head reveal">
      <span class="eyebrow">Pricing</span>
      <h2>Free to study. Plus to go deeper.</h2>
      <p>The past question library stays free for every student. Plus pays for the tools that take real work to run.</p>
    </div>
    <div class="toggle reveal" role="group" aria-label="Billing period">
      <?php foreach ($prices as $i => $p): ?><button type="button" data-b="<?= e($p['period']) ?>" aria-pressed="<?= $i === 0 ? 'true' : 'false' ?>"><?= e(Billing::LABEL[$p['period']]) ?></button><?php endforeach; ?>
    </div>
    <div class="plans">
      <div class="plan reveal" style="--d:0">
        <h3>Free</h3>
        <div class="amt">GH&#8373; 0</div>
        <ul>
          <li><svg viewBox="0 0 24 24"><path d="M5 12.5l4.5 4.5L19 7"/></svg>Browse and view every past question PDF</li>
          <li><svg viewBox="0 0 24 24"><path d="M5 12.5l4.5 4.5L19 7"/></svg>Search by course, level, semester and year</li>
          <li><svg viewBox="0 0 24 24"><path d="M5 12.5l4.5 4.5L19 7"/></svg>Shared flashcard decks</li>
          <li><svg viewBox="0 0 24 24"><path d="M5 12.5l4.5 4.5L19 7"/></svg>The Match game</li>
          <li class="off"><svg viewBox="0 0 24 24"><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/></svg>Record Lecture</li>
          <li class="off"><svg viewBox="0 0 24 24"><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/></svg>Study Guides and worked answers</li>
          <li class="off"><svg viewBox="0 0 24 24"><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/></svg>PrepDeck AI and lecture note uploads</li>
        </ul>
        <a class="btn" href="<?= e(url($me ? 'dashboard/papers.php' : 'register.php')) ?>">Start free</a>
      </div>
      <div class="plan hot reveal" style="--d:1">
        <span class="ribbon">Plus</span>
        <h3>PrepDeck Plus</h3>
        <div class="amt"><span id="amt"><?= e($first ? money($first['amount'], $first['currency']) : 'Coming soon') ?></span> <small id="per"><?= e($first ? $perLabel[$first['period']] : '') ?></small></div>
        <p class="bnote" id="bnote"><?= e($first ? $noteLabel[$first['period']] : '') ?></p>
        <ul>
          <li><svg viewBox="0 0 24 24"><path d="M5 12.5l4.5 4.5L19 7"/></svg>Everything in Free</li>
          <li><svg viewBox="0 0 24 24"><path d="M5 12.5l4.5 4.5L19 7"/></svg>Record Lecture with transcripts</li>
          <li><svg viewBox="0 0 24 24"><path d="M5 12.5l4.5 4.5L19 7"/></svg>Study Guides built from past papers</li>
          <li><svg viewBox="0 0 24 24"><path d="M5 12.5l4.5 4.5L19 7"/></svg>Worked answers on past questions</li>
          <li><svg viewBox="0 0 24 24"><path d="M5 12.5l4.5 4.5L19 7"/></svg>PrepDeck AI study assistant</li>
          <li><svg viewBox="0 0 24 24"><path d="M5 12.5l4.5 4.5L19 7"/></svg>Upload lecture notes for guides, flashcards and games</li>
          <li><svg viewBox="0 0 24 24"><path d="M5 12.5l4.5 4.5L19 7"/></svg>My notes and a custom focus timer</li>
          <li><svg viewBox="0 0 24 24"><path d="M5 12.5l4.5 4.5L19 7"/></svg>Beat the Clock and Class Duel</li>
        </ul>
        <a class="btn" href="<?= e(url($me ? 'dashboard/plan.php' : 'register.php')) ?>">Subscribe to Plus</a>
      </div>
    </div>
  </div>
</section>

<?php if ($faqs): ?>
<section id="faq" class="faqsec">
  <div class="wrap">
    <div class="head reveal"><span class="eyebrow">Questions</span><h2>Things students ask before they sign up.</h2></div>
    <div class="faqs reveal">
      <?php foreach ($faqs as $i => $f): ?><details<?= $i === 0 ? ' open' : '' ?>><summary><?= e($f['question']) ?></summary><p><?= nl2br(e($f['answer'])) ?></p></details><?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<?php if ($faqs): ?>
<script type="application/ld+json"><?= json_encode(['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => array_map(fn($f) => ['@type' => 'Question', 'name' => $f['question'], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => strip_tags(nl2br($f['answer']))]], $faqs)], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?></script>
<?php endif; ?>

<div class="wave" style="--from:var(--surface);--to:var(--pop)">
  <svg viewBox="0 0 1440 100" preserveAspectRatio="none" aria-hidden="true"><path d="M0,40 C280,96 540,2 800,46 C1060,92 1260,8 1440,48 L1440,100 L0,100Z"><animate attributeName="d" dur="10s" repeatCount="indefinite" values="M0,40 C280,96 540,2 800,46 C1060,92 1260,8 1440,48 L1440,100 L0,100Z;M0,54 C280,2 540,94 800,40 C1060,0 1260,92 1440,40 L1440,100 L0,100Z;M0,40 C280,96 540,2 800,46 C1060,92 1260,8 1440,48 L1440,100 L0,100Z"/></path></svg>
</div>

<section class="cta">
  <svg class="conf" viewBox="0 0 1200 400" preserveAspectRatio="xMidYMid slice" aria-hidden="true">
    <rect class="float" x="120" y="70" width="26" height="26" rx="6" fill="#FFD23F" stroke="#0D2B2E" stroke-width="2.5" transform="rotate(18 133 83)"/>
    <circle class="float b" cx="1030" cy="90" r="16" fill="#0F766E" stroke="#0D2B2E" stroke-width="2.5"/>
    <path class="twinkle" d="M980 300l8 18 18 8-18 8-8 18-8-18-18-8 18-8z" fill="#FFD23F" stroke="#0D2B2E" stroke-width="2"/>
    <path class="twinkle" style="animation-delay:-1s" d="M170 290l6 14 14 6-14 6-6 14-6-14-14-6 14-6z" fill="#0D2B2E"/>
  </svg>
  <div class="wrap" style="position:relative">
    <h2 class="reveal">Your next paper is already in the deck.</h2>
    <p class="reveal" style="--d:1">Make a free account, find your course and open the first question.</p>
    <a class="btn reveal" style="--d:2" href="<?= e(url($me ? 'dashboard/' : 'register.php')) ?>"><?= $me ? 'Open my dashboard' : 'Create free account' ?></a>
  </div>
</section>
</main>

<div class="wave" style="--from:var(--pop);--to:var(--deep)">
  <svg viewBox="0 0 1440 100" preserveAspectRatio="none" aria-hidden="true"><path d="M0,46 C300,100 560,0 840,50 C1100,96 1280,12 1440,44 L1440,100 L0,100Z"><animate attributeName="d" dur="11s" repeatCount="indefinite" values="M0,46 C300,100 560,0 840,50 C1100,96 1280,12 1440,44 L1440,100 L0,100Z;M0,56 C300,2 560,92 840,40 C1100,0 1280,88 1440,50 L1440,100 L0,100Z;M0,46 C300,100 560,0 840,50 C1100,96 1280,12 1440,44 L1440,100 L0,100Z"/></path></svg>
</div>

<footer class="ft">
  <div class="wrap">
    <div class="ft-join reveal">
      <svg class="bg" viewBox="0 0 190 190" aria-hidden="true">
        <g transform="rotate(14 95 95)"><rect x="50" y="30" width="84" height="110" rx="14" fill="#FFD23F" stroke="#0D2B2E" stroke-width="3"/></g>
        <g transform="rotate(-8 95 95)"><rect x="50" y="30" width="84" height="110" rx="14" fill="#0F766E" stroke="#0D2B2E" stroke-width="3"/></g>
        <rect x="50" y="30" width="84" height="110" rx="14" fill="#FF7A1A" stroke="#0D2B2E" stroke-width="3"/>
      </svg>
      <div>
        <h3>New papers for your course, first.</h3>
        <p>Add your email and pick up alerts when your department's past questions are uploaded.</p>
      </div>
      <form class="ft-form" id="alerts" novalidate data-dept="">
        <label for="alertEmail">Email address</label>
        <input id="alertEmail" type="email" placeholder="you@school.edu" autocomplete="email" required>
        <button class="btn sm" type="submit">Notify me</button>
      </form>
      <p class="ft-msg" id="alertMsg" role="status"></p>
    </div>

    <div class="ft-grid">
      <div class="ft-brand reveal">
        <a class="logo" href="#top" aria-label="PrepDeck home">
          <svg viewBox="0 0 40 40" aria-hidden="true"><rect x="9" y="4" width="23" height="29" rx="5" fill="#FFD23F" stroke="#0D2B2E" stroke-width="2.4" transform="rotate(12 20 19)"/><rect x="8" y="5" width="23" height="29" rx="5" fill="#0F766E" stroke="#0D2B2E" stroke-width="2.4" transform="rotate(-8 20 19)"/><rect x="8" y="7" width="23" height="29" rx="5" fill="#FF7A1A" stroke="#0D2B2E" stroke-width="2.4"/><path d="M14 21.5l4.6 4.6L26.5 16" fill="none" stroke="#0D2B2E" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/></svg>
          PrepDeck
        </a>
        <p>Past questions, study guides, flashcards and games for every department and semester.</p>
        <?php if ($social): ?><div class="ft-social"><?php $ico = array (   'Instagram' => '<rect x="3" y="3" width="18" height="18" rx="5"/><circle cx="12" cy="12" r="4"/><path d="M17.5 6.5h.01"/>',   'WhatsApp' => '<path d="M4 20l1.4-4.2A8 8 0 1 1 8.4 18.7z"/><path d="M9 9.5c0 3 2.5 5.5 5.5 5.5l1.3-1.4-2-1-1 .6a3.6 3.6 0 0 1-1.6-1.6l.6-1-1-2z"/>',   'X' => '<path d="M4 4l16 16M20 4L4 20"/>', ); foreach ($social as $k => $href): ?><a href="<?= e($href) ?>" target="_blank" rel="noopener" aria-label="PrepDeck on <?= e($k) ?>"><svg viewBox="0 0 24 24" aria-hidden="true"><?= $ico[$k] ?></svg></a><?php endforeach; ?></div><?php endif; ?>
      </div>
      <nav class="reveal" style="--d:1" aria-label="Study tools">
        <h4>Study</h4>
        <ul>
          <li><a href="#library">Past questions</a></li>
          <li><a href="#features">Flashcards</a></li>
          <li><a href="#features">Games</a></li>
          <li><a href="#features">Study guides <span class="pl">Plus</span></a></li>
          <li><a href="#features">Record lecture <span class="pl">Plus</span></a></li>
          <li><a href="#ai">Practice exams <span class="pl">Plus</span></a></li>
        </ul>
      </nav>
      <nav class="reveal" style="--d:2" aria-label="For schools">
        <h4>For schools</h4>
        <ul>
          <li><a href="#how">Upload a PDF</a></li>
          <li><a href="#library">Departments</a></li>
          <li><a href="#how">Class reps</a></li>
          <li><a href="#how">Lecturers</a></li>
          <li><a href="#pricing">Pricing</a></li>
        </ul>
      </nav>
      <nav class="reveal" style="--d:3" aria-label="Company">
        <h4>PrepDeck</h4>
        <ul>
          <li><a href="#how">About</a></li>
          <li><a href="<?= e($faqs ? '#faq' : url('dashboard/help.php')) ?>">Help centre</a></li>
          <?php if (setting('contact_email', '')): ?><li><a href="mailto:<?= e(setting('contact_email')) ?>">Contact</a></li><?php endif; ?>
          <li><a href="<?= e(url('dashboard/papers.php')) ?>">Report a paper</a></li>
        </ul>
      </nav>
    </div>

    <div class="ft-mark" aria-hidden="true">PrepDeck</div>

    <div class="ft-bar">
      <small>&copy; <?= gmdate('Y') ?> PrepDeck<?= $school ? ' for ' . e($school['name']) : ' for schools across Ghana' ?>. Built for students, by students.</small>
      <nav aria-label="Legal"><a href="<?= e(url('page.php?p=privacy')) ?>">Privacy</a><a href="<?= e(url('page.php?p=terms')) ?>">Terms</a><a href="<?= e(url('page.php?p=refund')) ?>">Refunds</a></nav>
      <a class="totop" href="#top">Back to top <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 19V5M6 11l6-6 6 6"/></svg></a>
    </div>
  </div>
</footer>

<style>
.bob{animation:float 2.4s ease-in-out infinite;transform-box:fill-box;transform-origin:center}
.s1,.s2,.s3{transform-box:fill-box;transform-origin:center;animation:spr 3.2s ease-in-out infinite}
.s1{--x:-8px;--r:-6deg}.s3{--x:8px;--r:6deg}.s2{--x:0px;--r:0deg;animation-delay:.1s}
@keyframes spr{0%,100%{transform:translateX(0) rotate(0)}50%{transform:translateX(var(--x)) rotate(var(--r))}}
.tick{stroke-dasharray:70;animation:draw 2.6s ease-in-out infinite}
@keyframes draw{0%{stroke-dashoffset:70}45%,100%{stroke-dashoffset:0}}
</style>
<style>.pipe.arr{stroke:var(--brand);stroke-width:4}.pipe.tick{stroke:var(--ok);stroke-width:7}</style>

<script>window.LIB = <?= json_encode($lib, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>; window.PRICES = <?= json_encode($priceJs, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>; window.PLANURL = <?= json_encode(url($me ? 'dashboard/plan.php' : 'register.php')) ?>;</script>
<?php $scripts = ['home.js']; include __DIR__ . '/../includes/footer.php'; ?>
