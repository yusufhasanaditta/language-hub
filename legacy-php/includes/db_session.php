<?php
// Sessions stored in MySQL. Used on Vercel, where each request may run on a
// different machine and files written to /tmp are not shared.
// Table: config/migrate_sessions.sql
defined('B_ACADEMY') or die('Direct access forbidden.');

final class DbSessionHandler implements SessionHandlerInterface, SessionUpdateTimestampHandlerInterface {
    public function open(string $path, string $name): bool { return true; }
    public function close(): bool { return true; }

    public function read(string $id): string|false {
        $row = db_row("SELECT data FROM php_sessions WHERE id=? AND updated_at > ?", [$id, time() - SESSION_LIFETIME]);
        return $row ? (string)$row['data'] : '';
    }

    public function write(string $id, string $data): bool {
        db_exec("INSERT INTO php_sessions (id, data, updated_at) VALUES (?,?,?)
                 ON DUPLICATE KEY UPDATE data=VALUES(data), updated_at=VALUES(updated_at)", [$id, $data, time()]);
        return true;
    }

    public function destroy(string $id): bool {
        db_exec("DELETE FROM php_sessions WHERE id=?", [$id]);
        return true;
    }

    public function gc(int $max_lifetime): int|false {
        return db_exec("DELETE FROM php_sessions WHERE updated_at < ?", [time() - $max_lifetime]);
    }

    // use_strict_mode: only accept IDs that already exist
    public function validateId(string $id): bool {
        return (bool)db_val("SELECT 1 FROM php_sessions WHERE id=?", [$id]);
    }

    public function updateTimestamp(string $id, string $data): bool {
        db_exec("UPDATE php_sessions SET updated_at=? WHERE id=?", [time(), $id]);
        return true;
    }
}
