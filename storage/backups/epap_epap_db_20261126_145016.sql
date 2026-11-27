-- EPAP PDO SQL Backup (2026-11-26T14:50:17+05:30)
SET FOREIGN_KEY_CHECKS=0;

DROP TABLE IF EXISTS `activities`;
CREATE TABLE `activities` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `faculty_id` int(11) NOT NULL,
  `subject_id` int(11) NOT NULL,
  `title` varchar(200) NOT NULL,
  `activity_type` varchar(60) NOT NULL DEFAULT 'Lab Practical Journal',
  `description` text DEFAULT NULL,
  `max_marks` int(11) NOT NULL DEFAULT 25,
  `deadline` datetime NOT NULL,
  `year` varchar(10) NOT NULL DEFAULT 'TE-IT',
  `division` varchar(5) NOT NULL DEFAULT 'A',
  `allow_late` tinyint(1) NOT NULL DEFAULT 1,
  `attachment_name` varchar(200) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_activities_div_deadline` (`division`,`deadline`),
  KEY `idx_activities_faculty` (`faculty_id`),
  KEY `fk_activities_subject` (`subject_id`),
  CONSTRAINT `fk_activities_faculty` FOREIGN KEY (`faculty_id`) REFERENCES `faculty` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_activities_subject` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `audit_logs`;
