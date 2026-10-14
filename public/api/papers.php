<?php
// Past question files. Students stream PDFs through here (never a public URL). Staff upload.
require __DIR__ . '/../../app/bootstrap.php';
$a = $_GET['a'] ?? 'download';

if ($a === 'download') {
    $u = require_login(true); $id = (int)($_GET['id'] ?? 0);
    $p = row("SELECT id,file_path,title,status,course_id,uploaded_by FROM papers WHERE id=? AND school_id=?", [$id, sid()]);   // another school's paper is "not found"
    $sc = staff_course_ids($u); $mayView = Auth::can('approve') || (int)($p['uploaded_by'] ?? 0) === (int)$u['id'] || (Auth::can('review') && $p && ($sc === null || in_array((int)$p['course_id'], $sc, true)));
    if (!$p || ($p['status'] !== 'published' && !$mayView)) { http_response_code(404); exit('Not found'); }
    $file = upload_path('papers', $p['file_path']);
    if (!is_file($file)) { http_response_code(404); exit('The file is missing on the server.'); }
    q('UPDATE papers SET views=views+1 WHERE id=?', [$id]); q('INSERT INTO paper_views(school_id,user_id,paper_id) VALUES (?,?,?)', [sid(), $u['id'], $id]);
    header('Content-Type: application/pdf');
    header('Content-Disposition: ' . (isset($_GET['dl']) ? 'attachment' : 'inline') . '; filename="' . preg_replace('/[^\w.-]+/', '_', $p['title']) . '.pdf"');
    header('Content-Length: ' . filesize($file)); readfile($file); exit;
}
if ($a === 'upload') {
    $u = require_perm('upload', true); require_post();
    $err = Validator::pdf($_FILES['file'] ?? []); if ($err) Response::error($err);
    $title = Validator::str($_POST['title'] ?? '', 3, 190); $course = (int)($_POST['course_id'] ?? 0);
    if (!$title || !$course || !in_my_school('courses', $course)) Response::error('Title and course are required.');
    $mine = staff_course_ids($u);                                    // reps and lecturers may only upload to the courses an admin gave them
    if ($mine !== null && !in_array($course, $mine, true)) Response::error('You are not assigned to that course. Ask an admin to add it to your account.', 403);
    $trusted = Auth::can('approve') || ((int)$u['role_id'] === 3 && setting('lecturer_autopublish', '1') === '1') || ((int)$u['role_id'] === 2 && setting('rep_autopublish', '0') === '1');
    $status = setting('upload_review', '1') === '1' && !$trusted ? 'pending' : 'published';
    $kind = Validator::in($_POST['kind'] ?? 'auto', ['auto', 'past_question', 'lecture_notes']) ?? 'auto';
    $name = bin2hex(random_bytes(16)) . '.pdf';
    move_uploaded_file($_FILES['file']['tmp_name'], upload_dir('papers') . '/' . $name); $name = upload_ref($name);
    q('INSERT INTO papers(school_id,course_id,uploaded_by,title,doc_kind,exam_year,semester,file_path,file_size,status) VALUES (?,?,?,?,?,?,?,?,?,?)',
        [sid(), $course, $u['id'], $title, $kind, (int)($_POST['exam_year'] ?? date('Y')), (int)($_POST['semester'] ?? 1), $name, $_FILES['file']['size'], $status]);
    $pid = (int)db()->lastInsertId(); Audit::log("uploaded paper $title", 'paper', $pid);
    if ($status === 'published') q('UPDATE courses SET paper_count=paper_count+1 WHERE id=?', [$course]);
    Response::ok(['status' => $status, 'paper_id' => $pid, 'job_id' => JobQueue::enqueue('paper', $pid, (int)$u['id'], $name, $kind)]);
}
Response::error('Unknown action.', 404);
