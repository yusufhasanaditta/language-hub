-- ═══════════════════════════════════════════════════════════════════════════
--  B Academy — migration v7
--  Retires the standalone Q&A block: any existing 'qna' block becomes a normal
--  FAQ block (same fields, nothing lost), then the enum drops 'qna'.
--  Safe to run more than once.
-- ═══════════════════════════════════════════════════════════════════════════

UPDATE `course_sections` SET `kind` = 'faq' WHERE `kind` = 'qna';

ALTER TABLE `course_sections`
  MODIFY COLUMN `kind` enum(
    'rich','features','faq','routine','stats','gallery','video','cta',
    'checklist','teachers','subjects','notice_cta'
  ) NOT NULL DEFAULT 'rich';
