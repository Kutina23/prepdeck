<?php
// Uptime check for monitors (UptimeRobot, a load balancer...). Returns 200 when the database answers, 503 otherwise. No secrets.
// Anyone gets up/down. Queue and configuration details only for the platform owner, or a monitor sending HEALTH_TOKEN (?token= or X-Health-Token).
require __DIR__ . '/../../app/bootstrap.php';
header('Cache-Control: no-store');
$tok = (string)env('HEALTH_TOKEN', ''); $given = (string)($_SERVER['HTTP_X_HEALTH_TOKEN'] ?? ($_GET['token'] ?? ''));
$detail = ($tok !== '' && $given !== '' && hash_equals($tok, $given)) || is_super();
try {
    if (!$detail) { val('SELECT 1'); Response::ok(['status' => 'up', 'release' => trim((string)@file_get_contents(ROOT . '/RELEASE')) ?: 'dev', 'database' => 'ok', 'time' => gmdate('c')]); }   // the deploy checks status + release
    $q = row("SELECT SUM(status='queued') queued, SUM(status='running') running, SUM(status='failed' AND finished_at > NOW() - INTERVAL 1 DAY) failed_24h,
              TIMESTAMPDIFF(MINUTE, MIN(CASE WHEN status='queued' THEN created_at END), NOW()) oldest_queued_min FROM processing_jobs");
    Response::ok(['status' => 'up', 'release' => trim((string)@file_get_contents(ROOT . '/RELEASE')) ?: 'dev', 'time' => gmdate('c'), 'database' => 'ok', 'ai_configured' => AiClient::configured(), 'pdf_reader' => PdfText::has('pdftotext'),
        'queue' => ['queued' => (int)$q['queued'], 'running' => (int)$q['running'], 'failed_24h' => (int)$q['failed_24h'], 'oldest_queued_minutes' => $q['oldest_queued_min'] === null ? null : (int)$q['oldest_queued_min']]]);
} catch (Throwable $e) { http_response_code(503); echo json_encode(['ok' => false, 'status' => 'down', 'database' => 'error']); }
