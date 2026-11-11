-- teacher/live.php saves a description and created_at; the table had neither.
ALTER TABLE live_sessions ADD COLUMN IF NOT EXISTS description TEXT NULL AFTER title;
ALTER TABLE live_sessions ADD COLUMN IF NOT EXISTS created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP;
