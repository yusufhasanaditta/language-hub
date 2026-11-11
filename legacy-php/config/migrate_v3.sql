-- ============================================================================
-- B Academy — Migration v3
-- Fixes broken admin pages + adds new features
-- Run ONCE: mysql -u root b_academy < migrate_v3.sql
-- Safe to re-run (IF NOT EXISTS / ADD COLUMN IF NOT EXISTS everywhere)
-- ============================================================================

SET NAMES utf8mb4;
SET time_zone = '+06:00';

-- ── Coupons (admin/coupons.php crashes without this) ─────────────────────────
CREATE TABLE IF NOT EXISTS coupons (
  id          INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code        VARCHAR(50)   NOT NULL,
  type        ENUM('percent','flat') NOT NULL DEFAULT 'percent',
  value       DECIMAL(10,2) NOT NULL DEFAULT 0,
  min_order   DECIMAL(10,2) NOT NULL DEFAULT 0,
  max_uses    INT UNSIGNED  NOT NULL DEFAULT 0,
  used_count  INT UNSIGNED  NOT NULL DEFAULT 0,
  expires_at  DATE          NULL,
  is_active   TINYINT(1)    NOT NULL DEFAULT 1,
  created_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_code (code),
  KEY idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Settings (admin/settings.php crashes without this) ───────────────────────
CREATE TABLE IF NOT EXISTS settings (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `key`      VARCHAR(100) NOT NULL,
  `value`    TEXT         NULL,
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_key (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Exam leaderboard ─────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS exam_leaderboard (
  exam_id    INT UNSIGNED  NOT NULL,
  user_id    INT UNSIGNED  NOT NULL,
  attempt_id INT UNSIGNED  NOT NULL,
  score      DECIMAL(8,2)  NOT NULL DEFAULT 0,
  rank       SMALLINT      NULL,
  percentile DECIMAL(5,2)  NULL,
  updated_at DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (exam_id, user_id),
  KEY idx_exam_score (exam_id, score DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Course teachers pivot (multiple teachers per course) ──────────────────────
CREATE TABLE IF NOT EXISTS course_teachers (
  course_id  INT UNSIGNED NOT NULL,
  teacher_id INT UNSIGNED NOT NULL,
  is_primary TINYINT(1)   NOT NULL DEFAULT 0,
  sort_order SMALLINT     NOT NULL DEFAULT 0,
  PRIMARY KEY (course_id, teacher_id),
  KEY idx_course  (course_id),
  KEY idx_teacher (teacher_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Admin impersonation audit log ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS admin_impersonations (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  admin_id   INT UNSIGNED NOT NULL,
  target_id  INT UNSIGNED NOT NULL,
  ip_address VARCHAR(45)  NOT NULL,
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ended_at   DATETIME     NULL,
  KEY idx_admin (admin_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Rate limits ───────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS rate_limits (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  ip_address   VARCHAR(45)  NOT NULL,
  endpoint     VARCHAR(100) NOT NULL,
  hit_count    INT UNSIGNED NOT NULL DEFAULT 0,
  window_start DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_ip_ep (ip_address, endpoint)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Support tickets ───────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS support_tickets (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id     INT UNSIGNED NOT NULL,
  subject     VARCHAR(255) NOT NULL,
  body        TEXT         NOT NULL,
  status      ENUM('open','replied','closed') NOT NULL DEFAULT 'open',
  admin_reply TEXT         NULL,
  replied_at  DATETIME     NULL,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_user   (user_id),
  KEY idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Teacher profiles: ensure all columns exist ────────────────────────────────
ALTER TABLE teacher_profiles
  ADD COLUMN IF NOT EXISTS featured_order SMALLINT     NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS headline       VARCHAR(255) NULL,
  ADD COLUMN IF NOT EXISTS bio            TEXT         NULL,
  ADD COLUMN IF NOT EXISTS designation    VARCHAR(200) NULL,
  ADD COLUMN IF NOT EXISTS expertise      VARCHAR(500) NULL,
  ADD COLUMN IF NOT EXISTS fb_url         VARCHAR(300) NULL,
  ADD COLUMN IF NOT EXISTS yt_url         VARCHAR(300) NULL,
  ADD COLUMN IF NOT EXISTS website        VARCHAR(300) NULL,
  ADD COLUMN IF NOT EXISTS rating_avg     DECIMAL(3,2) NOT NULL DEFAULT 0.00,
  ADD COLUMN IF NOT EXISTS total_students INT UNSIGNED  NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS total_courses  INT UNSIGNED  NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS updated_at     DATETIME     NULL;

-- ── Exams: ensure all columns ─────────────────────────────────────────────────
ALTER TABLE exams
  ADD COLUMN IF NOT EXISTS start_at         DATETIME          NULL,
  ADD COLUMN IF NOT EXISTS end_at           DATETIME          NULL,
  ADD COLUMN IF NOT EXISTS show_leaderboard TINYINT(1)        NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS max_attempts     TINYINT           NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS exam_type        ENUM('mcq','cq')  NOT NULL DEFAULT 'mcq',
  ADD COLUMN IF NOT EXISTS is_free          TINYINT(1)        NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS total_marks      SMALLINT          NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS pass_marks       SMALLINT          NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS negative_mark    DECIMAL(4,2)      NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS description      TEXT              NULL,
  ADD COLUMN IF NOT EXISTS updated_at       DATETIME          NULL;

-- ── Exam attempts: extra columns ──────────────────────────────────────────────
ALTER TABLE exam_attempts
  ADD COLUMN IF NOT EXISTS drive_url        VARCHAR(500) NULL,
  ADD COLUMN IF NOT EXISTS time_taken       SMALLINT     NULL,
  ADD COLUMN IF NOT EXISTS total_correct    SMALLINT     NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS total_wrong      SMALLINT     NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS total_skipped    SMALLINT     NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS duration_minutes SMALLINT     NULL;

-- ── Exam questions: ensure body + sort_order columns ─────────────────────────
ALTER TABLE exam_questions
  ADD COLUMN IF NOT EXISTS body        TEXT NULL,
  ADD COLUMN IF NOT EXISTS sort_order  SMALLINT NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS explanation TEXT NULL,
  ADD COLUMN IF NOT EXISTS marks       DECIMAL(4,1) NOT NULL DEFAULT 1,
  ADD COLUMN IF NOT EXISTS correct_option CHAR(1) NULL;

-- ── Users: extra columns ──────────────────────────────────────────────────────
ALTER TABLE users
  ADD COLUMN IF NOT EXISTS roll_number  VARCHAR(20)  NULL,
  ADD COLUMN IF NOT EXISTS bio          TEXT         NULL,
  ADD COLUMN IF NOT EXISTS school       VARCHAR(200) NULL,
  ADD COLUMN IF NOT EXISTS college      VARCHAR(200) NULL,
  ADD COLUMN IF NOT EXISTS class_level  VARCHAR(50)  NULL,
  ADD COLUMN IF NOT EXISTS parent_name  VARCHAR(100) NULL,
  ADD COLUMN IF NOT EXISTS parent_phone VARCHAR(20)  NULL,
  ADD COLUMN IF NOT EXISTS target       VARCHAR(200) NULL;

-- ── Enrollments: progress ─────────────────────────────────────────────────────
ALTER TABLE enrollments
  ADD COLUMN IF NOT EXISTS progress_pct TINYINT UNSIGNED NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS enrolled_at  DATETIME NULL;

-- ── Success stories: featured ─────────────────────────────────────────────────
ALTER TABLE success_stories
  ADD COLUMN IF NOT EXISTS featured TINYINT(1) NOT NULL DEFAULT 0;

-- ── Courses: short_desc + computed columns ────────────────────────────────────
ALTER TABLE courses
  ADD COLUMN IF NOT EXISTS short_desc     VARCHAR(400) NULL,
  ADD COLUMN IF NOT EXISTS total_duration INT UNSIGNED  NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS enrolled_count INT UNSIGNED  NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS rating_avg     DECIMAL(3,2)  NOT NULL DEFAULT 0.00,
  ADD COLUMN IF NOT EXISTS rating_count   INT UNSIGNED  NOT NULL DEFAULT 0;

-- ── Messages: read_at ─────────────────────────────────────────────────────────
ALTER TABLE messages
  ADD COLUMN IF NOT EXISTS read_at DATETIME NULL;

-- ── Populate course_teachers from existing teacher_id ─────────────────────────
INSERT IGNORE INTO course_teachers (course_id, teacher_id, is_primary, sort_order)
SELECT id, teacher_id, 1, 0 FROM courses WHERE teacher_id IS NOT NULL AND teacher_id > 0;
