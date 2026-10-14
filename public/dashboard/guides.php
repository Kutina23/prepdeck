<?php
require __DIR__ . '/../../app/bootstrap.php';
$u = require_login(); $plus = is_plus($u); $uid = (int)$u['id'];
$active = 'guides'; $title = 'Study guides'; $scripts = [];
$id = (int)($_GET['id'] ?? 0);
include ROOT . '/includes/dashboard_header.php';
if (!$plus && !Auth::can('decks')) { echo page_head('Study guides', 'Short revision guides built from past papers and your notes.') . '<section class="card">' . lock_note('Study guides') . '</section>'; include ROOT . '/includes/dashboard_footer.php'; exit; }
if ($id) {
    $g = row('SELECT g.*,p.title ptitle FROM study_guides g LEFT JOIN papers p ON p.id=g.paper_id WHERE g.id=?', [$id]);
    $ok = $g && (int)$g['school_id'] === sid() && ((int)$g['owner_id'] === $uid || Auth::can('decks') || $g['status'] === 'active');
    if (!$ok) { echo page_head('Not found') . empty_state('That guide is not available.', '<a class="btn sm" href="' . e(url('dashboard/guides.php')) . '">All guides</a>'); include ROOT . '/includes/dashboard_footer.php'; exit; }
    echo page_head($g['title'], ($g['ai_generated'] ? 'Made by PrepDeck AI · ' : '') . ago($g['created_at']), '<a class="btn sm line" href="' . e(url('dashboard/guides.php')) . '">All guides</a>');
    echo '<article class="card guide">' . guide_html($g['body']) . '</article>';
    include ROOT . '/includes/dashboard_footer.php'; exit;
}
$q = trim((string)($_GET['q'] ?? '')); $per = per_page(15); $page = page_no();
$w = in_school('g') . " AND (g.owner_id=? OR g.status='active')"; $a = [$uid]; if ($q !== '') { $w .= ' AND (g.title LIKE ? OR p.title LIKE ?)'; array_push($a, "%$q%", "%$q%"); }
$total = (int)val("SELECT COUNT(*) FROM study_guides g LEFT JOIN papers p ON p.id=g.paper_id WHERE $w", $a);
$list = rows("SELECT g.id,g.title,g.ai_generated,g.created_at,g.status,p.title ptitle FROM study_guides g LEFT JOIN papers p ON p.id=g.paper_id WHERE $w ORDER BY g.id DESC LIMIT $per OFFSET " . (($page - 1) * $per), $a);
echo page_head('Study guides', 'Revision guides built from past papers and your own notes.', '<a class="btn line" href="' . e(url('dashboard/materials.php')) . '">' . ic('upload') . 'Upload notes</a>');
?>
<section class="card dt">
  <?= dt_head('Guides', number_format($total) . ' guide' . ($total === 1 ? '' : 's')) ?>
  <form class="toolbar" method="get"><label class="search"><?= ic('search', 'i') ?><span class="vh">Search</span><input name="q" value="<?= e($q) ?>" type="search" placeholder="Search guides"></label><button class="btn sm">Search</button></form>
  <div class="dtwrap"><table class="dtable"><thead><tr><th>Guide</th><th>Built from</th><th>Made by</th><th>Created</th><th data-nosort><span class="vh">Actions</span></th></tr></thead><tbody>
  <?php foreach ($list as $g): $href = url('dashboard/guides.php?id=' . $g['id']); ?><tr data-href="<?= e($href) ?>">
    <td class="dmain"><a class="dtt" href="<?= e($href) ?>"><?= e($g['title']) ?></a><?= $g['status'] === 'hidden' ? '<span class="ts">' . status_pill('hidden') . '</span>' : '' ?></td>
    <td class="muted"><?= e($g['ptitle'] ?: 'Your notes') ?></td><td><?= $g['ai_generated'] ? 'PrepDeck AI' : 'Person' ?></td><td class="nw muted" data-v="<?= e($g['created_at']) ?>"><?= e(ago($g['created_at'])) ?></td>
    <td class="act"><a class="btn sm" href="<?= e($href) ?>">Read</a></td></tr>
  <?php endforeach; if (!$list) echo dt_empty(5, 'No guides yet. Upload notes or open a past paper to get one.'); ?></tbody></table></div>
  <?= dt_foot($total, $per, $page, ['q' => $q]) ?>
</section>
<?php include ROOT . '/includes/dashboard_footer.php';
