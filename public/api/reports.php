<?php
require __DIR__ . '/../../app/bootstrap.php';
$u = require_login(true); require_post(); $b = Response::body();
$paper = (int)($b['paper_id'] ?? 0) ?: null; $deck = (int)($b['deck_id'] ?? 0) ?: null; $reason = Validator::str($b['reason'] ?? '', 5, 255);
if ((!$paper && !$deck) || !$reason) Response::error('Tell us what is wrong (at least a few words).');
if (($paper && !in_my_school('papers', $paper)) || ($deck && !in_my_school('decks', $deck))) Response::error('Not found.', 404);
q('INSERT INTO reports(school_id,paper_id,deck_id,reported_by,reason) VALUES (?,?,?,?,?)', [sid(), $paper, $deck, $u['id'], $reason]);
Response::ok();
