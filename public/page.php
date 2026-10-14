<?php
// Legal / info pages edited in Admin > Site content (terms, privacy, refund)
require __DIR__ . '/../app/bootstrap.php';
$pg = row('SELECT title,body,updated_at FROM site_pages WHERE slug=?', [preg_replace('/[^a-z0-9-]/', '', (string)($_GET['p'] ?? ''))]);
if (!$pg) { http_response_code(404); }
$title = $pg['title'] ?? 'Page not found';
include __DIR__ . '/../includes/header.php';
?>
<main class="legal">
  <p><a href="<?= e(url()) ?>">&larr; PrepDeck</a></p>
  <h1><?= e($pg['title'] ?? 'Page not found') ?></h1>
  <div class="body"><?= e($pg['body'] ?? 'This page does not exist.') ?></div>
  <?php if ($pg): ?><p class="note" style="margin-top:24px">Last updated <?= e(fdate($pg['updated_at'])) ?></p><?php endif; ?>
</main>
<?php include __DIR__ . '/../includes/footer.php';
