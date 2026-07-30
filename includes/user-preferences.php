<?php
declare(strict_types=1);

function userPreferenceDefaults(): array
{
    return [
        'theme_preference' => 'tropical',
        'accent_color' => 'blue',
        'evortix_enabled' => 1,
        'evortix_suggestions' => 1,
        'evortix_sound' => 0,
        'in_app_notifications' => 1,
        'email_notifications' => 1,
        'task_reminders' => 1,
        'project_updates' => 1,
        'timezone' => 'Asia/Manila',
        'date_format' => 'M d, Y',
        'session_version' => 1,
    ];
}

function userPreferenceEnsureSchema(mysqli|PgsqlMysqliCompatConnection $conn): void
{
    $columns = [
        'theme_preference' => "VARCHAR(20) DEFAULT 'system'",
        'accent_color' => "VARCHAR(20) DEFAULT 'blue'",
        'evortix_enabled' => 'SMALLINT DEFAULT 1',
        'evortix_suggestions' => 'SMALLINT DEFAULT 1',
        'evortix_sound' => 'SMALLINT DEFAULT 0',
        'in_app_notifications' => 'SMALLINT DEFAULT 1',
        'timezone' => "VARCHAR(64) DEFAULT 'Asia/Manila'",
        'date_format' => "VARCHAR(20) DEFAULT 'M d, Y'",
        'session_version' => 'INT DEFAULT 1',
        'sessions_invalid_before' => (DB_DRIVER === 'pgsql' ? 'TIMESTAMP NULL' : 'DATETIME NULL'),
    ];

    foreach ($columns as $column => $definition) {
        if (dbColumnExists($conn, 'users', $column)) {
            continue;
        }

        if ($conn->query("ALTER TABLE users ADD COLUMN {$column} {$definition}") === false) {
            throw new RuntimeException("Unable to add the user preference field {$column}.");
        }
    }
}

function userPreferencesFromUser(?array $user): array
{
    $preferences = userPreferenceDefaults();
    if (!$user) {
        return $preferences;
    }

    foreach ($preferences as $key => $default) {
        if (array_key_exists($key, $user) && $user[$key] !== null) {
            $preferences[$key] = $user[$key];
        }
    }

    foreach ([
        'evortix_enabled',
        'evortix_suggestions',
        'evortix_sound',
        'in_app_notifications',
        'email_notifications',
        'task_reminders',
        'project_updates',
        'session_version',
    ] as $integerKey) {
        $preferences[$integerKey] = (int) $preferences[$integerKey];
    }

    // Light/dark is controlled by the navbar. Migrate the retired appearance
    // choices to the Tropical skin so every account has a real visual theme.
    if (!in_array((string) $preferences['theme_preference'], ['tropical', 'futuristic'], true)) {
        $preferences['theme_preference'] = 'tropical';
    }

    return $preferences;
}

function userPreferenceThemeOptions(): array
{
    return [
        'tropical' => 'Tropical Coast',
        'futuristic' => 'Futuristic Neon',
    ];
}

function userPreferenceAccentOptions(): array
{
    return [
        'blue' => [
            'label' => 'ERNI Blue',
            'primary' => '#0d556b',
            'light' => '#4da8da',
            'dark' => '#0a4454',
        ],
        'sky' => [
            'label' => 'Sky',
            'primary' => '#1677a5',
            'light' => '#59b9e6',
            'dark' => '#0f5d82',
        ],
        'teal' => [
            'label' => 'Teal',
            'primary' => '#0f766e',
            'light' => '#43b7ad',
            'dark' => '#115e59',
        ],
        'indigo' => [
            'label' => 'Indigo',
            'primary' => '#4f46a5',
            'light' => '#818cf8',
            'dark' => '#373078',
        ],
    ];
}

function userPreferenceTimezoneOptions(): array
{
    return [
        'Asia/Manila' => 'Asia/Manila (PHT)',
        'Asia/Singapore' => 'Asia/Singapore',
        'Asia/Tokyo' => 'Asia/Tokyo',
        'UTC' => 'UTC',
    ];
}

function userPreferenceDateFormatOptions(): array
{
    return [
        'M d, Y' => date('M d, Y'),
        'd M Y' => date('d M Y'),
        'm/d/Y' => date('m/d/Y'),
        'd/m/Y' => date('d/m/Y'),
        'Y-m-d' => date('Y-m-d'),
    ];
}
