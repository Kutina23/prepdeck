<?php
// Multi-school (multi-tenant) PrepDeck: a schools directory, school_id on every school-owned table, per-school settings,
// prices and role permissions, a platform owner (super admin) and staff who belong to more than one school.
// The school that already exists becomes school 1 and every existing row is backfilled to it. Safe to run twice.
return function (PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS `schools` (
      `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
      `slug` varchar(80) NOT NULL,
      `name` varchar(160) NOT NULL,
      `short_name` varchar(40) DEFAULT NULL,
      `type` enum('university','technical_university','polytechnic','college_of_education','nursing_college','senior_high','other') NOT NULL DEFAULT 'university',
      `ownership` enum('public','private') NOT NULL DEFAULT 'public',
      `region` varchar(40) DEFAULT NULL,
      `city` varchar(80) DEFAULT NULL,
      `contact_email` varchar(190) DEFAULT NULL,
      `phone` varchar(40) DEFAULT NULL,
      `website` varchar(190) DEFAULT NULL,
      `logo` varchar(255) DEFAULT NULL,
      `color` char(7) DEFAULT NULL,
      `is_active` tinyint(1) NOT NULL DEFAULT 1,
      `is_listed` tinyint(1) NOT NULL DEFAULT 1,
      `plan` enum('trial','basic','standard','premium') NOT NULL DEFAULT 'basic',
      `plan_expires` date DEFAULT NULL,
      `max_students` int(10) UNSIGNED DEFAULT NULL,
      `notes` text DEFAULT NULL,
      `created_at` datetime NOT NULL DEFAULT current_timestamp(),
      PRIMARY KEY (`id`), UNIQUE KEY `slug` (`slug`), KEY `idx_schools_dir` (`is_listed`,`is_active`,`name`), KEY `idx_schools_region` (`region`,`type`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $db->exec("CREATE TABLE IF NOT EXISTS `school_requests` (
      `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
      `name` varchar(160) NOT NULL,
      `type` varchar(30) DEFAULT NULL,
      `region` varchar(40) DEFAULT NULL,
      `city` varchar(80) DEFAULT NULL,
      `requester_name` varchar(120) DEFAULT NULL,
      `requester_email` varchar(190) NOT NULL,
      `message` varchar(600) DEFAULT NULL,
      `status` enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
      `school_id` int(10) UNSIGNED DEFAULT NULL,
      `handled_by` int(10) UNSIGNED DEFAULT NULL,
      `handled_at` datetime DEFAULT NULL,
      `created_at` datetime NOT NULL DEFAULT current_timestamp(),
      PRIMARY KEY (`id`), KEY `status` (`status`,`created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    // staff (lecturers, class reps) who also work in other schools, with their role there; users.school_id stays their home school
    $db->exec("CREATE TABLE IF NOT EXISTS `user_schools` (
      `user_id` int(10) UNSIGNED NOT NULL,
      `school_id` int(10) UNSIGNED NOT NULL,
      `role_id` tinyint(3) UNSIGNED NOT NULL DEFAULT 3,
      `added_by` int(10) UNSIGNED DEFAULT NULL,
      `created_at` datetime NOT NULL DEFAULT current_timestamp(),
      PRIMARY KEY (`user_id`,`school_id`), KEY `school_id` (`school_id`),
      CONSTRAINT `user_schools_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
      CONSTRAINT `user_schools_ibfk_2` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    // what each role may do inside one school, set by the platform owner. No rows for a school + role = the global defaults in role_permissions.
    $db->exec("CREATE TABLE IF NOT EXISTS `school_role_permissions` (
      `school_id` int(10) UNSIGNED NOT NULL,
      `role_id` tinyint(3) UNSIGNED NOT NULL,
      `permission_id` smallint(5) UNSIGNED NOT NULL,
      PRIMARY KEY (`school_id`,`role_id`,`permission_id`),
      CONSTRAINT `srp_ibfk_1` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE,
      CONSTRAINT `srp_ibfk_2` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // ----- platform owner role + permission -----
    $db->exec("INSERT IGNORE INTO roles(id,name) VALUES (5,'super_admin')");
    $db->exec("INSERT IGNORE INTO permissions(code) VALUES ('platform')");
    $db->exec('INSERT IGNORE INTO role_permissions(role_id,permission_id) SELECT 5,id FROM permissions');
    // Plus (prices, payments, coupons, giving Plus) is run by the platform owner only, so no school role keeps "plans"
    $db->exec("DELETE rp FROM role_permissions rp JOIN permissions p ON p.id=rp.permission_id WHERE p.code IN ('platform','plans') AND rp.role_id<>5");
    $db->exec("DELETE up FROM user_permissions up JOIN permissions p ON p.id=up.permission_id WHERE p.code IN ('platform','plans')");

    // ----- school 1 = the school this install already served -----
    if (!(int)$db->query('SELECT COUNT(*) FROM schools')->fetchColumn()) {
        $name = trim((string)$db->query("SELECT v FROM site_settings WHERE k='school'")->fetchColumn());
        if ($name === '' || $name === 'Your School') $name = 'My School';
        $slug = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-') ?: 'my-school';
        $db->prepare("INSERT INTO schools(id,slug,name,type,is_active,is_listed,plan) VALUES (1,?,?,'university',1,1,'standard')")->execute([$slug, $name]);
    }

    // ----- school_id on every school-owned table -----
    $scoped = ['departments', 'courses', 'users', 'academic_terms', 'announcements', 'alert_subscribers', 'decks', 'materials', 'notes', 'recordings',
        'study_guides', 'exam_timetable', 'practice_attempts', 'game_scores', 'paper_views', 'papers', 'processing_jobs', 'course_outlines', 'lesson_notes',
        'reports', 'transactions', 'subscriptions', 'audit_log', 'ai_usage', 'focus_sessions', 'coupons'];
    foreach ($scoped as $t) {
        if (!mig_has_table($t)) continue;
        mig_add_column($t, 'school_id', 'int(10) UNSIGNED DEFAULT NULL');
        if (!mig_has_index($t, "idx_{$t}_school")) $db->exec("ALTER TABLE `$t` ADD KEY `idx_{$t}_school` (`school_id`)");
        // coupons without a school work everywhere; audit rows of the platform owner stay unscoped
        if ($t !== 'coupons') $db->exec("UPDATE `$t` SET school_id=1 WHERE school_id IS NULL");
    }

    // ----- unique keys become per school -----
    if (mig_has_index('departments', 'name')) $db->exec('ALTER TABLE `departments` DROP INDEX `name`');
    if (!mig_has_index('departments', 'uk_dept_school_name')) $db->exec('ALTER TABLE `departments` ADD UNIQUE KEY `uk_dept_school_name` (`school_id`,`name`)');
    // courses: department ids are global and every department belongs to one school, so UNIQUE(department_id,code) is already per school;
    // the school-wide key below also stops the same code twice inside one school.
    if (!mig_has_index('courses', 'uk_course_school')) $db->exec('ALTER TABLE `courses` ADD UNIQUE KEY `uk_course_school` (`school_id`,`department_id`,`code`)');
    if (mig_has_index('alert_subscribers', 'email')) $db->exec('ALTER TABLE `alert_subscribers` DROP INDEX `email`');
    if (!mig_has_index('alert_subscribers', 'uk_alert_school_email')) $db->exec('ALTER TABLE `alert_subscribers` ADD UNIQUE KEY `uk_alert_school_email` (`school_id`,`email`)');

    // ----- per-school settings: school_id 0 = the platform default every school falls back to -----
    if (!mig_has_column('site_settings', 'school_id')) {
        $db->exec('ALTER TABLE `site_settings` ADD COLUMN `school_id` int(10) UNSIGNED NOT NULL DEFAULT 0 FIRST');
        $db->exec('ALTER TABLE `site_settings` DROP PRIMARY KEY, ADD PRIMARY KEY (`school_id`,`k`)');
    }
    foreach (['platform_maintenance' => '0', 'platform_reg_open' => '1', 'school_requests_open' => '1', 'school_default_plan' => 'basic', 'platform_ai_on' => '1'] as $k => $v)
        $db->prepare('INSERT IGNORE INTO site_settings(school_id,k,v) VALUES (0,?,?)')->execute([$k, $v]);

    // ----- per-school Plus prices: school_id 0 = default price list -----
    if (!mig_has_column('plan_prices', 'school_id')) {
        $db->exec('ALTER TABLE `plan_prices` ADD COLUMN `school_id` int(10) UNSIGNED NOT NULL DEFAULT 0 AFTER `id`');
        if (!mig_has_index('plan_prices', 'idx_pp_plan')) $db->exec('ALTER TABLE `plan_prices` ADD KEY `idx_pp_plan` (`plan_id`)');   // keeps the plan_id foreign key indexed
        if (mig_has_index('plan_prices', 'plan_id')) $db->exec('ALTER TABLE `plan_prices` DROP INDEX `plan_id`');
        $db->exec('ALTER TABLE `plan_prices` ADD UNIQUE KEY `uk_pp_school_period` (`school_id`,`plan_id`,`period`)');
    }

    // ----- the Ghana directory -----
    $ins = $db->prepare('INSERT IGNORE INTO schools(slug,name,short_name,type,ownership,region,city,is_active,is_listed) VALUES (?,?,?,?,?,?,?,1,1)');
    $have = array_flip(array_map('mb_strtolower', $db->query('SELECT name FROM schools')->fetchAll(PDO::FETCH_COLUMN)));
    foreach (require ROOT . '/database/seeds/ghana_schools.php' as [$name, $short, $type, $own, $region, $city])
        if (!isset($have[mb_strtolower($name)])) $ins->execute([trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($short . '-' . $city)), '-'), $name, $short, $type, $own, $region, $city]);

    // ----- the first admin becomes the platform owner (keeps school 1 as their home school) -----
    if (!(int)$db->query('SELECT COUNT(*) FROM users WHERE role_id=5')->fetchColumn())
        $db->exec('UPDATE users SET role_id=5 WHERE id=(SELECT id FROM (SELECT MIN(id) id FROM users WHERE role_id=4) x)');

    // ----- uploads are stored per school from now on: storage/uploads/<kind>/<school id>/file -----
    foreach (['papers', 'notes', 'audio', 'outlines', 'logos'] as $d) { $p = cfg('upload_dir') . "/$d"; if (!is_dir($p)) @mkdir($p, 0775, true); }
};
