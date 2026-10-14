<?php
// Platform owner: switches for the whole platform, the AI engine and its cost limits, and the defaults every school starts from.
require __DIR__ . '/../../app/bootstrap.php';
$u = require_super(); $platformArea = true; $active = 'p-settings'; $title = 'Platform settings'; $scripts = ['forms.js'];
$act = 'platform/settings.php';
$switches = [   // platform-wide on/off
    'platform_maintenance' => ['Maintenance mode for every school', 'Students everywhere see a "back soon" page. You still get in.', '0'],
    'platform_reg_open' => ['New students can register', 'Off closes registration in every school.', '1'],
    'school_requests_open' => ['"My school is not listed" requests', 'Visitors can ask for a school to be added.', '1'],
    'platform_ai_on' => ['PrepDeck AI chat', 'Off switches the AI chat off in every school (document processing keeps running).', '1'],
];
$limits = [     // platform-wide numbers (cost control)
    'ai_cap' => ['AI chat messages per Plus member per day', '50'], 'ai_max_pages' => ['Pages read from one document', '80'], 'ai_max_chars' => ['Characters sent to the AI per document', '60000'],
    'ai_predicted_count' => ['Predicted questions per document', '12'], 'rec_max_mb' => ['Largest audio upload (MB)', '200'], 'rec_keep_audio_days' => ['Keep recording audio (days, 0 = forever)', '90'],
    'mat_mb' => ['Largest note upload (MB)', '15'], 'mat_max' => ['Note uploads per Plus member', '10'],
];
$ai = [         // PrepDeck AI: what it may do (you can still override ai_on / lesson_tools_on for one school in Schools > Switches)
    'ai_on' => ['AI chat for students', '1'], 'ai_quiz' => ['Quiz mode in AI chat', '1'], 'ai_cards' => ['Flashcards from AI chat', '1'], 'ai_guide' => ['Study plans from AI chat', '1'],
    'ai_autopublish' => ['AI answers to papers publish without review', '0'], 'lesson_tools_on' => ['Lecturer tool: course outline to lesson notes', '1'], 'lesson_autopublish' => ['AI lesson notes go to students straight away', '0'],
];
$aiNums = ['lesson_quota' => ['AI lesson notes per lecturer per month (0 = no limit)', '60'], 'outline_max_topics' => ['Most topics read from one outline', '30'], 'outline_max_pages' => ['Longest outline PDF (pages)', '30']];
$defaults = [   // what a school has until its admin (or you) changes it
    'reg_open' => 'Students can register', 'upload_review' => 'Rep and lecturer uploads need review',
    'lecturer_plus' => 'Lecturers get Plus free', 'rep_plus' => 'Class reps get Plus free', 'lecturer_autopublish' => 'Lecturer uploads go live without review', 'rep_autopublish' => 'Class rep uploads go live without review',
    'lesson_students_plus' => 'Students need Plus to read lesson notes',
];
$defOn = ['reg_open', 'upload_review', 'lecturer_plus', 'lecturer_autopublish'];
if (is_post()) {
    form_guard(); $a = $_POST['a'] ?? '';
    if ($a === 'switches') { foreach ($switches as $k => $_) Settings::setGlobal($k, isset($_POST[$k]) ? '1' : '0'); Audit::log('changed platform switches', 'settings'); flash('ok', 'Platform switches saved.'); }
    elseif ($a === 'limits') {
        foreach ($limits as $k => $_) if (isset($_POST[$k])) Settings::setGlobal($k, (string)max(0, (int)$_POST[$k]));
        foreach (['ai_model' => 80, 'ai_tier' => 20, 'stt_language' => 20] as $k => $n) if (isset($_POST[$k])) Settings::setGlobal($k, mb_substr(trim((string)$_POST[$k]), 0, $n));
        Audit::log('changed AI and upload limits', 'settings'); flash('ok', 'Limits saved for every school.');
    } elseif ($a === 'defaults') {
        foreach ($defaults as $k => $_) Settings::setGlobal($k, isset($_POST[$k]) ? '1' : '0');
        Settings::setGlobal('school_default_plan', Validator::in($_POST['school_default_plan'] ?? '', ['trial', 'basic', 'standard', 'premium']) ?? 'basic');
        foreach (['year_label', 'semester_label'] as $k) Settings::setGlobal($k, mb_substr(trim((string)($_POST[$k] ?? '')), 0, 60));
        Audit::log('changed school defaults', 'settings'); flash('ok', 'Defaults saved. Schools that set their own value keep it.');
    } elseif ($a === 'ai_features') {
        foreach ($ai as $k => $_) Settings::setGlobal($k, isset($_POST[$k]) ? '1' : '0');
        foreach ($aiNums as $k => $_) if (isset($_POST[$k])) Settings::setGlobal($k, (string)max(0, min(10000, (int)$_POST[$k])));
        Settings::setGlobal('ai_rules', mb_substr((string)($_POST['ai_rules'] ?? ''), 0, 2000));
        Audit::log('changed PrepDeck AI features', 'settings'); flash('ok', 'PrepDeck AI settings saved for every school.');
    } elseif ($a === 'home') {
        $hs = (int)($_POST['home_school'] ?? 0);
        if ($hs && !val('SELECT COUNT(*) FROM schools WHERE id=? AND is_active=1', [$hs])) flash('bad', 'Pick an active school.');
        else { Settings::setGlobal('home_school', (string)$hs); Audit::log('changed home page default school', 'settings', $hs ?: null); flash('ok', 'Home page default school saved.'); }
    } elseif ($a === 'ai_test') {
        [$ok, $msg] = AiClient::test(); Settings::setGlobal('ai_last_test', gmdate('Y-m-d H:i') . ' UTC: ' . ($ok ? 'OK' : 'FAILED') . ' - ' . $msg); flash($ok ? 'ok' : 'bad', $msg);
    }
    redirect_self_or_ajax();
}
$g = fn(string $k, string $d = '') => (string)Settings::global($k, $d);
$homeSchools = rows('SELECT id,name FROM schools WHERE is_active=1 ORDER BY name'); $homeNow = home_default_school();
$overrides = array_column(rows("SELECT k, COUNT(*) n FROM site_settings WHERE school_id>0 AND k IN ('" . implode("','", array_keys($defaults)) . "') GROUP BY k"), 'n', 'k');
include ROOT . '/includes/dashboard_header.php';
echo page_head('Platform settings', 'Apply to every school. School admins cannot change these.', '<a class="btn sm line" href="' . e(url('admin/settings.php?tab=email')) . '">' . ic('mega') . 'Email</a><a class="btn sm line" href="' . e(url('admin/settings.php?tab=system')) . '">' . ic('gear') . 'System check</a>');
?>
<div class="grid2">
  <form class="card dt" method="post" data-action="<?= $act ?>"><?= csrf_field() ?><input type="hidden" name="a" value="switches"><?= dt_head('Platform switches', '', '<button class="btn sm">Save</button>') ?>
    <div style="padding:0 18px 18px"><?php foreach ($switches as $k => [$l, $h, $d]): ?><label class="chk" style="align-items:flex-start"><input type="checkbox" name="<?= $k ?>" value="1"<?= $g($k, $d) === '1' ? ' checked' : '' ?>><span><b><?= e($l) ?></b><br><small class="note"><?= e($h) ?></small></span></label><?php endforeach; ?></div></form>
  <form class="card dt" method="post" data-action="<?= $act ?>"><?= csrf_field() ?><input type="hidden" name="a" value="limits"><?= dt_head('AI engine & limits', 'API keys stay in the server .env file. ' . e($g('ai_last_test') ?: 'Not tested yet.'), '<button class="btn sm">Save</button>') ?>
    <div style="padding:0 18px 18px">
      <div class="f2"><div class="field"><label for="am">AI model</label><input class="in" id="am" name="ai_model" value="<?= e($g('ai_model')) ?>" placeholder="<?= e(AiClient::defaultModel()) ?>"></div><div class="field"><label for="at">Processing depth</label><select class="in" id="at" name="ai_tier"><?php foreach (['quick' => 'Quick', 'standard' => 'Standard', 'deep' => 'Deep'] as $k => $l): ?><option value="<?= $k ?>"<?= $g('ai_tier', 'quick') === $k ? ' selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div></div>
      <div class="field"><label for="sl">Lecture language (auto = detect)</label><input class="in" id="sl" name="stt_language" value="<?= e($g('stt_language', 'auto')) ?>"></div>
      <div class="fgrid2"><?php foreach ($limits as $k => [$l, $d]): ?><div class="field"><label for="lim-<?= $k ?>"><?= e($l) ?></label><input class="in" id="lim-<?= $k ?>" type="number" min="0" name="<?= $k ?>" value="<?= e($g($k, $d)) ?>"></div><?php endforeach; ?></div>
      <p class="note">AI key: <?= AiClient::configured() ? '<span class="pill ok">Set</span>' : '<span class="pill bad">Missing (AI_API_KEY)</span>' ?></p></div></form>
