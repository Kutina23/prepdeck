<?php
require __DIR__ . '/../../app/bootstrap.php';
$u = require_login(); $plus = is_plus($u); $uid = (int)$u['id'];
$active = 'notes'; $title = 'My notes'; $scripts = ['notes.js'];
$notes = rows('SELECT n.id,n.title,n.body,n.course_id,n.updated_at,c.code FROM notes n LEFT JOIN courses c ON c.id=n.course_id WHERE n.user_id=? AND ' . in_school('n') . ' ORDER BY n.updated_at DESC', [$uid]);
$limit = $plus ? null : (int)setting('free_notes', 1);
$courses = rows('SELECT id,CONCAT(code," · ",title) n FROM courses WHERE ' . in_school() . ' ORDER BY code');
include ROOT . '/includes/dashboard_header.php';
echo page_head('My notes', $plus ? 'Unlimited notes, tagged to your courses.' : 'Free accounts keep ' . $limit . ' note. Plus gives you unlimited.', '<button class="btn" type="button" id="newnote"' . (!$plus && count($notes) >= $limit ? ' data-full="1"' : '') . '>' . ic('plus') . 'New note</button>');
?>
<div id="notesapp" data-limit="<?= $limit === null ? '' : $limit ?>" data-count="<?= count($notes) ?>">
  <section class="card"><div class="nitems">
    <?php if (!$notes): ?><div class="empty" id="noempty">No notes yet. Start one.</div><?php endif; ?>
    <?php foreach ($notes as $n): ?>
    <button class="nitem" type="button" data-id="<?= $n['id'] ?>" data-course="<?= (int)$n['course_id'] ?>" data-body="<?= e($n['body']) ?>" data-title="<?= e($n['title']) ?>"><b><?= e($n['title']) ?></b><small><?= e(mb_strimwidth((string)$n['body'], 0, 120, '…')) ?></small><span class="nmeta note"><?= $n['code'] ? e($n['code']) . ' · ' : '' ?><?= e(ago($n['updated_at'])) ?></span></button>
    <?php endforeach; ?>
  </div>
  <?php if (!$plus): ?><div style="margin-top:12px"><?= lock_note('Unlimited notes', 'notes') ?></div><?php endif; ?></section>
</div>
<template id="tpl-note-editor">
<form class="card mform" id="neditor">
  <input type="hidden" name="id" value="0">
  <div class="field"><label class="vh" for="nt">Title</label><input class="ninput" id="nt" name="title" placeholder="Note title" maxlength="160"></div>
  <?php if ($plus): ?><div class="field"><label for="nc">Course</label><select class="nsel" id="nc" name="course_id"><?= options($courses, null, 'id', 'n', 'No course') ?></select></div><?php endif; ?>
  <div class="field"><label class="vh" for="nb">Note</label><textarea class="in" id="nb" name="body" rows="12" style="padding-top:12px;min-height:240px" placeholder="Write here. It saves when you press Save."></textarea></div>
  <p class="err note" role="alert"></p>
  <div class="inline"><button class="btn" type="submit">Save note</button><button class="btn danger" type="button" id="ndel" hidden>Delete</button></div>
</form>
</template>
<?php include ROOT . '/includes/dashboard_footer.php'; ?>
