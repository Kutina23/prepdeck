<?php
// Smart review (spaced repetition) and study-hub actions. Saving review progress is part of Plus, like deck progress.
require __DIR__ . '/../../app/bootstrap.php';
$u = require_login(true); $uid = (int)$u['id']; $plus = is_plus($u); $a = $_GET['a'] ?? '';
if ($a === 'queue') {
    if (!$plus) Response::error('Smart review is part of PrepDeck Plus.', 402);
    $deck = (int)($_GET['deck'] ?? 0);
    if ($deck && !val("SELECT COUNT(*) FROM decks WHERE id=? AND status='active' AND " . in_school() . " AND (owner_id=? OR visibility='shared')", [$deck, $uid])) Response::error('Deck not found.', 404);
    Response::ok(['cards' => Study::queue($uid, $deck), 'done_today' => Study::reviewedToday($uid), 'goal' => (int)($u['daily_card_goal'] ?? 20)]);
}
require_post(); $b = Response::body();
if ($a === 'rate') {
    if (!$plus) Response::error('Smart review is part of PrepDeck Plus.', 402);
    if (Throttle::over("review:$uid", 600, 10)) Response::error('Slow down a little and try again.', 429);
    $n = Study::rate($uid, (int)($b['card_id'] ?? 0), (int)($b['rating'] ?? 0));
    if (!$n) Response::error('Card not found.', 404);
    $labels = []; foreach (Study::RATINGS as $r => $_) $labels[$r] = Study::label(Study::schedule($n, $r)['minutes']);   // for a card shown again this session
    Response::ok(['next' => Study::label($n['minutes']), 'again' => $n['minutes'] < 60, 'labels' => $labels, 'done_today' => Study::reviewedToday($uid)]);
}
if ($a === 'result') {   // a finished Test (or Learn session): kept for progress and for the lecturer's set insights
    $d = Study::deck($uid, (int)($b['deck_id'] ?? 0)); if (!$d) Response::error('Deck not found.', 404);
    $mode = ($b['mode'] ?? '') === 'learn' ? 'learn' : 'test';
    $total = max(1, min(200, (int)($b['total'] ?? 0))); $correct = max(0, min($total, (int)($b['correct'] ?? 0)));
    if (Throttle::over("deck-result:$uid", 60, 10)) Response::error('Slow down a little and try again.', 429);
    q('INSERT INTO deck_test_attempts(school_id,user_id,deck_id,mode,total,correct,seconds) VALUES (?,?,?,?,?,?,?)', [sid() ?: null, $uid, $d['id'], $mode, $total, $correct, max(0, min(86400, (int)($b['seconds'] ?? 0)))]);
    $best = (int)val("SELECT MAX(ROUND(100*correct/NULLIF(total,0))) FROM deck_test_attempts WHERE user_id=? AND deck_id=? AND mode='test'", [$uid, $d['id']]);
    Response::ok(['saved' => true, 'best' => $best]);
}
Response::error('Unknown action.', 404);