</div>
<div class="rowact"><?= row_form($act, ['a' => 'ai_test'], 'Test AI connection', 'btn sm line') ?></div>
<form class="card dt" method="post" data-action="<?= $act ?>"><?= csrf_field() ?><input type="hidden" name="a" value="home"><?= dt_head('Home page', 'The school visitors see first in "Choose your school" and the past questions library. They can still pick another.', '<button class="btn sm">Save</button>') ?>
  <div style="padding:0 18px 18px"><div class="field"><label for="hs">Default school</label><select class="in" id="hs" name="home_school"><?= options($homeSchools, $homeNow ?: null, 'id', 'name', 'None: visitors choose a school themselves') ?></select></div></div></form>
<form class="card dt" method="post" data-action="<?= $act ?>"><?= csrf_field() ?><input type="hidden" name="a" value="ai_features"><?= dt_head('PrepDeck AI features', 'What the AI does in every school. School admins cannot change these.', '<button class="btn sm">Save</button>') ?>
  <div style="padding:0 18px 18px"><div class="fgrid2"><?php foreach ($ai as $k => [$l, $d]): ?><label class="chk"><input type="checkbox" name="<?= $k ?>" value="1"<?= $g($k, $d) === '1' ? ' checked' : '' ?>><?= e($l) ?></label><?php endforeach; ?></div>
    <div class="fgrid2"><?php foreach ($aiNums as $k => [$l, $d]): ?><div class="field"><label for="ain-<?= $k ?>"><?= e($l) ?></label><input class="in" id="ain-<?= $k ?>" type="number" min="0" name="<?= $k ?>" value="<?= e($g($k, $d)) ?>"></div><?php endforeach; ?></div>
    <div class="field"><label for="air">Extra rules for the AI (every school)</label><textarea class="in" id="air" name="ai_rules" rows="3"><?= e($g('ai_rules')) ?></textarea></div></div></form>
