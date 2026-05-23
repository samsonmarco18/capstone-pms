<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

function getAppSetting(string $key, ?string $default = null): ?string
{
    static $cache = [];

    $normalizedKey = trim($key);
    if ($normalizedKey === '') {
        return $default;
    }

    if (array_key_exists($normalizedKey, $cache)) {
        return $cache[$normalizedKey];
    }

    try {
        $conn = getConnection();
        $stmt = $conn->prepare('SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1');
        if ($stmt === false) {
            return $default;
        }

        $stmt->bind_param('s', $normalizedKey);
        if (!$stmt->execute()) {
            $stmt->close();
            return $default;
        }

        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();

        $value = isset($row['setting_value']) ? (string) $row['setting_value'] : $default;
        $cache[$normalizedKey] = $value;

        return $value;
    } catch (Throwable $e) {
        error_log('getAppSetting error for key ' . $normalizedKey . ': ' . $e->getMessage());
        return $default;
    }
}

function setAppSetting(string $key, ?string $value, string $description = '', string $type = 'system'): bool
{
    static $cache = [];

    $normalizedKey = trim($key);
    if ($normalizedKey === '') {
        return false;
    }

    try {
        $conn = getConnection();
        $storedValue = $value ?? '';
        $storedDescription = trim($description);
        $storedType = in_array($type, ['system', 'user', 'role'], true) ? $type : 'system';

        if (DB_DRIVER === 'pgsql') {
            $sql = '
                INSERT INTO settings (setting_key, setting_value, description, setting_type)
                VALUES (?, ?, ?, ?)
                ON CONFLICT (setting_key)
                DO UPDATE SET
                    setting_value = EXCLUDED.setting_value,
                    description = EXCLUDED.description,
                    setting_type = EXCLUDED.setting_type,
                    updated_at = CURRENT_TIMESTAMP
            ';
        } else {
            $sql = '
                INSERT INTO settings (setting_key, setting_value, description, setting_type)
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    setting_value = VALUES(setting_value),
                    description = VALUES(description),
                    setting_type = VALUES(setting_type),
                    updated_at = CURRENT_TIMESTAMP
            ';
        }

        $stmt = $conn->prepare($sql);
        if ($stmt === false) {
            return false;
        }

        $stmt->bind_param('ssss', $normalizedKey, $storedValue, $storedDescription, $storedType);
        $result = $stmt->execute();
        $stmt->close();

        if ($result) {
            $cache[$normalizedKey] = $storedValue;
        }

        return $result;
    } catch (Throwable $e) {
        error_log('setAppSetting error for key ' . $normalizedKey . ': ' . $e->getMessage());
        return false;
    }
}

function getAppSettings(array $keys): array
{
    $settings = [];

    foreach ($keys as $key) {
        $normalizedKey = trim((string) $key);
        if ($normalizedKey === '') {
            continue;
        }

        $settings[$normalizedKey] = getAppSetting($normalizedKey);
    }

    return $settings;
}
