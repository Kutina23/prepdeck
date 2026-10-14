<?php
// Anyone signed in can upload notes.  Free: a few one-page PDFs.  Plus: unlimited uploads, any length.
require __DIR__ . '/../../app/bootstrap.php';
$u = require_login(true); $a = $_GET['a'] ?? 'list'; $plus = is_plus($u);
$freeMax = (int)Settings::get('free_upload_max', 3); $freePages = (int)Settings::get('free_upload_pages', 1);
$count = fn() => (function () use ($u) { $c = db()->prepare('SELECT COUNT(*) FROM materials WHERE user_id=? AND ' . in_school()); $c->execute([$u['id']]); return (int)$c->fetchColumn(); })();

if ($a === 'list') {
    $st = db()->prepare('SELECT id,title,size_bytes,ai_status,created_at FROM materials WHERE user_id=? AND ' . in_school() . ' ORDER BY id DESC'); $st->execute([$u['id']]);
    Response::ok(['materials' => $st->fetchAll(), 'plus' => $plus, 'used' => $count(),
        'limit' => $plus ? null : $freeMax, 'max_pages' => $plus ? null : $freePages]);
}
require_post();
if ($a === 'upload') {
    if (!$plus && $count() >= $freeMax) Response::error("Free accounts can upload up to $freeMax documents. Delete one or upgrade to Plus for unlimited uploads.", 402);
    $err = Validator::pdf($_FILES['file'] ?? []); if ($err) Response::error($err);
    if (!$plus) {                                            // page limit is checked on the server from the real file
        $pages = PdfText::pages($_FILES['file']['tmp_name']);
        if ($pages === null || $pages > $freePages) Response::error("Free accounts can upload $freePages-page documents. Upgrade to Plus to upload longer notes.", 402);
    }
    $title = Validator::str($_POST['title'] ?? '', 2, 160) ?? 'Lecture notes';
    $name = bin2hex(random_bytes(16)) . '.pdf';
    move_uploaded_file($_FILES['file']['tmp_name'], upload_dir('notes') . '/' . $name); $name = upload_ref($name);
    db()->prepare('INSERT INTO materials(school_id,user_id,title,file_path,size_bytes) VALUES (?,?,?,?,?)')->execute([sid(), $u['id'], $title, $name, $_FILES['file']['size']]);
    $mid = (int)db()->lastInsertId();
    Response::ok(['material_id' => $mid, 'job_id' => JobQueue::enqueue('material', $mid, (int)$u['id'], $name, 'lecture_notes')]);
}
if ($a === 'delete') {                                       // frees a slot for free accounts
    $id = (int)(Response::body()['id'] ?? 0);
    $st = db()->prepare('SELECT file_path FROM materials WHERE id=? AND user_id=? AND ' . in_school()); $st->execute([$id, $u['id']]); $m = $st->fetch();
    if (!$m) Response::error('Not found.', 404);
    foreach (['DELETE FROM exam_questions WHERE source_type="material" AND source_id=?', 'DELETE FROM decks WHERE material_id=?', 'DELETE FROM study_guides WHERE material_id=?', 'DELETE FROM materials WHERE id=?'] as $q) db()->prepare($q)->execute([$id]);
    @unlink(upload_path('notes', $m['file_path'])); Response::ok();
}
Response::error('Unknown action.', 404);