<form class="card dt" method="post" data-action="<?= $act ?>"><?= csrf_field() ?><input type="hidden" name="a" value="defaults"><?= dt_head('School defaults', 'What every school has until its admin (or you, in Schools > Switches) changes it.', '<button class="btn sm">Save defaults</button>') ?>
  <div style="padding:0 18px 18px"><div class="fgrid2"><?php foreach ($defaults as $k => $l): ?><label class="chk"><input type="checkbox" name="<?= $k ?>" value="1"<?= $g($k, in_array($k, $defOn, true) ? '1' : '0') === '1' ? ' checked' : '' ?>><span><?= e($l) ?><?= !empty($overrides[$k]) ? ' <small class="note">(' . (int)$overrides[$k] . ' school' . ($overrides[$k] == 1 ? '' : 's') . ' set their own)</small>' : '' ?></span></label><?php endforeach; ?></div>
    <div class="f2"><div class="field"><label for="dp">Plan for new schools</label><select class="in" id="dp" name="school_default_plan"><?php foreach (['trial', 'basic', 'standard', 'premium'] as $p): ?><option value="<?= $p ?>"<?= $g('school_default_plan', 'basic') === $p ? ' selected' : '' ?>><?= ucfirst($p) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label for="dy">Academic year label</label><input class="in" id="dy" name="year_label" value="<?= e($g('year_label')) ?>"></div></div>
    <div class="field"><label for="ds">Semester label</label><input class="in" id="ds" name="semester_label" value="<?= e($g('semester_label')) ?>"></div></div></form>
<?php include ROOT . '/includes/dashboard_footer.php'; ?>
