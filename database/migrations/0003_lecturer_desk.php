<?php
// Lecturer dashboard: course outlines (PDF) -> topics -> AI lesson notes (a Plus feature), and the admin settings that control it.
return function (PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS `course_outlines` (
      `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
      `user_id` int(10) UNSIGNED NOT NULL,
      `course_id` int(10) UNSIGNED NOT NULL,
      `title` varchar(190) NOT NULL,
      `file_path` varchar(255) NOT NULL,
      `size_bytes` int(10) UNSIGNED NOT NULL DEFAULT 0,
      `page_count` smallint(5) UNSIGNED DEFAULT NULL,
      `depth` enum('concise','standard','detailed') NOT NULL DEFAULT 'standard',
      `instructions` varchar(600) DEFAULT NULL,
      `summary` text DEFAULT NULL,
      `ai_status` enum('none','queued','running','done','failed') NOT NULL DEFAULT 'none',
      `created_at` datetime DEFAULT current_timestamp(),
      PRIMARY KEY (`id`), KEY `user_id` (`user_id`), KEY `course_id` (`course_id`),
      CONSTRAINT `course_outlines_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
      CONSTRAINT `course_outlines_ibfk_2` FOREIGN KEY (`course_id`) REFERENCES `courses` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $db->exec("CREATE TABLE IF NOT EXISTS `outline_topics` (
      `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
      `outline_id` int(10) UNSIGNED NOT NULL,
      `position` smallint(5) UNSIGNED NOT NULL DEFAULT 0,
      `week` varchar(40) DEFAULT NULL,
      `title` varchar(190) NOT NULL,
      `subtopics` text DEFAULT NULL,
      `objectives` text DEFAULT NULL,
      `note_status` enum('none','queued','running','done','failed') NOT NULL DEFAULT 'none',
      PRIMARY KEY (`id`), KEY `outline_id` (`outline_id`,`position`),
      CONSTRAINT `outline_topics_ibfk_1` FOREIGN KEY (`outline_id`) REFERENCES `course_outlines` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $db->exec("CREATE TABLE IF NOT EXISTS `lesson_notes` (
      `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
      `user_id` int(10) UNSIGNED NOT NULL,
      `course_id` int(10) UNSIGNED NOT NULL,
      `outline_id` int(10) UNSIGNED DEFAULT NULL,
      `topic_id` int(10) UNSIGNED DEFAULT NULL,
      `title` varchar(190) NOT NULL,
      `week` varchar(40) DEFAULT NULL,
      `body` mediumtext NOT NULL,
      `word_count` int(10) UNSIGNED NOT NULL DEFAULT 0,
      `status` enum('draft','published','hidden') NOT NULL DEFAULT 'draft',
      `ai_generated` tinyint(1) NOT NULL DEFAULT 1,
      `views` int(10) UNSIGNED NOT NULL DEFAULT 0,
      `tokens` int(10) UNSIGNED NOT NULL DEFAULT 0,
      `generated_at` datetime DEFAULT NULL,
      `created_at` datetime DEFAULT current_timestamp(),
      `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
      `published_at` datetime DEFAULT NULL,
      PRIMARY KEY (`id`), UNIQUE KEY `topic_id` (`topic_id`), KEY `user_id` (`user_id`,`generated_at`), KEY `course_id` (`course_id`,`status`), KEY `outline_id` (`outline_id`),
      CONSTRAINT `lesson_notes_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
      CONSTRAINT `lesson_notes_ibfk_2` FOREIGN KEY (`course_id`) REFERENCES `courses` (`id`) ON DELETE CASCADE,
      CONSTRAINT `lesson_notes_ibfk_3` FOREIGN KEY (`outline_id`) REFERENCES `course_outlines` (`id`) ON DELETE SET NULL,
      CONSTRAINT `lesson_notes_ibfk_4` FOREIGN KEY (`topic_id`) REFERENCES `outline_topics` (`id`) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    // outline jobs run through the same live queue as papers, notes and recordings
    $db->exec("ALTER TABLE `processing_jobs` MODIFY `source_type` enum('paper','material','recording','outline') NOT NULL");
    mig_add_column('processing_jobs', 'options', 'text DEFAULT NULL');
    foreach (['lesson_tools_on' => '1', 'lesson_quota' => '60', 'outline_max_topics' => '30', 'outline_max_pages' => '30', 'lesson_autopublish' => '0', 'lesson_students_plus' => '0'] as $k => $v)
        $db->prepare('INSERT IGNORE INTO site_settings(k,v) VALUES (?,?)')->execute([$k, $v]);
};
