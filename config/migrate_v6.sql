-- ═══════════════════════════════════════════════════════════════════════════
--  B Academy — migration v6
--  Allows the searchable "Q&A" block kind in the course page builder.
--  Safe to run more than once.
-- ═══════════════════════════════════════════════════════════════════════════

ALTER TABLE `course_sections`
  MODIFY COLUMN `kind` enum(
    'rich','features','faq','routine','stats','gallery','video','cta',
    'checklist','teachers','subjects','notice_cta','qna'
  ) NOT NULL DEFAULT 'rich';
