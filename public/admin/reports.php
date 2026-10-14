<?php
require __DIR__ . '/../../app/bootstrap.php';
$u = require_perm('reports'); $adminArea = true; $active = 'reports'; $title = 'Reports'; $scripts = ['forms.js'];
if (is_post()) {
    form_guard(); $a = $_POST['a'] ?? ''; $id = (int)($_POST['id'] ?? 0); $r = row('SELECT * FROM reports WHERE id=? AND ' . in_school(), [$id]);
    if ($r) {
        if (in_array($a, ['resolved', 'dismissed', 'open'], true)) { q('UPDATE reports SET status=?, resolved_by=? WHERE id=?', [$a, $a === 'open' ? null : $u['id'], $id]); flash('ok', 'Report ' . $a . '.'); }
        elseif ($a === 'hide' && $r['paper_id'] && Auth::can('approve')) { $p = row('SELECT status,course_id FROM papers WHERE id=?', [$r['paper_id']]); if ($p && $p['status'] === 'published') { q("UPDATE papers SET status='unpublished' WHERE id=?", [$r['paper_id']]); q('UPDATE courses SET paper_count=GREATEST(paper_count-1,0) WHERE id=?', [$p['course_id']]); } q("UPDATE reports SET status='resolved', resolved_by=? WHERE id=?", [$u['id'], $id]); flash('ok', 'Paper unpublished and report resolved.'); }
        elseif ($a === 'hide' && $r['deck_id'] && Auth::can('decks')) { q("UPDATE decks SET status='hidden' WHERE id=?", [$r['deck_id']]); q("UPDATE reports SET status='resolved', resolved_by=? WHERE id=?", [$u['id'], $id]); flash('ok', 'Deck hidden and report resolved.'); }
        Audit::log("report $id: $a", 'report', $id);
    }
    redirect_self_or_ajax();
}
$st = $_GET['status'] ?? 'open'; $kind = $_GET['kind'] ?? ''; $q = trim((string)($_GET['q'] ?? ''));
$w = [in_school('r')]; $a = []; if (in_array($st, ['open', 'resolved', 'dismissed'], true)) { $w[] = 'r.status=?'; $a[] = $st; }
if ($kind === 'paper') $w[] = 'r.paper_id IS NOT NULL'; elseif ($kind === 'deck') $w[] = 'r.deck_id IS NOT NULL';
if ($q !== '') { $w[] = '(r.reason LIKE ? OR p.title LIKE ? OR d.title LIKE ?)'; array_push($a, "%$q%", "%$q%", "%$q%"); }
$W = implode(' AND ', $w); $from = 'FROM reports r JOIN users u ON u.id=r.reported_by LEFT JOIN papers p ON p.id=r.paper_id LEFT JOIN decks d ON d.id=r.deck_id';
$per = per_page(15); $page = page_no(); $total = (int)val("SELECT COUNT(*) $from WHERE $W", $a);
$list = rows("SELECT r.*,u.full_name,p.title ptitle,d.title dtitle $from WHERE $W ORDER BY r.id DESC LIMIT $per OFFSET " . (($page - 1) * $per), $a);
$counts = array_column(rows('SELECT status,COUNT(*) c FROM reports WHERE ' . in_school() . ' GROUP BY status'), 'c', 'status');
$act = 'admin/reports.php';
include ROOT . '/includes/dashboard_header.php';
echo page_head('Reports', 'Problems students flagged on papers and decks.');
?>
<div class="tabs" role="navigation"><?php foreach (['open' => 'Open', 'resolved' => 'Resolved', 'dismissed' => 'Dismissed', 'all' => 'All'] as $k => $l): ?><a class="tab" href="?status=<?= $k ?>" aria-selected="<?= $st === $k ? 'true' : 'false' ?>"><?= $l ?><?= isset($counts[$k]) ? ' (' . (int)$counts[$k] . ')' : '' ?></a><?php endforeach; ?></div>
<section class="card dt">
  <?= dt_head('Reports', number_format($total) . ' in this view') ?>
  <form class="toolbar" method="get"><input type="hidden" name="status" value="<?= e($st) ?>"><label class="search"><?= ic('search', 'i') ?><span class="vh">Search</span><input name="q" value="<?= e($q) ?>" type="search" placeholder="Reason or title"></label>
    <select class="in" name="kind" aria-label="Type"><option value="">Papers and decks</option><option value="paper"<?= $kind === 'paper' ? ' selected' : '' ?>>Papers</option><option value="deck"<?= $kind === 'deck' ? ' selected' : '' ?>>Decks</option></select><button class="btn sm">Filter</button></form>
  <div class="dtwrap"><table class="dtable"><thead><tr><th>Reason</th><th>Reported item</th><th>Reported by</th><th>When</th><th>Status</th><th data-nosort><span class="vh">Actions</span></th></tr></thead><tbody>
  <?php foreach ($list as $r): ?><tr>
    <td class="dmain"><span class="dtt clip"><?= e($r['reason']) ?></span></td>
    <td><?= $r['paper_id'] ? '<span class="ts">Paper</span><a href="' . e(url('admin/papers.php?id=' . $r['paper_id'])) . '">' . e($r['ptitle'] ?? 'Deleted paper') . '</a>' : '<span class="ts">Deck</span><a href="' . e(url('dashboard/decks.php?id=' . $r['deck_id'])) . '">' . e($r['dtitle'] ?? 'Deleted deck') . '</a>' ?></td>
    <td><?= e($r['full_name']) ?></td><td class="nw muted" data-v="<?= e($r['created_at']) ?>"><?= e(ago($r['created_at'])) ?></td><td><?= status_pill($r['status']) ?></td>
    <td class="act"><div class="rowact"><?php if ($r['status'] === 'open'): ?>
      <?= row_form($act, ['a' => 'hide', 'id' => $r['id']], $r['paper_id'] ? 'Unpublish paper' : 'Hide deck', 'btn sm', 'Take this down and resolve the report?') ?>
      <?= row_form($act, ['a' => 'resolved', 'id' => $r['id']], 'Resolve', 'btn sm line') ?><?= row_form($act, ['a' => 'dismissed', 'id' => $r['id']], 'Dismiss', 'btn sm line') ?>
    <?php else: echo row_form($act, ['a' => 'open', 'id' => $r['id']], 'Reopen', 'btn sm line'); endif; ?></div></td></tr>
  <?php endforeach; if (!$list) echo dt_empty(6, 'No reports here.'); ?></tbody></table></div>
  <?= dt_foot($total, $per, $page, ['status' => $st, 'kind' => $kind, 'q' => $q]) ?>
</section>
<?php include ROOT . '/includes/dashboard_footer.php'; ?>
