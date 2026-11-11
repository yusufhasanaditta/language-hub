-- ============================================================================
-- B Academy — Migration v2 (Student features, stories, leaderboard)
-- Run once on fresh or existing DB. Uses IF NOT EXISTS / IF NOT COLUMN.
-- ============================================================================

SET NAMES utf8mb4;
SET time_zone = '+06:00';

-- ── Extra user columns ────────────────────────────────────────────────────────
ALTER TABLE users
  ADD COLUMN IF NOT EXISTS bio          TEXT         NULL  AFTER email,
  ADD COLUMN IF NOT EXISTS school       VARCHAR(200) NULL  AFTER bio,
  ADD COLUMN IF NOT EXISTS college      VARCHAR(200) NULL  AFTER school,
  ADD COLUMN IF NOT EXISTS university   VARCHAR(200) NULL  AFTER college,
  ADD COLUMN IF NOT EXISTS academic_bg  TEXT         NULL  AFTER university,
  ADD COLUMN IF NOT EXISTS class_level  VARCHAR(50)  NULL  AFTER academic_bg,
  ADD COLUMN IF NOT EXISTS skills       VARCHAR(500) NULL  AFTER class_level,
  ADD COLUMN IF NOT EXISTS parent_name  VARCHAR(100) NULL  AFTER skills,
  ADD COLUMN IF NOT EXISTS parent_phone VARCHAR(20)  NULL  AFTER parent_name,
  ADD COLUMN IF NOT EXISTS address      VARCHAR(500) NULL  AFTER parent_phone,
  ADD COLUMN IF NOT EXISTS username     VARCHAR(80)  NULL  UNIQUE AFTER name;

-- ── Teacher profile extras ────────────────────────────────────────────────────
ALTER TABLE teacher_profiles
  ADD COLUMN IF NOT EXISTS fb_url    VARCHAR(300) NULL AFTER bio,
  ADD COLUMN IF NOT EXISTS yt_url    VARCHAR(300) NULL AFTER fb_url,
  ADD COLUMN IF NOT EXISTS website   VARCHAR(300) NULL AFTER yt_url,
  ADD COLUMN IF NOT EXISTS expertise VARCHAR(500) NULL AFTER headline;