CREATE TABLE `audit_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `action` varchar(100) NOT NULL,
  `detail` text DEFAULT NULL,
  `ip` varchar(50) DEFAULT NULL,
  `status` enum('SUCCESS','FAILURE') NOT NULL DEFAULT 'SUCCESS',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_audit_action_date` (`action`,`created_at`),
  KEY `idx_audit_user` (`user_id`),
  CONSTRAINT `fk_audit_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=24 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `audit_logs` (`id`,`user_id`,`action`,`detail`,`ip`,`status`,`created_at`) VALUES ('1','1','OTP_FAILED','Invalid OTP entered','127.0.0.1','FAILURE','2026-11-26 14:50:11');
INSERT INTO `audit_logs` (`id`,`user_id`,`action`,`detail`,`ip`,`status`,`created_at`) VALUES ('2','1','LOGIN','Role: admin','127.0.0.1','SUCCESS','2026-11-26 14:50:11');
INSERT INTO `audit_logs` (`id`,`user_id`,`action`,`detail`,`ip`,`status`,`created_at`) VALUES ('3','1','SUBJECT_CREATED','Created TST2869','127.0.0.1','SUCCESS','2026-11-26 14:50:11');
INSERT INTO `audit_logs` (`id`,`user_id`,`action`,`detail`,`ip`,`status`,`created_at`) VALUES ('4','1','FACULTY_CREATED','Created FAC-TST-2869','127.0.0.1','SUCCESS','2026-11-26 14:50:11');
INSERT INTO `audit_logs` (`id`,`user_id`,`action`,`detail`,`ip`,`status`,`created_at`) VALUES ('5','1','STUDENT_CREATED','Created STU-TST-2869','127.0.0.1','SUCCESS','2026-11-26 14:50:11');
INSERT INTO `audit_logs` (`id`,`user_id`,`action`,`detail`,`ip`,`status`,`created_at`) VALUES ('6','1','FACULTY_ALLOCATION_CREATED','Allocation #1 (TE-IT Div A)','127.0.0.1','SUCCESS','2026-11-26 14:50:11');
INSERT INTO `audit_logs` (`id`,`user_id`,`action`,`detail`,`ip`,`status`,`created_at`) VALUES ('7',NULL,'OTP_FAILED','Invalid OTP entered','127.0.0.1','FAILURE','2026-11-26 14:50:12');
INSERT INTO `audit_logs` (`id`,`user_id`,`action`,`detail`,`ip`,`status`,`created_at`) VALUES ('8',NULL,'LOGIN','Role: faculty','127.0.0.1','SUCCESS','2026-11-26 14:50:12');
INSERT INTO `audit_logs` (`id`,`user_id`,`action`,`detail`,`ip`,`status`,`created_at`) VALUES ('9',NULL,'PASSWORD_CHANGED','User updated password','127.0.0.1','SUCCESS','2026-11-26 14:50:13');
INSERT INTO `audit_logs` (`id`,`user_id`,`action`,`detail`,`ip`,`status`,`created_at`) VALUES ('10',NULL,'ACTIVITY_CREATED','Activity 1: Smoke Activity 2869 (TE-IT Div A)','127.0.0.1','SUCCESS','2026-11-26 14:50:13');
INSERT INTO `audit_logs` (`id`,`user_id`,`action`,`detail`,`ip`,`status`,`created_at`) VALUES ('11',NULL,'OTP_FAILED','Invalid OTP entered','127.0.0.1','FAILURE','2026-11-26 14:50:13');
INSERT INTO `audit_logs` (`id`,`user_id`,`action`,`detail`,`ip`,`status`,`created_at`) VALUES ('12',NULL,'LOGIN','Role: student','127.0.0.1','SUCCESS','2026-11-26 14:50:13');
INSERT INTO `audit_logs` (`id`,`user_id`,`action`,`detail`,`ip`,`status`,`created_at`) VALUES ('13',NULL,'PASSWORD_CHANGED','User updated password','127.0.0.1','SUCCESS','2026-11-26 14:50:14');
INSERT INTO `audit_logs` (`id`,`user_id`,`action`,`detail`,`ip`,`status`,`created_at`) VALUES ('14',NULL,'SUBMISSION_CREATED','Activity 1 (solution.txt), late=0','127.0.0.1','SUCCESS','2026-11-26 14:50:14');
INSERT INTO `audit_logs` (`id`,`user_id`,`action`,`detail`,`ip`,`status`,`created_at`) VALUES ('15',NULL,'SUBMISSION_GRADED','Submission 1: 23/25','127.0.0.1','SUCCESS','2026-11-26 14:50:14');
INSERT INTO `audit_logs` (`id`,`user_id`,`action`,`detail`,`ip`,`status`,`created_at`) VALUES ('16','1','EXAM_CREATED','Exam #1: Smoke Exam 2869','127.0.0.1','SUCCESS','2026-11-26 14:50:14');
INSERT INTO `audit_logs` (`id`,`user_id`,`action`,`detail`,`ip`,`status`,`created_at`) VALUES ('17','1','MARKS_BULK_IMPORT','Imported 1 mark records','127.0.0.1','SUCCESS','2026-11-26 14:50:15');
INSERT INTO `audit_logs` (`id`,`user_id`,`action`,`detail`,`ip`,`status`,`created_at`) VALUES ('18','1','EXAM_STATUS_UPDATED','Smoke Exam 2869 -> published','127.0.0.1','SUCCESS','2026-11-26 14:50:15');
INSERT INTO `audit_logs` (`id`,`user_id`,`action`,`detail`,`ip`,`status`,`created_at`) VALUES ('19',NULL,'LOGIN_FAILED','Unknown/inactive student: THROTTLE-2869','127.0.0.1','FAILURE','2026-11-26 14:50:15');
INSERT INTO `audit_logs` (`id`,`user_id`,`action`,`detail`,`ip`,`status`,`created_at`) VALUES ('20',NULL,'LOGIN_FAILED','Unknown/inactive student: THROTTLE-2869','127.0.0.1','FAILURE','2026-11-26 14:50:15');
INSERT INTO `audit_logs` (`id`,`user_id`,`action`,`detail`,`ip`,`status`,`created_at`) VALUES ('21',NULL,'LOGIN_FAILED','Unknown/inactive student: THROTTLE-2869','127.0.0.1','FAILURE','2026-11-26 14:50:15');
INSERT INTO `audit_logs` (`id`,`user_id`,`action`,`detail`,`ip`,`status`,`created_at`) VALUES ('22',NULL,'LOGIN_FAILED','Unknown/inactive student: THROTTLE-2869','127.0.0.1','FAILURE','2026-11-26 14:50:15');
INSERT INTO `audit_logs` (`id`,`user_id`,`action`,`detail`,`ip`,`status`,`created_at`) VALUES ('23',NULL,'LOGIN_FAILED','Unknown/inactive student: THROTTLE-2869','127.0.0.1','FAILURE','2026-11-26 14:50:15');

DROP TABLE IF EXISTS `exam_results_release`;
CREATE TABLE `exam_results_release` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `exam_name` varchar(160) NOT NULL,
  `year` varchar(10) NOT NULL DEFAULT 'TE-IT',
  `semester` int(11) NOT NULL,
  `exam_type` enum('in_sem','end_sem','combined') NOT NULL DEFAULT 'combined',
  `academic_year` varchar(20) NOT NULL DEFAULT '2025-26',
  `conducted_label` varchar(60) DEFAULT NULL,
  `status` enum('draft','under_moderation','published','locked') NOT NULL DEFAULT 'draft',
  `published_at` datetime DEFAULT NULL,
  `published_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_exam_session` (`year`,`semester`,`exam_type`,`academic_year`),
  KEY `idx_exam_status` (`semester`,`status`),
  KEY `fk_exam_publisher` (`published_by`),
  CONSTRAINT `fk_exam_publisher` FOREIGN KEY (`published_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `faculty`;
