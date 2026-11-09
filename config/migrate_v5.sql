-- ═══════════════════════════════════════════════════════════════════════════
--  B Academy — migration v5
--  Course landing-page settings: campaign strip, EMI note, helpline button,
--  tutorial call-out and hero video. All admin-editable per course.
--  Safe to run more than once.
-- ═══════════════════════════════════════════════════════════════════════════

SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'courses' AND COLUMN_NAME = 'campaign_text');
SET @sql := IF(@has = 0,
  "ALTER TABLE `courses`
     ADD COLUMN `campaign_text`  varchar(255) DEFAULT NULL COMMENT 'green strip above the sticky bar',
     ADD COLUMN `emi_text`       varchar(160) DEFAULT NULL COMMENT 'e.g. EMI available on credit cards',
     ADD COLUMN `discount_note`  varchar(80)  DEFAULT NULL COMMENT 'e.g. 1,500 off',
     ADD COLUMN `helpline_phone` varchar(40)  DEFAULT NULL,
     ADD COLUMN `helpline_label` varchar(60)  DEFAULT NULL,
     ADD COLUMN `hero_video_url` varchar(500) DEFAULT NULL COMMENT 'plays from the hero thumbnail',
     ADD COLUMN `tutorial_url`   varchar(500) DEFAULT NULL,
     ADD COLUMN `tutorial_note`  varchar(255) DEFAULT NULL",
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- New block kinds for the page builder
ALTER TABLE `course_sections`
  MODIFY COLUMN `kind` enum(
    'rich','features','faq','routine','stats','gallery','video','cta',
    'checklist','teachers','subjects','notice_cta'
  ) NOT NULL DEFAULT 'rich';
