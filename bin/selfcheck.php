<?php
// Run by the deploy script BEFORE a new release goes live. Exit code 0 = safe to switch.
if (PHP_SAPI !== 'cli') exit('CLI only');
$errors = [];
try {
    require __DIR__ . '/../app/bootstrap.php';
    db()->query('SELECT 1')->fetchColumn();
    if (!val("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='users'")) $errors[] = 'The database has no users table. Import database/prepdeck_fresh.sql first.';
} catch (Throwable $e) { $errors[] = 'Cannot boot the app or reach the database: ' . $e->getMessage(); }
foreach (['storage/uploads', 'storage/logs'] as $d) { $p = dirname(__DIR__) . "/$d"; if (!is_dir($p) || !is_writable($p)) $errors[] = "$d is missing or not writable"; }
foreach (['pdo_mysql', 'mbstring', 'openssl', 'curl', 'fileinfo'] as $x) if (!extension_loaded($x)) $errors[] = "PHP extension $x is not loaded";
if (version_compare(PHP_VERSION, '8.1', '<')) $errors[] = 'PHP 8.1 or newer is required (this is ' . PHP_VERSION . ')';
if ($errors) { fwrite(STDERR, "Self-check failed:\n - " . implode("\n - ", $errors) . "\n"); exit(1); }
echo 'Self-check OK (PHP ' . PHP_VERSION . ", database reachable)\n";
