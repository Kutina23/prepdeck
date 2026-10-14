<?php
// Flashcard decks. Browsing/studying shared decks is free. Building your own and saving progress is Plus.
require __DIR__ . '/../../app/bootstrap.php';
$u = require_login(true); $a = $_GET['a'] ?? 'cards'; $plus = is_plus($u); $staff = Auth::can('decks');

function deck_visible(array $d, array $u, bool $staff): bool {
    if ((int)$d['school_id'] !== sid()) return false;   // every deck, even your own, belongs to one school
    return (int)$d['owner_id'] === (int)$u['id'] || $staff || ($d['visibility'] === 'shared' && $d['status'] === 'active');
}
if ($a === 'cards') {
    $d = row('SELECT id,school_id,owner_id,title,visibility,status,contains_answers FROM decks WHERE id=?', [(int)($_GET['id'] ?? 0)]);
    if (!$d || !deck_visible($d, $u, $staff)) Response::error('Deck not found.', 404);
    $cards = rows('SELECT id,front,back FROM cards WHERE deck_id=? ORDER BY position,id', [$d['id']]);
    $locked = (int)$d['contains_answers'] === 1 && !$plus;            // solved answers are Plus-only: the answer text is not sent
    if ($locked) foreach ($cards as &$k) { $k['back'] = null; $k['locked'] = true; } unset($k);
    $known = $plus ? array_map('intval', array_column(rows('SELECT card_id FROM card_progress WHERE user_id=? AND known=1 AND card_id IN (SELECT id FROM cards WHERE deck_id=?)', [$u['id'], $d['id']]), 'card_id')) : [];
    Response::ok(['deck' => $d['title'], 'cards' => $cards, 'locked' => $locked, 'known' => $known, 'can_track' => $plus]);
}
require_post(); $b = Response::body();
if ($a === 'progress') {
    if (!$plus) Response::ok(['saved' => false]);                      // Free members can study; Plus saves what they know
    $cid = (int)($b['card_id'] ?? 0); $known = !empty($b['known']) ? 1 : 0;
    if (!val('SELECT COUNT(*) FROM cards c JOIN decks d ON d.id=c.deck_id WHERE c.id=? AND d.school_id=? AND (d.owner_id=? OR (d.visibility="shared" AND d.status="active"))', [$cid, sid(), $u['id']])) Response::error('Card not found.', 404);
    q('INSERT INTO card_progress(user_id,card_id,known,due_at) VALUES (?,?,?,DATE_ADD(NOW(),INTERVAL ? DAY)) ON DUPLICATE KEY UPDATE known=VALUES(known),due_at=VALUES(due_at)', [$u['id'], $cid, $known, $known ? 3 : 1]);
    Response::ok(['saved' => true]);
}
if ($a === 'create') {
    if (!$plus) Response::error('Building your own decks is part of PrepDeck Plus.', 402);
    $title = Validator::str($b['title'] ?? '', 2, 160); $cards = array_values(array_filter((array)($b['cards'] ?? []), fn($c) => is_array($c) && trim((string)($c['front'] ?? '')) !== '' && trim((string)($c['back'] ?? '')) !== ''));
    if (!$title || !$cards) Response::error('Add a title and at least one card.');
    $cards = array_slice($cards, 0, 300); $course = (int)($b['course_id'] ?? 0) ?: null; if ($course && !in_my_school('courses', $course)) $course = null;
    q("INSERT INTO decks(school_id,owner_id,course_id,title,source,visibility,status,card_count) VALUES (?,?,?,?,'manual','private','active',?)", [sid(), $u['id'], $course, $title, count($cards)]);
    $id = (int)db()->lastInsertId(); $ins = db()->prepare('INSERT INTO cards(deck_id,front,back,position) VALUES (?,?,?,?)');
    foreach ($cards as $i => $c) $ins->execute([$id, mb_substr(trim($c['front']), 0, 2000), mb_substr(trim($c['back']), 0, 2000), $i]);
    Response::ok(['id' => $id]);
}
if ($a === 'delete') {
    $id = (int)($b['id'] ?? 0); $d = row('SELECT owner_id,school_id FROM decks WHERE id=?', [$id]);
    if (!$d || (int)$d['school_id'] !== sid() || ((int)$d['owner_id'] !== (int)$u['id'] && !$staff)) Response::error('Deck not found.', 404);
    q('DELETE FROM decks WHERE id=?', [$id]); Response::ok();
}
Response::error('Unknown action.', 404);
