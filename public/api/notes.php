<?php
// Notes. Free: the number in free_notes (default 1). Plus: unlimited, tagged to courses.
require __DIR__ . '/../../app/bootstrap.php';
$u = require_login(true); $a = $_GET['a'] ?? 'list'; $plus = is_plus($u); $free = (int)setting('free_notes', '1');
if ($a === 'list') Response::ok(['notes' => rows('SELECT n.id,n.title,n.body,n.course_id,c.code course,n.updated_at FROM notes n LEFT JOIN courses c ON c.id=n.course_id WHERE n.user_id=? AND ' . in_school('n') . ' ORDER BY n.updated_at DESC', [$u['id']]), 'plus' => $plus, 'limit' => $plus ? null : $free]);
require_post(); $b = Response::body();
if ($a === 'save') {
    $id = (int)($b['id'] ?? 0); $title = Validator::str($b['title'] ?? '', 1, 160) ?? 'Untitled'; $body = mb_substr((string)($b['body'] ?? ''), 0, 100000);
    $course = $plus ? ((int)($b['course_id'] ?? 0) ?: null) : null; if ($course && !in_my_school('courses', $course)) $course = null;
    if ($id) {
        if (!val('SELECT COUNT(*) FROM notes WHERE id=? AND user_id=? AND ' . in_school(), [$id, $u['id']])) Response::error('Note not found.', 404);
        q('UPDATE notes SET title=?, body=?, course_id=? WHERE id=?', [$title, $body, $course, $id]); Response::ok(['id' => $id]);
    }
    if (!$plus && (int)val('SELECT COUNT(*) FROM notes WHERE user_id=? AND ' . in_school(), [$u['id']]) >= $free) Response::error("Free accounts keep $free note. Upgrade to Plus for unlimited notes.", 402);
    q('INSERT INTO notes(school_id,user_id,title,body,course_id) VALUES (?,?,?,?,?)', [sid(), $u['id'], $title, $body, $course]); Response::ok(['id' => (int)db()->lastInsertId()]);
}
if ($a === 'delete') { q('DELETE FROM notes WHERE id=? AND user_id=? AND ' . in_school(), [(int)($b['id'] ?? 0), $u['id']]); Response::ok(); }
Response::error('Unknown action.', 404);
