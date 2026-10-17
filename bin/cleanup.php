<?php
// Run daily (cron): php bin/cleanup.php   -> deletes audio older than rec_keep_audio_days; transcripts and study material stay.
if (PHP_SAPI !== 'cli') exit('CLI only');
require_once __DIR__ . '/../app/bootstrap.php';
$days = (int)Settings::get('rec_keep_audio_days', 90);
if ($days <= 0) echo "Keeping audio forever.\n";
else {
    $st = db()->prepare("SELECT id,audio_path FROM recordings WHERE audio_path IS NOT NULL AND audio_deleted_at IS NULL AND created_at < NOW() - INTERVAL ? DAY"); $st->execute([$days]);
    $n = 0; foreach ($st->fetchAll() as $r) { @unlink(upload_path('audio', $r['audio_path']));
        db()->prepare('UPDATE recordings SET audio_deleted_at=NOW() WHERE id=?')->execute([$r['id']]); $n++; }
    echo "Removed audio from $n recordings.\n";
}

// password reset links that were used or have run out
$d = db()->exec("DELETE FROM password_resets WHERE used_at IS NOT NULL OR expires_at < NOW()");
echo "Deleted $d old password reset links.\n";

// rate-limit log older than two days (every limit looks back at most a day)
echo 'Deleted ' . Throttle::prune(2) . " old rate-limit entries.\n";

// security log: one year is kept
echo 'Deleted ' . SecurityLog::prune(365) . " old security log entries.\n";
