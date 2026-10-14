<?php
require __DIR__ . '/../../app/bootstrap.php';
$u = lecturer_area_user(); $lecturerArea = true; $active = 'outlines'; $title = 'Course outlines'; $scripts = ['forms.js', 'process.js'];
$uid = (int)$u['id']; $plus = is_plus($u);
[$canGen, $why] = Lessons::canGenerate($u);
$mine = fn(int $id) => row('SELECT o.*,c.code,c.title course FROM course_outlines o JOIN courses c ON c.id=o.course_id WHERE o.id=? AND o.user_id=? AND ' . in_school('o'), [$id, $uid]);
$act = 'lecturer/outlines.php';
if (is_post()) {
    form_guard(); $a = $_POST['a'] ?? ''; $id = (int)($_POST['id'] ?? 0); $o = $mine($id);
    if (!$o) flash('bad', 'Outline not found.');
    elseif (in_array($a, ['write', 'write_missing', 'retry'], true)) {
        [$canGen, $why] = Lessons::canGenerate($u);
        $busy = "note_status IN ('queued','running')";
        $ids = $a === 'write' ? array_map('intval', array_column(rows("SELECT id FROM outline_topics WHERE outline_id=? AND id=? AND NOT $busy", [$id, (int)($_POST['topic'] ?? 0)]), 'id'))
            : ($a === 'write_missing' ? array_map('intval', array_column(rows("SELECT id FROM outline_topics WHERE outline_id=? AND note_status IN ('none','failed') ORDER BY position", [$id]), 'id')) : []);
        $left = Lessons::left($uid);
        if (!$canGen) flash('bad', $why);
        elseif ($a === 'retry') { if ((int)val('SELECT COUNT(*) FROM outline_topics WHERE outline_id=?', [$id])) flash('bad', 'The topics were already found. Write notes for them below.'); else { Lessons::enqueue($o, [], $uid); flash('ok', 'PrepDeck AI is reading the outline again.'); } }
        elseif (!$ids) flash('bad', $a === 'write' ? 'That topic is already being written.' : 'Every topic already has a lesson note.');
        elseif ($left !== null && $left < 1) flash('bad', 'You have used this month\'s ' . Lessons::quota() . ' lesson notes. The count resets on the 1st.');
        else {
            $cut = $left !== null && count($ids) > $left; if ($cut) $ids = array_slice($ids, 0, $left);
            Lessons::enqueue($o, $ids, $uid); Audit::log('queued ' . count($ids) . " lesson note(s) for outline $id", 'outline', $id);
            flash('ok', 'Writing ' . count($ids) . ' lesson note' . (count($ids) === 1 ? '' : 's') . ($cut ? ' (the rest of your monthly allowance)' : '') . '. You can leave this page; we email you when it is done.');
        }
    } elseif ($a === 'topic_save' || $a === 'topic_add') {
        $t = Validator::str($_POST['title'] ?? '', 2, 190);
        $f = [mb_substr(trim((string)($_POST['week'] ?? '')), 0, 40) ?: null, $t, mb_substr(trim((string)($_POST['subtopics'] ?? '')), 0, 3000) ?: null, mb_substr(trim((string)($_POST['objectives'] ?? '')), 0, 3000) ?: null];
        if (!$t) flash('bad', 'Give the topic a title.');
        elseif ($a === 'topic_add') { q('INSERT INTO outline_topics(outline_id,position,week,title,subtopics,objectives) VALUES (?,?,?,?,?,?)', array_merge([$id, (int)val('SELECT COALESCE(MAX(position),0)+1 FROM outline_topics WHERE outline_id=?', [$id])], $f)); flash('ok', 'Topic added.'); }
        else { q('UPDATE outline_topics SET week=?, title=?, subtopics=?, objectives=? WHERE id=? AND outline_id=?', array_merge($f, [(int)($_POST['topic'] ?? 0), $id])); flash('ok', 'Topic saved. Rewrite its note to use the changes.'); }
    } elseif ($a === 'topic_delete') {
        q("DELETE FROM outline_topics WHERE id=? AND outline_id=? AND note_status NOT IN ('queued','running')", [(int)($_POST['topic'] ?? 0), $id]); flash('ok', 'Topic removed. Its lesson note (if any) is kept.');
    } elseif ($a === 'delete') {
        Lessons::deleteOutline($id, !empty($_POST['with_notes'])); Audit::log("deleted course outline $id", 'outline', $id); flash('ok', 'Outline deleted.'); redirect_or_ajax('lecturer/outlines.php');
    }
    redirect_self_or_ajax();
}

