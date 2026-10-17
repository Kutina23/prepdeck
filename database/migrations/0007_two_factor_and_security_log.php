<?php
// Two-step sign-in (authenticator app codes + one-time recovery codes) for every account, and a security log of
// sign-ins, failures, lockouts and account-security changes that school admins and the platform owner can read.
return function (PDO $db): void {
    mig_add_column('users', 'twofa_secret', 'varchar(255) DEFAULT NULL');            // older installs never had it
    if (mig_has_column('users', 'twofa_secret')) $db->exec('ALTER TABLE `users` MODIFY `twofa_secret` varchar(255) DEFAULT NULL');   // room for the encrypted form
    mig_add_column('users', 'twofa_enabled_at', 'datetime DEFAULT NULL');
    mig_add_column('users', 'twofa_last_step', 'bigint(20) UNSIGNED DEFAULT NULL');  // newest code used, so a code cannot be replayed

    $db->exec("CREATE TABLE IF NOT EXISTS `user_recovery_codes` (
      `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
      `user_id` int(10) UNSIGNED NOT NULL,
      `code_hash` char(64) NOT NULL,
      `used_at` datetime DEFAULT NULL,
      `created_at` datetime NOT NULL DEFAULT current_timestamp(),
      PRIMARY KEY (`id`), KEY `user_id` (`user_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $db->exec("CREATE TABLE IF NOT EXISTS `security_log` (
      `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
      `school_id` int(10) UNSIGNED DEFAULT NULL,
      `user_id` int(10) UNSIGNED DEFAULT NULL,
      `email` varchar(190) DEFAULT NULL,
      `event` varchar(40) NOT NULL,
      `severity` enum('info','warning','critical') NOT NULL DEFAULT 'info',
      `detail` varchar(255) DEFAULT NULL,
      `ip` varchar(45) DEFAULT NULL,
      `user_agent` varchar(255) DEFAULT NULL,
      `created_at` datetime NOT NULL DEFAULT current_timestamp(),
      PRIMARY KEY (`id`), KEY `school_id` (`school_id`,`id`), KEY `user_id` (`user_id`,`id`), KEY `event` (`event`,`created_at`), KEY `created_at` (`created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
};
