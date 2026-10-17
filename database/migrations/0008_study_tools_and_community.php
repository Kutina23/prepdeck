<?php
// Advanced study tools (spaced-repetition review, daily goal, personal exam countdowns, lecturer study tasks) and the
// cross-school community messenger (profiles, friends, blocks, direct messages, reports).
return function (PDO $db): void {
    // --- spaced repetition: each card a person studies keeps its own schedule (SM-2 style)
    mig_add_column('card_progress', 'ease', 'decimal(4,2) NOT NULL DEFAULT 2.50');
    mig_add_column('card_progress', 'interval_days', 'smallint(5) UNSIGNED NOT NULL DEFAULT 0');
    mig_add_column('card_progress', 'reps', 'smallint(5) UNSIGNED NOT NULL DEFAULT 0');
    mig_add_column('card_progress', 'lapses', 'smallint(5) UNSIGNED NOT NULL DEFAULT 0');
    mig_add_column('card_progress', 'last_reviewed_at', 'datetime DEFAULT NULL');
    if (!mig_has_index('card_progress', 'user_due')) $db->exec('ALTER TABLE `card_progress` ADD KEY `user_due` (`user_id`,`due_at`)');
    mig_add_column('users', 'daily_card_goal', 'smallint(5) UNSIGNED NOT NULL DEFAULT 20');

    $db->exec("CREATE TABLE IF NOT EXISTS `card_reviews` (
      `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
      `user_id` int(10) UNSIGNED NOT NULL,
      `card_id` int(10) UNSIGNED NOT NULL,
      `rating` tinyint(3) UNSIGNED NOT NULL,
      `interval_days` smallint(5) UNSIGNED NOT NULL DEFAULT 0,
      `reviewed_at` datetime NOT NULL DEFAULT current_timestamp(),
      PRIMARY KEY (`id`), KEY `user_time` (`user_id`,`reviewed_at`), KEY `card_id` (`card_id`),
      CONSTRAINT `card_reviews_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
      CONSTRAINT `card_reviews_card` FOREIGN KEY (`card_id`) REFERENCES `cards` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $db->exec("CREATE TABLE IF NOT EXISTS `personal_exams` (
      `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
      `user_id` int(10) UNSIGNED NOT NULL,
      `school_id` int(10) UNSIGNED DEFAULT NULL,
      `course_id` int(10) UNSIGNED DEFAULT NULL,
      `title` varchar(160) NOT NULL,
      `exam_date` date NOT NULL,
      `created_at` datetime NOT NULL DEFAULT current_timestamp(),
      PRIMARY KEY (`id`), KEY `user_date` (`user_id`,`exam_date`),
      CONSTRAINT `personal_exams_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $db->exec("CREATE TABLE IF NOT EXISTS `lesson_reads` (
      `user_id` int(10) UNSIGNED NOT NULL,
      `lesson_id` int(10) UNSIGNED NOT NULL,
      `read_at` datetime NOT NULL DEFAULT current_timestamp(),
      PRIMARY KEY (`user_id`,`lesson_id`), KEY `lesson_id` (`lesson_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // --- lecturer study tasks: "review this deck / practise this paper / read this lesson by Friday"
    $db->exec("CREATE TABLE IF NOT EXISTS `study_tasks` (
      `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
      `school_id` int(10) UNSIGNED NOT NULL,
      `course_id` int(10) UNSIGNED NOT NULL,
      `created_by` int(10) UNSIGNED NOT NULL,
      `kind` enum('deck','paper','lesson','custom') NOT NULL DEFAULT 'custom',
      `target_id` int(10) UNSIGNED DEFAULT NULL,
      `title` varchar(160) NOT NULL,
      `instructions` varchar(1000) DEFAULT NULL,
      `due_on` date NOT NULL,
      `status` enum('active','archived') NOT NULL DEFAULT 'active',
      `created_at` datetime NOT NULL DEFAULT current_timestamp(),
      PRIMARY KEY (`id`), KEY `course` (`school_id`,`course_id`,`status`), KEY `created_by` (`created_by`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $db->exec("CREATE TABLE IF NOT EXISTS `study_task_done` (
      `task_id` int(10) UNSIGNED NOT NULL,
      `user_id` int(10) UNSIGNED NOT NULL,
      `auto` tinyint(1) NOT NULL DEFAULT 0,
      `done_at` datetime NOT NULL DEFAULT current_timestamp(),
      PRIMARY KEY (`task_id`,`user_id`), KEY `user_id` (`user_id`),
      CONSTRAINT `task_done_task` FOREIGN KEY (`task_id`) REFERENCES `study_tasks` (`id`) ON DELETE CASCADE,
      CONSTRAINT `task_done_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // --- community: one profile per person who joined, friendships, blocks, direct messages, reports
    $db->exec("CREATE TABLE IF NOT EXISTS `community_profiles` (
      `user_id` int(10) UNSIGNED NOT NULL,
      `display_name` varchar(60) NOT NULL,
      `bio` varchar(280) DEFAULT NULL,
      `discoverable` tinyint(1) NOT NULL DEFAULT 1,
      `joined_at` datetime NOT NULL DEFAULT current_timestamp(),
      `last_seen_at` datetime DEFAULT NULL,
      `banned_at` datetime DEFAULT NULL,
      `ban_reason` varchar(255) DEFAULT NULL,
      PRIMARY KEY (`user_id`), KEY `display_name` (`display_name`),
      CONSTRAINT `community_profiles_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $db->exec("CREATE TABLE IF NOT EXISTS `friendships` (
      `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
      `requester_id` int(10) UNSIGNED NOT NULL,
      `addressee_id` int(10) UNSIGNED NOT NULL,
      `status` enum('pending','accepted','declined') NOT NULL DEFAULT 'pending',
      `created_at` datetime NOT NULL DEFAULT current_timestamp(),
      `responded_at` datetime DEFAULT NULL,
      PRIMARY KEY (`id`), UNIQUE KEY `pair` (`requester_id`,`addressee_id`), KEY `addressee` (`addressee_id`,`status`),
      CONSTRAINT `friendships_req` FOREIGN KEY (`requester_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
      CONSTRAINT `friendships_addr` FOREIGN KEY (`addressee_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $db->exec("CREATE TABLE IF NOT EXISTS `user_blocks` (
      `user_id` int(10) UNSIGNED NOT NULL,
      `blocked_id` int(10) UNSIGNED NOT NULL,
      `created_at` datetime NOT NULL DEFAULT current_timestamp(),
      PRIMARY KEY (`user_id`,`blocked_id`), KEY `blocked_id` (`blocked_id`),
      CONSTRAINT `user_blocks_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
      CONSTRAINT `user_blocks_blocked` FOREIGN KEY (`blocked_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $db->exec("CREATE TABLE IF NOT EXISTS `messages` (
      `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
      `sender_id` int(10) UNSIGNED NOT NULL,
      `recipient_id` int(10) UNSIGNED NOT NULL,
      `body` text NOT NULL,
      `created_at` datetime NOT NULL DEFAULT current_timestamp(),
      `read_at` datetime DEFAULT NULL,
      `hidden_for_sender` tinyint(1) NOT NULL DEFAULT 0,
      `hidden_for_recipient` tinyint(1) NOT NULL DEFAULT 0,
      PRIMARY KEY (`id`), KEY `pair` (`sender_id`,`recipient_id`,`id`), KEY `inbox` (`recipient_id`,`read_at`),
      CONSTRAINT `messages_sender` FOREIGN KEY (`sender_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
      CONSTRAINT `messages_recipient` FOREIGN KEY (`recipient_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $db->exec("CREATE TABLE IF NOT EXISTS `community_reports` (
      `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
      `reporter_id` int(10) UNSIGNED NOT NULL,
      `target_id` int(10) UNSIGNED NOT NULL,
      `message_id` bigint(20) UNSIGNED DEFAULT NULL,
      `reason` varchar(500) NOT NULL,
      `status` enum('open','resolved') NOT NULL DEFAULT 'open',
      `resolution` varchar(40) DEFAULT NULL,
      `created_at` datetime NOT NULL DEFAULT current_timestamp(),
      `resolved_by` int(10) UNSIGNED DEFAULT NULL,
      `resolved_at` datetime DEFAULT NULL,
      PRIMARY KEY (`id`), KEY `status` (`status`,`id`), KEY `target_id` (`target_id`),
      CONSTRAINT `community_reports_reporter` FOREIGN KEY (`reporter_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
      CONSTRAINT `community_reports_target` FOREIGN KEY (`target_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
};
