<?php
// Lecture recordings.  Student side: Plus members record/upload (private to them).  Staff with 'upload': shared with the course once published.
// Admin side (perm 'approve'): track every audio file, transcript and what was built from it.
require __DIR__ . '/../../app/bootstrap.php';
$u = require_login(true); $a = $_GET['a'] ?? 'mine'; $staff = Auth::can('approve'); $plus = is_plus($u);
$dir = upload_dir('audio') . '/';

/** A recording of the current school (staff tools never reach another school's audio). */
function rec_row(int $id): ?array { $s = db()->prepare('SELECT * FROM recordings WHERE id=? AND school_id=?'); $s->execute([$id, sid()]); return $s->fetch() ?: null; }
function rec_summary_sql(): string {
    return "SELECT r.id,r.title,r.format,r.size_bytes,r.seconds,r.language,r.word_count,r.stt_status,r.stt_confidence,r.ai_status,r.status,r.visibility,r.error,
        r.audio_path IS NOT NULL AND r.audio_deleted_at IS NULL AS has_audio, r.created_at, u.full_name AS owner, c.code AS course,
        (SELECT COUNT(*) FROM cards k JOIN decks d ON d.id=k.deck_id WHERE d.recording_id=r.id) AS cards,
        (SELECT COUNT(*) FROM study_guides g WHERE g.recording_id=r.id) AS guides,
        (SELECT COUNT(*) FROM exam_questions q WHERE q.source_type='recording' AND q.source_id=r.id) AS questions,
        (SELECT COUNT(*) FROM exam_questions q WHERE q.source_type='recording' AND q.source_id=r.id AND q.status='draft') AS drafts
        FROM recordings r JOIN users u ON u.id=r.owner_id LEFT JOIN courses c ON c.id=r.course_id";
}

if ($a === 'mine') {                                              // student's own recordings
    $st = db()->prepare(rec_summary_sql() . ' WHERE r.owner_id=? AND ' . in_school('r') . ' ORDER BY r.id DESC'); $st->execute([$u['id']]); Response::ok(['recordings' => $st->fetchAll()]);
}
if ($a === 'upload') {
    require_post();
    $shared = Auth::can('upload');                                // lecturers / class reps; others need Plus
    if (!$shared && !$plus) Response::error('Lecture recording is part of PrepDeck Plus.', 402);
    $f = $_FILES['file'] ?? []; if (($f['error'] ?? 1) !== UPLOAD_ERR_OK) Response::error('Upload failed.');
    if ($f['size'] > (int)Settings::get('rec_max_mb', 200) * 1048576) Response::error('That audio file is too large.');
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
    $ext = ['audio/mpeg' => 'mp3', 'audio/mp4' => 'm4a', 'audio/x-m4a' => 'm4a', 'video/mp4' => 'm4a', 'audio/wav' => 'wav', 'audio/x-wav' => 'wav', 'audio/webm' => 'webm', 'video/webm' => 'webm', 'audio/ogg' => 'ogg'][$mime] ?? null;
    if (!$ext) Response::error('Upload an MP3, M4A, WAV, WebM or OGG audio file.');
    $name = bin2hex(random_bytes(16)) . '.' . $ext; move_uploaded_file($f['tmp_name'], $dir . $name);
    $secs = Transcriber::seconds($dir . $name);
    $cap = (int)Settings::get($shared || $plus ? 'rec_max_min_plus' : 'rec_max_min_free', 180);
    if ($secs && $secs > $cap * 60) { unlink($dir . $name); Response::error("Recordings can be up to $cap minutes."); }
    $course = (int)($_POST['course_id'] ?? 0); if ($course && !in_my_school('courses', $course)) $course = 0;
    db()->prepare("INSERT INTO recordings(school_id,owner_id,course_id,title,audio_path,original_name,format,size_bytes,seconds,visibility,stt_status) VALUES (?,?,?,?,?,?,?,?,?,?, 'queued')")
        ->execute([sid(), $u['id'], $course ?: null, Validator::str($_POST['title'] ?? '', 2, 160) ?? 'Lecture recording', upload_ref($name), mb_substr($f['name'], 0, 190), strtoupper($ext), $f['size'], $secs, $shared ? 'course' : 'private']);
    $id = (int)db()->lastInsertId(); Audit::log('uploaded recording', 'recording', $id);
    Response::ok(['recording_id' => $id, 'job_id' => JobQueue::enqueue('recording', $id, (int)$u['id'], upload_ref($name), 'lecture_notes')]);   // watch with api/jobs.php?a=stream
}

