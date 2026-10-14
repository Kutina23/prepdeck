<?php
final class Response {
    public static function json($data, int $code = 200): never {
        http_response_code($code); header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE); exit;
    }
    public static function error(string $msg, int $code = 400): never { self::json(['ok' => false, 'error' => $msg], $code); }
    public static function ok($data = []): never { self::json(['ok' => true] + (array)$data); }
    public static function body(): array {
        $raw = file_get_contents('php://input'); $j = json_decode($raw ?: '', true);
        return is_array($j) ? $j : $_POST;
    }
}
