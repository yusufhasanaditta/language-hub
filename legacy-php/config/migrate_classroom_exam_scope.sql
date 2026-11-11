-- Scope exams/CQ exams to the specific virtual classroom they were created in,
-- so an exam created in one classroom doesn't appear as selectable in another.
ALTER TABLE `exams`
  ADD COLUMN `classroom_id` int(10) unsigned DEFAULT NULL AFTER `course_id`,
  ADD KEY `idx_classroom` (`classroom_id`),
  ADD CONSTRAINT `exams_classroom_fk` FOREIGN KEY (`classroom_id`) REFERENCES `virtual_classrooms` (`id`) ON DELETE SET NULL;

ALTER TABLE `cq_exams`
  ADD COLUMN `classroom_id` int(10) unsigned DEFAULT NULL AFTER `course_id`,
  ADD KEY `idx_classroom` (`classroom_id`),
  ADD CONSTRAINT `cq_exams_classroom_fk` FOREIGN KEY (`classroom_id`) REFERENCES `virtual_classrooms` (`id`) ON DELETE SET NULL;
