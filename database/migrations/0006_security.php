<?php
// Security: a rate-limit log that survives a new session. Sign-in, password reset, registration and the public forms
// count attempts here per IP address and per account, so dropping the session cookie no longer resets the limit.
return function (PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS `auth_throttle` (
      `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
      `k` char(64) NOT NULL,
      `at` datetime NOT NULL DEFAULT current_timestamp(),
      PRIMARY KEY (`id`), KEY `k_at` (`k`,`at`), KEY `at` (`at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
};
