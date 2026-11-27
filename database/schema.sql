-- ============================================================
-- EPAP - Examination & Predictive Analytics Portal
-- Complete MySQL 8 Schema (InnoDB, utf8mb4)
-- ============================================================

CREATE DATABASE IF NOT EXISTS epap_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE epap_db;

-- 1. Users (all roles)
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    identifier VARCHAR(60) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('student','faculty','admin') NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    email VARCHAR(120) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    must_change_password TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_users_identifier (identifier),
    UNIQUE KEY uq_users_email (email),
    INDEX idx_users_role_active (role, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Student profiles
CREATE TABLE IF NOT EXISTS students (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    zprn VARCHAR(60) NOT NULL,
    seat_number VARCHAR(30) DEFAULT NULL,
    roll_no INT DEFAULT 0,
    division VARCHAR(5) NOT NULL DEFAULT 'A',
    year VARCHAR(10) NOT NULL DEFAULT 'TE-IT',
    semester INT NOT NULL DEFAULT 5,
    cgpa DECIMAL(4,2) NOT NULL DEFAULT 0.00,
    current_sgpa DECIMAL(4,2) NOT NULL DEFAULT 0.00,
    UNIQUE KEY uq_students_user (user_id),
    UNIQUE KEY uq_students_zprn (zprn),
    INDEX idx_students_year_div (year, division),
    CONSTRAINT fk_students_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Faculty profiles
CREATE TABLE IF NOT EXISTS faculty (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    faculty_id VARCHAR(60) NOT NULL,
    designation VARCHAR(100) NOT NULL DEFAULT 'Assistant Professor',
    department VARCHAR(50) NOT NULL DEFAULT 'Information Technology',
    UNIQUE KEY uq_faculty_user (user_id),
    UNIQUE KEY uq_faculty_fid (faculty_id),
    CONSTRAINT fk_faculty_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Subjects
CREATE TABLE IF NOT EXISTS subjects (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(20) NOT NULL,
    name VARCHAR(120) NOT NULL,
    semester INT NOT NULL DEFAULT 5,
    credits INT NOT NULL DEFAULT 4,
    max_in_sem INT NOT NULL DEFAULT 30,
    max_end_sem INT NOT NULL DEFAULT 70,
    max_practical INT NOT NULL DEFAULT 50,
    UNIQUE KEY uq_subjects_code (code),
    INDEX idx_subjects_sem (semester)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Faculty Course & Division Allocations
CREATE TABLE IF NOT EXISTS faculty_allocations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    faculty_id INT NOT NULL,
    subject_id INT NOT NULL,
    year VARCHAR(10) NOT NULL DEFAULT 'TE-IT',
    division VARCHAR(5) NOT NULL DEFAULT 'A',
    academic_year VARCHAR(20) NOT NULL DEFAULT '2025-26',
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_allocation (faculty_id, subject_id, year, division, academic_year),
    INDEX idx_alloc_fac_div (faculty_id, division, status),
    CONSTRAINT fk_alloc_faculty FOREIGN KEY (faculty_id) REFERENCES faculty(id) ON DELETE CASCADE,
    CONSTRAINT fk_alloc_subject FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Activities (assignments created by faculty)
CREATE TABLE IF NOT EXISTS activities (
    id INT AUTO_INCREMENT PRIMARY KEY,
    faculty_id INT NOT NULL,
    subject_id INT NOT NULL,
    title VARCHAR(200) NOT NULL,
    activity_type VARCHAR(60) NOT NULL DEFAULT 'Lab Practical Journal',
    description TEXT,
    max_marks INT NOT NULL DEFAULT 25,
    deadline DATETIME NOT NULL,
    year VARCHAR(10) NOT NULL DEFAULT 'TE-IT',
    division VARCHAR(5) NOT NULL DEFAULT 'A',
    allow_late TINYINT(1) NOT NULL DEFAULT 1,
    attachment_name VARCHAR(200) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_activities_div_deadline (division, deadline),
    INDEX idx_activities_faculty (faculty_id),
    CONSTRAINT fk_activities_faculty FOREIGN KEY (faculty_id) REFERENCES faculty(id) ON DELETE CASCADE,
    CONSTRAINT fk_activities_subject FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. Submissions
CREATE TABLE IF NOT EXISTS submissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    activity_id INT NOT NULL,
    student_id INT NOT NULL,
    submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    file_name VARCHAR(200) DEFAULT NULL,
    original_name VARCHAR(200) DEFAULT NULL,
    file_path VARCHAR(255) DEFAULT NULL,
    file_size INT DEFAULT NULL,
    mime_type VARCHAR(100) DEFAULT NULL,
    project_url VARCHAR(255) DEFAULT NULL,
    notes TEXT,
    marks INT DEFAULT NULL,
    feedback TEXT,
    status ENUM('submitted','graded') NOT NULL DEFAULT 'submitted',
    is_late TINYINT(1) NOT NULL DEFAULT 0,
    graded_at DATETIME DEFAULT NULL,
    UNIQUE KEY unique_submission (activity_id, student_id),
    INDEX idx_submissions_status (status),
    INDEX idx_submissions_student (student_id),
    CONSTRAINT fk_submissions_activity FOREIGN KEY (activity_id) REFERENCES activities(id) ON DELETE CASCADE,
    CONSTRAINT fk_submissions_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8. Exam Result Releases / Moderation Sessions
CREATE TABLE IF NOT EXISTS exam_results_release (
    id INT AUTO_INCREMENT PRIMARY KEY,
    exam_name VARCHAR(160) NOT NULL,
    year VARCHAR(10) NOT NULL DEFAULT 'TE-IT',
    semester INT NOT NULL,
    exam_type ENUM('in_sem','end_sem','combined') NOT NULL DEFAULT 'combined',
    academic_year VARCHAR(20) NOT NULL DEFAULT '2025-26',
    conducted_label VARCHAR(60) DEFAULT NULL,
    status ENUM('draft','under_moderation','published','locked') NOT NULL DEFAULT 'draft',
    published_at DATETIME DEFAULT NULL,
    published_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_exam_session (year, semester, exam_type, academic_year),
    INDEX idx_exam_status (semester, status),
    CONSTRAINT fk_exam_publisher FOREIGN KEY (published_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 9. Semester Marks
CREATE TABLE IF NOT EXISTS semester_marks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    subject_id INT NOT NULL,
    exam_id INT DEFAULT NULL,
    semester INT NOT NULL,
    in_sem INT NOT NULL DEFAULT 0,
    end_sem INT NOT NULL DEFAULT 0,
    practical INT NOT NULL DEFAULT 0,
    total_marks INT NOT NULL DEFAULT 0,
    max_marks INT NOT NULL DEFAULT 150,
    grade VARCHAR(5) NOT NULL DEFAULT 'P',
    grade_points DECIMAL(4,2) NOT NULL DEFAULT 0.00,
    passed TINYINT(1) NOT NULL DEFAULT 1,
    sgpa DECIMAL(4,2) NOT NULL DEFAULT 0.00,
    UNIQUE KEY uq_sem_marks_stu_sub_sem (student_id, subject_id, semester),
    INDEX idx_sem_marks_sem (semester),
    CONSTRAINT fk_marks_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    CONSTRAINT fk_marks_subject FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE,
    CONSTRAINT fk_marks_exam FOREIGN KEY (exam_id) REFERENCES exam_results_release(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 10. OTP Tokens (hashed)
CREATE TABLE IF NOT EXISTS otp_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    otp_hash VARCHAR(255) NOT NULL,
    purpose ENUM('login','password_reset') NOT NULL DEFAULT 'login',
    attempts INT NOT NULL DEFAULT 0,
    ip VARCHAR(50) DEFAULT NULL,
    expires_at DATETIME NOT NULL,
    used TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_otp_user_purpose (user_id, purpose, used),
    CONSTRAINT fk_otp_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 11. Login Attempts (throttling)
CREATE TABLE IF NOT EXISTS login_attempts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    identifier VARCHAR(60) NOT NULL,
    ip VARCHAR(50) NOT NULL,
    success TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_login_attempts_lookup (identifier, ip, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 12. Practice Problems (Coding Arena)
CREATE TABLE IF NOT EXISTS practice_problems (
    id INT AUTO_INCREMENT PRIMARY KEY,
    problem_number INT NOT NULL,
    subject_track ENUM('dsa','oop','dbms') NOT NULL DEFAULT 'dsa',
    title VARCHAR(180) NOT NULL,
    difficulty ENUM('Easy','Medium','Hard') NOT NULL DEFAULT 'Easy',
    topic VARCHAR(80) NOT NULL,
    description TEXT NOT NULL,
    constraints_json JSON NOT NULL,
    examples_json JSON NOT NULL,
    starter_code_json JSON NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_problem_track_num (subject_track, problem_number),
    INDEX idx_problem_track_diff (subject_track, difficulty)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 13. Practice Test Cases
CREATE TABLE IF NOT EXISTS practice_testcases (
    id INT AUTO_INCREMENT PRIMARY KEY,
    problem_id INT NOT NULL,
    input_data TEXT NOT NULL,
    expected_output TEXT NOT NULL,
    is_sample TINYINT(1) NOT NULL DEFAULT 0,
    CONSTRAINT fk_testcase_problem FOREIGN KEY (problem_id) REFERENCES practice_problems(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 14. Practice Submissions
CREATE TABLE IF NOT EXISTS practice_submissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    problem_id INT NOT NULL,
    language ENUM('python','java','cpp','c') NOT NULL DEFAULT 'python',
    code MEDIUMTEXT NOT NULL,
    verdict VARCHAR(60) NOT NULL DEFAULT 'Demo',
    runtime_ms INT DEFAULT NULL,
    passed_count INT NOT NULL DEFAULT 0,
    total_count INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_prac_sub_stu_prob (student_id, problem_id, verdict),
    CONSTRAINT fk_prac_sub_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    CONSTRAINT fk_prac_sub_problem FOREIGN KEY (problem_id) REFERENCES practice_problems(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 15. Notifications
CREATE TABLE IF NOT EXISTS notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    title VARCHAR(140) NOT NULL,
    message VARCHAR(255) NOT NULL,
    link VARCHAR(160) DEFAULT NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_notif_user_read (user_id, is_read),
    CONSTRAINT fk_notif_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 16. Audit Logs
CREATE TABLE IF NOT EXISTS audit_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT DEFAULT NULL,
    action VARCHAR(100) NOT NULL,
    detail TEXT,
    ip VARCHAR(50) DEFAULT NULL,
    status ENUM('SUCCESS','FAILURE') NOT NULL DEFAULT 'SUCCESS',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_audit_action_date (action, created_at),
    INDEX idx_audit_user (user_id),
    CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 17. System Settings
CREATE TABLE IF NOT EXISTS settings (
    setting_key VARCHAR(80) PRIMARY KEY,
    setting_value TEXT NOT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 18. DB-Backed Rate Limits (cannot be bypassed by clearing session cookie)
CREATE TABLE IF NOT EXISTS rate_limits (
    id INT AUTO_INCREMENT PRIMARY KEY,
    bucket VARCHAR(50) NOT NULL,
    rate_key VARCHAR(120) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_rl_lookup (bucket, rate_key, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 19. Numbered Migrations Tracker
CREATE TABLE IF NOT EXISTS schema_migrations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    migration VARCHAR(120) NOT NULL,
    applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_migration_name (migration)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
