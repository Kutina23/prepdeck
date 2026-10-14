<?php
final class Database {
    private static ?PDO $pdo = null;
    public static function pdo(): PDO {
        if (!self::$pdo) {
            $c = $GLOBALS['cfg']['db'];
            try {
                self::$pdo = new PDO("mysql:host={$c['host']};dbname={$c['name']};charset=utf8mb4", $c['user'], $c['pass'], [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,                 // real prepared statements = SQL injection protection
                ]);
                self::$pdo->exec("SET time_zone = '+00:00'");
            } catch (PDOException $e) {
                http_response_code(500); exit($GLOBALS['cfg']['debug'] ? 'Database error: ' . $e->getMessage() : 'The site is temporarily unavailable.');
            }
        }
        return self::$pdo;
    }
}
