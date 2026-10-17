<?php
/**
 * Lecturer tools bar: the teaching tools in one rail on the right (like the students' study tools), plus a short
 * "this week" summary. Collapses to icons; the choice is remembered (shell.js, data-ltools on #app). Phones use the More sheet.
 */
$ltools = array_values(array_filter([
    ['outlines', 'Outline to notes', 'AI lesson notes from a syllabus', 'spark', 'var(--pop)', 'lecturer/outlines.php', 'view'],
    ['sets', 'Flashcard sets', 'Make sets for your course', 'cards', 'var(--lemon)', 'lecturer/decks.php', 'view'],
    ['tasks', 'Study tasks', 'Set work, see who did it', 'list', 'var(--brand);color:var(--brand-ink)', 'lecturer/tasks.php', 'view'],
    ['lessons', 'Lesson notes', 'Edit and publish', 'lesson', 'var(--lemon)', 'lecturer/lessons.php', 'view'],
    ['papers', 'Upload papers', 'AI writes model answers', 'upload', 'var(--lemon)', 'staff/papers.php', 'upload'],
    ['recordings', 'Record lecture', 'Transcript, notes, cards', 'mic', 'var(--pop)', 'dashboard/recordings.php', 'upload'],
    ['insights', 'Student insights', 'Scores and weak spots', 'chart', 'var(--brand);color:var(--brand-ink)', 'lecturer/insights.php', 'view'],
    ['review', 'Review queue', 'Check uploads', 'docchk', 'var(--surface);color:var(--ink)', 'staff/review.php', Auth::can('approve') ? 'approve' : 'review'],
    ['announcements', 'Announce', 'Message your class', 'mega', 'var(--lemon)', 'staff/announcements.php', 'announce'],
    ['community', 'Community', 'Colleagues and students', 'users2', 'var(--surface);color:var(--ink)', 'dashboard/community.php', 'view'],
], fn($t) => Auth::can($t[6])));
$luid = (int)$u['id'];
$lweek = [
    ['Tasks due this week', (int)val("SELECT COUNT(*) FROM study_tasks WHERE created_by=? AND status='active' AND due_on BETWEEN CURDATE() AND CURDATE()+INTERVAL 7 DAY", [$luid]), 'lecturer/tasks.php'],
    ['Papers to review', staff_queue_count(), 'staff/review.php'],
    ['Lesson note drafts', (int)val("SELECT COUNT(*) FROM lesson_notes WHERE user_id=? AND status='draft'", [$luid]), 'lecturer/lessons.php'],
    ['Your flashcard sets', (int)val("SELECT COUNT(*) FROM decks WHERE owner_id=? AND status='active'", [$luid]), 'lecturer/decks.php'],
];
?>
  <aside class="ltools" id="ltools" aria-label="Teaching tools">
    <div class="lthead"><h2>Teaching tools</h2><button class="tt" id="ltt" type="button" aria-expanded="true" aria-controls="ltools" aria-label="Collapse teaching tools" title="Collapse teaching tools"><?= ic('chev') ?></button></div>
    <div class="ltgrid">
      <?php foreach ($ltools as [$k, $label, $sub, $icon, $bg, $href]): ?>
      <a class="ltool" href="<?= e(url($href)) ?>" title="<?= e($label) ?>"<?= ($active ?? '') === $k ? ' aria-current="page"' : '' ?>><span class="ic" style="background:<?= $bg ?>"><?= ic($icon) ?></span><span class="tx"><b><?= e($label) ?></b><small><?= e($sub) ?></small></span></a>
      <?php endforeach; ?>
    </div>
    <section class="ltweek" aria-labelledby="ltwh"><h3 id="ltwh">This week</h3>
      <ul><?php foreach ($lweek as [$l, $n, $h]): ?><li><a href="<?= e(url($h)) ?>"><span><?= e($l) ?></span><b class="<?= $n ? 'has' : '' ?>"><?= $n ?></b></a></li><?php endforeach; ?></ul>
      <a class="btn sm" href="<?= e(url('lecturer/decks.php?new=1')) ?>"><?= ic('plus') ?>New flashcard set</a>
    </section>
  </aside>
