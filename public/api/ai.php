<?php
// PrepDeck AI chat (Plus, 18+). The AI key stays on the server.
require __DIR__ . '/../../app/bootstrap.php';
require_post(); $u = require_plus();
if (setting('ai_on', '1') !== '1' || Settings::global('platform_ai_on', '1') !== '1') Response::error('PrepDeck AI is switched off right now.', 503);
$age = age_from_dob($u['date_of_birth'] ?? null);
if ($age !== null && $age < 18 && !is_owner($u)) Response::error('PrepDeck AI is for students aged 18 and over.', 403);
$cap = (int)setting('ai_cap', 50);
if ((int)val('SELECT COUNT(*) FROM ai_usage WHERE user_id=? AND created_at>=CURDATE()', [$u['id']]) >= $cap) Response::error("You have used today's $cap AI messages. Try again tomorrow.", 429);
$b = Response::body(); $msgs = [];
foreach (array_slice((array)($b['messages'] ?? []), -12) as $m) {
    $role = ($m['role'] ?? '') === 'assistant' ? 'assistant' : 'user'; $c = mb_substr(trim((string)($m['content'] ?? '')), 0, 3000);
    if ($c !== '') $msgs[] = ['role' => $role, 'content' => $c];
}
while ($msgs && $msgs[0]['role'] !== 'user') array_shift($msgs);
if (!$msgs || end($msgs)['role'] !== 'user') Response::error('Type a question first.');
$course = (int)($b['course_id'] ?? 0) ? row('SELECT code,title FROM courses WHERE id=? AND ' . in_school(), [(int)$b['course_id']]) : null;
$mode = Validator::in($b['mode'] ?? 'chat', ['chat', 'quiz', 'cards', 'guide']) ?? 'chat';
$sys = "You are PrepDeck AI, a friendly study partner for university students at " . school_name() . ". "
    . "Explain in simple English, be accurate, and say when you are not sure. Do not write essays or assignments for the student to submit; teach and quiz instead. "
    . setting('ai_rules', '') . ($course ? " The student is studying {$course['code']} {$course['title']}." : '')
    . ($mode === 'quiz' ? ' Ask ONE exam-style question at a time, wait for the answer, then mark it and explain.' : '')
    . ($mode === 'cards' ? ' Reply with flashcards as lines "Q: ... | A: ...".' : '')
    . ($mode === 'guide' ? ' Reply with a short study plan with headings.' : '');
$ai = new AiClient(setting('ai_model', env('AI_MODEL', 'claude-sonnet-5-5')));
try { $text = $ai->chat($sys, $msgs, 1500); } catch (RuntimeException $e) { Response::error($e->getMessage(), 503); }
q('INSERT INTO ai_usage(school_id,user_id,kind,tokens) VALUES (?,?,?,?)', [sid() ?: null, $u['id'], $mode, $ai->in + $ai->out]);
Response::ok(['text' => $text, 'left' => max(0, $cap - (int)val('SELECT COUNT(*) FROM ai_usage WHERE user_id=? AND created_at>=CURDATE()', [$u['id']]))]);
