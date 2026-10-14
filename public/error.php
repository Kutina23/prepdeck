<?php
// Friendly error page (Apache ErrorDocument and the app's exception handler). No database: it must work when everything else is down.
$code = (int)($code ?? $_GET['code'] ?? 404); if (!in_array($code, [403, 404, 500, 503], true)) $code = 404;
if (!headers_sent()) { http_response_code($code); header('Content-Type: text/html; charset=utf-8'); }
try { $base = ($GLOBALS['cfg'] ?? (require __DIR__ . '/../app/config/config.php'))['base'] ?? '/'; } catch (Throwable $e) { $base = '/'; }
$copy = [403 => ['That page is private', 'You do not have access to this page. If you think you should, sign in with the right account or ask an admin.'],
         404 => ['We could not find that page', 'The link may be old or mistyped. The paper or deck may also have been removed.'],
         500 => ['Something went wrong on our side', 'The error has been logged. Please try again in a minute.'],
         503 => ['Back soon', 'PrepDeck is down for maintenance. Please try again in a little while.']][$code];
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex">
<title><?= $h($copy[0]) ?> · PrepDeck</title>
<style>
:root{--bg:#F2F7F5;--ink:#0D2B2E;--muted:#4A6468;--lemon:#FFD23F;--pop:#FF7A1A}
@media(prefers-color-scheme:dark){:root{--bg:#071618;--ink:#E8F4F1;--muted:#9BB7B3}}
body{margin:0;min-height:100vh;display:grid;place-items:center;background:var(--bg);color:var(--ink);font:16px/1.55 system-ui,-apple-system,"Segoe UI",sans-serif;padding:24px;box-sizing:border-box}
main{max-width:520px;text-align:center}
.n{font:800 clamp(80px,20vw,140px)/1 system-ui;letter-spacing:-.05em;color:var(--pop)}
h1{font-size:28px;line-height:1.15;margin:8px 0 10px}p{color:var(--muted);margin:0 0 24px}
a{display:inline-flex;align-items:center;min-height:46px;padding:0 22px;border-radius:999px;border:2px solid #0D2B2E;background:var(--lemon);color:#0D2B2E;font-weight:600;text-decoration:none;margin:4px}
a.l{background:transparent;color:var(--ink);border-color:var(--ink)}
</style></head>
<body><main><div class="n"><?= $code ?></div><h1><?= $h($copy[0]) ?></h1><p><?= $h($copy[1]) ?></p>
<a href="<?= $h($base) ?>">Go to the home page</a><a class="l" href="<?= $h($base) ?>dashboard/">My dashboard</a></main></body></html>
