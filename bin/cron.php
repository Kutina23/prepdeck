<?php
// Run every 15 minutes (crontab):  every-15-min  php /path/to/prepdeck/bin/cron.php
//  - turns lapsed Plus members back to Free
//  - processes any AI jobs still waiting in the queue
//  - runs the daily cleanup (old recording audio) once a day
// Windows (XAMPP): Task Scheduler, every 15 minutes:  C:/xampp/php/php.exe C:/xampp/htdocs/prepdeck/bin/cron.php
if (PHP_SAPI !== 'cli') exit('CLI only');
require_once __DIR__ . '/../app/bootstrap.php';
echo 'Lapsed Plus members switched to Free: ', Billing::sweep(), PHP_EOL;
echo 'Emails re-sent: ', Mailer::retryQueued(), PHP_EOL;
echo 'AI jobs processed: ', JobQueue::runQueue(true), PHP_EOL;   // safety net if an upload could not start a worker (the lock stops overlapping runs)
$flag = ROOT . '/storage/.cleanup-' . gmdate('Ymd');
if (!is_file($flag)) { foreach (glob(ROOT . '/storage/.cleanup-*') ?: [] as $f) @unlink($f); @touch($flag); require __DIR__ . '/cleanup.php'; }
