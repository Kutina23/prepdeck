<?php
// Email delivery log (with retry queue), address verification and a notification opt-out.
return function (PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS `email_log` (
      `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
      `user_id` int(10) UNSIGNED DEFAULT NULL,
      `to_email` varchar(254) NOT NULL,
      `subject` varchar(255) NOT NULL,
      `template` varchar(40) NOT NULL DEFAULT 'general',
      `kind` enum('transactional','notification') NOT NULL DEFAULT 'transactional',
      `status` enum('queued','sent','failed') NOT NULL DEFAULT 'queued',
      `attempts` tinyint(3) UNSIGNED NOT NULL DEFAULT 0,
      `error` varchar(500) DEFAULT NULL,
      `html` mediumtext DEFAULT NULL,
      `text` mediumtext DEFAULT NULL,
      `message_id` varchar(190) DEFAULT NULL,
      `created_at` datetime DEFAULT current_timestamp(),
      `sent_at` datetime DEFAULT NULL,
      PRIMARY KEY (`id`), KEY `status` (`status`,`attempts`), KEY `to_email` (`to_email`), KEY `created_at` (`created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    mig_add_column('users', 'email_verified_at', 'datetime DEFAULT NULL');
    mig_add_column('users', 'email_opt_out', 'tinyint(1) NOT NULL DEFAULT 0');
    $db->exec("CREATE TABLE IF NOT EXISTS `email_tokens` (
      `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
      `user_id` int(10) UNSIGNED NOT NULL,
      `purpose` enum('verify') NOT NULL DEFAULT 'verify',
      `token_hash` char(64) NOT NULL,
      `expires_at` datetime NOT NULL,
      `used_at` datetime DEFAULT NULL,
      `created_at` datetime DEFAULT current_timestamp(),
      PRIMARY KEY (`id`), UNIQUE KEY `token_hash` (`token_hash`), KEY `user_id` (`user_id`),
      CONSTRAINT `email_tokens_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    // accounts that already exist (staff, seeded users) count as verified so nobody is nagged retroactively
    $db->exec('UPDATE users SET email_verified_at=created_at WHERE email_verified_at IS NULL');
};
