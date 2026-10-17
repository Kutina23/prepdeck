<?php
// Test mode (dashboard/test.php): one row per finished flashcard test, for the student's progress and the lecturer's set insights.
return function (PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS `deck_test_attempts` (
      `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
      `school_id` int(10) UNSIGNED DEFAULT NULL,
      `user_id` int(10) UNSIGNED NOT NULL,
      `deck_id` int(10) UNSIGNED NOT NULL,
      `mode` enum('test','learn') NOT NULL DEFAULT 'test',
      `total` smallint(5) UNSIGNED NOT NULL DEFAULT 0,
      `correct` smallint(5) UNSIGNED NOT NULL DEFAULT 0,
      `seconds` int(10) UNSIGNED NOT NULL DEFAULT 0,
      `created_at` datetime NOT NULL DEFAULT current_timestamp(),
      PRIMARY KEY (`id`), KEY `user_id` (`user_id`,`created_at`), KEY `deck_id` (`deck_id`),
      CONSTRAINT `deck_tests_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
      CONSTRAINT `deck_tests_deck` FOREIGN KEY (`deck_id`) REFERENCES `decks` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
};