// ---------------- admin tracking ----------------
require_perm('approve', true);
if ($a === 'list') {
    $q = '%' . ($_GET['q'] ?? '') . '%'; $f = $_GET['f'] ?? 'all';
    $w = ['all' => '1', 'attention' => "(r.stt_status='failed' OR r.ai_status='failed' OR r.stt_confidence<85)", 'processing' => "(r.stt_status IN ('queued','running') OR r.ai_status IN ('queued','running'))", 'ready' => "r.ai_status='done'"][$f] ?? '1';
    $st = db()->prepare(rec_summary_sql() . " WHERE $w AND " . in_school('r') . " AND (r.title LIKE ? OR u.full_name LIKE ? OR c.code LIKE ?) ORDER BY r.id DESC LIMIT 200"); $st->execute([$q, $q, $q]);
    $kp = db()->query("SELECT COUNT(*) n, COALESCE(SUM(seconds),0)/3600 hours, COALESCE(SUM(CASE WHEN audio_deleted_at IS NULL THEN size_bytes END),0)/1048576 mb,
        SUM(stt_status='failed' OR ai_status='failed') attention FROM recordings WHERE " . in_school())->fetch();
    Response::ok(['recordings' => $st->fetchAll(), 'totals' => $kp]);
}
if ($a === 'get') {                                               // one recording: transcript + everything built from it
    $id = (int)($_GET['id'] ?? 0); $r = rec_row($id); if (!$r) Response::error('Not found.', 404); unset($r['audio_path']);
    $d = db()->prepare('SELECT d.id,d.title,d.status,(SELECT COUNT(*) FROM cards WHERE deck_id=d.id) cards FROM decks d WHERE d.recording_id=?'); $d->execute([$id]);
    $g = db()->prepare('SELECT id,title,status FROM study_guides WHERE recording_id=?'); $g->execute([$id]);
    $q = db()->prepare("SELECT id,kind,question,topic,confidence,status FROM exam_questions WHERE source_type='recording' AND source_id=? ORDER BY id"); $q->execute([$id]);
    $j = db()->prepare("SELECT id,status,stage,progress,message,tokens_in+tokens_out tokens FROM processing_jobs WHERE source_type='recording' AND source_id=? ORDER BY id DESC LIMIT 5"); $j->execute([$id]);
    Response::ok(['recording' => $r, 'decks' => $d->fetchAll(), 'guides' => $g->fetchAll(), 'questions' => $q->fetchAll(), 'jobs' => $j->fetchAll()]);
}
if ($a === 'audio') {                                             // stream the file to staff only; never a public URL
    $r = rec_row((int)($_GET['id'] ?? 0)); if (!$r || !$r['audio_path'] || $r['audio_deleted_at']) Response::error('Audio is not available.', 404);
    Audit::log('played recording audio', 'recording', (int)$r['id']);
    $f = upload_path('audio', $r['audio_path']); if (!is_file($f)) Response::error('Audio is not available.', 404);
    header('Content-Type: audio/' . strtolower($r['format'] === 'M4A' ? 'mp4' : $r['format'])); header('Content-Length: ' . filesize($f));
    readfile($f); exit;
}
require_post(); $b = Response::body(); $id = (int)($b['id'] ?? 0); $r = rec_row($id); if (!$r) Response::error('Not found.', 404);
switch ($a) {
    case 'save_transcript':                                       // fix mistakes, then rebuild so study material uses the fixed text
        $t = Validator::str($b['transcript'] ?? '', 50, 500000); if (!$t) Response::error('The transcript is too short.');
        db()->prepare("UPDATE recordings SET transcript=?, word_count=?, stt_status='ready', transcript_edited_by=? WHERE id=?")->execute([$t, str_word_count($t), $u['id'], $id]);
        Audit::log('edited transcript', 'recording', $id); Response::ok();
    case 'rebuild':                                               // regenerate guide/flashcards/questions from the current transcript
        Audit::log('rebuilt recording material', 'recording', $id);
        Response::ok(['job_id' => JobQueue::enqueue('recording', $id, (int)$r['owner_id'], (string)$r['audio_path'], 'lecture_notes')]);
    case 'retranscribe':                                          // run speech-to-text again (audio must still exist)
        if (!$r['audio_path'] || $r['audio_deleted_at']) Response::error('The audio was deleted, so it cannot be transcribed again.');
        db()->prepare("UPDATE recordings SET transcript=NULL, stt_status='queued', word_count=0 WHERE id=?")->execute([$id]);
        Audit::log('re-transcribed recording', 'recording', $id);
        Response::ok(['job_id' => JobQueue::enqueue('recording', $id, (int)$r['owner_id'], (string)$r['audio_path'], 'lecture_notes')]);
    case 'publish':                                               // one switch: recording + its flashcards + guide + questions
        $on = !empty($b['on']); $s = $on ? 'published' : 'hidden';
        db()->prepare('UPDATE recordings SET status=? WHERE id=?')->execute([$s, $id]);
        db()->prepare("UPDATE decks SET status=? WHERE recording_id=? AND ai_generated=1")->execute([$on ? 'active' : 'hidden', $id]);
        db()->prepare("UPDATE study_guides SET status=? WHERE recording_id=? AND ai_generated=1")->execute([$on ? 'active' : 'hidden', $id]);
        db()->prepare("UPDATE exam_questions SET status=? WHERE source_type='recording' AND source_id=? AND (?=0 OR confidence<>'low')")->execute([$on ? 'published' : 'hidden', $id, $on ? 1 : 0]);
        Audit::log(($on ? 'published' : 'hid') . ' recording and its study material', 'recording', $id); Response::ok();
    case 'delete_audio':                                          // free disk space, keep transcript + study material
        if ($r['audio_path']) @unlink(upload_path('audio', $r['audio_path']));
        db()->prepare('UPDATE recordings SET audio_deleted_at=NOW() WHERE id=?')->execute([$id]); Audit::log('deleted recording audio', 'recording', $id); Response::ok();
    case 'delete':                                                // everything: audio, transcript, decks, guides, questions
        if ($r['audio_path']) @unlink(upload_path('audio', $r['audio_path']));
        foreach (['DELETE c FROM cards c JOIN decks d ON d.id=c.deck_id WHERE d.recording_id=?', 'DELETE FROM decks WHERE recording_id=?', 'DELETE FROM study_guides WHERE recording_id=?',
                  "DELETE FROM exam_questions WHERE source_type='recording' AND source_id=?", "DELETE FROM processing_jobs WHERE source_type='recording' AND source_id=?", 'DELETE FROM recordings WHERE id=?'] as $q)
            db()->prepare($q)->execute([$id]);
        Audit::log('deleted recording and everything built from it', 'recording', $id); Response::ok();
}
Response::error('Unknown action.', 404);
