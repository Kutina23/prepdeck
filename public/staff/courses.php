<?php
/** Courses: school admins, lecturers and class reps with the "courses" permission can add courses.
 * Lecturers and class reps also pick their own courses here (any number of them), which is what their uploads, reviews and dashboard are scoped to.
 * Everything is scoped to the school the person belongs to (sid()); departments are picked from that school only. */
require __DIR__ . '/../../app/bootstrap.php';
$u = require_login(); $self = picks_own_courses($u);   // lecturers and class reps assign courses to themselves
if (!$self) $u = require_perm('courses');
$canAdd = Auth::can('courses');
$staffArea = true; $active = 'courses'; $title = $self ? 'My courses' : 'Courses'; $scripts = ['forms.js'];
$S = sid(); $uid = (int)$u['id'];
if (is_post()) {
    form_guard(); $a = $_POST['a'] ?? ''; $id = (int)($_POST['id'] ?? 0);
    $picked = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['course'] ?? [])))));
    if ($id) $picked[] = $id;
    // every id and every referenced department must belong to this school
    $bad = array_filter($picked, fn($c) => !in_my_school('courses', $c)) || (!empty($_POST['department_id']) && !in_my_school('departments', (int)$_POST['department_id']));
    if ($bad) { flash('bad', 'Not found in this school.'); redirect_self_or_ajax(); }
    try {
        if (($a === 'assign' || $a === 'unassign') && !$self) flash('bad', 'Only lecturers and class reps pick their own courses.');
        elseif ($a === 'assign') {
            if (!$picked) flash('bad', 'Tick at least one course.');
            else {
                $n = 0; foreach ($picked as $c) $n += q('INSERT IGNORE INTO staff_courses(user_id,course_id,assigned_by) VALUES (?,?,?)', [$uid, $c, $uid])->rowCount();
                Audit::log('assigned ' . count($picked) . ' course(s) to self', 'courses');
                flash('ok', $n ? $n . ' course' . ($n === 1 ? '' : 's') . ' added to your course list.' : 'Those courses were already yours.');
            }
        } elseif ($a === 'unassign') {
            foreach ($picked as $c) q('DELETE FROM staff_courses WHERE user_id=? AND course_id=?', [$uid, $c]);
            Audit::log('removed ' . count($picked) . ' course(s) from self', 'courses'); flash('ok', 'Removed from your course list.');
        } elseif ($a === 'course_add' && !$canAdd) flash('bad', 'Your account cannot add courses.');
        elseif ($a === 'course_add') {
            $d = (int)($_POST['department_id'] ?? 0);
            $c = strtoupper(Validator::str($_POST['code'] ?? '', 2, 20) ?? '');
            $t = Validator::str($_POST['title'] ?? '', 2, 160);
            if ($d && $c && $t) {
                q('INSERT INTO courses(school_id,department_id,code,title,level) VALUES (?,?,?,?,?)', [$S, $d, $c, $t, (int)($_POST['level'] ?? 100) ?: null]);
                $cid = (int)db()->lastInsertId();
                // the person who adds a course can upload to it straight away (admins already manage every course)
                if (!Auth::can('admin')) q('INSERT IGNORE INTO staff_courses(user_id,course_id,assigned_by) VALUES (?,?,?)', [(int)$u['id'], $cid, (int)$u['id']]);
                Audit::log("added course $c", 'course', $cid); flash('ok', 'Course added to ' . school_name() . '.');
            } else flash('bad', 'Department, code and title are required.');
        }
        Audit::log("courses: $a", 'courses', $id ?: null);
    } catch (PDOException $e) { flash('bad', 'Could not save: that may already exist.'); }
    redirect_self_or_ajax();
}
$q = trim((string)($_GET['q'] ?? '')); $per = per_page(15); $page = page_no();
$deps = rows('SELECT id,name FROM departments WHERE ' . in_school() . ' ORDER BY name');
$depF = (int)($_GET['dep'] ?? 0); $mineF = $self && !empty($_GET['mine']);
$w = [in_school('c')]; $a = [];
if ($depF) { $w[] = 'c.department_id=?'; $a[] = $depF; }
if ($mineF) { $w[] = 'c.id IN (SELECT course_id FROM staff_courses WHERE user_id=?)'; $a[] = $uid; }
if ($q !== '') { $w[] = '(c.code LIKE ? OR c.title LIKE ?)'; array_push($a, "%$q%", "%$q%"); }
$W = implode(' AND ', $w);
$total = (int)val("SELECT COUNT(*) FROM courses c WHERE $W", $a);
$list = rows("SELECT c.*,d.name dept FROM courses c JOIN departments d ON d.id=c.department_id WHERE $W ORDER BY d.name,c.code LIMIT $per OFFSET " . (($page - 1) * $per), $a);
$mine = staff_course_ids($u);   // null = admin (every course), otherwise the courses assigned to this person
$act = 'staff/courses.php';
include ROOT . '/includes/dashboard_header.php';
echo page_head($self ? 'My courses' : 'Courses', $self ? (is_lecturer($u) ? 'Tick the courses you teach in ' . school_name() . ' and assign them to yourself. Your lecturer dashboard, uploads and lesson notes cover these courses.' : 'Tick the courses you represent in ' . school_name() . ' and assign them to yourself. Your class rep desk and uploads cover these courses.') : 'Courses in ' . school_name() . '. New courses are added to this school only.', $deps && $canAdd ? modal_btn('tpl-ac', 'Add a course', 'Add course', 'btn', 'plus') : '');
if (!$deps) echo '<div class="banner warn">No departments yet. A school admin adds departments in Admin &rsaquo; Departments &amp; exams; courses need a department.</div>';
if ($self && !$mine) echo '<div class="banner">You have no courses yet. Tick your courses below, then press <b>Assign selected to me</b>.</div>';
$cols = 6 + ($mine === null ? 0 : 1) + ($self ? 1 : 0);
?>
<section class="card dt">
  <?= dt_head($self ? 'Courses in ' . school_name() : 'Courses', number_format($total) . ' in this view' . ($self ? ' · ' . count($mine) . ' assigned to you' : '')) ?>
  <form class="toolbar" method="get"><label class="search"><?= ic('search', 'i') ?><span class="vh">Search</span><input name="q" value="<?= e($q) ?>" type="search" placeholder="Code or title"></label>
    <select class="in" name="dep" aria-label="Department"><?= options($deps, $depF, 'id', 'name', 'All departments') ?></select>
    <?php if ($self): ?><select class="in" name="mine" aria-label="Show"><option value="">All courses</option><option value="1"<?= $mineF ? ' selected' : '' ?>>Only my courses</option></select><?php endif; ?>
    <button class="btn sm">Filter</button></form>
  <?php if ($self): ?>
  <form class="toolbar" id="pick" method="post" data-action="<?= $act ?>"><?= csrf_field() ?>
    <button class="btn sm" name="a" value="assign"><?= ic('plus') ?>Assign selected to me</button>
    <button class="btn sm line" name="a" value="unassign" data-confirm="Remove the ticked courses from your course list?">Remove selected</button></form>
  <?php endif; ?>
  <div class="dtwrap"><table class="dtable"><thead><tr><?= $self ? '<th data-nosort><span class="vh">Select</span></th>' : '' ?><th>Code</th><th>Title</th><th>Department</th><th class="num">Level</th><th class="num">Papers</th><?= $mine === null ? '' : '<th>Assigned</th>' ?><th data-nosort><span class="vh">Actions</span></th></tr></thead><tbody>
  <?php foreach ($list as $c): $yours = $mine !== null && in_array((int)$c['id'], $mine, true); ?><tr>
    <?php if ($self): ?><td><label class="chk"><input type="checkbox" name="course[]" value="<?= (int)$c['id'] ?>" form="pick"><span class="vh">Select <?= e($c['code']) ?></span></label></td><?php endif; ?>
    <td class="dmain"><span class="code"><?= e($c['code']) ?></span></td><td><span class="dtt"><?= e($c['title']) ?></span></td><td class="muted"><?= e($c['dept']) ?></td>
    <td class="num"><?= $c['level'] ? (int)$c['level'] : '&ndash;' ?></td><td class="num"><?= (int)$c['paper_count'] ?></td>
    <?php if ($mine !== null): ?><td><?= $yours ? '<span class="pill ok">Yours</span>' : '<span class="muted">&ndash;</span>' ?></td><?php endif; ?>
    <td class="act"><div class="rowact">
      <?php if ($self): ?><?= $yours ? row_form($act, ['a' => 'unassign', 'id' => $c['id']], 'Remove', 'btn sm line', 'Remove ' . $c['code'] . ' from your course list?') : row_form($act, ['a' => 'assign', 'id' => $c['id']], 'Assign to me', 'btn sm') ?>
      <?php if ($yours && is_lecturer($u)): ?><a class="btn sm line" href="<?= e(url('lecturer/insights.php?course=' . $c['id'])) ?>">Insights</a><?php endif; ?>
      <?php else: ?><a class="btn sm line" href="<?= e(url('dashboard/papers.php?course=' . $c['id'])) ?>">Papers</a><?php endif; ?>
    </div></td></tr>
  <?php endforeach; if (!$list) echo dt_empty($cols, $mineF ? 'No courses assigned to you yet.' : 'No courses yet.'); ?></tbody></table></div>
  <?= dt_foot($total, $per, $page, ['q' => $q, 'dep' => $depF, 'mine' => $mineF ? 1 : '']) ?>
</section>
<template id="tpl-ac">
<form class="card mform" method="post" data-action="<?= $act ?>"><?= csrf_field() ?><input type="hidden" name="a" value="course_add"><h2>Add a course</h2>
  <p class="note">The course is added to <?= e(school_name()) ?>, in the department you pick below.</p>
  <div class="field"><label for="cd">Department</label><select class="in" id="cd" name="department_id" required><?= options($deps, $depF ?: null, 'id', 'name', 'Choose') ?></select></div>
  <div class="f2"><div class="field"><label for="cc">Code</label><input class="in" id="cc" name="code" placeholder="CSC 201" required></div><div class="field"><label for="cl">Level</label><input class="in" id="cl" name="level" type="number" value="200"></div></div>
  <div class="field"><label for="ct">Title</label><input class="in" id="ct" name="title" required></div><button class="btn">Add course</button></form>
</template>
<?php include ROOT . '/includes/dashboard_footer.php'; ?>
