<?php
// Platform owner: what each role may do. The platform defaults apply to every school; a school can get its own matrix,
// which is the ceiling for that school's admins (they can never give anyone more than their own role may do there).
require __DIR__ . '/../../app/bootstrap.php';
$u = require_super(); $platformArea = true; $active = 'p-perms'; $title = 'Permissions'; $scripts = ['forms.js'];
$act = 'platform/permissions.php';
$school = Tenant::find((int)($_GET['school'] ?? $_POST['school'] ?? 0));
$sid = (int)($school['id'] ?? 0);
$perms = rows("SELECT * FROM permissions WHERE code NOT IN ('platform','plans') ORDER BY id");   // Plus ("plans") belongs to the platform owner only
$roles = rows('SELECT * FROM roles WHERE id<>5 ORDER BY id');
$desc = ['view' => 'Browse papers', 'upload' => 'Upload papers and share recordings', 'approve' => 'Review any paper, AI output and recording in the school', 'review' => 'Review papers and AI answers for assigned courses only',
    'decks' => 'Moderate study content', 'users' => 'Manage the school\'s users', 'plans' => 'Prices, payments, coupons', 'reports' => 'Handle reports', 'announce' => 'Post announcements',
    'settings' => 'School content and settings', 'courses' => 'Add courses to the school', 'admin' => 'Departments, courses, staff, audit log, delete things'];
if (is_post()) {
    form_guard(); $a = $_POST['a'] ?? '';
    if ($a === 'save') {
        foreach ($roles as $r) {
            $rid = (int)$r['id']; $on = array_values(array_filter($perms, fn($p) => isset($_POST['rp'][$rid][$p['id']]) || $p['code'] === 'view'));
            if ($sid) {
                q('DELETE FROM school_role_permissions WHERE school_id=? AND role_id=?', [$sid, $rid]);
                foreach ($on as $p) q('INSERT INTO school_role_permissions(school_id,role_id,permission_id) VALUES (?,?,?)', [$sid, $rid, $p['id']]);
                Settings::put($sid, 'perm_matrix_' . $rid, '1');   // marks the matrix as set even when a role has nothing but "view"
            } else {
                q('DELETE FROM role_permissions WHERE role_id=?', [$rid]);
                foreach ($on as $p) q('INSERT INTO role_permissions(role_id,permission_id) VALUES (?,?)', [$rid, $p['id']]);
            }
        }
        // extra grants above the new ceiling stop working at once (Auth caps them), and are cleaned up here
        Audit::log('changed role permissions for ' . ($sid ? 'school ' . $sid : 'the platform defaults'), 'permissions', $sid ?: null);
        flash('ok', $sid ? 'Permissions for ' . $school['name'] . ' saved.' : 'Platform default permissions saved. Schools without their own matrix use them.');
    } elseif ($a === 'reset' && $sid) {
        q('DELETE FROM school_role_permissions WHERE school_id=?', [$sid]); q("DELETE FROM site_settings WHERE school_id=? AND k LIKE 'perm_matrix_%'", [$sid]); Settings::flush();
        Audit::log('reset permissions of school ' . $sid . ' to the platform defaults', 'permissions', $sid); flash('ok', $school['name'] . ' follows the platform defaults again.');
    } elseif ($a === 'copy' && $sid) {   // start a school's own matrix from the defaults
        foreach ($roles as $r) { q('DELETE FROM school_role_permissions WHERE school_id=? AND role_id=?', [$sid, $r['id']]);
            q('INSERT INTO school_role_permissions(school_id,role_id,permission_id) SELECT ?,role_id,permission_id FROM role_permissions WHERE role_id=?', [$sid, $r['id']]); Settings::put($sid, 'perm_matrix_' . $r['id'], '1'); }
        flash('ok', 'Copied the defaults. Now adjust them for ' . $school['name'] . '.');
    }
    redirect_self_or_ajax();
}
$custom = $sid && (bool)val("SELECT COUNT(*) FROM site_settings WHERE school_id=? AND k LIKE 'perm_matrix_%'", [$sid]);
$has = []; foreach ($roles as $r) foreach (Auth::rolePerms((int)$r['id'], $sid) as $code) $has[$r['id']][$code] = 1;
$customs = rows("SELECT s.id,s.name FROM schools s WHERE EXISTS (SELECT 1 FROM site_settings x WHERE x.school_id=s.id AND x.k LIKE 'perm_matrix_%') ORDER BY s.name");
$schoolOpts = rows('SELECT id,name n FROM schools WHERE is_active=1 ORDER BY name');
include ROOT . '/includes/dashboard_header.php';
echo page_head('Permissions', $sid ? 'What each role may do in ' . $school['name'] . '.' : 'Platform defaults: what each role may do in every school without its own matrix.');
?>
<section class="card"><form class="toolbar" method="get" style="padding:0"><label class="vh" for="ps">School</label><select class="in" id="ps" name="school" data-autosubmit><option value="">Platform defaults (every school)</option><?= options($schoolOpts, $sid ?: null, 'id', 'n') ?></select><noscript><button class="btn sm">Show</button></noscript></form>
  <p class="note" style="margin-top:10px"><?= $sid ? ($custom ? 'This school has its own matrix.' : 'This school follows the platform defaults. Save below (or copy the defaults first) to give it its own.') : count($customs) . ' school(s) have their own matrix' . ($customs ? ': ' . e(implode(', ', array_column(array_slice($customs, 0, 8), 'name'))) . (count($customs) > 8 ? '…' : '') : '.') ?>
  School admins can give a lecturer or class rep extra permissions, but only ones the admin holds. Everything about Plus (prices, payments, coupons, giving Plus) stays with the platform owner.</p>
  <?php if ($sid): ?><div class="rowact" style="margin-top:10px"><?= !$custom ? row_form($act, ['a' => 'copy', 'school' => $sid], 'Start from the defaults', 'btn sm line') : row_form($act, ['a' => 'reset', 'school' => $sid], 'Use platform defaults again', 'btn sm line', 'Remove this school\'s own permissions?') ?><a class="btn sm line" href="<?= e(url('platform/schools.php?id=' . $sid)) ?>">School</a></div><?php endif; ?></section>
<form class="card dt" method="post" data-action="<?= e($act . ($sid ? '?school=' . $sid : '')) ?>"><?= csrf_field() ?><input type="hidden" name="a" value="save"><input type="hidden" name="school" value="<?= $sid ?>">
  <?= dt_head($sid ? $school['name'] : 'Platform defaults', '"view" is always on. The platform owner always has everything.', '<button class="btn sm" type="submit">Save permissions</button>') ?>
  <div class="dtwrap"><table class="dtable pm"><thead><tr><th>Permission</th><?php foreach ($roles as $r): ?><th><?= e(role_label($r['name'])) ?></th><?php endforeach; ?></tr></thead><tbody>
  <?php foreach ($perms as $p): ?><tr><td class="dmain"><span class="dtt"><?= e($p['code']) ?></span><span class="ts"><?= e($desc[$p['code']] ?? '') ?></span></td>
    <?php foreach ($roles as $r): ?><td><input type="checkbox" name="rp[<?= $r['id'] ?>][<?= $p['id'] ?>]" value="1" aria-label="<?= e($r['name'] . ' ' . $p['code']) ?>"<?= !empty($has[$r['id']][$p['code']]) ? ' checked' : '' ?><?= $p['code'] === 'view' ? ' disabled' : '' ?>></td><?php endforeach; ?></tr><?php endforeach; ?>
  </tbody></table></div></form>
<?php include ROOT . '/includes/dashboard_footer.php'; ?>
