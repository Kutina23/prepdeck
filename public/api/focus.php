<?php
require __DIR__ . '/../../app/bootstrap.php';
$u = require_login(true); require_post(); $b = Response::body();
$max = is_plus($u) ? (int)setting('plus_max_timer', 180) : (int)setting('free_timer', 15);
$m = max(1, min((int)($b['minutes'] ?? 0), $max));          // server clamps: free members cannot log past their limit
q('INSERT INTO focus_sessions(school_id,user_id,minutes) VALUES (?,?,?)', [sid(), $u['id'], $m]); Response::ok();
