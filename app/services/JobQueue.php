<?php
final class JobQueue {
    public static function enqueue(string $type, int $sourceId, int $owner, string $file, string $kind = 'auto'): int {
        $school = (int)val('SELECT school_id FROM ' . self::table($type) . ' WHERE id=?', [$sourceId]) ?: sid();   // the job belongs to the school that owns the document
        db()->prepare('INSERT INTO processing_jobs(school_id,source_type,source_id,owner_id,file_path,requested_kind,events,stats) VALUES (?,?,?,?,?,?,?,?)')
            ->execute([$school, $type, $sourceId, $owner, $file, $kind, json_encode([['t' => time(), 'stage' => 'queued', 'msg' => 'Upload received. Waiting for PrepDeck AI to start']]), '{}']);
        $id = (int)db()->lastInsertId();
        self::setSourceStatus($type, $sourceId, 'queued');
        self::kick();
        return $id;
    }

    /** PHP command line binary. Under Apache PHP_BINARY is the web server, so look next to the PHP install instead. Override with PHP_CLI in .env. */
    public static function phpCli(): string {
        if ($p = env('PHP_CLI', '')) return $p;
        if (PHP_SAPI === 'cli' && PHP_BINARY) return PHP_BINARY;
        if ($ini = php_ini_loaded_file()) foreach (['php.exe', 'php'] as $n) if (is_file($p = dirname($ini) . DIRECTORY_SEPARATOR . $n)) return $p;   // XAMPP: C:/xampp/php/php.exe
        foreach ([PHP_BINDIR . '/php.exe', PHP_BINDIR . '/php', dirname(PHP_BINDIR) . '/php/php.exe', '/usr/bin/php', '/usr/local/bin/php'] as $c) if (is_file($c)) return $c;
        return 'php';
    }

    /** Start a background worker right away so students see progress in seconds. Production: also run bin/worker.php under supervisor/systemd/Task Scheduler. */
    public static function kick(): void {
        $php = self::phpCli(); $w = dirname(__DIR__, 2) . '/bin/worker.php';
        if (PHP_OS_FAMILY === 'Windows') {
            if (!function_exists('popen')) return;
            $h = @popen('start "prepdeck-worker" /B ' . escapeshellarg($php) . ' ' . escapeshellarg($w) . ' --once > NUL 2>&1', 'r');
            if ($h) pclose($h);
            return;
        }
        if (!function_exists('exec')) return;
        @exec(escapeshellarg($php) . ' ' . escapeshellarg($w) . ' --once > /dev/null 2>&1 &');
    }

    /** Process queued jobs until the queue is empty. Used by bin/worker.php and by api/jobs.php?a=run when the host cannot start background processes. */
    public static function runQueue(bool $once = true): int {
        $lock = fopen(sys_get_temp_dir() . '/prepdeck-worker.lock', 'c');
        if (!flock($lock, LOCK_EX | LOCK_NB)) return 0;              // another worker is already busy
        $n = 0;
        try {
            while (true) {
                $job = self::claim();
                if (!$job) { if ($once) break; sleep(3); continue; }
                $n++; self::runJob($job);
            }
        } finally { flock($lock, LOCK_UN); fclose($lock); }
        return $n;
    }

    public static function runJob(array $job): void {
        Tenant::force((int)($job['school_id'] ?? 0) ?: null);         // settings such as ai_autopublish are read for the job's school
        try { ($job['source_type'] === 'outline' ? new OutlineProcessor() : new DocumentProcessor())->run($job); self::notify((int)$job['id']); }
        catch (Throwable $e) {
            $msg = self::friendlyError($e->getMessage());
            $retry = $job['attempts'] + 1 < 2 && str_contains($e->getMessage(), 'retry');
            self::event((int)$job['id'], $retry ? 'queued' : 'failed', $retry ? 'The AI service is busy. Trying again shortly' : $msg, [], [], $retry ? 'info' : 'error');
            db()->prepare('UPDATE processing_jobs SET status=?, stage=?, message=?, error=?, finished_at=NOW() WHERE id=?')
                ->execute([$retry ? 'queued' : 'failed', $retry ? 'queued' : 'failed', mb_substr($msg, 0, 250), $e->getMessage(), $job['id']]);
            self::setSourceStatus($job['source_type'], (int)$job['source_id'], $retry ? 'queued' : 'failed');
            if (!$retry && $job['source_type'] === 'outline') q("UPDATE outline_topics SET note_status='failed' WHERE outline_id=? AND note_status IN ('queued','running')", [$job['source_id']]);   // frees the lecturer's quota
            error_log('[prepdeck-worker] job ' . $job['id'] . ': ' . $e->getMessage());
            if (!$retry) self::notify((int)$job['id']);
        } finally { Tenant::force(null); }
    }
    /** Email the uploader when a job ends (they may have closed the page). Never lets a mail problem fail the job. */
    private static function notify(int $id): void { try { Mailer::aiFinished($id); } catch (Throwable $e) { error_log('[prepdeck-mail] job ' . $id . ': ' . $e->getMessage()); } }

