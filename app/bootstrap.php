<?php
declare(strict_types=1);
define('ROOT', dirname(__DIR__));
$GLOBALS['cfg'] = require __DIR__ . '/config/config.php';
foreach (['Database','Auth','Csrf','Response','Validator','Audit','Settings','Tenant','Throttle','Totp','SecurityLog'] as $c) require __DIR__ . "/core/$c.php";
foreach (['PdfText','Transcriber','AiClient','Prompts','JobQueue','DocumentProcessor','Billing','Paystack','Mailer','Lessons','Stats','Study','Community'] as $c) require __DIR__ . "/services/$c.php";
require __DIR__ . '/middleware/guards.php';
require __DIR__ . '/helpers.php';
require __DIR__ . '/icons.php';
require __DIR__ . '/ui.php';
ini_set('display_errors', $GLOBALS['cfg']['debug'] ? '1' : '0');
ini_set('log_errors', '1');
if (is_dir(ROOT . '/storage/logs') && is_writable(ROOT . '/storage/logs')) ini_set('error_log', ROOT . '/storage/logs/php-error.log');
// Production: log the real error, show a friendly page (or JSON for API calls) instead of a blank screen or a stack trace.
if (!$GLOBALS['cfg']['debug']) set_exception_handler(function (Throwable $e) {
    error_log('[prepdeck] ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    if (PHP_SAPI === 'cli') { fwrite(STDERR, $e->getMessage() . PHP_EOL); exit(1); }
    if (str_contains($_SERVER['REQUEST_URI'] ?? '', '/api/') || !empty($_SERVER['HTTP_X_REQUESTED_WITH'])) { if (!headers_sent()) { http_response_code(500); header('Content-Type: application/json'); } echo json_encode(['ok' => false, 'error' => 'Something went wrong. Please try again.']); exit; }
    $code = 500; require ROOT . '/public/error.php'; exit;
});
date_default_timezone_set('UTC');           // the database stores UTC; pages show relative times
Auth::start();
