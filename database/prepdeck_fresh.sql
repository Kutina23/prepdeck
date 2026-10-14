-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Oct 11, 2026 at 05:22 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `prepdeck`  (fresh install: structure + reference data. Create an empty database called prepdeck first.)
--

DELIMITER $$
--
-- Procedures
--
CREATE PROCEDURE `ensure_column` (IN `tbl` VARCHAR(64), IN `col` VARCHAR(64), IN `def` TEXT)   BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = tbl AND column_name = col
  ) THEN
    SET @sql = CONCAT('ALTER TABLE `', tbl, '` ADD COLUMN ', def);
    PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
  END IF;
END$$

CREATE PROCEDURE `ensure_index` (IN `tbl` VARCHAR(64), IN `idx` VARCHAR(64), IN `def` TEXT)   BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = tbl AND index_name = idx
  ) THEN
    SET @sql = CONCAT('ALTER TABLE `', tbl, '` ADD ', def);
    PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
  END IF;
END$$

DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `academic_terms`
--

CREATE TABLE `academic_terms` (
  `id` int(10) UNSIGNED NOT NULL,
  `year_label` varchar(20) NOT NULL,
  `semester` tinyint(4) NOT NULL,
  `starts_on` date DEFAULT NULL,
  `ends_on` date DEFAULT NULL,
  `is_current` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `ai_usage`
--

CREATE TABLE `ai_usage` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `kind` enum('chat','quiz','cards','guide') NOT NULL,
  `tokens` int(10) UNSIGNED DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `alert_subscribers`
--

CREATE TABLE `alert_subscribers` (
  `id` int(10) UNSIGNED NOT NULL,
  `email` varchar(190) NOT NULL,
  `department_id` int(10) UNSIGNED DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `announcements`
--

CREATE TABLE `announcements` (
  `id` int(10) UNSIGNED NOT NULL,
  `author_id` int(10) UNSIGNED NOT NULL,
  `title` varchar(160) NOT NULL,
  `body` text NOT NULL,
  `audience` enum('all','free','plus','department') NOT NULL DEFAULT 'all',
  `department_id` int(10) UNSIGNED DEFAULT NULL,
  `publish_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `audit_log`
--

CREATE TABLE `audit_log` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `actor_id` int(10) UNSIGNED DEFAULT NULL,
  `action` varchar(120) NOT NULL,
  `target_type` varchar(40) DEFAULT NULL,
  `target_id` int(10) UNSIGNED DEFAULT NULL,
  `ip` varchar(45) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- --------------------------------------------------------

--
-- Table structure for table `cards`
--

CREATE TABLE `cards` (
  `id` int(10) UNSIGNED NOT NULL,
  `deck_id` int(10) UNSIGNED NOT NULL,
  `front` text NOT NULL,
  `back` text NOT NULL,
  `position` smallint(6) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `card_progress`
--

CREATE TABLE `card_progress` (
  `user_id` int(10) UNSIGNED NOT NULL,
  `card_id` int(10) UNSIGNED NOT NULL,
  `known` tinyint(1) NOT NULL DEFAULT 0,
  `due_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `coupons`
--

CREATE TABLE `coupons` (
  `id` int(10) UNSIGNED NOT NULL,
  `code` varchar(30) NOT NULL,
  `percent_off` tinyint(3) UNSIGNED NOT NULL,
  `max_uses` int(10) UNSIGNED DEFAULT NULL,
  `used_count` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `expires_at` date DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `courses`
--

CREATE TABLE `courses` (
  `id` int(10) UNSIGNED NOT NULL,
  `department_id` int(10) UNSIGNED NOT NULL,
  `code` varchar(20) NOT NULL,
  `title` varchar(160) NOT NULL,
  `level` smallint(6) DEFAULT NULL,
  `paper_count` int(10) UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `decks`
--

CREATE TABLE `decks` (
  `id` int(10) UNSIGNED NOT NULL,
  `owner_id` int(10) UNSIGNED NOT NULL,
  `course_id` int(10) UNSIGNED DEFAULT NULL,
  `title` varchar(160) NOT NULL,
  `source` enum('paper','notes','manual','ai') NOT NULL DEFAULT 'manual',
  `visibility` enum('private','shared') NOT NULL DEFAULT 'private',
  `status` enum('active','hidden') NOT NULL DEFAULT 'active',
  `created_at` datetime DEFAULT current_timestamp(),
  `contains_answers` tinyint(1) NOT NULL DEFAULT 0,
  `paper_id` int(10) UNSIGNED DEFAULT NULL,
  `material_id` int(10) UNSIGNED DEFAULT NULL,
  `ai_generated` tinyint(1) NOT NULL DEFAULT 0,
  `card_count` smallint(5) UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `departments`
--

CREATE TABLE `departments` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(120) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `departments`
--

INSERT INTO `departments` (`id`, `name`, `is_active`) VALUES
(1, 'Computer Science', 1),
(2, 'Nursing', 1),
(3, 'Business Admin', 1),
(4, 'Engineering', 1),
(5, 'Biological Sciences', 1);

-- --------------------------------------------------------

--
-- Table structure for table `exam_questions`
--

CREATE TABLE `exam_questions` (
  `id` int(10) UNSIGNED NOT NULL,
  `source_type` enum('paper','material') NOT NULL,
  `source_id` int(10) UNSIGNED NOT NULL,
  `kind` enum('past','predicted') NOT NULL,
  `number` varchar(12) DEFAULT NULL,
  `question` mediumtext NOT NULL,
  `answer` mediumtext NOT NULL,
  `topic` varchar(120) DEFAULT NULL,
  `marks` tinyint(3) UNSIGNED DEFAULT NULL,
  `difficulty` enum('easy','medium','hard') DEFAULT NULL,
  `confidence` enum('high','medium','low') NOT NULL DEFAULT 'medium',
  `status` enum('draft','published','hidden') NOT NULL DEFAULT 'draft',
  `edited_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `exam_timetable`
--

CREATE TABLE `exam_timetable` (
  `id` int(10) UNSIGNED NOT NULL,
  `course_id` int(10) UNSIGNED NOT NULL,
  `term_id` int(10) UNSIGNED NOT NULL,
  `exam_date` date NOT NULL,
  `exam_time` time DEFAULT NULL,
  `venue` varchar(120) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `faqs`
--

CREATE TABLE `faqs` (
  `id` int(10) UNSIGNED NOT NULL,
  `question` varchar(255) NOT NULL,
  `answer` text NOT NULL,
  `position` smallint(6) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `focus_sessions`
--

CREATE TABLE `focus_sessions` (
  `id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `minutes` smallint(6) NOT NULL,
  `started_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `games`
--

CREATE TABLE `games` (
  `id` int(10) UNSIGNED NOT NULL,
  `code` varchar(30) NOT NULL,
  `name` varchar(80) NOT NULL,
  `is_plus` tinyint(1) NOT NULL DEFAULT 0,
  `is_enabled` tinyint(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `games`
--

INSERT INTO `games` (`id`, `code`, `name`, `is_plus`, `is_enabled`) VALUES
(1, 'match', 'Match', 0, 1),
(2, 'scatter', 'Scatter', 1, 1);

-- --------------------------------------------------------

--
-- Table structure for table `materials`
--

CREATE TABLE `materials` (
  `id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `title` varchar(160) NOT NULL,
  `file_path` varchar(255) DEFAULT NULL,
  `text_content` mediumtext DEFAULT NULL,
  `size_bytes` int(10) UNSIGNED DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp(),
  `ai_status` enum('none','queued','running','done','failed') NOT NULL DEFAULT 'none',
  `page_count` smallint(5) UNSIGNED NOT NULL DEFAULT 0,
  `question_count` smallint(5) UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `notes`
--

CREATE TABLE `notes` (
  `id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `title` varchar(160) NOT NULL,
  `body` mediumtext DEFAULT NULL,
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `papers`
--

CREATE TABLE `papers` (
  `id` int(10) UNSIGNED NOT NULL,
  `course_id` int(10) UNSIGNED NOT NULL,
  `uploaded_by` int(10) UNSIGNED NOT NULL,
  `title` varchar(190) NOT NULL,
  `doc_kind` enum('auto','past_question','lecture_notes') NOT NULL DEFAULT 'auto',
  `exam_year` smallint(6) NOT NULL,
  `semester` tinyint(4) NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `file_size` int(10) UNSIGNED DEFAULT NULL,
  `has_answers` tinyint(1) NOT NULL DEFAULT 0,
  `status` enum('pending','published','rejected','unpublished') NOT NULL DEFAULT 'pending',
  `reject_reason` varchar(255) DEFAULT NULL,
  `views` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `reviewed_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `ai_status` enum('none','queued','running','done','failed') NOT NULL DEFAULT 'none'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `password_resets`
--

CREATE TABLE `password_resets` (
  `id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `token_hash` char(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `permissions`
--

CREATE TABLE `permissions` (
  `id` smallint(5) UNSIGNED NOT NULL,
  `code` varchar(40) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `permissions`
--

INSERT INTO `permissions` (`id`, `code`) VALUES
(12, 'courses'),
(11, 'review'),
(10, 'admin'),
(8, 'announce'),
(3, 'approve'),
(4, 'decks'),
(6, 'plans'),
(7, 'reports'),
(9, 'settings'),
(2, 'upload'),
(5, 'users'),
(1, 'view');

-- --------------------------------------------------------

--
-- Table structure for table `plans`
--

CREATE TABLE `plans` (
  `id` tinyint(3) UNSIGNED NOT NULL,
  `code` varchar(20) NOT NULL,
  `name` varchar(40) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `plans`
--

INSERT INTO `plans` (`id`, `code`, `name`) VALUES
(1, 'free', 'Free'),
(2, 'plus', 'Plus');

-- --------------------------------------------------------

--
-- Table structure for table `plan_prices`
--

CREATE TABLE `plan_prices` (
  `id` int(10) UNSIGNED NOT NULL,
  `plan_id` tinyint(3) UNSIGNED NOT NULL,
  `period` enum('month','semester','year') NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `currency` char(3) NOT NULL DEFAULT 'GHS',
  `is_enabled` tinyint(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `plan_prices`
--

INSERT INTO `plan_prices` (`id`, `plan_id`, `period`, `amount`, `currency`, `is_enabled`) VALUES
(1, 2, 'month', 15.00, 'GHS', 1),
(2, 2, 'semester', 60.00, 'GHS', 1),
(3, 2, 'year', 100.00, 'GHS', 1);

-- --------------------------------------------------------

--
-- Table structure for table `processing_jobs`
--

CREATE TABLE `processing_jobs` (
  `id` int(10) UNSIGNED NOT NULL,
  `source_type` enum('paper','material') NOT NULL,
  `source_id` int(10) UNSIGNED NOT NULL,
  `owner_id` int(10) UNSIGNED NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `requested_kind` enum('auto','past_question','lecture_notes') NOT NULL DEFAULT 'auto',
  `detected_kind` enum('past_question','lecture_notes') DEFAULT NULL,
  `status` enum('queued','running','done','failed') NOT NULL DEFAULT 'queued',
  `stage` varchar(30) NOT NULL DEFAULT 'queued',
  `progress` tinyint(3) UNSIGNED NOT NULL DEFAULT 0,
  `message` varchar(255) DEFAULT NULL,
  `attempts` tinyint(3) UNSIGNED NOT NULL DEFAULT 0,
  `error` text DEFAULT NULL,
  `tokens_in` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `tokens_out` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp(),
  `started_at` datetime DEFAULT NULL,
  `finished_at` datetime DEFAULT NULL,
  `events` mediumtext DEFAULT NULL,
  `stats` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `recordings`
--

CREATE TABLE `recordings` (
  `id` int(10) UNSIGNED NOT NULL,
  `owner_id` int(10) UNSIGNED NOT NULL,
  `course_id` int(10) UNSIGNED DEFAULT NULL,
  `title` varchar(160) NOT NULL,
  `audio_path` varchar(255) DEFAULT NULL,
  `transcript` mediumtext DEFAULT NULL,
  `seconds` int(10) UNSIGNED DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `reports`
--

CREATE TABLE `reports` (
  `id` int(10) UNSIGNED NOT NULL,
  `paper_id` int(10) UNSIGNED DEFAULT NULL,
  `deck_id` int(10) UNSIGNED DEFAULT NULL,
  `reported_by` int(10) UNSIGNED NOT NULL,
  `reason` varchar(255) NOT NULL,
  `status` enum('open','resolved','dismissed') NOT NULL DEFAULT 'open',
  `resolved_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `roles`
--

CREATE TABLE `roles` (
  `id` tinyint(3) UNSIGNED NOT NULL,
  `name` varchar(40) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `roles`
--

INSERT INTO `roles` (`id`, `name`) VALUES
(4, 'admin'),
(2, 'class_rep'),
(3, 'lecturer'),
(1, 'student');

-- --------------------------------------------------------

--
-- Table structure for table `role_permissions`
--

CREATE TABLE `role_permissions` (
  `role_id` tinyint(3) UNSIGNED NOT NULL,
  `permission_id` smallint(5) UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `role_permissions`
--

INSERT INTO `role_permissions` (`role_id`, `permission_id`) VALUES
(1, 1),
(2, 1),
(2, 2),
(2, 12),
(3, 1),
(3, 2),
(3, 8),
(3, 11),
(3, 12),
(4, 1),
(4, 2),
(4, 3),
(4, 4),
(4, 5),
(4, 6),
(4, 7),
(4, 8),
(4, 9),
(4, 10),
(4, 11),
(4, 12);

-- --------------------------------------------------------

--
-- Table structure for table `site_pages`
--

CREATE TABLE `site_pages` (
  `slug` varchar(40) NOT NULL,
  `title` varchar(120) NOT NULL,
  `body` mediumtext NOT NULL,
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `site_settings`
--

CREATE TABLE `site_settings` (
  `k` varchar(60) NOT NULL,
  `v` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `site_settings`
--

INSERT INTO `site_settings` (`k`, `v`) VALUES
('2fa_required', '0'),
('ai_autopublish', '0'),
('ai_cap', '50'),
('ai_max_chars', '60000'),
('ai_max_pages', '80'),
('ai_model', 'claude-sonnet-5-5'),
('ai_on', '1'),
('ai_predicted_count', '12'),
('free_notes', '1'),
('free_timer', '15'),
('free_upload_max', '3'),
('free_upload_pages', '1'),
('maintenance', '0'),
('plus_max_timer', '180'),
('reg_open', '1'),
('school', 'Your School'),
('upload_review', '1');

-- --------------------------------------------------------

--
-- Table structure for table `study_guides`
--

CREATE TABLE `study_guides` (
  `id` int(10) UNSIGNED NOT NULL,
  `owner_id` int(10) UNSIGNED NOT NULL,
  `paper_id` int(10) UNSIGNED DEFAULT NULL,
  `title` varchar(160) NOT NULL,
  `body` mediumtext NOT NULL,
  `status` enum('active','hidden') DEFAULT 'active',
  `created_at` datetime DEFAULT current_timestamp(),
  `material_id` int(10) UNSIGNED DEFAULT NULL,
  `ai_generated` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `subscriptions`
--

CREATE TABLE `subscriptions` (
  `id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `plan_id` tinyint(3) UNSIGNED NOT NULL,
  `period` enum('month','semester','year') NOT NULL,
  `status` enum('active','cancelled','expired','past_due') NOT NULL DEFAULT 'active',
  `starts_at` datetime NOT NULL,
  `renews_at` datetime DEFAULT NULL,
  `ends_at` datetime DEFAULT NULL,
  `cancel_at_period_end` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- --------------------------------------------------------

--
-- Table structure for table `transactions`
--

CREATE TABLE `transactions` (
  `id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `subscription_id` int(10) UNSIGNED DEFAULT NULL,
  `coupon_id` int(10) UNSIGNED DEFAULT NULL,
  `gateway_ref` varchar(80) DEFAULT NULL,
  `amount` decimal(10,2) NOT NULL,
  `currency` char(3) NOT NULL DEFAULT 'GHS',
  `status` enum('paid','failed','refunded','pending') NOT NULL DEFAULT 'pending',
  `refunded_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `period` enum('month','semester','year') DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(10) UNSIGNED NOT NULL,
  `role_id` tinyint(3) UNSIGNED NOT NULL DEFAULT 1,
  `department_id` int(10) UNSIGNED DEFAULT NULL,
  `full_name` varchar(120) NOT NULL,
  `email` varchar(190) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `date_of_birth` date DEFAULT NULL,
  `level` varchar(20) DEFAULT NULL,
  `status` enum('active','suspended','pending') NOT NULL DEFAULT 'active',
  `plus` tinyint(1) NOT NULL DEFAULT 0,
  `plus_expires` datetime DEFAULT NULL,
  `twofa_secret` varchar(64) DEFAULT NULL,
  `last_login_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


--
-- Indexes for dumped tables
--

--
-- Indexes for table `academic_terms`
--
ALTER TABLE `academic_terms`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `ai_usage`
--
ALTER TABLE `ai_usage`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`,`created_at`),
  ADD KEY `idx_ai_usage_user_day` (`user_id`,`created_at`,`kind`);

--
-- Indexes for table `alert_subscribers`
--
ALTER TABLE `alert_subscribers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD UNIQUE KEY `uk_alert_email_dept` (`email`,`department_id`);

--
-- Indexes for table `announcements`
--
ALTER TABLE `announcements`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_announce_author` (`author_id`),
  ADD KEY `idx_announce_dept` (`department_id`),
  ADD KEY `idx_announce_audience_pub` (`audience`,`publish_at`);

--
-- Indexes for table `audit_log`
--
ALTER TABLE `audit_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `created_at` (`created_at`),
  ADD KEY `idx_audit_target` (`target_type`(20),`target_id`),
  ADD KEY `idx_audit_actor` (`actor_id`,`created_at`),
  ADD KEY `idx_audit_time` (`created_at`);

--
-- Indexes for table `cards`
--
ALTER TABLE `cards`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_cards_deck_pos` (`deck_id`,`position`);

--
-- Indexes for table `card_progress`
--
ALTER TABLE `card_progress`
  ADD PRIMARY KEY (`user_id`,`card_id`),
  ADD KEY `idx_cp_user` (`user_id`),
  ADD KEY `idx_cp_card` (`card_id`),
  ADD KEY `idx_cp_due` (`user_id`,`due_at`);

--
-- Indexes for table `coupons`
--
ALTER TABLE `coupons`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `code` (`code`),
  ADD KEY `idx_coupon_active` (`is_active`,`expires_at`);

--
-- Indexes for table `courses`
--
ALTER TABLE `courses`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `department_id` (`department_id`,`code`),
  ADD KEY `idx_courses_dept` (`department_id`);

--
-- Indexes for table `decks`
--
ALTER TABLE `decks`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_decks_owner` (`owner_id`),
  ADD KEY `idx_decks_course` (`course_id`),
  ADD KEY `idx_decks_paper` (`paper_id`),
  ADD KEY `idx_decks_material` (`material_id`),
  ADD KEY `idx_decks_owner_status` (`owner_id`,`status`),
  ADD KEY `idx_decks_source` (`source`),
  ADD KEY `idx_decks_visibility` (`visibility`,`status`,`created_at`);

--
-- Indexes for table `departments`
--
ALTER TABLE `departments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`name`);

--
-- Indexes for table `exam_questions`
--
ALTER TABLE `exam_questions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `source_type` (`source_type`,`source_id`,`kind`,`status`),
  ADD KEY `idx_eq_owner` (`edited_by`),
  ADD KEY `idx_eq_source` (`source_type`,`source_id`,`kind`,`status`),
  ADD KEY `idx_eq_confidence` (`confidence`,`created_at`);

--
-- Indexes for table `exam_timetable`
--
ALTER TABLE `exam_timetable`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_exam_course` (`course_id`),
  ADD KEY `idx_exam_term` (`term_id`);

--
-- Indexes for table `faqs`
--
ALTER TABLE `faqs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_faqs_pos` (`position`);

--
-- Indexes for table `focus_sessions`
--
ALTER TABLE `focus_sessions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_focus_user_time` (`user_id`,`started_at`);

--
-- Indexes for table `games`
--
ALTER TABLE `games`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `code` (`code`);

--
-- Indexes for table `materials`
--
ALTER TABLE `materials`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_mat_user` (`user_id`),
  ADD KEY `idx_mat_ai` (`user_id`,`ai_status`,`created_at`);

--
-- Indexes for table `notes`
--
ALTER TABLE `notes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_notes_user` (`user_id`);

--
-- Indexes for table `papers`
--
ALTER TABLE `papers`
  ADD PRIMARY KEY (`id`),
  ADD KEY `status` (`status`),
  ADD KEY `course_id` (`course_id`,`exam_year`),
  ADD KEY `idx_papers_uploader` (`uploaded_by`),
  ADD KEY `idx_papers_reviewer` (`reviewed_by`),
  ADD KEY `idx_papers_status_course` (`status`,`course_id`,`exam_year`,`semester`),
  ADD KEY `idx_papers_status_year` (`status`,`exam_year`,`id`);

--
-- Indexes for table `password_resets`
--
ALTER TABLE `password_resets`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_pr_user` (`user_id`);

--
-- Indexes for table `permissions`
--
ALTER TABLE `permissions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `code` (`code`);

--
-- Indexes for table `plans`
--
ALTER TABLE `plans`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `code` (`code`);

--
-- Indexes for table `plan_prices`
--
ALTER TABLE `plan_prices`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `plan_id` (`plan_id`,`period`);

--
-- Indexes for table `processing_jobs`
--
ALTER TABLE `processing_jobs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `status` (`status`,`id`),
  ADD KEY `source_type` (`source_type`,`source_id`),
  ADD KEY `idx_jobs_owner` (`owner_id`),
  ADD KEY `idx_jobs_status_id` (`status`,`id`),
  ADD KEY `idx_jobs_owner_status` (`owner_id`,`status`,`created_at`),
  ADD KEY `idx_jobs_heartbeat` (`status`,`started_at`,`id`);

--
-- Indexes for table `recordings`
--
ALTER TABLE `recordings`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_recs_owner` (`owner_id`),
  ADD KEY `idx_recs_course` (`course_id`);

--
-- Indexes for table `reports`
--
ALTER TABLE `reports`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_reports_paper` (`paper_id`),
  ADD KEY `idx_reports_deck` (`deck_id`),
  ADD KEY `idx_reports_user` (`reported_by`),
  ADD KEY `idx_reports_status` (`status`,`created_at`);

--
-- Indexes for table `roles`
--
ALTER TABLE `roles`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`name`);

--
-- Indexes for table `role_permissions`
--
ALTER TABLE `role_permissions`
  ADD PRIMARY KEY (`role_id`,`permission_id`),
  ADD KEY `permission_id` (`permission_id`);

--
-- Indexes for table `site_pages`
--
ALTER TABLE `site_pages`
  ADD PRIMARY KEY (`slug`);

--
-- Indexes for table `site_settings`
--
ALTER TABLE `site_settings`
  ADD PRIMARY KEY (`k`);

--
-- Indexes for table `study_guides`
--
ALTER TABLE `study_guides`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_guides_owner` (`owner_id`),
  ADD KEY `idx_guides_paper` (`paper_id`),
  ADD KEY `idx_guides_material` (`material_id`),
  ADD KEY `idx_guides_owner_status` (`owner_id`,`status`,`created_at`);

--
-- Indexes for table `subscriptions`
--
ALTER TABLE `subscriptions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`,`status`),
  ADD KEY `idx_sub_plan` (`plan_id`),
  ADD KEY `idx_sub_active` (`user_id`,`status`,`ends_at`),
  ADD KEY `idx_sub_expires` (`ends_at`,`status`);

--
-- Indexes for table `transactions`
--
ALTER TABLE `transactions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `gateway_ref` (`gateway_ref`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `status` (`status`),
  ADD KEY `idx_txn_sub` (`subscription_id`),
  ADD KEY `idx_txn_coupon` (`coupon_id`),
  ADD KEY `idx_txn_status_created` (`status`,`created_at`),
  ADD KEY `idx_txn_gateway` (`gateway_ref`(40));

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD KEY `idx_users_role_status` (`role_id`,`status`,`created_at`),
  ADD KEY `idx_users_name` (`full_name`(64),`created_at`),
  ADD KEY `idx_users_plus_active` (`plus`,`plus_expires`),
  ADD KEY `idx_users_dob` (`date_of_birth`),
  ADD KEY `department_id` (`department_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `academic_terms`
--
ALTER TABLE `academic_terms`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `ai_usage`
--
ALTER TABLE `ai_usage`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `alert_subscribers`
--
ALTER TABLE `alert_subscribers`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `announcements`
--
ALTER TABLE `announcements`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `audit_log`
--
ALTER TABLE `audit_log`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `cards`
--
ALTER TABLE `cards`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `coupons`
--
ALTER TABLE `coupons`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `courses`
--
ALTER TABLE `courses`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `decks`
--
ALTER TABLE `decks`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `departments`
--
ALTER TABLE `departments`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `exam_questions`
--
ALTER TABLE `exam_questions`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `exam_timetable`
--
ALTER TABLE `exam_timetable`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `faqs`
--
ALTER TABLE `faqs`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `focus_sessions`
--
ALTER TABLE `focus_sessions`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `games`
--
ALTER TABLE `games`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `materials`
--
ALTER TABLE `materials`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `notes`
--
ALTER TABLE `notes`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `papers`
--
ALTER TABLE `papers`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `password_resets`
--
ALTER TABLE `password_resets`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `permissions`
--
ALTER TABLE `permissions`
  MODIFY `id` smallint(5) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `plans`
--
ALTER TABLE `plans`
  MODIFY `id` tinyint(3) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `plan_prices`
--
ALTER TABLE `plan_prices`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `processing_jobs`
--
ALTER TABLE `processing_jobs`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `recordings`
--
ALTER TABLE `recordings`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `reports`
--
ALTER TABLE `reports`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `roles`
--
ALTER TABLE `roles`
  MODIFY `id` tinyint(3) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `study_guides`
--
ALTER TABLE `study_guides`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `subscriptions`
--
ALTER TABLE `subscriptions`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `transactions`
--
ALTER TABLE `transactions`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `ai_usage`
--
ALTER TABLE `ai_usage`
  ADD CONSTRAINT `ai_usage_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `announcements`
--
ALTER TABLE `announcements`
  ADD CONSTRAINT `announcements_ibfk_1` FOREIGN KEY (`author_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `cards`
--
ALTER TABLE `cards`
  ADD CONSTRAINT `cards_ibfk_1` FOREIGN KEY (`deck_id`) REFERENCES `decks` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `card_progress`
--
ALTER TABLE `card_progress`
  ADD CONSTRAINT `card_progress_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `card_progress_ibfk_2` FOREIGN KEY (`card_id`) REFERENCES `cards` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `courses`
--
ALTER TABLE `courses`
  ADD CONSTRAINT `courses_ibfk_1` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `decks`
--
ALTER TABLE `decks`
  ADD CONSTRAINT `decks_ibfk_1` FOREIGN KEY (`owner_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `exam_timetable`
--
ALTER TABLE `exam_timetable`
  ADD CONSTRAINT `exam_timetable_ibfk_1` FOREIGN KEY (`course_id`) REFERENCES `courses` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `exam_timetable_ibfk_2` FOREIGN KEY (`term_id`) REFERENCES `academic_terms` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `focus_sessions`
--
ALTER TABLE `focus_sessions`
  ADD CONSTRAINT `focus_sessions_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `materials`
--
ALTER TABLE `materials`
  ADD CONSTRAINT `materials_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `notes`
--
ALTER TABLE `notes`
  ADD CONSTRAINT `notes_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `papers`
--
ALTER TABLE `papers`
  ADD CONSTRAINT `papers_ibfk_1` FOREIGN KEY (`course_id`) REFERENCES `courses` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `papers_ibfk_2` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `password_resets`
--
ALTER TABLE `password_resets`
  ADD CONSTRAINT `password_resets_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `plan_prices`
--
ALTER TABLE `plan_prices`
  ADD CONSTRAINT `plan_prices_ibfk_1` FOREIGN KEY (`plan_id`) REFERENCES `plans` (`id`);

--
-- Constraints for table `processing_jobs`
--
ALTER TABLE `processing_jobs`
  ADD CONSTRAINT `processing_jobs_ibfk_1` FOREIGN KEY (`owner_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `recordings`
--
ALTER TABLE `recordings`
  ADD CONSTRAINT `recordings_ibfk_1` FOREIGN KEY (`owner_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `reports`
--
ALTER TABLE `reports`
  ADD CONSTRAINT `reports_ibfk_1` FOREIGN KEY (`reported_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `role_permissions`
--
ALTER TABLE `role_permissions`
  ADD CONSTRAINT `role_permissions_ibfk_1` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `role_permissions_ibfk_2` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `study_guides`
--
ALTER TABLE `study_guides`
  ADD CONSTRAINT `study_guides_ibfk_1` FOREIGN KEY (`owner_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `subscriptions`
--
ALTER TABLE `subscriptions`
  ADD CONSTRAINT `subscriptions_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `subscriptions_ibfk_2` FOREIGN KEY (`plan_id`) REFERENCES `plans` (`id`);

--
-- Constraints for table `transactions`
--
ALTER TABLE `transactions`
  ADD CONSTRAINT `transactions_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `users`
--
ALTER TABLE `users`
  ADD CONSTRAINT `users_ibfk_1` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`),
  ADD CONSTRAINT `users_ibfk_2` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL;

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

COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;


-- ===== upgrade (recordings, paper_views, game_scores, settings) =====
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


-- ===== sample content =====
-- Sample content so the site is not empty on first run. Delete it later from the admin area.
-- First admin: admin@prepdeck.local / Admin@12345  (CHANGE THIS PASSWORD after your first sign-in; the admin overview warns you until you do)
INSERT IGNORE INTO `users` (`id`,`role_id`,`department_id`,`full_name`,`email`,`password_hash`,`status`) VALUES
 (1,4,NULL,'Admin','admin@prepdeck.local','$2y$10$HLJXGO1KKuVyk1KEELFNLOurOSorYADbFeZtgbtOU1Y6zmimi8OIe','active');

INSERT IGNORE INTO `courses` (`id`,`department_id`,`code`,`title`,`level`) VALUES
 (1,1,'CSC 101','Introduction to Computing',100),(2,1,'CSC 201','Data Structures and Algorithms',200),(3,1,'CSC 202','Computer Networks',200),(4,1,'CSC 301','Database Systems',300),
 (5,2,'NUR 102','Foundations of Nursing',100),(6,2,'NUR 201','Pharmacology',200),
 (7,3,'BUS 101','Principles of Management',100),(8,3,'BUS 201','Financial Accounting',200),
 (9,4,'MTH 201','Linear Algebra',200),(10,4,'ENG 202','Engineering Mechanics',200),
 (11,5,'BIO 201','Cell Biology',200),(12,5,'BIO 202','Genetics',200);

INSERT IGNORE INTO `academic_terms` (`id`,`year_label`,`semester`,`starts_on`,`ends_on`,`is_current`) VALUES
 (1,'2026/2027',1,'2026-09-14','2027-01-29',1);
INSERT IGNORE INTO `exam_timetable` (`id`,`course_id`,`term_id`,`exam_date`,`exam_time`,`venue`) VALUES
 (1,3,1,DATE_ADD(CURDATE(),INTERVAL 12 DAY),'09:00:00','Hall B'),(2,2,1,DATE_ADD(CURDATE(),INTERVAL 19 DAY),'09:00:00','Lab 2'),(3,9,1,DATE_ADD(CURDATE(),INTERVAL 26 DAY),'13:30:00','Hall A');

INSERT IGNORE INTO `papers` (`id`,`course_id`,`uploaded_by`,`title`,`doc_kind`,`exam_year`,`semester`,`file_path`,`file_size`,`has_answers`,`status`,`views`) VALUES
 (1,3,1,'Computer Networks Semester 1 Examination','past_question',2025,1,'sample-csc202-2025.pdf',2150,0,'published',0),
 (2,2,1,'Data Structures Semester 1 Examination','past_question',2025,1,'sample-csc201-2025.pdf',1985,0,'published',0),
 (3,6,1,'Pharmacology: Drug absorption (lecture notes)','lecture_notes',2026,1,'sample-nur201-notes.pdf',2298,0,'published',0);
UPDATE `courses` c SET paper_count=(SELECT COUNT(*) FROM papers p WHERE p.course_id=c.id AND p.status='published');

INSERT IGNORE INTO `faqs` (`id`,`question`,`answer`,`position`) VALUES
 (1,'What is PrepDeck?','PrepDeck collects your school''s past questions and turns them into flashcards, study guides and games.',1),
 (2,'What does Plus include?','PrepDeck AI, solved answers, study guides, lecture recording, unlimited note uploads, My notes, a custom focus timer and extra games.',2),
 (3,'How do I pay for Plus?','Choose monthly, per semester or yearly on the Plan page. Your school may also confirm payments by hand.',3),
 (4,'Can I upload my own lecture notes?','Yes. Free accounts can upload a few one-page PDFs. Plus accounts can upload unlimited documents of any length.',4);
INSERT IGNORE INTO `site_pages` (`slug`,`title`,`body`) VALUES
 ('terms','Terms of use','By using PrepDeck you agree to use the material for your own study, not to share your account, and not to upload content you do not have the right to share.\n\nEdit this page in Admin > Site content.'),
 ('privacy','Privacy policy','We store your name, email, department and study activity to run PrepDeck. We do not sell your data.\n\nEdit this page in Admin > Site content.'),
 ('refund','Refund policy','Plus payments can be refunded within 7 days if you have not used PrepDeck AI.\n\nEdit this page in Admin > Site content.');
INSERT IGNORE INTO `coupons` (`id`,`code`,`percent_off`,`max_uses`,`is_active`) VALUES (1,'WELCOME10',10,100,1);
INSERT IGNORE INTO `announcements` (`id`,`author_id`,`title`,`body`,`audience`) VALUES
 (1,1,'Welcome to PrepDeck','Past questions for every department are being added. Check the Past questions page and turn on course alerts.','all');

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
