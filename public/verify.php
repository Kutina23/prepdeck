<?php
// Email confirmation link from the welcome / confirm-your-email message.
require __DIR__ . '/../app/bootstrap.php';
$raw = (string)($_GET['token'] ?? ''); $ok = false; $msg = 'This confirmation link is not valid. It may have been used already or replaced by a newer one.';
if (preg_match('/^[a-f0-9]{64}$/', $raw)) {
    $t = row("SELECT t.id,t.user_id,t.expires_at,t.used_at,u.email_verified_at FROM email_tokens t JOIN users u ON u.id=t.user_id WHERE t.token_hash=? AND t.purpose='verify'", [hash('sha256', $raw)]);
    if ($t && $t['email_verified_at']) { $ok = true; $msg = 'Your email address is already confirmed.'; }
    elseif ($t && !$t['used_at'] && strtotime($t['expires_at'] . ' UTC') > time()) {
        q('UPDATE users SET email_verified_at=NOW() WHERE id=?', [$t['user_id']]); q('UPDATE email_tokens SET used_at=NOW() WHERE id=?', [$t['id']]);
        Audit::log('confirmed email address', 'user', (int)$t['user_id']); $ok = true; $msg = 'Thanks, your email address is confirmed.';
    } elseif ($t) $msg = 'This confirmation link has expired. Sign in and press "Send a new link" on your dashboard.';
}
$title = $ok ? 'Email confirmed' : 'Link not valid';
include __DIR__ . '/../includes/header.php';
?>
<main class="legal" style="text-align:center;max-width:560px">
  <h1><?= e($title) ?></h1>
  <p style="margin:12px 0 24px"><?= e($msg) ?></p>
  <p><a class="btn pop" href="<?= e(url(Auth::user() ? 'dashboard/' : 'login.php')) ?>"><?= Auth::user() ? 'Go to my dashboard' : 'Sign in' ?></a></p>
</main>
<?php include __DIR__ . '/../includes/footer.php';
