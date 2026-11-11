-- ═══════════════════════════════════════════════════════════════════════════
--  B Academy — migration v4
--  Adds: notice feed (posts + reactions + comments), course page builder
--        sections, and invoice numbers on orders.
--  Safe to run more than once (IF NOT EXISTS / duplicate-safe).
-- ═══════════════════════════════════════════════════════════════════════════

-- ── 1. Notice feed ─────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `notices` (
  `id`          int(10) unsigned NOT NULL AUTO_INCREMENT,
  `author_id`   int(10) unsigned NOT NULL,
  `course_id`   int(10) unsigned DEFAULT NULL COMMENT 'NULL = whole site',
  `audience`    enum('all','students','course') NOT NULL DEFAULT 'all',
  `title`       varchar(200) DEFAULT NULL,
  `body`        text NOT NULL,
  `image`       varchar(500) DEFAULT NULL,
  `link_url`    varchar(500) DEFAULT NULL,
  `is_pinned`   tinyint(1) NOT NULL DEFAULT 0,
  `is_locked`   tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 = comments closed',
  `status`      enum('published','draft','archived') NOT NULL DEFAULT 'published',
  `view_count`  int(10) unsigned NOT NULL DEFAULT 0,
  `created_at`  timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at`  timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_author` (`author_id`),
  KEY `idx_course` (`course_id`),
  KEY `idx_feed` (`status`,`is_pinned`,`created_at`),
  CONSTRAINT `notices_author_fk` FOREIGN KEY (`author_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `notices_course_fk` FOREIGN KEY (`course_id`) REFERENCES `courses` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `notice_reactions` (
  `id`         int(10) unsigned NOT NULL AUTO_INCREMENT,
  `notice_id`  int(10) unsigned NOT NULL,
  `user_id`    int(10) unsigned NOT NULL,
  `reaction`   enum('like','love','care','wow','sad','angry') NOT NULL DEFAULT 'like',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_notice_user` (`notice_id`,`user_id`),
  KEY `idx_user` (`user_id`),
  CONSTRAINT `nr_notice_fk` FOREIGN KEY (`notice_id`) REFERENCES `notices` (`id`) ON DELETE CASCADE,
  CONSTRAINT `nr_user_fk`   FOREIGN KEY (`user_id`)   REFERENCES `users` (`id`)   ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `notice_comments` (
  `id`         int(10) unsigned NOT NULL AUTO_INCREMENT,
  `notice_id`  int(10) unsigned NOT NULL,
  `user_id`    int(10) unsigned NOT NULL,
  `parent_id`  int(10) unsigned DEFAULT NULL COMMENT 'reply-to comment',
  `body`       text NOT NULL,
  `is_hidden`  tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_notice` (`notice_id`,`created_at`),
  KEY `idx_user` (`user_id`),
  KEY `idx_parent` (`parent_id`),
  CONSTRAINT `nc_notice_fk` FOREIGN KEY (`notice_id`) REFERENCES `notices` (`id`) ON DELETE CASCADE,
  CONSTRAINT `nc_user_fk`   FOREIGN KEY (`user_id`)   REFERENCES `users` (`id`)   ON DELETE CASCADE,
  CONSTRAINT `nc_parent_fk` FOREIGN KEY (`parent_id`) REFERENCES `notice_comments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 2. Course page builder ─────────────────────────────────────────────────
-- `content` holds JSON whose shape depends on `kind`:
--   rich     {"html":"..."}
--   features [{"icon":"check","title":"...","text":"..."}]
--   faq      [{"q":"...","a":"..."}]
--   routine  [{"day":"...","time":"...","subject":"...","teacher":"..."}]
--   stats    [{"value":"...","label":"..."}]
--   gallery  [{"src":"...","caption":"..."}]
--   video    {"url":"...","caption":"..."}
--   cta      {"heading":"...","text":"...","btn_label":"...","btn_url":"..."}
CREATE TABLE IF NOT EXISTS `course_sections` (
  `id`         int(10) unsigned NOT NULL AUTO_INCREMENT,
  `course_id`  int(10) unsigned NOT NULL,
  `kind`       enum('rich','features','faq','routine','stats','gallery','video','cta') NOT NULL DEFAULT 'rich',
  `title`      varchar(200) DEFAULT NULL,
  `subtitle`   varchar(300) DEFAULT NULL,
  `content`    longtext DEFAULT NULL,
  `bg_style`   enum('plain','tint','dark') NOT NULL DEFAULT 'plain',
  `sort_order` smallint(5) unsigned NOT NULL DEFAULT 0,
  `is_active`  tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_course_order` (`course_id`,`sort_order`),
  CONSTRAINT `cs_course_fk` FOREIGN KEY (`course_id`) REFERENCES `courses` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 3. Invoice numbers on orders ───────────────────────────────────────────
-- MariaDB has no "ADD COLUMN IF NOT EXISTS" on every version, so guard it.
SET @has_invoice := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'invoice_no'
);
SET @sql := IF(@has_invoice = 0,
  'ALTER TABLE `orders` ADD COLUMN `invoice_no` varchar(30) DEFAULT NULL AFTER `id`, ADD UNIQUE KEY `uniq_invoice` (`invoice_no`)',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_bill := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'billing_name'
);
SET @sql := IF(@has_bill = 0,
  'ALTER TABLE `orders`
     ADD COLUMN `billing_name`  varchar(120) DEFAULT NULL,
     ADD COLUMN `billing_phone` varchar(20)  DEFAULT NULL,
     ADD COLUMN `billing_email` varchar(150) DEFAULT NULL',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Backfill invoice numbers for existing paid orders (BA-YYYY-000123)
UPDATE `orders`
   SET `invoice_no` = CONCAT('BA-', DATE_FORMAT(COALESCE(`paid_at`, `created_at`), '%Y'), '-', LPAD(`id`, 6, '0'))
 WHERE `invoice_no` IS NULL;
