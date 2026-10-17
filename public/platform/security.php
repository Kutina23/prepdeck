<?php
// Platform owner: security events of every school, plus failed sign-ins for emails that have no account.
require __DIR__ . '/../../app/bootstrap.php';
$u = require_super(); $platformArea = true; $active = 'p-security'; $title = 'Platform security'; $scripts = [];
include ROOT . '/includes/dashboard_header.php';
$platform = true; include ROOT . '/includes/security_log_view.php';
include ROOT . '/includes/dashboard_footer.php';
