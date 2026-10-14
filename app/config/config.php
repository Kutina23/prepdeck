<?php
// Reads .env once. Nothing secret lives in this file.
function env_load(string $path): void {
    if (!is_file($path)) return;
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
        [$k, $v] = explode('=', $line, 2);
        $_ENV[trim($k)] = trim($v, " \t\"'");
    }
}
function env(string $k, $default = null) { $v = $_ENV[$k] ?? getenv($k); return ($v === false || $v === null || $v === '') ? $default : $v; }

env_load(dirname(__DIR__, 2) . '/.env');
$base = '/' . trim((string)env('APP_BASE', '/prepdeck/public/'), '/') . '/';
if ($base === '//') $base = '/';

return [
    'db'   => ['host' => env('DB_HOST', '127.0.0.1'), 'name' => env('DB_NAME', 'prepdeck'), 'user' => env('DB_USER', 'root'), 'pass' => env('DB_PASS', '')],
    'base' => $base,
    'debug' => env('APP_ENV', 'production') === 'development',
    'upload_dir' => dirname(__DIR__, 2) . '/storage/uploads',
    'max_pdf_bytes' => 15 * 1024 * 1024,
];