$left = Lessons::left($uid); $quota = Lessons::quota(); $used = Lessons::usedThisMonth($uid);
$quotaCard = function () use ($left, $quota, $used, $plus) {
    if (!$plus) return '';
    $pct = $quota ? min(100, round(100 * $used / max(1, $quota))) : 0;
    return '<section class="card"><div class="hd"><div><h2>This month</h2><p class="sub">' . ($quota ? number_format($used) . ' of ' . number_format($quota) . ' lesson notes used · ' . number_format((int)$left) . ' left · resets on the 1st' : number_format($used) . ' lesson notes written · no monthly limit') . '</p></div></div>'
        . ($quota ? '<div class="meter' . ($left === 0 ? ' full' : '') . '" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="' . $pct . '" aria-label="Monthly allowance used"><i style="width:' . $pct . '%"></i></div>' : '') . '</section>';
};
$id = (int)($_GET['id'] ?? 0);
if ($id) {
    $o = $mine($id);
    if (!$o) { include ROOT . '/includes/dashboard_header.php'; echo page_head('Not found') . empty_state('That outline is not in your account.', '<a class="btn sm" href="' . e(url($act)) . '">All outlines</a>'); include ROOT . '/includes/dashboard_footer.php'; exit; }
    $title = $o['title'];
    $topics = rows('SELECT t.*,n.id note_id,n.status nstatus,n.word_count FROM outline_topics t LEFT JOIN lesson_notes n ON n.topic_id=t.id WHERE t.outline_id=? ORDER BY t.position,t.id', [$id]);
    $missing = count(array_filter($topics, fn($t) => in_array($t['note_status'], ['none', 'failed'], true)));
    $busy = count(array_filter($topics, fn($t) => in_array($t['note_status'], ['queued', 'running'], true)));
    include ROOT . '/includes/dashboard_header.php';
    echo page_head($o['title'], $o['code'] . ' · ' . $o['course'] . ' · ' . count($topics) . ' topic' . (count($topics) === 1 ? '' : 's'),
        '<a class="btn sm line" href="' . e(url($act)) . '">All outlines</a><a class="btn sm line" href="' . e(url('api/outlines.php?a=download&id=' . $id)) . '" target="_blank" rel="noopener">' . ic('file') . 'Outline PDF</a>'
        . modal_btn('tpl-topic-add', 'Add a topic', 'Add topic', 'btn sm line', 'plus')
        . ($missing && $canGen ? row_form($act, ['a' => 'write_missing', 'id' => $id], ic('spark') . 'Write ' . $missing . ' missing note' . ($missing === 1 ? '' : 's'), 'btn sm', 'Write lesson notes for the ' . $missing . ' topic' . ($missing === 1 ? '' : 's') . ' without one? Each counts toward your monthly allowance.') : ''));
    if (!$canGen) echo '<div class="banner warn">' . e($why) . ($plus ? '' : ' <a href="' . e(url('dashboard/plan.php')) . '"><b>Get Plus</b></a>') . ' You can still edit, publish and download the notes you already have.</div>';
    echo ai_live('outline', $id);
    if ($o['ai_status'] === 'failed' && !$topics) echo '<section class="card"><h2>PrepDeck AI could not read this outline</h2><p class="note" style="margin:6px 0 12px">' . e((string)val("SELECT message FROM processing_jobs WHERE source_type='outline' AND source_id=? ORDER BY id DESC LIMIT 1", [$id])) . '</p>' . ($canGen ? row_form($act, ['a' => 'retry', 'id' => $id], 'Try again', 'btn sm') : '') . '</section>';
    echo $quotaCard();
    ?>
    <section class="card"><h2>About this outline</h2>
      <?php if ($o['summary']): ?><p style="margin-top:8px;line-height:1.6"><?= e($o['summary']) ?></p><?php endif; ?>
      <p class="note" style="margin-top:8px">Notes are written <?= e(strtolower(Lessons::DEPTH[$o['depth']])) ?><?= $o['page_count'] ? ' · ' . (int)$o['page_count'] . ' page PDF' : '' ?> · uploaded <?= e(ago($o['created_at'])) ?><?= $o['instructions'] ? ' · your instructions: “' . e($o['instructions']) . '”' : '' ?></p>
      <?php if ($busy): ?><p class="note" style="margin-top:6px"><?= $busy ?> note<?= $busy === 1 ? ' is' : 's are' ?> being written. This page updates when you reload it.</p><?php endif; ?></section>
    <section class="card dt">
      <?= dt_head('Topics', 'Check and edit the topics, then write a lesson note for each. Notes are saved as drafts until you publish them.') ?>
      <div class="dtwrap"><table class="dtable"><thead><tr><th class="num">#</th><th>Week</th><th>Topic</th><th>Lesson note</th><th data-nosort><span class="vh">Actions</span></th></tr></thead><tbody>
      <?php foreach ($topics as $i => $t): $nh = $t['note_id'] ? url('lecturer/lessons.php?id=' . $t['note_id']) : ''; ?><tr>
        <td class="num"><?= $i + 1 ?></td><td class="nw muted"><?= e($t['week'] ?: '–') ?></td>
        <td class="dmain"><span class="dtt"><?= e($t['title']) ?></span><?php if ($t['subtopics']): ?><span class="topicsub"><?= e(mb_strimwidth(str_replace("\n", ' · ', $t['subtopics']), 0, 220, '…')) ?></span><?php endif; ?></td>
        <td><?php if (in_array($t['note_status'], ['queued', 'running', 'failed'], true)): echo status_pill($t['note_status'] === 'running' ? 'writing' : $t['note_status']);
            elseif ($t['note_id']): ?><a href="<?= e($nh) ?>"><?= status_pill($t['nstatus']) ?></a><span class="ts"><?= number_format((int)$t['word_count']) ?> words</span><?php else: ?><span class="muted">Not written</span><?php endif; ?></td>
        <td class="act"><div class="rowact">
          <?php if ($t['note_id']): ?><a class="btn sm line" href="<?= e($nh) ?>">Open</a><?php endif; ?>
          <?php if ($canGen && !in_array($t['note_status'], ['queued', 'running'], true)) echo row_form($act, ['a' => 'write', 'id' => $id, 'topic' => $t['id']], $t['note_id'] ? 'Rewrite' : 'Write note', $t['note_id'] ? 'btn sm line' : 'btn sm', $t['note_id'] ? 'Rewrite this lesson note? Your edits to the current note are replaced.' : ''); ?>
          <?= modal_btn('tpl-t' . $t['id'], 'Edit topic', 'Edit', 'btn sm line', 'edit') ?></div>
          <template id="tpl-t<?= $t['id'] ?>">
            <form class="card mform" method="post" data-action="<?= $act ?>"><?= csrf_field() ?><input type="hidden" name="a" value="topic_save"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="topic" value="<?= $t['id'] ?>"><h2>Topic</h2>
              <div class="f2"><div class="field"><label for="tw<?= $t['id'] ?>">Week</label><input class="in" id="tw<?= $t['id'] ?>" name="week" maxlength="40" value="<?= e($t['week']) ?>"></div><div class="field"><label for="tt<?= $t['id'] ?>">Title</label><input class="in" id="tt<?= $t['id'] ?>" name="title" required maxlength="190" value="<?= e($t['title']) ?>"></div></div>
              <div class="field"><label for="ts<?= $t['id'] ?>">Sub-topics (one per line)</label><textarea class="in" id="ts<?= $t['id'] ?>" name="subtopics" rows="4"><?= e($t['subtopics']) ?></textarea></div>
              <div class="field"><label for="to<?= $t['id'] ?>">Learning objectives (one per line)</label><textarea class="in" id="to<?= $t['id'] ?>" name="objectives" rows="3"><?= e($t['objectives']) ?></textarea></div>
              <button class="btn" type="submit">Save topic</button></form>
            <?php if (!in_array($t['note_status'], ['queued', 'running'], true)) echo row_form($act, ['a' => 'topic_delete', 'id' => $id, 'topic' => $t['id']], 'Remove topic', 'btn sm danger', 'Remove this topic from the outline? Its lesson note is kept.'); ?>
          </template></td></tr>
      <?php endforeach; if (!$topics) echo dt_empty(5, in_array($o['ai_status'], ['queued', 'running'], true) ? 'PrepDeck AI is reading the outline…' : 'No topics yet. Add them by hand or try again.'); ?></tbody></table></div>
    </section>
    <section class="card"><h2>Delete this outline</h2><p class="note" style="margin:6px 0 12px">Removes the PDF and the topic list. Tick the box to delete the lesson notes made from it as well.</p>
      <form method="post" data-action="<?= $act ?>"><?= csrf_field() ?><input type="hidden" name="a" value="delete"><input type="hidden" name="id" value="<?= $id ?>"><label class="chk"><input type="checkbox" name="with_notes" value="1">Also delete its lesson notes</label><button class="btn sm danger" data-confirm="Delete this outline?" style="margin-top:8px">Delete outline</button></form></section>
    <template id="tpl-topic-add">
    <form class="card mform" method="post" data-action="<?= $act ?>"><?= csrf_field() ?><input type="hidden" name="a" value="topic_add"><input type="hidden" name="id" value="<?= $id ?>"><h2>New topic</h2>
      <div class="f2"><div class="field"><label for="nw">Week</label><input class="in" id="nw" name="week" maxlength="40" placeholder="Week 5"></div><div class="field"><label for="nt">Title</label><input class="in" id="nt" name="title" required maxlength="190"></div></div>
      <div class="field"><label for="ns">Sub-topics (one per line)</label><textarea class="in" id="ns" name="subtopics" rows="4"></textarea></div>
      <div class="field"><label for="no">Learning objectives (one per line)</label><textarea class="in" id="no" name="objectives" rows="3"></textarea></div>
      <button class="btn" type="submit">Add topic</button></form>
    </template>
    <?php include ROOT . '/includes/dashboard_footer.php'; exit;
}