CREATE TABLE `faculty` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `faculty_id` varchar(60) NOT NULL,
  `designation` varchar(100) NOT NULL DEFAULT 'Assistant Professor',
  `department` varchar(50) NOT NULL DEFAULT 'Information Technology',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_faculty_user` (`user_id`),
  UNIQUE KEY `uq_faculty_fid` (`faculty_id`),
  CONSTRAINT `fk_faculty_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `faculty_allocations`;
CREATE TABLE `faculty_allocations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `faculty_id` int(11) NOT NULL,
  `subject_id` int(11) NOT NULL,
  `year` varchar(10) NOT NULL DEFAULT 'TE-IT',
  `division` varchar(5) NOT NULL DEFAULT 'A',
  `academic_year` varchar(20) NOT NULL DEFAULT '2025-26',
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_allocation` (`faculty_id`,`subject_id`,`year`,`division`,`academic_year`),
  KEY `idx_alloc_fac_div` (`faculty_id`,`division`,`status`),
  KEY `fk_alloc_subject` (`subject_id`),
  CONSTRAINT `fk_alloc_faculty` FOREIGN KEY (`faculty_id`) REFERENCES `faculty` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_alloc_subject` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `login_attempts`;
CREATE TABLE `login_attempts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `identifier` varchar(60) NOT NULL,
  `ip` varchar(50) NOT NULL,
  `success` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_login_attempts_lookup` (`identifier`,`ip`,`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `login_attempts` (`id`,`identifier`,`ip`,`success`,`created_at`) VALUES ('1','TEST-HOD-ADMIN','127.0.0.1','1','2026-11-26 14:50:10');
INSERT INTO `login_attempts` (`id`,`identifier`,`ip`,`success`,`created_at`) VALUES ('2','FAC-TST-2869','127.0.0.1','1','2026-11-26 14:50:12');
INSERT INTO `login_attempts` (`id`,`identifier`,`ip`,`success`,`created_at`) VALUES ('3','STU-TST-2869','127.0.0.1','1','2026-11-26 14:50:13');

