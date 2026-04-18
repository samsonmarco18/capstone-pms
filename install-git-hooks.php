<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

$cwd = getcwd() ?: __DIR__;
$gitHooksDir = $cwd . DIRECTORY_SEPARATOR . '.git' . DIRECTORY_SEPARATOR . 'hooks';

if (!is_dir($gitHooksDir)) {
    fwrite(STDERR, "Cannot find a Git repository here.\n");
    fwrite(STDERR, "Run this from the project root where the .git directory exists.\n");
    exit(1);
}

$options = getopt('', ['url::', 'help::']);

if (isset($options['help'])) {
    echo "Usage:\n";
    echo "  php install-git-hooks.php --url=\"https://example.com/api/version-control/sync.php?token=...\"\n\n";
    echo "If no --url option is provided, you will be prompted for the PMS sync URL.\n";
    exit(0);
}

$url = $options['url'] ?? null;
if (!$url) {
    echo "Enter the full PMS webhook sync URL (from the repository version-control setup page):\n";
    $url = trim((string) fgets(STDIN));
}

$url = trim((string) $url);
if ($url === '') {
    fwrite(STDERR, "No URL was provided. Installation aborted.\n");
    exit(1);
}

if (!preg_match('#^https?://#i', $url)) {
    fwrite(STDERR, "The URL must start with http:// or https://.\n");
    exit(1);
}

$hooks = [
    'post-commit' => 'commit',
    'pre-push' => 'push',
    'post-merge' => 'pull',
];

$scriptTemplate = <<<'HOOK'
#!/bin/sh
# ERNI PMS Git hook installer
PMS_URL="%s"

json_escape() {
  printf '%%s' "$1" | sed 's/\\/\\\\/g; s/"/\\"/g' | tr '\n' ' '
}

BRANCH=$(git rev-parse --abbrev-ref HEAD)
COMMIT_HASH=$(git rev-parse HEAD)
TITLE=$(git log -1 --pretty=%s)
BODY=$(git log -1 --pretty=%B)
AUTHOR_NAME=$(git log -1 --pretty=%an)
AUTHOR_EMAIL=$(git log -1 --pretty=%ae)
REMOTE_URL=$(git config --get remote.origin.url)
PAYLOAD=$(printf '{"event_type":"%s","branch_name":"%s","commit_hash":"%s","commit_title":"%s","commit_message":"%s","author_name":"%s","author_email":"%s","remote_url":"%s","platform":"git-client"}' "$(json_escape "$BRANCH")" "$(json_escape "$COMMIT_HASH")" "$(json_escape "$TITLE")" "$(json_escape "$BODY")" "$(json_escape "$AUTHOR_NAME")" "$(json_escape "$AUTHOR_EMAIL")" "$(json_escape "$REMOTE_URL")")

curl -s -X POST "$PMS_URL" -H "Content-Type: application/json" -d "$PAYLOAD" >/dev/null 2>&1
HOOK;

foreach ($hooks as $fileName => $eventType) {
    $hookPath = $gitHooksDir . DIRECTORY_SEPARATOR . $fileName;

    if (file_exists($hookPath)) {
        $backupPath = $hookPath . '.bak';
        if (!rename($hookPath, $backupPath)) {
            fwrite(STDERR, "Failed to back up existing hook: $fileName\n");
            exit(1);
        }
        echo "Existing hook renamed to $backupPath\n";
    }

    $hookContent = sprintf($scriptTemplate, $url, $eventType);
    if (file_put_contents($hookPath, $hookContent) === false) {
        fwrite(STDERR, "Failed to write hook file: $hookPath\n");
        exit(1);
    }

    if (!@chmod($hookPath, 0755)) {
        echo "Warning: could not make $hookPath executable. Set executable permission manually if needed.\n";
    }

    echo "Installed $fileName\n";
}

echo "\nGit hooks installed successfully.\n";
