<?php
// Platform owner: the public site every school shares: home page text, message bar, contact and social links, FAQ and legal pages.
require __DIR__ . '/../../app/bootstrap.php';
$u = require_super(); $platformArea = true; $active = 'p-site'; $title = 'Site content'; $scripts = ['forms.js'];
$keys = ['hero_title', 'hero_sub', 'banner_text', 'contact_email', 'contact_phone', 'social_whatsapp', 'social_instagram', 'social_x'];
if (is_post()) {
    form_guard(); $a = $_POST['a'] ?? ''; $id = (int)($_POST['id'] ?? 0);
    if ($a === 'site') { foreach ($keys as $k) Settings::set($k, mb_substr(trim((string)($_POST[$k] ?? '')), 0, 400)); Settings::set('banner_on', isset($_POST['banner_on']) ? '1' : '0'); Audit::log('edited site content', 'site'); flash('ok', 'Site content saved.'); }
    elseif ($a === 'faq_add') { $qq = Validator::str($_POST['question'] ?? '', 3, 255); $an = Validator::str($_POST['answer'] ?? '', 3, 3000); if ($qq && $an) { q('INSERT INTO faqs(question,answer,position) VALUES (?,?,?)', [$qq, $an, (int)val('SELECT COALESCE(MAX(position),0)+1 FROM faqs')]); flash('ok', 'Question added.'); } else flash('bad', 'Enter a question and an answer.'); }
    elseif ($a === 'faq_save') { $qq = Validator::str($_POST['question'] ?? '', 3, 255); $an = Validator::str($_POST['answer'] ?? '', 3, 3000); if ($qq && $an) { q('UPDATE faqs SET question=?, answer=?, position=? WHERE id=?', [$qq, $an, (int)$_POST['position'], $id]); flash('ok', 'Saved.'); } }
    elseif ($a === 'faq_delete') { q('DELETE FROM faqs WHERE id=?', [$id]); flash('ok', 'Deleted.'); }
    elseif ($a === 'page_save') { $slug = preg_replace('/[^a-z0-9-]/', '', strtolower((string)($_POST['slug'] ?? ''))); $t = Validator::str($_POST['title'] ?? '', 2, 120); if ($slug && $t) { q('INSERT INTO site_pages(slug,title,body) VALUES (?,?,?) ON DUPLICATE KEY UPDATE title=VALUES(title),body=VALUES(body)', [$slug, $t, (string)$_POST['body']]); Audit::log("edited page $slug", 'page'); flash('ok', 'Page saved.'); } }
    elseif ($a === 'page_delete') { q('DELETE FROM site_pages WHERE slug=?', [(string)($_POST['slug'] ?? '')]); flash('ok', 'Page deleted.'); }
    redirect_self_or_ajax();
}
$faqs = rows('SELECT * FROM faqs ORDER BY position,id'); $pages = rows('SELECT * FROM site_pages ORDER BY slug');
$tab = $_GET['tab'] ?? 'home'; $q = trim((string)($_GET['q'] ?? '')); $per = per_page(15); $page = page_no();
$act = 'platform/site.php';
include ROOT . '/includes/dashboard_header.php';
$plat = true;
echo page_head('Site content', 'The public site shared by every school: home page, message bar, contact links, FAQ and legal pages.', '<a class="btn sm line" href="' . e(url()) . '">View home page</a>');
?>
<div class="tabs" role="navigation"><?php foreach (['home' => 'Home & contact', 'faq' => 'FAQ', 'pages' => 'Legal pages'] as $k => $l): ?><a class="tab" href="?tab=<?= $k ?>" aria-selected="<?= $tab === $k ? 'true' : 'false' ?>"><?= $l ?></a><?php endforeach; ?></div>
 <?php if ($tab === 'home'): ?>
  <section class="card dt"><?= dt_head('Home page & contact', 'Shown on the public home page and footer for every school.', modal_btn('tpl-home', 'Edit home page', 'Edit', 'btn sm', 'edit', 'wide')) ?>
    <div class="dtwrap"><table class="dtable"><thead><tr><th>Field</th><th>Value</th></tr></thead><tbody>
    <?php foreach (['hero_title' => 'Headline', 'hero_sub' => 'Sub-headline', 'banner_text' => 'Message bar', 'contact_email' => 'Contact email', 'contact_phone' => 'Phone', 'social_whatsapp' => 'WhatsApp link', 'social_instagram' => 'Instagram link', 'social_x' => 'X (Twitter) link'] as $k => $l): ?>
      <tr><td class="dmain nw"><span class="dtt"><?= e($l) ?></span><?= $k === 'banner_text' ? '<span class="ts">' . (setting('banner_on', '0') === '1' ? 'Showing to students' : 'Hidden') . '</span>' : '' ?></td><td style="overflow-wrap:anywhere"><?= e(setting($k, '')) ?: '<span class="muted">Not set</span>' ?></td></tr><?php endforeach; ?>
    </tbody></table></div></section>
  <template id="tpl-home">
  <form class="card mform" method="post" data-action="<?= $act ?>"><?= csrf_field() ?><input type="hidden" name="a" value="site"><h2>Home page</h2>
  <div class="field"><label for="ht">Headline</label><input class="in" id="ht" name="hero_title" value="<?= e(setting('hero_title', '')) ?>"></div>
  <div class="field"><label for="hs">Sub-headline</label><textarea class="in" id="hs" name="hero_sub" rows="3"><?= e(setting('hero_sub', '')) ?></textarea></div>
  <label class="chk"><input type="checkbox" name="banner_on" value="1"<?= setting('banner_on', '0') === '1' ? ' checked' : '' ?>>Show a message bar to students</label>
  <div class="field"><label for="bt">Bar message</label><input class="in" id="bt" name="banner_text" value="<?= e(setting('banner_text', '')) ?>"></div>
  <h2>Contact &amp; social</h2><div class="f2"><div class="field"><label for="ce">Contact email</label><input class="in" id="ce" type="email" name="contact_email" value="<?= e(setting('contact_email', '')) ?>"></div><div class="field"><label for="cp">Phone</label><input class="in" id="cp" name="contact_phone" value="<?= e(setting('contact_phone', '')) ?>"></div></div>
  <div class="f2"><div class="field"><label for="sw">WhatsApp link</label><input class="in" id="sw" name="social_whatsapp" value="<?= e(setting('social_whatsapp', '')) ?>"></div><div class="field"><label for="si">Instagram link</label><input class="in" id="si" name="social_instagram" value="<?= e(setting('social_instagram', '')) ?>"></div></div>
  <div class="field"><label for="sx">X (Twitter) link</label><input class="in" id="sx" name="social_x" value="<?= e(setting('social_x', '')) ?>"></div><button class="btn">Save</button></form>
  </template>
 <?php elseif ($tab === 'faq'):
    [$list, $total] = page_slice($q !== '' ? array_values(array_filter($faqs, fn($f) => stripos($f['question'] . ' ' . $f['answer'], $q) !== false)) : $faqs, $per, $page); ?>
  <section class="card dt"><?= dt_head('Frequently asked questions', count($faqs) . ' questions · lower order shows first' . ($plat ? '' : ' · managed by the platform owner'), $plat ? modal_btn('tpl-faq-add', 'Add a question', 'Add a question', 'btn sm', 'plus') : '') ?>
    <form class="toolbar" method="get"><input type="hidden" name="tab" value="faq"><label class="search"><?= ic('search', 'i') ?><span class="vh">Search</span><input name="q" value="<?= e($q) ?>" type="search" placeholder="Search questions"></label><button class="btn sm">Search</button></form>
    <div class="dtwrap"><table class="dtable"><thead><tr><th class="num">Order</th><th>Question</th><th>Answer</th><th data-nosort><span class="vh">Actions</span></th></tr></thead><tbody>
    <?php foreach ($list as $f): ?><tr><td class="num"><?= (int)$f['position'] ?></td><td class="dmain"><span class="dtt"><?= e($f['question']) ?></span></td><td><span class="clip muted"><?= e(mb_strimwidth((string)$f['answer'], 0, 160, '…')) ?></span></td>
      <td class="act"><div class="rowact"><?php if ($plat): ?><?= modal_btn('tpl-faq' . $f['id'], 'Edit question', 'Edit', 'btn sm line', 'edit') ?><?= row_form($act, ['a' => 'faq_delete', 'id' => $f['id']], 'Delete', 'btn sm danger', 'Delete this question?') ?><?php endif; ?></div>
        <template id="tpl-faq<?= $f['id'] ?>">
        <form class="card mform" method="post" data-action="<?= $act ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $f['id'] ?>"><input type="hidden" name="a" value="faq_save"><h2>Edit question</h2>
          <div class="field"><label for="fq<?= $f['id'] ?>">Question</label><input class="in" id="fq<?= $f['id'] ?>" name="question" value="<?= e($f['question']) ?>" required></div>
          <div class="field"><label for="fa<?= $f['id'] ?>">Answer</label><textarea class="in" id="fa<?= $f['id'] ?>" name="answer" rows="5" required><?= e($f['answer']) ?></textarea></div>
          <div class="field"><label for="fp<?= $f['id'] ?>">Order</label><input class="in" id="fp<?= $f['id'] ?>" type="number" name="position" value="<?= (int)$f['position'] ?>"></div>
          <button class="btn" type="submit">Save</button></form>
        </template></td></tr>
    <?php endforeach; if (!$list) echo dt_empty(4, 'No questions yet.'); ?></tbody></table></div>
    <?= dt_foot($total, $per, $page, ['tab' => 'faq', 'q' => $q]) ?></section>
  <template id="tpl-faq-add">
  <form class="card mform" method="post" data-action="<?= $act ?>"><?= csrf_field() ?><input type="hidden" name="a" value="faq_add"><h2>Add a question</h2><div class="field"><label for="fq">Question</label><input class="in" id="fq" name="question" required></div><div class="field"><label for="fa">Answer</label><textarea class="in" id="fa" name="answer" rows="5" required></textarea></div><button class="btn">Add</button></form>
  </template>
 <?php else:
    [$list, $total] = page_slice($pages, $per, $page); ?>
  <section class="card dt"><?= dt_head('Legal pages', 'Terms, privacy and other pages linked from the footer.' . ($plat ? '' : ' Managed by the platform owner.'), $plat ? modal_btn('tpl-page-new', 'New page', 'New page', 'btn sm', 'plus', 'wide') : '') ?>
    <div class="dtwrap"><table class="dtable"><thead><tr><th>Title</th><th>Address</th><th>Updated</th><th data-nosort><span class="vh">Actions</span></th></tr></thead><tbody>
    <?php foreach ($list as $p): ?><tr><td class="dmain"><span class="dtt"><?= e($p['title']) ?></span></td><td class="nw"><span class="code">page.php?p=<?= e($p['slug']) ?></span></td><td class="nw muted" data-v="<?= e($p['updated_at']) ?>"><?= e(ago($p['updated_at'])) ?></td>
      <td class="act"><div class="rowact"><?php if ($plat) echo modal_btn('tpl-pg' . $p['slug'], 'Edit page', 'Edit', 'btn sm line', 'edit', 'wide'); ?><a class="btn sm line" href="<?= e(url('page.php?p=' . $p['slug'])) ?>">View</a><?php if ($plat) echo row_form($act, ['a' => 'page_delete', 'slug' => $p['slug']], 'Delete', 'btn sm danger', 'Delete this page?'); ?></div>
        <template id="tpl-pg<?= e($p['slug']) ?>">
        <form class="card mform" method="post" data-action="<?= $act ?>"><?= csrf_field() ?><input type="hidden" name="a" value="page_save"><h2>Edit page</h2>
        <div class="f2"><div class="field"><label for="psg">Address (letters, numbers, dashes)</label><input class="in" id="psg" name="slug" value="<?= e($p['slug']) ?>" readonly></div><div class="field"><label for="ptg">Title</label><input class="in" id="ptg" name="title" value="<?= e($p['title']) ?>" required></div></div>
        <div class="field"><label for="pbg">Text (blank line starts a new paragraph)</label><textarea class="in" id="pbg" name="body" rows="14"><?= e($p['body']) ?></textarea></div>
        <button class="btn" type="submit">Save page</button></form>
        </template></td></tr>
    <?php endforeach; if (!$list) echo dt_empty(4, 'No pages yet.'); ?></tbody></table></div>
    <?= dt_foot($total, $per, $page, ['tab' => 'pages']) ?></section>
  <template id="tpl-page-new">
  <form class="card mform" method="post" data-action="<?= $act ?>"><?= csrf_field() ?><input type="hidden" name="a" value="page_save"><h2>New page</h2>
  <div class="f2"><div class="field"><label for="psn">Address (letters, numbers, dashes)</label><input class="in" id="psn" name="slug" required pattern="[a-z0-9-]+"></div><div class="field"><label for="ptn">Title</label><input class="in" id="ptn" name="title" required></div></div>
  <div class="field"><label for="pbn">Text (blank line starts a new paragraph)</label><textarea class="in" id="pbn" name="body" rows="14"></textarea></div>
  <button class="btn">Save page</button></form>
  </template>
<?php endif; ?>
<?php include ROOT . '/includes/dashboard_footer.php'; ?>
