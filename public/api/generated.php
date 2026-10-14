<?php
// Read generated questions/answers, and (staff) review them.
require __DIR__ . '/../../app/bootstrap.php';
$u = require_login(true); $a = $_GET['a'] ?? 'list'; $staff = Auth::can('approve');
/** Is this paper / material / recording in the current school? (exam_questions hang off their source, which carries the school) */
function source_here(string $type, int $id): bool { return in_my_school(JobQueue::table($type), $id); }
/** The source of one generated question, only when it is in the current school. */
function question_here(int $qid): bool { $q = row('SELECT source_type,source_id FROM exam_questions WHERE id=?', [$qid]); return $q && source_here($q['source_type'], (int)$q['source_id']); }
if ($a === 'list') {
    $type = Validator::in($_GET['type'] ?? 'paper', ['paper', 'material', 'recording']) ?? 'paper'; $sid = (int)($_GET['id'] ?? 0);
    if (!source_here($type, $sid)) Response::error('Not found.', 404);   // only what belongs to the school being viewed
    $staff = $staff && source_here($type, $sid);
    if ($type === 'material') {                                        // notes are private to their owner
        $o = db()->prepare('SELECT user_id FROM materials WHERE id=?'); $o->execute([$sid]);
        if ((int)$o->fetchColumn() !== (int)$u['id'] && !$staff) Response::error('Not found.', 404);
    } elseif ($type === 'recording') {                                 // owner always; others only once published
        $o = db()->prepare('SELECT owner_id,status FROM recordings WHERE id=?'); $o->execute([$sid]); $rc = $o->fetch();
        if (!$rc || (!$staff && (int)$rc['owner_id'] !== (int)$u['id'] && $rc['status'] !== 'published')) Response::error('Not found.', 404);
    }
    $own = $type === 'material' || ($type === 'recording' && (int)$rc['owner_id'] === (int)$u['id']);   // your own private study material needs no review
    $where = ($staff || $own) ? '' : " AND status='published'";                                  // everyone else never sees unreviewed output
    $st = db()->prepare("SELECT id,kind,number,question,answer,topic,marks,difficulty,confidence,status FROM exam_questions WHERE source_type=? AND source_id=? $where ORDER BY kind, id");
    $st->execute([$type, $sid]); $rows = $st->fetchAll();
    // Solved answers are a Plus feature. Free members get the questions; the answer text never leaves the server for them.
    $unlocked = $staff || is_plus($u);
    if (!$unlocked) foreach ($rows as &$r) { $r['answer'] = null; $r['locked'] = true; } unset($r);
    Response::ok(['questions' => $rows, 'ai_generated' => true, 'locked' => !$unlocked]);
}
if ($a === 'queue') {                                                  // staff review queue
    require_perm('approve', true);
    Response::ok(['jobs' => db()->query("SELECT j.id,j.source_type,j.source_id,j.detected_kind,j.status,j.message,j.tokens_in+j.tokens_out AS tokens,j.created_at,
        (SELECT COUNT(*) FROM exam_questions q WHERE q.source_type=j.source_type AND q.source_id=j.source_id AND q.status='draft') AS drafts,
        (SELECT COUNT(*) FROM exam_questions q WHERE q.source_type=j.source_type AND q.source_id=j.source_id AND q.confidence='low') AS low
        FROM processing_jobs j WHERE " . in_school('j') . " ORDER BY j.id DESC LIMIT 100")->fetchAll()]);
}
require_perm('approve', true); require_post(); $b = Response::body();
if (in_array($a, ['edit', 'status'], true) && !question_here((int)($b['id'] ?? 0))) Response::error('Not found.', 404);
if ($a === 'edit')   { db()->prepare('UPDATE exam_questions SET question=?, answer=?, confidence=\'high\', edited_by=? WHERE id=?')
    ->execute([(string)$b['question'], (string)$b['answer'], $u['id'], (int)$b['id']]); Response::ok(); }
if ($a === 'status') { $s = Validator::in($b['status'] ?? '', ['published', 'hidden', 'draft']); if (!$s) Response::error('Bad status.');
    db()->prepare('UPDATE exam_questions SET status=? WHERE id=?')->execute([$s, (int)$b['id']]); Response::ok(); }
if ($a === 'publish_all') {                                            // publish everything for one source; low-confidence stays for manual check
    $type = Validator::in($b['type'] ?? '', ['paper', 'material', 'recording']); $sid = (int)($b['id'] ?? 0); if (!$type || !source_here($type, $sid)) Response::error('Bad request.');
    $col = JobQueue::col($type);
    db()->prepare("UPDATE exam_questions SET status='published' WHERE source_type=? AND source_id=? AND status='draft' AND confidence<>'low'")->execute([$type, $sid]);
    db()->prepare("UPDATE decks SET status='active' WHERE $col=? AND ai_generated=1")->execute([$sid]);
    db()->prepare("UPDATE study_guides SET status='active' WHERE $col=? AND ai_generated=1")->execute([$sid]);
    if ($type === 'paper') db()->prepare("UPDATE papers SET has_answers=1 WHERE id=?")->execute([$sid]);
    Audit::log("published AI content for $type $sid", $type, $sid); Response::ok(); }
Response::error('Unknown action.', 404);
