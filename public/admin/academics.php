<?php
require __DIR__ . '/../../app/bootstrap.php';
$u = require_perm('admin'); $adminArea = true; $active = 'academics'; $title = 'Departments & exams'; $scripts = ['forms.js'];
if (is_post()) {
    form_guard(); $a = $_POST['a'] ?? ''; $id = (int)($_POST['id'] ?? 0); $S = sid();
    // every id and every referenced department / course / term must belong to this school
    $own = ['dept' => 'departments', 'course' => 'courses', 'term' => 'academic_terms', 'exam' => 'exam_timetable'][explode('_', $a)[0]] ?? null;
    $bad = ($id && $own && !in_my_school($own, $id)) || (!empty($_POST['department_id']) && !in_my_school('departments', (int)$_POST['department_id']))
        || (!empty($_POST['course_id']) && !in_my_school('courses', (int)$_POST['course_id'])) || (!empty($_POST['term_id']) && !in_my_school('academic_terms', (int)$_POST['term_id']));
    if ($bad) { flash('bad', 'Not found in this school.'); redirect_self_or_ajax(); }
    try {
        if ($a === 'dept_add') { $n = Validator::str($_POST['name'] ?? '', 2, 120); if ($n) { q('INSERT INTO departments(school_id,name,is_active) VALUES (?,?,1)', [$S, $n]); Audit::log("added department $n", 'department', (int)db()->lastInsertId()); flash('ok', 'Department added.'); } else flash('bad', 'Enter a name.'); }
        elseif ($a === 'dept_save') { $n = Validator::str($_POST['name'] ?? '', 2, 120); if ($n) { q('UPDATE departments SET name=?, is_active=? WHERE id=?', [$n, isset($_POST['is_active']) ? 1 : 0, $id]); flash('ok', 'Saved.'); } }
        elseif ($a === 'dept_delete') { if (val('SELECT COUNT(*) FROM courses WHERE department_id=?', [$id]) || val('SELECT COUNT(*) FROM users WHERE department_id=?', [$id])) flash('bad', 'This department has courses or students. Deactivate it instead.'); else { q('DELETE FROM departments WHERE id=?', [$id]); flash('ok', 'Department deleted.'); } }
        elseif ($a === 'course_add') { $d = (int)($_POST['department_id'] ?? 0); $c = strtoupper(Validator::str($_POST['code'] ?? '', 2, 20) ?? ''); $t = Validator::str($_POST['title'] ?? '', 2, 160); if ($d && $c && $t) { q('INSERT INTO courses(school_id,department_id,code,title,level) VALUES (?,?,?,?,?)', [$S, $d, $c, $t, (int)($_POST['level'] ?? 100) ?: null]); flash('ok', 'Course added.'); } else flash('bad', 'Department, code and title are required.'); }
        elseif ($a === 'course_save') { q('UPDATE courses SET department_id=?, code=?, title=?, level=? WHERE id=?', [(int)$_POST['department_id'], strtoupper(trim($_POST['code'])), trim($_POST['title']), (int)$_POST['level'] ?: null, $id]); flash('ok', 'Saved.'); }
        elseif ($a === 'course_delete') { if (val('SELECT COUNT(*) FROM papers WHERE course_id=?', [$id])) flash('bad', 'This course has papers. Move or delete them first.'); else { q('DELETE FROM exam_timetable WHERE course_id=?', [$id]); q('DELETE FROM courses WHERE id=?', [$id]); flash('ok', 'Course deleted.'); } }
        elseif ($a === 'term_add') { q('INSERT INTO academic_terms(school_id,year_label,semester,starts_on,ends_on,is_current) VALUES (?,?,?,?,?,0)', [$S, Validator::str($_POST['year_label'] ?? '', 4, 20) ?? '', (int)($_POST['semester'] ?? 1), $_POST['starts_on'] ?: null, $_POST['ends_on'] ?: null]); flash('ok', 'Term added.'); }
        elseif ($a === 'term_current') { q('UPDATE academic_terms SET is_current=0 WHERE school_id=?', [$S]); q('UPDATE academic_terms SET is_current=1 WHERE id=?', [$id]); $t = row('SELECT * FROM academic_terms WHERE id=?', [$id]); if ($t) { Settings::set('year_label', $t['year_label']); Settings::set('semester_label', 'Semester ' . $t['semester']); } flash('ok', 'Current term set.'); }
        elseif ($a === 'term_delete') { q('DELETE FROM exam_timetable WHERE term_id=?', [$id]); q('DELETE FROM academic_terms WHERE id=?', [$id]); flash('ok', 'Term deleted.'); }
        elseif ($a === 'exam_add') { $c = (int)$_POST['course_id']; $t = (int)$_POST['term_id']; if ($c && $t && $_POST['exam_date']) { q('INSERT INTO exam_timetable(school_id,course_id,term_id,exam_date,exam_time,venue) VALUES (?,?,?,?,?,?)', [$S, $c, $t, $_POST['exam_date'], $_POST['exam_time'] ?: null, Validator::str($_POST['venue'] ?? '', 0, 120) ?? null]); flash('ok', 'Exam added.'); } else flash('bad', 'Course, term and date are required.'); }
        elseif ($a === 'exam_delete') { q('DELETE FROM exam_timetable WHERE id=?', [$id]); flash('ok', 'Exam removed.'); }
        Audit::log("academics: $a", 'academics', $id ?: null);
    } catch (PDOException $e) { flash('bad', 'Could not save: that may already exist.'); }
    redirect_self_or_ajax();
}
$tab = $_GET['tab'] ?? 'departments'; $q = trim((string)($_GET['q'] ?? '')); $per = per_page(15); $page = page_no();
$deps = rows('SELECT d.*,(SELECT COUNT(*) FROM courses c WHERE c.department_id=d.id) cc,(SELECT COUNT(*) FROM users u WHERE u.department_id=d.id) uc FROM departments d WHERE ' . in_school('d') . ' ORDER BY name');
$depF = (int)($_GET['dep'] ?? 0); $termF = (int)($_GET['term'] ?? 0);
$terms = rows('SELECT * FROM academic_terms WHERE ' . in_school() . ' ORDER BY starts_on DESC,id DESC');
$allCourses = rows('SELECT id,CONCAT(code," · ",title) n FROM courses WHERE ' . in_school() . ' ORDER BY code');
$termOpts = array_map(fn($t) => ['id' => $t['id'], 'n' => $t['year_label'] . ' S' . $t['semester']], $terms);
$act = 'admin/academics.php';
include ROOT . '/includes/dashboard_header.php';
echo page_head('Departments & exams', 'The structure students see when they pick a department and course.');
?>
<div class="tabs" role="navigation"><?php foreach (['departments' => 'Departments', 'courses' => 'Courses', 'terms' => 'Terms', 'exams' => 'Exam timetable'] as $k => $l): ?><a class="tab" href="?tab=<?= $k ?>" aria-selected="<?= $tab === $k ? 'true' : 'false' ?>"><?= $l ?></a><?php endforeach; ?></div>
<?php if ($tab === 'departments'):
    [$list, $total] = page_slice($q !== '' ? array_values(array_filter($deps, fn($d) => stripos($d['name'], $q) !== false)) : $deps, $per, $page); ?>
