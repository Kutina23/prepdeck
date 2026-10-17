<?php
// Platform owner: the cross-school community. Reports from members, removing someone from the community, and its switches.
require __DIR__ . '/../../app/bootstrap.php';
$u = require_super(); $platformArea = true; $active = 'p-community'; $title = 'Community'; $scripts = ['forms.js'];
$act = 'platform/community.php';
if (is_post()) {
    form_guard(); $a = $_POST['a'] ?? ''; $id = (int)($_POST['id'] ?? 0);
    if ($a === 'settings') {
        Settings::setGlobal('community_open', isset($_POST['open']) ? '1' : '0');
        Settings::setGlobal('community_min_age', (string)max(13, min(30, (int)($_POST['min_age'] ?? 18))));
        Audit::log('changed community settings'); flash('ok', 'Community settings saved.');
    } elseif ($a === 'dismiss') {
        q("UPDATE community_reports SET status='resolved',resolution='dismissed',resolved_by=?,resolved_at=NOW() WHERE id=?", [$u['id'], $id]); flash('ok', 'Report closed with no action.');
    } elseif ($a === 'ban') {
        $r = row('SELECT * FROM community_reports WHERE id=?', [$id]); $target = $r ? (int)$r['target_id'] : (int)($_POST['user'] ?? 0);
        $why = Validator::str($_POST['reason'] ?? '', 3, 255) ?: 'Broke the community guidelines';
        if (!Community::profile($target)) flash('bad', 'That member is not in the community.');
        else {
            q('UPDATE community_profiles SET banned_at=NOW(),ban_reason=? WHERE user_id=?', [$why, $target]);
            q("UPDATE community_reports SET status='resolved',resolution='banned',resolved_by=?,resolved_at=NOW() WHERE target_id=? AND status='open'", [$u['id'], $target]);
            SecurityLog::record('community_banned', 'warning', row('SELECT id,school_id,email FROM users WHERE id=?', [$target]), null, $why);
            Audit::log('removed a member from the community', 'user', $target); flash('ok', 'Removed from the community. Their open reports are closed.');
        }
    } elseif ($a === 'unban') {
        q('UPDATE community_profiles SET banned_at=NULL,ban_reason=NULL WHERE user_id=?', [$id]); Audit::log('let a member back into the community', 'user', $id); flash('ok', 'They can use the community again.');
    }
    redirect_self_or_ajax();
}
$tab = in_array($_GET['tab'] ?? '', ['open', 'resolved', 'banned'], true) ? $_GET['tab'] : 'open';
$k = row("SELECT (SELECT COUNT(*) FROM community_profiles WHERE banned_at IS NULL) members, (SELECT COUNT(*) FROM community_profiles WHERE joined_at>=NOW()-INTERVAL 7 DAY) joined7,
  (SELECT COUNT(*) FROM messages WHERE created_at>=NOW()-INTERVAL 7 DAY) msgs7, (SELECT COUNT(*) FROM friendships WHERE status='accepted') friends,
  (SELECT COUNT(*) FROM community_reports WHERE status='open') open, (SELECT COUNT(*) FROM community_profiles WHERE banned_at IS NOT NULL) banned");
$per = per_page(25); $page = page_no();
if ($tab === 'banned') {
    $total = (int)$k['banned'];
    $list = rows("SELECT p.*,u.full_name,u.email,s.short_name FROM community_profiles p JOIN users u ON u.id=p.user_id LEFT JOIN schools s ON s.id=u.school_id WHERE p.banned_at IS NOT NULL ORDER BY p.banned_at DESC LIMIT $per OFFSET " . (($page - 1) * $per));
} else {
    $total = (int)val('SELECT COUNT(*) FROM community_reports WHERE status=?', [$tab]);
    $list = rows("SELECT r.*,rp.display_name reporter,ru.email reporter_email,tp.display_name target,tu.full_name target_full,tu.email target_email,ts.short_name target_school,tp.banned_at,m.body msg,m.kind msg_kind,m.created_at msg_at,
        (SELECT COUNT(*) FROM community_reports x WHERE x.target_id=r.target_id) reports_on_target
        FROM community_reports r JOIN users ru ON ru.id=r.reporter_id LEFT JOIN community_profiles rp ON rp.user_id=r.reporter_id JOIN users tu ON tu.id=r.target_id LEFT JOIN community_profiles tp ON tp.user_id=r.target_id
        LEFT JOIN schools ts ON ts.id=tu.school_id LEFT JOIN messages m ON m.id=r.message_id WHERE r.status=? ORDER BY r.id DESC LIMIT $per OFFSET " . (($page - 1) * $per), [$tab]);
}
include ROOT . '/includes/dashboard_header.php';
echo page_head('Community', 'Students from every school, connected. Review reports and keep it safe.', modal_btn('tpl-cset', 'Community settings', 'Settings', 'btn sm line', 'gear'));
?>
<section class="kpis six" style="margin-bottom:14px">
  <?= stat_tile(number_format((int)$k['members']), 'Members', '', number_format((int)$k['joined7']) . ' joined this week') ?>
  <?= stat_tile(number_format((int)$k['msgs7']), 'Messages, 7 days') ?>
  <?= stat_tile(number_format((int)$k['friends']), 'Friendships') ?>
  <?= stat_tile(number_format((int)$k['open']), 'Open reports') ?>
  <?= stat_tile(number_format((int)$k['banned']), 'Removed members') ?>
  <?= stat_tile(Community::open() ? 'Open' : 'Closed', 'Community', '', 'Age ' . Community::minAge() . '+ for students') ?>
</section>
<div class="tabs" role="navigation" aria-label="Show"><?php foreach (['open' => 'Open reports', 'resolved' => 'Closed reports', 'banned' => 'Removed members'] as $t => $l): ?><a class="tab" href="?tab=<?= $t ?>" aria-selected="<?= $tab === $t ? 'true' : 'false' ?>"><?= e($l) ?></a><?php endforeach; ?></div>
<?php if ($tab === 'banned'): ?>
<section class="card dt"><?= dt_head('Removed members') ?><div class="dtwrap"><table class="dtable"><thead><tr><th>Member</th><th>School</th><th>Reason</th><th>Removed</th><th data-nosort><span class="vh">Actions</span></th></tr></thead><tbody>
  <?php foreach ($list as $r): ?><tr><td class="dmain"><a class="dtt" href="<?= e(url('platform/users.php?id=' . $r['user_id'])) ?>"><?= e($r['display_name']) ?></a><span class="ts"><?= e($r['full_name']) ?> · <?= e($r['email']) ?></span></td><td class="nw"><?= e($r['short_name'] ?: '–') ?></td><td><?= e($r['ban_reason']) ?></td><td class="nw muted"><?= e(ago($r['banned_at'])) ?></td>
    <td class="act"><?= row_form($act, ['a' => 'unban', 'id' => $r['user_id']], 'Let back in', 'btn sm line', 'Let this member use the community again?') ?></td></tr>
  <?php endforeach; if (!$list) echo dt_empty(5, 'Nobody has been removed.'); ?></tbody></table></div><?= dt_foot($total, $per, $page, ['tab' => $tab]) ?></section>
<?php else: ?>
<section class="card dt"><?= dt_head($tab === 'open' ? 'Open reports' : 'Closed reports', 'Members report a person, and optionally one of their messages.') ?><div class="dtwrap"><table class="dtable"><thead><tr><th>Reported</th><th>Reason and message</th><th>By</th><th>When</th><th data-nosort><span class="vh">Actions</span></th></tr></thead><tbody>
  <?php foreach ($list as $r): ?><tr>
    <td class="dmain"><a class="dtt" href="<?= e(url('platform/users.php?id=' . $r['target_id'])) ?>"><?= e($r['target'] ?: $r['target_full']) ?></a><span class="ts"><?= e($r['target_email']) ?><?= $r['target_school'] ? ' · ' . e($r['target_school']) : '' ?></span><?= (int)$r['reports_on_target'] > 1 ? '<span class="pill bad">' . (int)$r['reports_on_target'] . ' reports</span>' : '' ?><?= $r['banned_at'] ? ' <span class="pill new">Removed</span>' : '' ?></td>
    <td><p><?= nl2br(e($r['reason'])) ?></p><?php if ($r['msg'] !== null): ?><blockquote class="cm-quote"><?= $r['msg_kind'] === 'file' ? '<i>An end-to-end encrypted file. PrepDeck cannot open it; ask the reporter to describe it.</i>' : nl2br(e(mb_strimwidth($r['msg'], 0, 400, '…'))) ?><small><?= e(fdate($r['msg_at'], 'j M Y H:i')) ?></small></blockquote><?php endif; ?></td>
    <td><?= e($r['reporter'] ?: '–') ?><span class="ts"><?= e($r['reporter_email']) ?></span></td>
    <td class="nw muted"><?= e(ago($r['created_at'])) ?><?= $r['resolution'] ? '<span class="ts">' . e(ucfirst($r['resolution'])) . '</span>' : '' ?></td>
    <td class="act"><?php if ($tab === 'open'): ?><?= row_form($act, ['a' => 'dismiss', 'id' => $r['id']], 'Dismiss', 'btn sm line') ?><?php if (!$r['banned_at']) echo modal_btn('tpl-ban' . $r['id'], 'Remove from community', 'Remove', 'btn sm danger'); ?><?php endif; ?></td></tr>
    <?php if ($tab === 'open' && !$r['banned_at']): ?><template id="tpl-ban<?= (int)$r['id'] ?>"><form class="card mform" method="post" data-action="<?= $act ?>"><?= csrf_field() ?><input type="hidden" name="a" value="ban"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><h2>Remove <?= e($r['target'] ?: $r['target_full']) ?></h2>
      <p class="note">They can still use the rest of PrepDeck, but not the community. They see the reason.</p>
      <div class="field"><label for="br<?= (int)$r['id'] ?>">Reason</label><input class="in" id="br<?= (int)$r['id'] ?>" name="reason" maxlength="255" value="Broke the community guidelines" required></div><button class="btn danger" type="submit">Remove from community</button></form></template><?php endif; ?>
  <?php endforeach; if (!$list) echo dt_empty(5, $tab === 'open' ? 'No open reports.' : 'No closed reports.'); ?></tbody></table></div><?= dt_foot($total, $per, $page, ['tab' => $tab]) ?></section>
<?php endif; ?>
<template id="tpl-cset"><form class="card mform" method="post" data-action="<?= $act ?>"><?= csrf_field() ?><input type="hidden" name="a" value="settings"><h2>Community settings</h2>
  <label class="chk"><input type="checkbox" name="open" value="1"<?= Community::open() ? ' checked' : '' ?>>Community is open</label>
  <div class="field"><label for="cma">Youngest age for students</label><input class="in" id="cma" name="min_age" type="number" min="13" max="30" value="<?= Community::minAge() ?>"><span class="hint note">Students below this age (from their date of birth) cannot join. Default 18.</span></div>
  <button class="btn" type="submit">Save</button></form></template>
<?php include ROOT . '/includes/dashboard_footer.php'; ?>