$courses = staff_courses($u);
$q = trim((string)($_GET['q'] ?? '')); $per = per_page(15); $page = page_no();
$w = 'o.user_id=? AND ' . in_school('o'); $args = [$uid]; if ($q !== '') { $w .= ' AND (o.title LIKE ? OR c.code LIKE ?)'; array_push($args, "%$q%", "%$q%"); }
$total = (int)val("SELECT COUNT(*) FROM course_outlines o JOIN courses c ON c.id=o.course_id WHERE $w", $args);
$list = rows("SELECT o.id,o.title,o.ai_status,o.created_at,c.code,(SELECT COUNT(*) FROM outline_topics t WHERE t.outline_id=o.id) topics,(SELECT COUNT(*) FROM lesson_notes n WHERE n.outline_id=o.id) notes,(SELECT COUNT(*) FROM lesson_notes n WHERE n.outline_id=o.id AND n.status='published') live
  FROM course_outlines o JOIN courses c ON c.id=o.course_id WHERE $w ORDER BY o.id DESC LIMIT $per OFFSET " . (($page - 1) * $per), $args);
include ROOT . '/includes/dashboard_header.php';
echo page_head('Course outlines', 'Upload a course outline and PrepDeck AI writes a lesson note for every topic in it.', $canGen && $courses ? modal_btn('tpl-outline', 'Upload a course outline', 'Upload outline', 'btn', 'upload', 'wide') : '');
if (!Lessons::enabled()) echo '<div class="banner warn">Lesson note generation is switched off by the admin right now.</div>';
elseif (!$plus) echo '<section class="card">' . lock_note('Course outline to lesson notes') . '<ul class="stack" style="padding-left:18px;margin:12px 0 0"><li>Upload your course outline as a PDF. PrepDeck AI finds every topic, week and learning objective.</li><li>Get a full lesson note for each topic: objectives, explanations, worked examples, summary, key terms and review questions.</li><li>Edit the notes, then publish them to students in your course or download them.</li></ul></section>';
elseif (!$canGen) echo '<div class="banner warn">' . e($why) . '</div>';
if (!$courses) echo '<div class="banner warn">No courses are assigned to your account yet. <a href="' . e(url('staff/courses.php')) . '">Pick the courses you teach</a>.</div>';
echo $quotaCard();
if ($canGen && $courses): ?>
<template id="tpl-outline">
<form class="card mform" data-api="outlines.php?a=upload" data-job="1" enctype="multipart/form-data">
  <h2>New course outline</h2>
  <div class="f2"><div class="field"><label for="ot">Title</label><input class="in" id="ot" name="title" required minlength="3" maxlength="190" placeholder="CSC 201 course outline, 2026/27"></div>
  <div class="field"><label for="oc">Course</label><select class="in" id="oc" name="course_id" required><?= options($courses, count($courses) === 1 ? $courses[0]['id'] : null, 'id', 'n', 'Choose course') ?></select></div></div>
  <div class="field"><label for="of">Outline PDF</label><input class="in" id="of" name="file" type="file" accept="application/pdf" required style="padding-top:11px"><span class="hint note">Up to <?= (int)setting('outline_max_pages', 30) ?> pages. Topics, weeks and objectives are enough.</span></div>
  <div class="field"><label for="od">Length of each note</label><select class="in" id="od" name="depth"><?php foreach (Lessons::DEPTH as $k => $l): ?><option value="<?= $k ?>"<?= $k === 'standard' ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
  <div class="field"><label for="oi">Instructions for PrepDeck AI (optional)</label><textarea class="in" id="oi" name="instructions" rows="3" maxlength="600" placeholder="Use Ghanaian examples. Students are mostly from a non-maths background."></textarea></div>
  <label class="chk"><input type="checkbox" name="write_all" value="1" checked>Write a lesson note for every topic now<?= $left !== null ? ' (up to ' . (int)$left . ' this month)' : '' ?></label>
  <div class="progressbar" hidden><i style="width:0"></i></div><p class="note" data-jobmsg></p><p class="err note" role="alert"></p>
  <button class="btn" type="submit"><?= ic('upload') ?>Upload and start</button></form>
</template>
<?php endif; ?>
<section class="card dt">
  <?= dt_head('Your outlines', number_format($total) . ' outline' . ($total === 1 ? '' : 's')) ?>
  <form class="toolbar" method="get"><label class="search"><?= ic('search', 'i') ?><span class="vh">Search</span><input name="q" value="<?= e($q) ?>" type="search" placeholder="Title or course code"></label><button class="btn sm">Search</button></form>
  <div class="dtwrap"><table class="dtable"><thead><tr><th>Outline</th><th>Course</th><th class="num">Topics</th><th class="num">Notes</th><th class="num">Published</th><th>AI</th><th>Uploaded</th><th data-nosort><span class="vh">Actions</span></th></tr></thead><tbody>
  <?php foreach ($list as $r): $href = url('lecturer/outlines.php?id=' . $r['id']); ?><tr data-href="<?= e($href) ?>">
    <td class="dmain"><a class="dtt" href="<?= e($href) ?>"><?= e($r['title']) ?></a></td><td class="nw"><span class="code"><?= e($r['code']) ?></span></td>
    <td class="num"><?= (int)$r['topics'] ?></td><td class="num"><?= (int)$r['notes'] ?></td><td class="num"><?= (int)$r['live'] ?></td>
    <td><?= status_pill($r['ai_status'] === 'none' ? 'queued' : $r['ai_status']) ?></td><td class="nw muted" data-v="<?= e($r['created_at']) ?>"><?= e(ago($r['created_at'])) ?></td>
    <td class="act"><a class="btn sm line" href="<?= e($href) ?>">Open</a></td></tr>
  <?php endforeach; if (!$list) echo dt_empty(8, $plus ? 'No outlines yet. Upload one to get started.' : 'No outlines yet.'); ?></tbody></table></div>
  <?= dt_foot($total, $per, $page, ['q' => $q]) ?>
</section>
<?php include ROOT . '/includes/dashboard_footer.php'; ?>
