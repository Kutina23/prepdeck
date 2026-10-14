<?php
// Ask for a password reset link.
require __DIR__ . '/../app/bootstrap.php';
if (Auth::user()) redirect('dashboard/');
$title = 'Reset your password'; $scripts = ['auth.js']; $initial = 'forgot';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/auth_split.php';
include __DIR__ . '/../includes/footer.php';
