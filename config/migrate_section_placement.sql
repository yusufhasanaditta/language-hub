-- Lets admin choose whether a Course Page Builder block appears in the
-- course page's Overview tab or its Curriculum tab.
ALTER TABLE `course_sections`
  ADD COLUMN `placement` ENUM('overview','curriculum') NOT NULL DEFAULT 'overview' AFTER `kind`;