DROP TABLE IF EXISTS `notifications`;
CREATE TABLE `notifications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `title` varchar(140) NOT NULL,
  `message` varchar(255) NOT NULL,
  `link` varchar(160) DEFAULT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_notif_user_read` (`user_id`,`is_read`),
  CONSTRAINT `fk_notif_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `otp_tokens`;
CREATE TABLE `otp_tokens` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `otp_hash` varchar(255) NOT NULL,
  `purpose` enum('login','password_reset') NOT NULL DEFAULT 'login',
  `attempts` int(11) NOT NULL DEFAULT 0,
  `ip` varchar(50) DEFAULT NULL,
  `expires_at` datetime NOT NULL,
  `used` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_otp_user_purpose` (`user_id`,`purpose`,`used`),
  CONSTRAINT `fk_otp_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `otp_tokens` (`id`,`user_id`,`otp_hash`,`purpose`,`attempts`,`ip`,`expires_at`,`used`,`created_at`) VALUES ('1','1','$2y$10$i4tmT1CXa6y.uzPV0s0TS.khT7LG3sQhiRt2Z7PbVSolzmIgykEvO','login','2','127.0.0.1','2026-11-26 14:55:10','1','2026-11-26 14:50:10');

DROP TABLE IF EXISTS `practice_problems`;
CREATE TABLE `practice_problems` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `problem_number` int(11) NOT NULL,
  `subject_track` enum('dsa','oop','dbms') NOT NULL DEFAULT 'dsa',
  `title` varchar(180) NOT NULL,
  `difficulty` enum('Easy','Medium','Hard') NOT NULL DEFAULT 'Easy',
  `topic` varchar(80) NOT NULL,
  `description` text NOT NULL,
  `constraints_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`constraints_json`)),
  `examples_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`examples_json`)),
  `starter_code_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`starter_code_json`)),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_problem_track_num` (`subject_track`,`problem_number`),
  KEY `idx_problem_track_diff` (`subject_track`,`difficulty`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `practice_submissions`;
CREATE TABLE `practice_submissions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `student_id` int(11) NOT NULL,
  `problem_id` int(11) NOT NULL,
  `language` enum('python','java','cpp','c') NOT NULL DEFAULT 'python',
  `code` mediumtext NOT NULL,
  `verdict` varchar(60) NOT NULL DEFAULT 'Demo',
  `runtime_ms` int(11) DEFAULT NULL,
  `passed_count` int(11) NOT NULL DEFAULT 0,
  `total_count` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_prac_sub_stu_prob` (`student_id`,`problem_id`,`verdict`),
  KEY `fk_prac_sub_problem` (`problem_id`),
  CONSTRAINT `fk_prac_sub_problem` FOREIGN KEY (`problem_id`) REFERENCES `practice_problems` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_prac_sub_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `practice_testcases`;
CREATE TABLE `practice_testcases` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `problem_id` int(11) NOT NULL,
  `input_data` text NOT NULL,
  `expected_output` text NOT NULL,
  `is_sample` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `fk_testcase_problem` (`problem_id`),
  CONSTRAINT `fk_testcase_problem` FOREIGN KEY (`problem_id`) REFERENCES `practice_problems` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `rate_limits`;
CREATE TABLE `rate_limits` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `bucket` varchar(50) NOT NULL,
  `rate_key` varchar(120) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_rl_lookup` (`bucket`,`rate_key`,`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `rate_limits` (`id`,`bucket`,`rate_key`,`created_at`) VALUES ('2','otp_req_ip','127.0.0.1','2026-11-26 14:50:10');
INSERT INTO `rate_limits` (`id`,`bucket`,`rate_key`,`created_at`) VALUES ('6','otp_req_ip','127.0.0.1','2026-11-26 14:50:12');
INSERT INTO `rate_limits` (`id`,`bucket`,`rate_key`,`created_at`) VALUES ('10','otp_req_ip','127.0.0.1','2026-11-26 14:50:13');
INSERT INTO `rate_limits` (`id`,`bucket`,`rate_key`,`created_at`) VALUES ('1','otp_req_user','1','2026-11-26 14:50:10');
INSERT INTO `rate_limits` (`id`,`bucket`,`rate_key`,`created_at`) VALUES ('5','otp_req_user','2','2026-11-26 14:50:12');
INSERT INTO `rate_limits` (`id`,`bucket`,`rate_key`,`created_at`) VALUES ('9','otp_req_user','3','2026-11-26 14:50:13');
INSERT INTO `rate_limits` (`id`,`bucket`,`rate_key`,`created_at`) VALUES ('3','otp_verify_ip','127.0.0.1','2026-11-26 14:50:11');
INSERT INTO `rate_limits` (`id`,`bucket`,`rate_key`,`created_at`) VALUES ('4','otp_verify_ip','127.0.0.1','2026-11-26 14:50:11');
INSERT INTO `rate_limits` (`id`,`bucket`,`rate_key`,`created_at`) VALUES ('7','otp_verify_ip','127.0.0.1','2026-11-26 14:50:12');
INSERT INTO `rate_limits` (`id`,`bucket`,`rate_key`,`created_at`) VALUES ('8','otp_verify_ip','127.0.0.1','2026-11-26 14:50:12');
INSERT INTO `rate_limits` (`id`,`bucket`,`rate_key`,`created_at`) VALUES ('11','otp_verify_ip','127.0.0.1','2026-11-26 14:50:13');
INSERT INTO `rate_limits` (`id`,`bucket`,`rate_key`,`created_at`) VALUES ('12','otp_verify_ip','127.0.0.1','2026-11-26 14:50:13');

DROP TABLE IF EXISTS `schema_migrations`;
CREATE TABLE `schema_migrations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `migration` varchar(120) NOT NULL,
  `applied_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_migration_name` (`migration`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `schema_migrations` (`id`,`migration`,`applied_at`) VALUES ('1','001_production_tables.sql','2026-11-26 14:50:09');

DROP TABLE IF EXISTS `semester_marks`;
CREATE TABLE `semester_marks` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `student_id` int(11) NOT NULL,
  `subject_id` int(11) NOT NULL,
  `exam_id` int(11) DEFAULT NULL,
  `semester` int(11) NOT NULL,
  `in_sem` int(11) NOT NULL DEFAULT 0,
  `end_sem` int(11) NOT NULL DEFAULT 0,
  `practical` int(11) NOT NULL DEFAULT 0,
  `total_marks` int(11) NOT NULL DEFAULT 0,
  `max_marks` int(11) NOT NULL DEFAULT 150,
  `grade` varchar(5) NOT NULL DEFAULT 'P',
  `grade_points` decimal(4,2) NOT NULL DEFAULT 0.00,
  `passed` tinyint(1) NOT NULL DEFAULT 1,
  `sgpa` decimal(4,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sem_marks_stu_sub_sem` (`student_id`,`subject_id`,`semester`),
  KEY `idx_sem_marks_sem` (`semester`),
  KEY `fk_marks_subject` (`subject_id`),
  KEY `fk_marks_exam` (`exam_id`),
  CONSTRAINT `fk_marks_exam` FOREIGN KEY (`exam_id`) REFERENCES `exam_results_release` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_marks_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_marks_subject` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `settings`;
CREATE TABLE `settings` (
  `setting_key` varchar(80) NOT NULL,
  `setting_value` text NOT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `students`;
CREATE TABLE `students` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `zprn` varchar(60) NOT NULL,
  `seat_number` varchar(30) DEFAULT NULL,
  `roll_no` int(11) DEFAULT 0,
  `division` varchar(5) NOT NULL DEFAULT 'A',
  `year` varchar(10) NOT NULL DEFAULT 'TE-IT',
  `semester` int(11) NOT NULL DEFAULT 5,
  `cgpa` decimal(4,2) NOT NULL DEFAULT 0.00,
  `current_sgpa` decimal(4,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_students_user` (`user_id`),
  UNIQUE KEY `uq_students_zprn` (`zprn`),
  KEY `idx_students_year_div` (`year`,`division`),
  CONSTRAINT `fk_students_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `subjects`;
CREATE TABLE `subjects` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `code` varchar(20) NOT NULL,
  `name` varchar(120) NOT NULL,
  `semester` int(11) NOT NULL DEFAULT 5,
  `credits` int(11) NOT NULL DEFAULT 4,
  `max_in_sem` int(11) NOT NULL DEFAULT 30,
  `max_end_sem` int(11) NOT NULL DEFAULT 70,
  `max_practical` int(11) NOT NULL DEFAULT 50,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_subjects_code` (`code`),
  KEY `idx_subjects_sem` (`semester`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `submissions`;
CREATE TABLE `submissions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `activity_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `submitted_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `file_name` varchar(200) DEFAULT NULL,
  `original_name` varchar(200) DEFAULT NULL,
  `file_path` varchar(255) DEFAULT NULL,
  `file_size` int(11) DEFAULT NULL,
  `mime_type` varchar(100) DEFAULT NULL,
  `project_url` varchar(255) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `marks` int(11) DEFAULT NULL,
  `feedback` text DEFAULT NULL,
  `status` enum('submitted','graded') NOT NULL DEFAULT 'submitted',
  `is_late` tinyint(1) NOT NULL DEFAULT 0,
  `graded_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_submission` (`activity_id`,`student_id`),
  KEY `idx_submissions_status` (`status`),
  KEY `idx_submissions_student` (`student_id`),
  CONSTRAINT `fk_submissions_activity` FOREIGN KEY (`activity_id`) REFERENCES `activities` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_submissions_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `identifier` varchar(60) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` enum('student','faculty','admin') NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `email` varchar(120) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `must_change_password` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_identifier` (`identifier`),
  UNIQUE KEY `uq_users_email` (`email`),
  KEY `idx_users_role_active` (`role`,`is_active`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `users` (`id`,`identifier`,`password_hash`,`role`,`full_name`,`email`,`is_active`,`must_change_password`,`created_at`,`updated_at`) VALUES ('1','TEST-HOD-ADMIN','$2y$10$a3QZOPGFVI9fjoHeZEOG0.wlVxDEuFqIUopkkkE1GtCogjoRJNhym','admin','Test Admin','admin@epap.test','1','0','2026-11-26 14:50:09','2026-11-26 14:50:09');

SET FOREIGN_KEY_CHECKS=1;