<section class="card dt">
  <?= dt_head('Departments', count($deps) . ' in total', modal_btn('tpl-ad', 'Add a department', 'Add department', 'btn sm', 'plus')) ?>
  <form class="toolbar" method="get"><input type="hidden" name="tab" value="departments"><label class="search"><?= ic('search', 'i') ?><span class="vh">Search</span><input name="q" value="<?= e($q) ?>" type="search" placeholder="Department name"></label><button class="btn sm">Search</button></form>
  <div class="dtwrap"><table class="dtable"><thead><tr><th>Department</th><th class="num">Courses</th><th class="num">Students</th><th>Status</th><th data-nosort><span class="vh">Actions</span></th></tr></thead><tbody>
  <?php foreach ($list as $d): ?><tr>
    <td class="dmain"><span class="dtt"><?= e($d['name']) ?></span></td><td class="num"><?= (int)$d['cc'] ?></td><td class="num"><?= (int)$d['uc'] ?></td>
    <td><span class="pill <?= $d['is_active'] ? 'ok' : 'wait' ?>"><?= $d['is_active'] ? 'Active' : 'Inactive' ?></span></td>
    <td class="act"><div class="rowact"><?= modal_btn('tpl-d' . $d['id'], 'Edit department', 'Edit', 'btn sm line', 'edit') ?></div>
      <template id="tpl-d<?= $d['id'] ?>">
      <form class="card mform" method="post" data-action="<?= $act ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $d['id'] ?>"><input type="hidden" name="a" value="dept_save"><h2>Edit department</h2>
        <div class="field"><label for="dn<?= $d['id'] ?>">Name</label><input class="in" id="dn<?= $d['id'] ?>" name="name" value="<?= e($d['name']) ?>" required></div>
        <label class="chk"><input type="checkbox" name="is_active" value="1"<?= $d['is_active'] ? ' checked' : '' ?>>Active</label>
        <button class="btn" type="submit">Save</button></form>
      <?= row_form($act, ['a' => 'dept_delete', 'id' => $d['id']], 'Delete department', 'btn sm danger', 'Delete this department?') ?>
      </template></td></tr>
  <?php endforeach; if (!$list) echo dt_empty(5, 'No departments.'); ?></tbody></table></div>
  <?= dt_foot($total, $per, $page, ['tab' => 'departments', 'q' => $q]) ?>
