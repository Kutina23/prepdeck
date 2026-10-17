<?php
// School admin: security events (sign-ins, failures, lockouts, two-step and password changes) for this school's members.
require __DIR__ . '/../../app/bootstrap.php';
$u = require_perm('admin'); $adminArea = true; $active = 'security'; $title = 'Security log'; $scripts = [];
include ROOT . '/includes/dashboard_header.php';
$platform = false; include ROOT . '/includes/security_log_view.php';
include ROOT . '/includes/dashboard_footer.php';
