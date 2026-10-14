-- PrepDeck: upgrade for the database you already have (your phpMyAdmin dump).
-- Safe to run more than once. In phpMyAdmin: select the `prepdeck` database > SQL tab > paste > Go.
-- Adds: lecture-recording columns, the 'recording' source type, notes.course_id, paper_views, game_scores, new settings.

DELIMITER $$
DROP PROCEDURE IF EXISTS ensure_column$$
CREATE PROCEDURE ensure_column (IN tbl VARCHAR(64), IN col VARCHAR(64), IN def TEXT)
BEGIN
  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = tbl AND column_name = col) THEN
    SET @sql = CONCAT('ALTER TABLE `', tbl, '` ADD COLUMN ', def);
    PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
  END IF;
END$$
DROP PROCEDURE IF EXISTS ensure_index$$
CREATE PROCEDURE ensure_index (IN tbl VARCHAR(64), IN idx VARCHAR(64), IN def TEXT)
BEGIN
  IF NOT EXISTS (SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = tbl AND index_name = idx) THEN
    SET @sql = CONCAT('ALTER TABLE `', tbl, '` ADD ', def);
    PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
  END IF;
END$$
DELIMITER ;

-- lecture recordings: audio + transcript + what was built from it
-- live AI activity log shown while documents are processed
CALL ensure_column('processing_jobs','events','`events` mediumtext DEFAULT NULL');
CALL ensure_column('processing_jobs','stats','`stats` text DEFAULT NULL');
CALL ensure_column('recordings','department_id','`department_id` int(10) UNSIGNED DEFAULT NULL');
CALL ensure_column('recordings','original_name','`original_name` varchar(190) DEFAULT NULL');
CALL ensure_column('recordings','format','`format` varchar(10) DEFAULT NULL');
CALL ensure_column('recordings','size_bytes','`size_bytes` int(10) UNSIGNED NOT NULL DEFAULT 0');
CALL ensure_column('recordings','language','`language` varchar(30) NOT NULL DEFAULT ''English''');
CALL ensure_column('recordings','stt_status','`stt_status` enum(''none'',''queued'',''running'',''ready'',''failed'') NOT NULL DEFAULT ''none''');
CALL ensure_column('recordings','stt_confidence','`stt_confidence` tinyint(3) UNSIGNED DEFAULT NULL');
CALL ensure_column('recordings','word_count','`word_count` int(10) UNSIGNED NOT NULL DEFAULT 0');
CALL ensure_column('recordings','transcript_edited_by','`transcript_edited_by` int(10) UNSIGNED DEFAULT NULL');
CALL ensure_column('recordings','ai_status','`ai_status` enum(''none'',''queued'',''running'',''done'',''failed'') NOT NULL DEFAULT ''none''');
CALL ensure_column('recordings','visibility','`visibility` enum(''private'',''course'') NOT NULL DEFAULT ''private''');
CALL ensure_column('recordings','status','`status` enum(''draft'',''published'',''hidden'') NOT NULL DEFAULT ''draft''');
CALL ensure_column('recordings','audio_deleted_at','`audio_deleted_at` datetime DEFAULT NULL');
CALL ensure_column('recordings','error','`error` varchar(255) DEFAULT NULL');
ALTER TABLE `processing_jobs` MODIFY `source_type` enum('paper','material','recording') NOT NULL;
ALTER TABLE `exam_questions`  MODIFY `source_type` enum('paper','material','recording') NOT NULL;
CALL ensure_column('decks','recording_id','`recording_id` int(10) UNSIGNED DEFAULT NULL');
CALL ensure_column('study_guides','recording_id','`recording_id` int(10) UNSIGNED DEFAULT NULL');
CALL ensure_index('decks','idx_decks_recording','KEY `idx_decks_recording` (`recording_id`)');
CALL ensure_index('study_guides','idx_guides_recording','KEY `idx_guides_recording` (`recording_id`)');

-- remember which billing period a payment was for (so confirming it activates the right Plus length)
CALL ensure_column('transactions','period','`period` enum(''month'',''semester'',''year'') DEFAULT NULL');

-- notes can be tagged to a course
CALL ensure_column('notes','course_id','`course_id` int(10) UNSIGNED DEFAULT NULL');

-- what students opened and how they scored (drives "continue", progress, top papers)
CREATE TABLE IF NOT EXISTS `paper_views` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` int(10) UNSIGNED NOT NULL,
  `paper_id` int(10) UNSIGNED NOT NULL,
  `viewed_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_pv_user` (`user_id`,`viewed_at`),
  KEY `idx_pv_paper` (`paper_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `game_scores` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` int(10) UNSIGNED NOT NULL,
  `game_code` varchar(30) NOT NULL,
  `deck_id` int(10) UNSIGNED DEFAULT NULL,
  `score` int(11) NOT NULL DEFAULT 0,
  `seconds` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_gs_user` (`user_id`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- settings (only added when missing, never overwritten)
INSERT IGNORE INTO `site_settings` (`k`,`v`) VALUES
 ('rec_max_mb','200'),('rec_max_min_free','0'),('rec_max_min_plus','180'),('rec_keep_audio_days','90'),('stt_language','auto'),
 ('mat_max','10'),('mat_mb','15'),('ai_tier','quick'),('ai_rules','Be accurate and concise. Explain in simple English. Use the course material first.'),
 ('banner_on','0'),('banner_text',''),('contact_email',''),('contact_phone',''),('social_instagram',''),('social_x',''),('social_whatsapp',''),
 ('hero_title','Walk into the exam having already seen it'),('hero_sub','Past questions, flashcards and study guides for every course.'),
 ('ai_quiz','1'),('ai_cards','1'),('ai_guide','1'),('year_label','2026/2027'),('semester_label','Semester 1');

-- Staff scoping and per-user permission grants (class reps, lecturers)
CREATE TABLE IF NOT EXISTS `staff_courses` (
  `user_id` int(10) UNSIGNED NOT NULL,
  `course_id` int(10) UNSIGNED NOT NULL,
  `assigned_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`user_id`,`course_id`),
  KEY `idx_sc_course` (`course_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `user_permissions` (
  `user_id` int(10) UNSIGNED NOT NULL,
  `permission_id` smallint(5) UNSIGNED NOT NULL,
  PRIMARY KEY (`user_id`,`permission_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO `site_settings` (`k`,`v`) VALUES ('rep_plus','0'),('lecturer_plus','1'),('lecturer_autopublish','1'),('rep_autopublish','0');

-- 'review' = approve papers and AI answers, but only for the courses an admin assigned to this person
INSERT IGNORE INTO `permissions` (`id`,`code`) VALUES (11,'review');
INSERT IGNORE INTO `role_permissions` (`role_id`,`permission_id`) VALUES (3,11),(4,11);

-- 'courses' = add courses to the school (school admins, lecturers and class reps)
INSERT IGNORE INTO `permissions` (`id`,`code`) VALUES (12,'courses');
INSERT IGNORE INTO `role_permissions` (`role_id`,`permission_id`) VALUES (2,12),(3,12),(4,12);

-- practice exams (self-marked, timed)
CREATE TABLE IF NOT EXISTS `practice_attempts` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` int(10) UNSIGNED NOT NULL,
  `source_type` enum('paper','material','recording') NOT NULL,
  `source_id` int(10) UNSIGNED NOT NULL,
  `total` smallint(5) UNSIGNED NOT NULL DEFAULT 0,
  `correct` smallint(5) UNSIGNED NOT NULL DEFAULT 0,
  `seconds` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `practice_attempts_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
