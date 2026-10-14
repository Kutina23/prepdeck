<?php
// Game data + scores. Match is free; other games follow the Plus flag set in Admin > Study content.
require __DIR__ . '/../../app/bootstrap.php';
$u = require_login(true); $a = $_GET['a'] ?? 'pairs'; $plus = is_plus($u);
if ($a === 'pairs') {
    $deck = row('SELECT id,school_id,owner_id,title,visibility,status,contains_answers FROM decks WHERE id=?', [(int)($_GET['deck'] ?? 0)]);
    $g = row('SELECT * FROM games WHERE code=? AND is_enabled=1', [$_GET['game'] ?? 'match']);
    if (!$deck || !$g) Response::error('Game not found.', 404);
    if ((int)$deck['school_id'] !== sid() || ((int)$deck['owner_id'] !== (int)$u['id'] && !Auth::can('decks') && !($deck['visibility'] === 'shared' && $deck['status'] === 'active'))) Response::error('Deck not found.', 404);
    if ((int)$g['is_plus'] && !$plus) Response::error('This game is part of PrepDeck Plus.', 402);
    if ((int)$deck['contains_answers'] && !$plus) Response::error('Games built from solved answers are part of PrepDeck Plus.', 402);
    $pairs = rows('SELECT front,back FROM cards WHERE deck_id=? ORDER BY RAND() LIMIT 40', [$deck['id']]);
    Response::ok(['deck' => $deck['title'], 'pairs' => $pairs, 'game' => $g['code']]);
}
require_post(); $b = Response::body();
if ($a === 'score') {
    $code = Validator::in($b['game'] ?? '', array_column(rows('SELECT code FROM games'), 'code')); if (!$code) Response::error('Bad game.');
    q('INSERT INTO game_scores(school_id,user_id,game_code,deck_id,score,seconds) VALUES (?,?,?,?,?,?)', [sid(), $u['id'], $code, ((int)($b['deck'] ?? 0)) ?: null, max(0, (int)($b['score'] ?? 0)), max(0, (int)($b['seconds'] ?? 0))]);
    Response::ok();
}
Response::error('Unknown action.', 404);
