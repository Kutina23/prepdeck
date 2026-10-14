<?php
// php tests/render.php <userId> <page under public/> [query]  -> renders one page as that user, prints the HTML.
// Any PHP notice/warning/error goes to stderr, so the smoke test can fail on it. CLI only; tests/ is never served.
if (PHP_SAPI !== 'cli') exit('CLI only');
[$_, $uid, $page] = $argv; $qs = $argv[3] ?? '';
parse_str($qs, $_GET); $_SERVER += ['REQUEST_METHOD' => 'GET', 'HTTP_HOST' => 'localhost', 'REMOTE_ADDR' => '127.0.0.1'];
$_SERVER['REQUEST_URI'] = '/' . $page . ($qs ? "?$qs" : ''); $_SERVER['SCRIPT_NAME'] = '/' . $page;
session_id('t' . $uid . bin2hex(random_bytes(6))); @session_start(); $_SESSION['uid'] = (int)$uid;
set_error_handler(function ($no, $str, $file, $line) { if (!(error_reporting() & $no)) return true; fwrite(STDERR, "PHP[$no] $str @ " . basename($file) . ":$line\n"); return true; });
register_shutdown_function(function () { $e = error_get_last(); if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_COMPILE_ERROR, E_CORE_ERROR], true)) fwrite(STDERR, "FATAL {$e['message']} @ " . basename($e['file']) . ":{$e['line']}\n"); });
$root = dirname(__DIR__) . '/public/';
chdir($root . dirname($page));
require $root . $page;
