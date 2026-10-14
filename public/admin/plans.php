<?php
require __DIR__ . '/../../app/bootstrap.php';
$u = require_perm('plans'); $adminArea = true; $active = 'plans'; $title = 'Plans & access'; $scripts = ['forms.js'];
$limits = [ // setting key => [label, hint, type]
  'free_timer' => ['Free focus timer (minutes)', 'Longest focus session a free student can run.', 'number'],
  'plus_max_timer' => ['Plus maximum timer (minutes)', 'Plus members can set any length up to this.', 'number'],
  'free_notes' => ['Free notes allowed', 'How many notes a free student can keep.', 'number'],
  'free_upload_max' => ['Free uploads allowed', 'Documents a free student may upload.', 'number'],
  'free_upload_pages' => ['Free upload length (pages)', 'Longest document a free student may upload.', 'number'],
  'rec_max_min_plus' => ['Longest recording (minutes)', 'For Plus members and staff.', 'number'],
];
// limits that cost the platform money are set by the platform owner (Platform > Settings), not by each school
if (is_super()) $limits += ['ai_cap' => ['AI messages per day (Plus, all schools)', 'Daily limit per Plus member. Platform setting.', 'number'], 'rec_max_mb' => ['Largest audio file (MB, all schools)', 'Platform setting.', 'number']];
if (is_post()) {
    form_guard(); $a = $_POST['a'] ?? '';
    if ($a === 'prices_default' && sid()) {     // drop this school's own prices: it follows the platform price list again
        q('DELETE FROM plan_prices WHERE school_id=?', [sid()]); Audit::log('school prices reset to platform default', 'plan', 2); flash('ok', 'This school now uses the platform prices.');
    } elseif ($a === 'prices') {
        Billing::ownPrices(sid());
        foreach (['month', 'semester', 'year'] as $p) { $amt = (float)($_POST['amount'][$p] ?? 0); if ($amt < 0) continue;
            q('INSERT INTO plan_prices(school_id,plan_id,period,amount,currency,is_enabled) VALUES (?,2,?,?,?,?) ON DUPLICATE KEY UPDATE amount=VALUES(amount),currency=VALUES(currency),is_enabled=VALUES(is_enabled)', [sid(), $p, $amt, strtoupper(substr(trim($_POST['currency'] ?? 'GHS'), 0, 3)) ?: 'GHS', isset($_POST['enabled'][$p]) ? 1 : 0]); }
        Audit::log('updated Plus prices', 'plan', 2); flash('ok', 'Prices saved.');
    } elseif ($a === 'limits') {
        foreach ($limits as $k => $_) if (isset($_POST[$k])) Settings::set($k, (string)max(0, (int)$_POST[$k]));
        foreach (['ai_on', 'ai_quiz', 'ai_cards', 'ai_guide'] as $k) Settings::set($k, isset($_POST[$k]) ? '1' : '0');
        Settings::set('ai_rules', mb_substr((string)($_POST['ai_rules'] ?? ''), 0, 2000)); Audit::log('updated access limits', 'plan', 2); flash('ok', 'Access rules saved.');
    }
    redirect_self_or_ajax();
}
$ownPrices = Billing::priceSchool() === sid() && sid();
$prices = array_column(rows('SELECT * FROM plan_prices WHERE plan_id=2 AND school_id=?', [Billing::priceSchool()]), null, 'period'); $cur = $prices ? reset($prices)['currency'] : 'GHS';
$plusN = (int)val("SELECT COUNT(*) FROM users WHERE plus=1 AND (plus_expires IS NULL OR plus_expires>NOW()) AND role_id NOT IN (4,5) AND " . in_school());
$canPrice = is_super();   // Plus is run by the platform owner (school admins never hold the "plans" permission)
$aiFlags = ['ai_on' => 'PrepDeck AI is on', 'ai_quiz' => 'Allow quiz mode', 'ai_cards' => 'Allow flashcards from AI', 'ai_guide' => 'Allow study guides from AI'];
include ROOT . '/includes/dashboard_header.php';
echo page_head('Plans & access', $plusN . ' active Plus member' . ($plusN === 1 ? '' : 's') . '. Change what is free and what needs Plus.');
?>
<div class="grid2">
<section class="card dt">
  <?= dt_head('Plus prices', ($ownPrices ? 'This school\'s own prices.' : 'Using the platform default prices.') . ' Turned-off options are hidden from students.', $canPrice ? modal_btn('tpl-prices', 'Edit Plus prices', 'Edit prices', 'btn sm', 'edit') . ($ownPrices ? row_form('admin/plans.php', ['a' => 'prices_default'], 'Use platform prices', 'btn sm line', 'Go back to the platform default prices for this school?') : '') : '<span class="pill free">Set by the platform owner</span>') ?>
  <div class="dtwrap"><table class="dtable"><thead><tr><th>Billing option</th><th class="num">Days</th><th class="num">Price</th><th>For sale</th></tr></thead><tbody>
  <?php foreach (Billing::LABEL as $p => $l): $r = $prices[$p] ?? ['amount' => 0, 'is_enabled' => 0, 'currency' => $cur]; ?><tr>
    <td class="dmain"><span class="dtt"><?= e($l) ?></span></td><td class="num"><?= Billing::DAYS[$p] ?></td><td class="num" data-v="<?= e($r['amount']) ?>"><b><?= e(money($r['amount'], $r['currency'])) ?></b></td>
    <td><?= $r['is_enabled'] ? '<span class="pill ok">On</span>' : '<span class="pill free">Off</span>' ?></td></tr>
  <?php endforeach; ?></tbody></table></div>