</section>
<template id="tpl-ad">
<form class="card mform" method="post" data-action="<?= $act ?>"><?= csrf_field() ?><input type="hidden" name="a" value="dept_add"><h2>Add a department</h2><div class="field"><label for="nd">Name</label><input class="in" id="nd" name="name" required></div><button class="btn">Add department</button></form>
</template>
<?php elseif ($tab === 'courses'):
    $w = [in_school('c')]; $a = []; if ($depF) { $w[] = 'c.department_id=?'; $a[] = $depF; } if ($q !== '') { $w[] = '(c.code LIKE ? OR c.title LIKE ?)'; array_push($a, "%$q%", "%$q%"); }
    $W = implode(' AND ', $w); $total = (int)val("SELECT COUNT(*) FROM courses c WHERE $W", $a);
    $list = rows("SELECT c.*,d.name dept FROM courses c JOIN departments d ON d.id=c.department_id WHERE $W ORDER BY d.name,c.code LIMIT $per OFFSET " . (($page - 1) * $per), $a); ?>
<section class="card dt">
  <?= dt_head('Courses', number_format($total) . ' in this view', modal_btn('tpl-ac', 'Add a course', 'Add course', 'btn sm', 'plus')) ?>
  <form class="toolbar" method="get"><input type="hidden" name="tab" value="courses"><label class="search"><?= ic('search', 'i') ?><span class="vh">Search</span><input name="q" value="<?= e($q) ?>" type="search" placeholder="Code or title"></label><select class="in" name="dep" aria-label="Department"><?= options($deps, $depF, 'id', 'name', 'All departments') ?></select><button class="btn sm">Filter</button></form>
  <div class="dtwrap"><table class="dtable"><thead><tr><th>Code</th><th>Title</th><th>Department</th><th class="num">Level</th><th class="num">Papers</th><th data-nosort><span class="vh">Actions</span></th></tr></thead><tbody>
  <?php foreach ($list as $c): ?><tr>
    <td class="dmain"><span class="code"><?= e($c['code']) ?></span></td><td><span class="dtt"><?= e($c['title']) ?></span></td><td class="muted"><?= e($c['dept']) ?></td>
    <td class="num"><?= $c['level'] ? (int)$c['level'] : '&ndash;' ?></td><td class="num"><?= (int)$c['paper_count'] ?></td>
    <td class="act"><div class="rowact"><?= modal_btn('tpl-c' . $c['id'], 'Edit course', 'Edit', 'btn sm line', 'edit') ?></div>
      <template id="tpl-c<?= $c['id'] ?>">
      <form class="card mform" method="post" data-action="<?= $act ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $c['id'] ?>"><input type="hidden" name="a" value="course_save"><h2>Edit course</h2>
        <div class="f2"><div class="field"><label for="cc<?= $c['id'] ?>">Code</label><input class="in" id="cc<?= $c['id'] ?>" name="code" value="<?= e($c['code']) ?>" required></div><div class="field"><label for="cl<?= $c['id'] ?>">Level</label><input class="in" id="cl<?= $c['id'] ?>" name="level" type="number" value="<?= (int)$c['level'] ?>"></div></div>
        <div class="field"><label for="ct<?= $c['id'] ?>">Title</label><input class="in" id="ct<?= $c['id'] ?>" name="title" value="<?= e($c['title']) ?>" required></div>
        <div class="field"><label for="cd<?= $c['id'] ?>">Department</label><select class="in" id="cd<?= $c['id'] ?>" name="department_id"><?= options($deps, $c['department_id'], 'id', 'name') ?></select></div>
        <button class="btn" type="submit">Save</button></form>
      <?= row_form($act, ['a' => 'course_delete', 'id' => $c['id']], 'Delete course', 'btn sm danger', 'Delete this course?') ?>
      </template></td></tr>
  <?php endforeach; if (!$list) echo dt_empty(6, 'No courses.'); ?></tbody></table></div>
  <?= dt_foot($total, $per, $page, ['tab' => 'courses', 'q' => $q, 'dep' => $depF]) ?>