    /** Turn API errors into something a student or admin can act on. */
    public static function friendlyError(string $m): string {
        if (stripos($m, 'credit balance') !== false) return 'The AI account has run out of credit. An admin needs to add credit in the Anthropic Console (Plans & Billing).';
        if (stripos($m, 'AI_API_KEY is not set') !== false) return 'PrepDeck AI is not connected yet. An admin needs to add AI_API_KEY to the .env file.';
        if (stripos($m, 'invalid x-api-key') !== false || stripos($m, 'authentication') !== false) return 'The AI key was rejected. An admin needs to check AI_API_KEY in .env.';
        if (stripos($m, 'model') !== false && stripos($m, 'not found') !== false) return 'The AI model name is not recognised. The platform owner can change it in Platform settings.';
        return $m;
    }

    /** Take the oldest waiting job (or one whose worker died). Atomic without SKIP LOCKED, which MariaDB before 10.6 (XAMPP) does not support. */
    public static function claim(): ?array {
        for ($try = 0; $try < 5; $try++) {
            $j = db()->query("SELECT * FROM processing_jobs WHERE status='queued' OR (status='running' AND started_at < NOW() - INTERVAL 15 MINUTE AND attempts<3) ORDER BY id LIMIT 1")->fetch();
            if (!$j) return null;
            $st = db()->prepare("UPDATE processing_jobs SET status='running', attempts=attempts+1, started_at=NOW(), stage='starting', progress=2 WHERE id=? AND status=? AND attempts=?");
            $st->execute([$j['id'], $j['status'], $j['attempts']]);
            if ($st->rowCount() === 1) { self::event((int)$j['id'], 'starting', 'PrepDeck AI picked up your document'); return $j; }
        }
        return null;
    }

    /** Update the progress bar and add a line to the live activity feed. */
    public static function progress(int $id, string $stage, int $pct, string $msg = '', array $stats = [], array $items = []): void {
        db()->prepare('UPDATE processing_jobs SET stage=?, progress=?, message=? WHERE id=?')->execute([$stage, $pct, mb_substr($msg, 0, 250), $id]);
        if ($msg !== '' || $stats) self::event($id, $stage, $msg, $stats, $items);
    }

    /** Append to the job's activity feed (kept to the last 80 lines) and merge counters such as questions, cards, guides. */
    public static function event(int $id, string $stage, string $msg, array $stats = [], array $items = [], string $level = 'info'): void {
        try {
            $r = row('SELECT events,stats FROM processing_jobs WHERE id=?', [$id]); if (!$r) return;
            $ev = json_decode((string)$r['events'], true) ?: []; $st = json_decode((string)$r['stats'], true) ?: [];
            if ($msg !== '') $ev[] = array_filter(['t' => time(), 'stage' => $stage, 'msg' => mb_substr($msg, 0, 300), 'level' => $level !== 'info' ? $level : null,
                'items' => $items ? array_map(fn($i) => mb_strimwidth((string)$i, 0, 140, '…'), array_slice($items, 0, 6)) : null]);
            foreach ($stats as $k => $v) $st[$k] = $v;
            q('UPDATE processing_jobs SET events=?, stats=? WHERE id=?', [json_encode(array_slice($ev, -80), JSON_UNESCAPED_UNICODE), json_encode($st), $id]);
        } catch (Throwable $e) { /* the activity feed is a nice-to-have; never fail a job because of it */ }
    }

    public static function setSourceStatus(string $type, int $id, string $s): void {
        db()->prepare('UPDATE ' . self::table($type) . ' SET ai_status=? WHERE id=?')->execute([$s, $id]);
    }
    public static function table(string $type): string { return ['paper' => 'papers', 'material' => 'materials', 'recording' => 'recordings', 'outline' => 'course_outlines'][$type]; }
    /** column on decks / study_guides that points back to the source */
    public static function col(string $type): string { return ['paper' => 'paper_id', 'material' => 'material_id', 'recording' => 'recording_id'][$type]; }
}
