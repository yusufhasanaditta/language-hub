-- These columns were TIMESTAMP with ON UPDATE CURRENT_TIMESTAMP, so editing a
-- row (e.g. adding a recording link) silently moved the class to "now".
ALTER TABLE live_sessions MODIFY scheduled_at DATETIME NOT NULL;
ALTER TABLE schedules     MODIFY scheduled_at DATETIME NOT NULL;
ALTER TABLE otps          MODIFY expires_at   DATETIME NOT NULL;
