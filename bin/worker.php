<?php
// php bin/worker.php          -> run forever (use supervisor/systemd, or Windows Task Scheduler "at startup")
// php bin/worker.php --once   -> process queued jobs then exit (started automatically after every upload)
if (PHP_SAPI !== 'cli') exit('CLI only');
require __DIR__ . '/../app/bootstrap.php';
set_time_limit(0);
JobQueue::runQueue(in_array('--once', $argv, true));   // only one worker runs at a time (file lock), so a burst of uploads never spawns a swarm
