<?php
// Notification emails carry List-Unsubscribe: a signed link (no login needed) and RFC 8058 one-click POST from Gmail/Yahoo.
// Only notification emails stop; password resets, confirmations and receipts are always sent.
require __DIR__ . '/../app/bootstrap.php';
$uid = (int)($_GET['u'] ?? 0); $tok = (string)($_GET['t'] ?? '');
$valid = $uid > 0 && hash_equals(Mailer::unsubToken($uid), $tok) && val('SELECT COUNT(*) FROM users WHERE id=?', [$uid]);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $valid) {
    $on = ($_POST['resubscribe'] ?? '') === '1';
    q('UPDATE users SET email_opt_out=? WHERE id=?', [$on ? 0 : 1, $uid]); Audit::log($on ? 'turned email notifications back on' : 'unsubscribed from notification emails', 'user', $uid);
    if (($_POST['List-Unsubscribe'] ?? '') === 'One-Click') { http_response_code(200); exit('Unsubscribed'); }   // mail client one-click: no page needed
    $done = $on ? 'on' : 'off';
}
$out = $valid ? (int)val('SELECT email_opt_out FROM users WHERE id=?', [$uid]) : 0;
$title = 'Email preferences';
include __DIR__ . '/../includes/header.php';
?>
<main class="legal" style="text-align:center;max-width:560px">
  <h1>Email preferences</h1>
  <?php if (!$valid): ?><p style="margin:12px 0 24px">This link is not valid. You can change email settings after signing in, in Settings.</p>
  <?php else: ?>
    <p style="margin:12px 0 24px"><?= isset($done) ? ($done === 'off' ? 'Done. You will not get notification emails any more.' : 'Notification emails are back on.') : ($out ? 'Notification emails are off for your account.' : 'Stop emails such as "your study material is ready"?') ?>
      <br><small class="note">Password resets, email confirmations and payment receipts are always sent.</small></p>
    <form method="post"><input type="hidden" name="resubscribe" value="<?= $out ? '1' : '0' ?>"><button class="btn <?= $out ? '' : 'pop' ?>" type="submit"><?= $out ? 'Turn notification emails back on' : 'Unsubscribe' ?></button></form>
  <?php endif; ?>
</main>
<?php include __DIR__ . '/../includes/footer.php';