</section>
<template id="tpl-ac">
<form class="card mform" method="post" data-action="<?= $act ?>"><?= csrf_field() ?><input type="hidden" name="a" value="course_add"><h2>Add a course</h2><div class="field"><label for="cd">Department</label><select class="in" id="cd" name="department_id" required><?= options($deps, $depF, 'id', 'name', 'Choose') ?></select></div>
  <div class="f2"><div class="field"><label for="cc">Code</label><input class="in" id="cc" name="code" placeholder="CSC 201" required></div><div class="field"><label for="cl">Level</label><input class="in" id="cl" name="level" type="number" value="200"></div></div><div class="field"><label for="ct">Title</label><input class="in" id="ct" name="title" required></div><button class="btn">Add course</button></form>
</template>
<?php elseif ($tab === 'terms'):
    [$list, $total] = page_slice($terms, $per, $page); ?>
<section class="card dt">
  <?= dt_head('Terms', 'The current term sets the year and semester shown to students.', modal_btn('tpl-at', 'Add a term', 'Add term', 'btn sm', 'plus')) ?>
  <div class="dtwrap"><table class="dtable"><thead><tr><th>Academic year</th><th>Semester</th><th>Starts</th><th>Ends</th><th>Status</th><th data-nosort><span class="vh">Actions</span></th></tr></thead><tbody>
  <?php foreach ($list as $t): ?><tr>
    <td class="dmain"><span class="dtt"><?= e($t['year_label']) ?></span></td><td>Semester <?= (int)$t['semester'] ?></td>
    <td class="nw" data-v="<?= e((string)$t['starts_on']) ?>"><?= e(fdate($t['starts_on'])) ?></td><td class="nw" data-v="<?= e((string)$t['ends_on']) ?>"><?= e(fdate($t['ends_on'])) ?></td>
    <td><?= $t['is_current'] ? '<span class="pill ok">Current</span>' : '<span class="pill free">Past / upcoming</span>' ?></td>
    <td class="act"><div class="rowact"><?php if (!$t['is_current']) echo row_form($act, ['a' => 'term_current', 'id' => $t['id']], 'Make current', 'btn sm line'); ?>
      <?= row_form($act, ['a' => 'term_delete', 'id' => $t['id']], 'Delete', 'btn sm danger', 'Delete this term and its exam dates?') ?></div></td></tr>
  <?php endforeach; if (!$list) echo dt_empty(6, 'No terms yet.'); ?></tbody></table></div>
  <?= dt_foot($total, $per, $page, ['tab' => 'terms']) ?>
</section>
<template id="tpl-at">
<form class="card mform" method="post" data-action="<?= $act ?>"><?= csrf_field() ?><input type="hidden" name="a" value="term_add"><h2>Add a term</h2><div class="f2"><div class="field"><label for="yl">Academic year</label><input class="in" id="yl" name="year_label" placeholder="2026/2027" required></div><div class="field"><label for="sm">Semester</label><select class="in" id="sm" name="semester"><option>1</option><option>2</option></select></div></div>
  <div class="f2"><div class="field"><label for="so">Starts</label><input class="in" id="so" type="date" name="starts_on"></div><div class="field"><label for="eo">Ends</label><input class="in" id="eo" type="date" name="ends_on"></div></div><button class="btn">Add term</button></form>
