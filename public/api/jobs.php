<?php
// Live progress. stream = Server-Sent Events (push), status = one-shot (fallback), run = process the queue in this request
// (for hosts that cannot start background workers), retry = owner/admin re-run.
require __DIR__ . '/../../app/bootstrap.php';
$u = require_login(true); $a = $_GET['a'] ?? 'status'; $id = (int)($_GET['id'] ?? 0);
$st = db()->prepare('SELECT * FROM processing_jobs WHERE id=?'); $st->execute([$id]); $job = $st->fetch();
if (!$job || ($job['owner_id'] != $u['id'] && !(Auth::can('approve') && (int)$job['school_id'] === sid()))) Response::error('Not found.', 404);

$own = (int)$job['owner_id'] === (int)$u['id'];
$type = $job['source_type']; $sid = (int)$job['source_id'];
$title = (string)val('SELECT title FROM ' . JobQueue::table($type) . ' WHERE id=?', [$sid]);
$link = ['outline' => ($own ? 'lecturer/outlines.php?id=' : 'admin/teaching.php?tab=outlines&id='), 'material' => 'dashboard/materials.php?id=', 'paper' => ($own || !Auth::can('approve') ? 'dashboard/papers.php?id=' : 'admin/papers.php?id='), 'recording' => ($own ? 'dashboard/recordings.php?id=' : 'admin/recordings.php?id=')][$type] . $sid;
$review = Auth::can('approve') && $type !== 'outline' ? 'admin/processing.php?type=' . $type . '&sid=' . $sid : null;
$view = function (array $j) use ($title, $link, $review, $type) {
    return ['id' => (int)$j['id'], 'status' => $j['status'], 'stage' => $j['stage'], 'progress' => (int)$j['progress'], 'message' => $j['message'],
        'kind' => $type === 'outline' ? 'outline' : ($j['detected_kind'] ?: ($type === 'recording' ? 'lecture_notes' : ($j['requested_kind'] !== 'auto' ? $j['requested_kind'] : null))),
        'source' => $type, 'title' => $title, 'link' => url($link), 'review' => $review ? url($review) : null,
        'events' => array_slice(json_decode((string)($j['events'] ?? ''), true) ?: [], -40), 'stats' => json_decode((string)($j['stats'] ?? ''), true) ?: new stdClass(),
        'started' => $j['started_at'], 'now' => gmdate('Y-m-d H:i:s')];
};

if ($a === 'status') Response::ok(['job' => $view($job)]);

if ($a === 'stream') {
    session_write_close();                       // don't hold the session lock while streaming
    while (ob_get_level()) ob_end_flush();
    header('Content-Type: text/event-stream'); header('Cache-Control: no-cache, no-transform'); header('X-Accel-Buffering: no');
    set_time_limit(0); $last = '';
    echo ":" . str_repeat(' ', 2048) . "\n\n"; flush();     // padding so proxies and mod_deflate start sending straight away
    for ($i = 0; $i < 900 && !connection_aborted(); $i++) {  // up to ~15 minutes; the JS reconnects or polls after that
        $st->execute([$id]); $j = $st->fetch(); $d = json_encode($view($j), JSON_UNESCAPED_UNICODE);
        if ($d !== $last) { echo "data: $d\n\n"; $last = $d; } elseif ($i % 15 === 0) echo ": keep-alive\n\n";
        flush();
        if (in_array($j['status'], ['done', 'failed'], true)) break;
        sleep(1);
    }
    exit;
}
if ($a === 'run') {                               // fallback worker: the page calls this when a job is still queued after a few seconds
    require_post(); session_write_close(); ignore_user_abort(true); set_time_limit(0);
    if (!in_array($job['status'], ['queued'], true)) Response::ok(['ran' => 0]);
    Response::ok(['ran' => JobQueue::runQueue(true)]);
}
if ($a === 'retry') {
    require_post();
    if ($job['status'] !== 'failed' && !Auth::can('approve')) Response::error('This job is not failed.');
    db()->prepare("UPDATE processing_jobs SET status='queued', stage='queued', progress=0, message=NULL, error=NULL, attempts=0 WHERE id=?")->execute([$id]);
    JobQueue::event($id, 'queued', 'Trying again');
    JobQueue::setSourceStatus($type, $sid, 'queued'); JobQueue::kick(); Response::ok(['job_id' => $id]);
}
Response::error('Unknown action.', 404);
