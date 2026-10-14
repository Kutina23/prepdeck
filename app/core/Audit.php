<?php
final class Audit {
    public static function log(string $action, ?string $type = null, ?int $id = null): void {
        $u = Auth::user();
        db()->prepare('INSERT INTO audit_log(school_id,actor_id,action,target_type,target_id,ip) VALUES (?,?,?,?,?,?)')
            ->execute([Tenant::id() ?: null, $u['id'] ?? $_SESSION['uid'] ?? null, $action, $type, $id, $_SERVER['REMOTE_ADDR'] ?? null]);
    }
}
