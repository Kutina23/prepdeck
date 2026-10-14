<?php
// Lecturer tools: upload a course outline PDF. PrepDeck AI lists its topics and (optionally) writes a lesson note for each. PrepDeck Plus only.
require __DIR__ . '/../../app/bootstrap.php';
$u = require_login(true); $a = $_GET['a'] ?? '';
if (!is_lecturer($u) && !Auth::can('admin')) Response::error('Course outlines are part of the lecturer dashboard.', 403);

if ($a === 'upload') {
    require_post();
    [$ok, $why] = Lessons::canGenerate($u); if (!$ok) Response::error($why, 402);
    $err = Validator::pdf($_FILES['file'] ?? []); if ($err) Response::error($err);
    $course = (int)($_POST['course_id'] ?? 0); $title = Validator::str($_POST['title'] ?? '', 3, 190);
    if (!$title || !$course || !in_my_school('courses', $course)) Response::error('Add a title and choose a course.');
    if (!Lessons::courseOk($course, staff_course_ids($u))) Response::error('You are not assigned to that course. Ask an admin to add it to your account.', 403);
    $pages = PdfText::pages($_FILES['file']['tmp_name']); $maxPages = (int)Settings::get('outline_max_pages', 30);
    if ($pages !== null && $pages > $maxPages) Response::error("Course outlines can be up to $maxPages pages. Upload just the outline (topics and weeks), not the full course pack.");
    $depth = Validator::in($_POST['depth'] ?? 'standard', array_keys(Lessons::DEPTH)) ?? 'standard';
    $instr = Validator::str($_POST['instructions'] ?? '', 0, 600) ?: null;
    $all = 0;
    if (!empty($_POST['write_all'])) {                              // write notes for every topic now, up to what is left of this month's quota
        $left = Lessons::left((int)$u['id']); $all = $left === null ? (int)Settings::get('outline_max_topics', 30) : $left;
    }
    $dir = upload_dir('outlines');
    $name = bin2hex(random_bytes(16)) . '.pdf';
    if (!move_uploaded_file($_FILES['file']['tmp_name'], "$dir/$name")) Response::error('The file could not be saved. Please try again.');
    $name = upload_ref($name);
    q('INSERT INTO course_outlines(school_id,user_id,course_id,title,file_path,size_bytes,page_count,depth,instructions) VALUES (?,?,?,?,?,?,?,?,?)',
        [sid(), $u['id'], $course, $title, $name, (int)$_FILES['file']['size'], $pages, $depth, $instr]);
    $oid = (int)db()->lastInsertId(); Audit::log("uploaded course outline $title", 'outline', $oid);
    $job = Lessons::enqueue(row('SELECT * FROM course_outlines WHERE id=?', [$oid]), [], (int)$u['id'], $all);
    Response::ok(['outline_id' => $oid, 'job_id' => $job]);
}

if ($a === 'download') {                                            // the lecturer's own outline file (admins: any)
    $id = (int)($_GET['id'] ?? 0); $o = row('SELECT * FROM course_outlines WHERE id=?', [$id]);
    if (!$o || ((int)$o['user_id'] !== (int)$u['id'] && !(Auth::can('admin') && (int)$o['school_id'] === sid()))) { http_response_code(404); exit('Not found'); }
    $f = upload_path('outlines', $o['file_path']); if (!is_file($f)) { http_response_code(404); exit('The file is missing on the server.'); }
    header('Content-Type: application/pdf'); header('Content-Disposition: inline; filename="' . preg_replace('/[^\w.-]+/', '_', $o['title']) . '.pdf"');
    header('Content-Length: ' . filesize($f)); readfile($f); exit;
}
Response::error('Unknown action.', 404);
