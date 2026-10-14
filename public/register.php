<?php
require __DIR__ . '/../app/bootstrap.php';
if (Auth::user()) redirect('dashboard/');
$title = 'Create your account'; $scripts = ['auth.js']; $initial = 'register';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/auth_split.php';
include __DIR__ . '/../includes/footer.php';
