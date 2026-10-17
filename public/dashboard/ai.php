<?php
require __DIR__ . '/../../app/bootstrap.php';
$u = require_login(); $plus = is_plus($u);
$active = 'ai'; $title = 'PrepDeck AI'; $scripts = ['ai.js'];
$age = age_from_dob($u['date_of_birth'] ?? null); $adult = $age === null || $age >= 18 || is_owner($u);
$cap = (int)setting('ai_cap', 50); $left = max(0, $cap - (int)val('SELECT COUNT(*) FROM ai_usage WHERE user_id=? AND created_at>=CURDATE()', [$u['id']]));
$courses = rows('SELECT id,CONCAT(code," · ",title) n FROM courses WHERE ' . in_school() . ' AND (department_id=? OR ?=0) ORDER BY code', [$u['department_id'], (int)$u['department_id']]);
include ROOT . '/includes/dashboard_header.php';
echo page_head('PrepDeck AI', 'Your study partner. It explains, quizzes you and plans your revision.');
if (setting('ai_on', '1') !== '1') { echo '<div class="banner warn">PrepDeck AI is switched off for now.</div>'; }
elseif (!$plus) { echo '<section class="card">' . lock_note('PrepDeck AI', 'ai', true) . '</section>'; }
elseif (!$adult) { echo '<div class="banner warn">PrepDeck AI is for students aged 18 and over.</div>'; }
else { ?>
<section class="card" style="padding:0;display:flex;flex-direction:column;min-height:60vh" id="aichat" data-left="<?= $left ?>" data-q="<?= e($_GET['q'] ?? '') ?>">
  <div class="chead" style="padding-right:16px"><span class="spark"><?= ic('spark') ?></span><div><h2>Study chat</h2>
    <select id="aicourse" aria-label="Course"><?= options($courses, null, 'id', 'n', 'Any course') ?></select>
    <select id="aimode" aria-label="Mode" style="border:1.5px solid var(--line);background:var(--bg);border-radius:999px;min-height:36px;padding:0 10px;font:600 13px var(--body)"><option value="chat">Explain</option><option value="quiz">Quiz me</option><option value="cards">Flashcards</option><option value="guide">Study plan</option></select></div>
    <span class="pill new" style="margin-left:auto" id="aileft"><?= $left ?> left today</span></div>
  <div class="msgs" id="msgs" aria-live="polite"><div class="msg bot"><p>Hi <?= $firstName ?>. Ask me about any topic, or pick Quiz me to practise exam-style questions.</p></div></div>
  <div class="cfoot"><div class="sugg"><button type="button" data-ask="Explain this topic in simple words: ">Explain a topic</button><button type="button" data-ask="Quiz me with 5 exam-style questions on ">Quiz me on…</button><button type="button" data-ask="Make 6 flashcards on ">Flashcards on…</button><button type="button" data-ask="Make a 5 day study plan for ">Plan for…</button></div>
    <form class="ask" id="askform"><label class="vh" for="askin">Your question</label><input id="askin" type="text" placeholder="Ask a question" autocomplete="off" maxlength="3000" enterkeyhint="send"><button class="send" type="submit" aria-label="Send"><?= ic('send') ?></button></form></div>
</section>
<?php } include ROOT . '/includes/dashboard_footer.php'; ?>
