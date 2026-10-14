<?php
require __DIR__ . '/../app/bootstrap.php';
if (Auth::user()) redirect('dashboard/');
$title = 'Sign in'; $scripts = ['auth.js']; $initial = 'login';
if (isset($_GET['reset'])) $authNotice = 'Your password is updated. Sign in with your new password.';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/auth_split.php';
include __DIR__ . '/../includes/footer.php';