</section>
<section class="card dt">
  <?= dt_head('Limits & AI', 'Free-plan limits and PrepDeck AI switches.', modal_btn('tpl-limits', 'Edit limits & AI', 'Edit limits', 'btn sm', 'edit', 'wide')) ?>
  <div class="dtwrap"><table class="dtable"><thead><tr><th>Setting</th><th class="num">Value</th></tr></thead><tbody>
  <?php foreach ($limits as $k => [$l, $h, $t]): ?><tr><td class="dmain"><span class="dtt"><?= e($l) ?></span><?php if ($h): ?><span class="ts"><?= e($h) ?></span><?php endif; ?></td><td class="num"><b><?= e(setting($k, '')) ?: '&ndash;' ?></b></td></tr><?php endforeach; ?>
  <?php foreach ($aiFlags as $k => $l): ?><tr><td class="dmain"><span class="dtt"><?= e($l) ?></span></td><td class="num"><?= setting($k, '1') === '1' ? '<span class="pill ok">Yes</span>' : '<span class="pill free">No</span>' ?></td></tr><?php endforeach; ?>
  </tbody></table></div>
</section>
</div>
<section class="card dt"><?= dt_head('What each plan gets') ?>
  <div class="dtwrap"><table class="dtable"><thead><tr><th>Feature</th><th>Free</th><th>Plus</th></tr></thead><tbody>
  <?php foreach ([['Browse past questions', 'Yes', 'Yes'], ['Solved answers', 'No', 'Yes'], ['Flashcards', 'Shared decks', 'Own decks + progress'], ['Games', 'Match', 'All games'], ['Study guides, AI, recordings', 'No', 'Yes'],
      ['Uploads', (int)setting('free_upload_max', 3) . ' × ' . (int)setting('free_upload_pages', 1) . ' page', 'Unlimited'], ['Notes', (string)(int)setting('free_notes', 1), 'Unlimited'], ['Focus timer', (int)setting('free_timer', 15) . ' min fixed', 'Up to ' . (int)setting('plus_max_timer', 180) . ' min']] as [$f, $a, $b]): ?>
    <tr><td class="dmain"><span class="dtt"><?= e($f) ?></span></td><td><?= e($a) ?></td><td><b><?= e($b) ?></b></td></tr><?php endforeach; ?>
  </tbody></table></div></section>
<template id="tpl-prices">
<form class="card mform" method="post" data-action="admin/plans.php"><?= csrf_field() ?><input type="hidden" name="a" value="prices"><h2>Plus prices</h2><p class="note">Turn a billing option off to hide it from students.</p>
<div class="field"><label for="cu">Currency (3 letters)</label><input class="in" id="cu" name="currency" value="<?= e($cur) ?>" maxlength="3"></div>
<?php foreach (Billing::LABEL as $p => $l): $r = $prices[$p] ?? ['amount' => 0, 'is_enabled' => 0]; ?><div class="frow"><div class="ft"><b><?= e($l) ?></b><small><?= Billing::DAYS[$p] ?> days of Plus</small></div><input class="in" style="width:110px" type="number" step="0.01" min="0" name="amount[<?= $p ?>]" value="<?= e($r['amount']) ?>" aria-label="<?= e($l) ?> price"><label class="chk"><input type="checkbox" name="enabled[<?= $p ?>]" value="1"<?= $r['is_enabled'] ? ' checked' : '' ?>>On</label></div><?php endforeach; ?>
<button class="btn">Save prices</button></form>
</template>
<template id="tpl-limits">
<form class="card mform" method="post" data-action="admin/plans.php"><?= csrf_field() ?><input type="hidden" name="a" value="limits"><h2>Limits &amp; AI</h2>
<div class="fgrid2"><?php foreach ($limits as $k => [$l, $h, $t]): ?><div class="field"><label for="l_<?= $k ?>"><?= e($l) ?></label><input class="in" id="l_<?= $k ?>" type="<?= $t ?>" min="0" name="<?= $k ?>" value="<?= e(setting($k, '')) ?>"><?php if ($h): ?><span class="hint note"><?= e($h) ?></span><?php endif; ?></div><?php endforeach; ?></div>
<?php foreach ($aiFlags as $k => $l): ?><label class="chk"><input type="checkbox" name="<?= $k ?>" value="1"<?= setting($k, '1') === '1' ? ' checked' : '' ?>><?= e($l) ?></label><?php endforeach; ?>
<div class="field"><label for="air">Extra rules for the AI</label><textarea class="in" id="air" name="ai_rules" rows="4"><?= e(setting('ai_rules', '')) ?></textarea></div>
<button class="btn">Save access rules</button></form>
</template>
<?php include ROOT . '/includes/dashboard_footer.php'; ?>
