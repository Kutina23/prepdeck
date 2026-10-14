<?php
// School switcher in the dashboard header (staff who work in several schools, and the platform owner).
require __DIR__ . '/../app/bootstrap.php';
$u = require_login();
if (!is_post()) redirect('dashboard/');
form_guard();
$to = (int)($_POST['school_id'] ?? 0);
if (Tenant::switchTo($to)) { Audit::log('switched to school ' . $to, 'school', $to); flash('ok', 'Now working in ' . Tenant::name() . '.'); }
else flash('bad', 'You cannot open that school.');
$back = (string)($_POST['back'] ?? '');
// back to the same page, unless it was a record of the previous school (an ?id= page) or another site
if ($back === '' || !str_starts_with($back, cfg('base')) || str_contains($back, '//') || preg_match('/[?&](id|view)=/', $back)) $back = url(is_super($u) || Auth::can('admin') ? 'admin/' : 'dashboard/');
header('Location: ' . $back); exit;
