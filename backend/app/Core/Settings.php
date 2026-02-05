<?php
namespace App\Core;

/**
 * Minimal key/value settings store backed by MySQL.
 *
 * Table: app_settings(setting_key UNIQUE, setting_value TEXT)
 */
class Settings
{
    private static $ensured = false;

    private static function ensureTable(): void
    {
        if (self::$ensured) {
            return;
        }
        self::$ensured = true;

        try {
            $db = Database::getInstance();
            // Best-effort: create table for environments without migrations.
            $db->execute(
                "CREATE TABLE IF NOT EXISTS app_settings (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    setting_key VARCHAR(190) NOT NULL UNIQUE,
                    setting_value TEXT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_app_settings_key (setting_key)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        } catch (\Throwable $e) {
            // Ignore if the DB user lacks DDL privileges; reads/writes will fail naturally.
        }
    }

    public static function get(string $key, $default = null)
    {
        self::ensureTable();
        $db = Database::getInstance();
        $row = $db->fetchOne('SELECT setting_value FROM app_settings WHERE setting_key = ? LIMIT 1', [$key]);
        if (!$row) {
            return $default;
        }
        return $row['setting_value'];
    }

    public static function getInt(string $key, int $default = 0): int
    {
        $val = self::get($key, null);
        if ($val === null) {
            return $default;
        }
        if (is_numeric($val)) {
            return (int) $val;
        }
        return $default;
    }

    public static function set(string $key, $value): void
    {
        self::ensureTable();
        $db = Database::getInstance();
        $db->execute(
            'INSERT INTO app_settings (setting_key, setting_value, created_at, updated_at)
             VALUES (?, ?, NOW(), NOW())
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()',
            [$key, (string) $value]
        );
    }

    public static function setInt(string $key, int $value): void
    {
        self::set($key, (string) $value);
    }
}

