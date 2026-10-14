<?php
// Live AI activity feed on processing jobs, and self-marked practice exams.
return function (PDO $db): void {
    mig_add_column('processing_jobs', 'events', 'mediumtext DEFAULT NULL');
    mig_add_column('processing_jobs', 'stats', 'text DEFAULT NULL');
    $db->exec("CREATE TABLE IF NOT EXISTS `practice_attempts` (
      `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
      `user_id` int(10) UNSIGNED NOT NULL,
      `source_type` enum('paper','material','recording') NOT NULL,
      `source_id` int(10) UNSIGNED NOT NULL,
      `total` smallint(5) UNSIGNED NOT NULL DEFAULT 0,
      `correct` smallint(5) UNSIGNED NOT NULL DEFAULT 0,
      `seconds` int(10) UNSIGNED NOT NULL DEFAULT 0,
      `created_at` datetime DEFAULT current_timestamp(),
      PRIMARY KEY (`id`), KEY `user_id` (`user_id`),
      CONSTRAINT `practice_attempts_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
};
