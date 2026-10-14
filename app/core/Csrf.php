<?php
final class Csrf {
    public static function token(): string { return $_SESSION['csrf'] ??= bin2hex(random_bytes(32)); }
    public static function field(): string { return '<input type="hidden" name="_csrf" value="' . self::token() . '">'; }
    public static function valid(): bool { $t = $_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''; return is_string($t) && hash_equals(self::token(), $t); }
    /** JSON endpoints. 403, not Laravel's 419: Apache turns status codes it does not know into 500. */
    public static function check(): void { if (!self::valid()) Response::error('Session expired. Refresh the page and try again.', 403); }
    /** HTML form pages: bounce back with a message instead of JSON */
    public static function form(): void {
        if (!self::valid()) {
            if (is_ajax()) Response::error('Session expired. Refresh the page and try again.', 403);
            flash('bad', 'Your session expired. Please try again.');
            redirect_self();
        }
    }
}