</template>
<?php else:
    $w = [in_school('e')]; $a = []; if ($termF) { $w[] = 'e.term_id=?'; $a[] = $termF; } if ($q !== '') { $w[] = '(c.code LIKE ? OR c.title LIKE ? OR e.venue LIKE ?)'; array_push($a, "%$q%", "%$q%", "%$q%"); }
    $W = implode(' AND ', $w); $total = (int)val("SELECT COUNT(*) FROM exam_timetable e JOIN courses c ON c.id=e.course_id WHERE $W", $a);
    $list = rows("SELECT e.*,c.code,c.title,t.year_label,t.semester FROM exam_timetable e JOIN courses c ON c.id=e.course_id JOIN academic_terms t ON t.id=e.term_id WHERE $W ORDER BY e.exam_date DESC,e.exam_time LIMIT $per OFFSET " . (($page - 1) * $per), $a); ?>
<section class="card dt">
  <?= dt_head('Exam timetable', number_format($total) . ' exams in this view', modal_btn('tpl-ae', 'Schedule an exam', 'Schedule exam', 'btn sm', 'plus')) ?>
  <form class="toolbar" method="get"><input type="hidden" name="tab" value="exams"><label class="search"><?= ic('search', 'i') ?><span class="vh">Search</span><input name="q" value="<?= e($q) ?>" type="search" placeholder="Course or venue"></label><select class="in" name="term" aria-label="Term"><?= options($termOpts, $termF, 'id', 'n', 'All terms') ?></select><button class="btn sm">Filter</button></form>
  <div class="dtwrap"><table class="dtable"><thead><tr><th>Course</th><th>Date</th><th>Time</th><th>Venue</th><th>Term</th><th data-nosort><span class="vh">Actions</span></th></tr></thead><tbody>
  <?php foreach ($list as $x): ?><tr>
    <td class="dmain"><span class="dtt"><?= e($x['code']) ?></span><span class="ts"><?= e($x['title']) ?></span></td>
    <td class="nw" data-v="<?= e($x['exam_date']) ?>"><?= e(fdate($x['exam_date'], 'D j M Y')) ?></td><td class="nw"><?= $x['exam_time'] ? e(substr($x['exam_time'], 0, 5)) : '&ndash;' ?></td>
    <td><?= e($x['venue'] ?: 'Venue TBA') ?></td><td class="nw"><?= e($x['year_label']) ?> S<?= (int)$x['semester'] ?></td>
    <td class="act"><div class="rowact"><?= row_form($act, ['a' => 'exam_delete', 'id' => $x['id']], 'Remove', 'btn sm danger', 'Remove this exam?') ?></div></td></tr>
  <?php endforeach; if (!$list) echo dt_empty(6, 'No exams scheduled.'); ?></tbody></table></div>
  <?= dt_foot($total, $per, $page, ['tab' => 'exams', 'q' => $q, 'term' => $termF]) ?>
</section>
<template id="tpl-ae">
<form class="card mform" method="post" data-action="<?= $act ?>"><?= csrf_field() ?><input type="hidden" name="a" value="exam_add"><h2>Schedule an exam</h2><div class="field"><label for="xc">Course</label><select class="in" id="xc" name="course_id" required><?= options($allCourses, null, 'id', 'n', 'Choose') ?></select></div><div class="field"><label for="xt">Term</label><select class="in" id="xt" name="term_id" required><?= options($termOpts, $termF ?: null, 'id', 'n', 'Choose') ?></select></div>
  <div class="f2"><div class="field"><label for="xd">Date</label><input class="in" id="xd" type="date" name="exam_date" required></div><div class="field"><label for="xh">Time</label><input class="in" id="xh" type="time" name="exam_time"></div></div><div class="field"><label for="xv">Venue</label><input class="in" id="xv" name="venue"></div><button class="btn">Add exam</button></form>
</template>
<?php endif; ?>
<?php include ROOT . '/includes/dashboard_footer.php'; ?>