-- ── Exams ─────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS exams (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  course_id         INT UNSIGNED NOT NULL,
  title             VARCHAR(255) NOT NULL,
  description       TEXT         NULL,
  duration_minutes  SMALLINT     NOT NULL DEFAULT 60,
  total_marks       SMALLINT     NOT NULL DEFAULT 100,
  pass_marks        SMALLINT     NOT NULL DEFAULT 40,
  negative_mark     DECIMAL(4,2) NOT NULL DEFAULT 0,
  total_questions   SMALLINT     NOT NULL DEFAULT 0,
  max_attempts      TINYINT      NOT NULL DEFAULT 0, -- 0 = unlimited
  starts_at         DATETIME     NOT NULL,
  ends_at           DATETIME     NULL,
  is_free           TINYINT(1)   NOT NULL DEFAULT 0,
  status            ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
  created_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_course (course_id),
  KEY idx_status (status),
  KEY idx_starts (starts_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Exam questions ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS exam_questions (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  exam_id       INT UNSIGNED NOT NULL,
  question      TEXT         NOT NULL,
  option_a      VARCHAR(500) NOT NULL,
  option_b      VARCHAR(500) NOT NULL,
  option_c      VARCHAR(500) NULL,
  option_d      VARCHAR(500) NULL,
  correct       CHAR(1)      NOT NULL, -- 'a','b','c','d'
  explanation   TEXT         NULL,
  marks         DECIMAL(4,1) NOT NULL DEFAULT 1,
  sort_order    SMALLINT     NOT NULL DEFAULT 0,
  KEY idx_exam (exam_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Exam attempts ─────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS exam_attempts (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id       INT UNSIGNED NOT NULL,
  exam_id       INT UNSIGNED NOT NULL,
  score         DECIMAL(8,2) NOT NULL DEFAULT 0,
  rank          SMALLINT     NULL,
  status        ENUM('in_progress','submitted','graded') NOT NULL DEFAULT 'in_progress',
  started_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  submitted_at  DATETIME     NULL,
  KEY idx_user   (user_id),
  KEY idx_exam   (exam_id),
  KEY idx_status (status),
  KEY idx_score  (score DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Attempt answers ───────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS exam_attempt_answers (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  attempt_id  INT UNSIGNED NOT NULL,
  question_id INT UNSIGNED NOT NULL,
  chosen      CHAR(1)      NULL,
  is_correct  TINYINT(1)   NOT NULL DEFAULT 0,
  KEY idx_attempt (attempt_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Modules (course chapters) ─────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS modules (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  course_id   INT UNSIGNED NOT NULL,
  title       VARCHAR(255) NOT NULL,
  sort_order  SMALLINT     NOT NULL DEFAULT 0,
  KEY idx_course (course_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Lessons ───────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS lessons (
  id               INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
  course_id        INT UNSIGNED  NOT NULL,
  module_id        INT UNSIGNED  NULL,
  title            VARCHAR(255)  NOT NULL,
  kind             ENUM('video','pdf','quiz','live','article') NOT NULL DEFAULT 'video',
  video_url        VARCHAR(500)  NULL,
  duration         SMALLINT      NULL COMMENT 'minutes',
  duration_seconds INT           NULL,
  sort_order       SMALLINT      NOT NULL DEFAULT 0,
  is_free          TINYINT(1)    NOT NULL DEFAULT 0,
  is_published     TINYINT(1)    NOT NULL DEFAULT 1,
  created_at       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_course   (course_id),
  KEY idx_module   (module_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Lesson progress ───────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS lesson_progress (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id          INT UNSIGNED NOT NULL,
  lesson_id        INT UNSIGNED NOT NULL,
  duration_watched INT          NOT NULL DEFAULT 0 COMMENT 'seconds',
  completed        TINYINT(1)   NOT NULL DEFAULT 0,
  updated_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_user_lesson (user_id, lesson_id),
  KEY idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Live sessions ─────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS live_sessions (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  teacher_id     INT UNSIGNED NOT NULL,
  course_id      INT UNSIGNED NULL,
  title          VARCHAR(255) NOT NULL,
  description    TEXT         NULL,
  platform       ENUM('zoom','meet','youtube','other') NOT NULL DEFAULT 'zoom',
  join_url       VARCHAR(500) NULL,
  scheduled_at   DATETIME     NOT NULL,
  duration_mins  SMALLINT     NOT NULL DEFAULT 60,
  status         ENUM('scheduled','live','ended','cancelled') NOT NULL DEFAULT 'scheduled',
  recording_url  VARCHAR(500) NULL,
  started_at     DATETIME     NULL,
  ended_at       DATETIME     NULL,
  created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_teacher (teacher_id),
  KEY idx_course  (course_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Success stories ───────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS success_stories (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id     INT UNSIGNED NOT NULL,
  course_id   INT UNSIGNED NULL,
  quote       TEXT         NOT NULL,
  role_after  VARCHAR(200) NULL COMMENT 'Student current position/career',
  rating      TINYINT      NOT NULL DEFAULT 5,
  is_approved TINYINT(1)   NOT NULL DEFAULT 0,
  sort_order  SMALLINT     NOT NULL DEFAULT 0,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_user     (user_id),
  KEY idx_approved (is_approved)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Enrollments (ensure progress_pct column) ──────────────────────────────────
ALTER TABLE enrollments
  ADD COLUMN IF NOT EXISTS progress_pct TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER status;

-- ── Courses (ensure extra columns) ───────────────────────────────────────────
ALTER TABLE courses
  ADD COLUMN IF NOT EXISTS enrolled_count INT UNSIGNED NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS rating_avg     DECIMAL(3,2) NOT NULL DEFAULT 0.00,
  ADD COLUMN IF NOT EXISTS rating_count   INT UNSIGNED NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS total_duration INT UNSIGNED NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS short_desc     VARCHAR(400) NULL;

-- ── Teacher profile (ensure extra columns) ────────────────────────────────────
ALTER TABLE teacher_profiles
  ADD COLUMN IF NOT EXISTS headline       VARCHAR(255) NULL AFTER user_id,
  ADD COLUMN IF NOT EXISTS bio            TEXT         NULL,
  ADD COLUMN IF NOT EXISTS designation    VARCHAR(200) NULL,
  ADD COLUMN IF NOT EXISTS rating_avg     DECIMAL(3,2) NOT NULL DEFAULT 0.00,
  ADD COLUMN IF NOT EXISTS total_students INT UNSIGNED NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS total_courses  INT UNSIGNED NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS featured_order SMALLINT     NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS updated_at     DATETIME     NULL;

-- ── Roles (ensure teacher row) ───────────────────────────────────────────────
INSERT IGNORE INTO roles (id, name) VALUES
  (1, 'student'),
  (2, 'teacher'),
  (3, 'ta'),
  (4, 'moderator'),
  (5, 'ops'),
  (6, 'admin');

-- ── Indexes for hot queries ───────────────────────────────────────────────────
CREATE INDEX IF NOT EXISTS idx_courses_status   ON courses (status, is_featured);
CREATE INDEX IF NOT EXISTS idx_courses_category ON courses (category_id, status);
CREATE INDEX IF NOT EXISTS idx_enrollments_user ON enrollments (user_id, status);
CREATE INDEX IF NOT EXISTS idx_reviews_course   ON reviews (course_id, is_approved);

-- ── Settings table ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS settings (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `key`      VARCHAR(100) NOT NULL,
  `value`    TEXT         NULL,
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_key (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Student-teacher messaging (course-scoped) ─────────────────────────────────
CREATE TABLE IF NOT EXISTS messages (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  course_id   INT UNSIGNED NOT NULL,
  sender_id   INT UNSIGNED NOT NULL,
  receiver_id INT UNSIGNED NOT NULL,
  body        TEXT         NOT NULL,
  read_at     DATETIME     NULL,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_course   (course_id),
  KEY idx_sender   (sender_id),
  KEY idx_receiver (receiver_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Teacher education background ───────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS teacher_education (
  id                 INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
  teacher_profile_id INT UNSIGNED  NOT NULL,
  degree             VARCHAR(200)  NOT NULL,
  institution        VARCHAR(300)  NOT NULL,
  year_from          SMALLINT      NULL,
  year_to            SMALLINT      NULL,
  description        VARCHAR(500)  NULL,
  sort_order         SMALLINT      NOT NULL DEFAULT 0,
  KEY idx_tp (teacher_profile_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Course materials ─────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS course_materials (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  course_id  INT UNSIGNED NOT NULL,
  lesson_id  INT UNSIGNED NULL,
  title      VARCHAR(255) NOT NULL,
  file_url   VARCHAR(500) NOT NULL,
  file_type  VARCHAR(50)  NULL,
  sort_order SMALLINT     NOT NULL DEFAULT 0,
  is_free    TINYINT(1)   NOT NULL DEFAULT 0,
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_course (course_id),
  KEY idx_lesson (lesson_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Teacher feedback to students ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS teacher_feedback (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  teacher_profile_id INT UNSIGNED NOT NULL,
  course_id          INT UNSIGNED NOT NULL,
  student_id         INT UNSIGNED NULL COMMENT 'NULL = broadcast to all course students',
  subject            VARCHAR(255) NOT NULL,
  body               TEXT         NOT NULL,
  created_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_tp      (teacher_profile_id),
  KEY idx_course  (course_id),
  KEY idx_student (student_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Exam: add type + free flag + updated_at ───────────────────────────────────
ALTER TABLE exams
  ADD COLUMN IF NOT EXISTS exam_type  ENUM('mcq','cq') NOT NULL DEFAULT 'mcq',
  ADD COLUMN IF NOT EXISTS is_free    TINYINT(1)        NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS updated_at DATETIME          NULL;

-- ── Exam attempts: CQ Google Drive link ───────────────────────────────────────
ALTER TABLE exam_attempts
  ADD COLUMN IF NOT EXISTS drive_url VARCHAR(500) NULL COMMENT 'CQ answer Google Drive link';

-- ── Live sessions: ensure recording_url exists ────────────────────────────────
ALTER TABLE live_sessions
  ADD COLUMN IF NOT EXISTS recording_url VARCHAR(500) NULL COMMENT 'YouTube recording link',
  ADD COLUMN IF NOT EXISTS meeting_url   VARCHAR(500) NULL COMMENT 'Alias for join_url',
  ADD COLUMN IF NOT EXISTS duration_minutes SMALLINT  NULL,
  ADD COLUMN IF NOT EXISTS updated_at    DATETIME     NULL;

-- ── Admin stories page ───────────────────────────────────────────────────────
ALTER TABLE success_stories
  ADD COLUMN IF NOT EXISTS featured TINYINT(1) NOT NULL DEFAULT 0;

-- ── Exam attempts: grading columns for exam-take.php ─────────────────────────
ALTER TABLE exam_attempts
  ADD COLUMN IF NOT EXISTS time_taken     SMALLINT     NULL    COMMENT 'seconds',
  ADD COLUMN IF NOT EXISTS total_correct  SMALLINT     NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS total_wrong    SMALLINT     NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS total_skipped  SMALLINT     NOT NULL DEFAULT 0;

-- ── Support tickets (help page) ──────────────────────────────────────────────
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

-- ── Performance indexes ───────────────────────────────────────────────────────
-- messages: conversation lookup (course + both participants)
CREATE INDEX IF NOT EXISTS idx_messages_conversation
  ON messages (course_id, sender_id, receiver_id);

-- exam_attempts: user exam history + status filter
CREATE INDEX IF NOT EXISTS idx_ea_user_exam_status
  ON exam_attempts (user_id, exam_id, status);

-- exam_attempts: leaderboard sort (by exam, ordered by score)
CREATE INDEX IF NOT EXISTS idx_ea_exam_score
  ON exam_attempts (exam_id, score DESC, submitted_at ASC);

-- enrollments: completed courses for certificate page
CREATE INDEX IF NOT EXISTS idx_enroll_progress
  ON enrollments (user_id, progress_pct);

-- live_sessions: recordings listing
CREATE INDEX IF NOT EXISTS idx_live_course_status
  ON live_sessions (course_id, status);

-- course_materials: lookup by course + free flag
CREATE INDEX IF NOT EXISTS idx_materials_course_free
  ON course_materials (course_id, is_free);
