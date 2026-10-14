<?php
// Link from the reset email: check it, then save a new password.
require __DIR__ . '/../app/bootstrap.php';
if (Auth::user()) redirect('dashboard/');
$token = (string)($_GET['token'] ?? '');
$resetError = ''; $resetToken = '';
if ($token === '' || strlen($token) !== 64) {
    $resetError = 'That reset link is not valid.';
} else {
    // the token is stored hashed, the link itself is only ever in the email
    $r = row('SELECT pr.id, pr.user_id FROM password_resets pr JOIN users u ON u.id=pr.user_id
              WHERE pr.token_hash=? AND pr.used_at IS NULL AND pr.expires_at>NOW() LIMIT 1', [hash('sha256', $token)]);
    if (!$r) $resetError = 'This reset link has expired or was already used.';
    else $resetToken = $token;
}
$title = $resetToken !== '' ? 'Choose a new password' : 'Reset link problem';
$scripts = ['auth.js']; $initial = 'reset';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/auth_split.php';
include __DIR__ . '/../includes/footer.php';
