<?php
declare(strict_types=1);

header('Content-Type: application/json');

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/version-control.php';

function vcSyncRespond(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload);
    exit();
}

function vcSyncNormalizeBranch(?string $ref): string
{
    $value = trim((string) $ref);
    if (str_starts_with($value, 'refs/heads/')) {
        return substr($value, 11);
    }

    return $value;
}

function vcSyncBuildFileChanges(array $added, array $modified, array $removed): array
{
    $changes = [];

    foreach ($added as $path) {
        $changes[] = [
            'path' => (string) $path,
            'change_type' => 'added',
        ];
    }

    foreach ($modified as $path) {
        $changes[] = [
            'path' => (string) $path,
            'change_type' => 'modified',
        ];
    }

    foreach ($removed as $path) {
        $changes[] = [
            'path' => (string) $path,
            'change_type' => 'deleted',
        ];
    }

    return $changes;
}

function vcSyncHandleGenericPayload(mysqli|PgsqlMysqliCompatConnection $conn, array $repository, array $payload): array
{
    $operationType = trim((string) ($payload['event_type'] ?? $payload['operation_type'] ?? 'commit'));
    $branchName = vcSyncNormalizeBranch((string) ($payload['branch_name'] ?? ''));
    $result = vcSyncExternalCommit(
        $conn,
        $repository,
        [
            'operation_type' => $operationType,
            'branch_name' => $branchName,
            'commit_hash' => (string) ($payload['commit_hash'] ?? ''),
            'commit_title' => (string) ($payload['commit_title'] ?? ''),
            'commit_message' => (string) ($payload['commit_message'] ?? ''),
            'files_changed' => (int) ($payload['files_changed'] ?? 0),
            'lines_added' => (int) ($payload['lines_added'] ?? 0),
            'lines_removed' => (int) ($payload['lines_removed'] ?? 0),
            'author_name' => (string) ($payload['author_name'] ?? ''),
            'author_email' => (string) ($payload['author_email'] ?? ''),
            'pms_username' => (string) ($payload['pms_username'] ?? ''),
            'remote_url' => (string) ($payload['remote_url'] ?? ''),
            'platform' => (string) ($payload['platform'] ?? ''),
            'provider' => (string) ($payload['provider'] ?? ''),
            'notes' => (string) ($payload['notes'] ?? ''),
            'file_changes' => is_array($payload['file_changes'] ?? null) ? $payload['file_changes'] : null,
            'file_changes_b64' => (string) ($payload['file_changes_b64'] ?? ''),
            'file_changes_text' => (string) ($payload['file_changes_text'] ?? ''),
            'file_stats_b64' => (string) ($payload['file_stats_b64'] ?? ''),
            'file_stats_text' => (string) ($payload['file_stats_text'] ?? ''),
        ]
    );

    return [
        'synced' => 1,
        'last_commit_hash' => $result['commit_hash'],
        'operation_type' => $operationType,
    ];
}

function vcSyncHandleGitHubPayload(mysqli|PgsqlMysqliCompatConnection $conn, array $repository, array $payload): array
{
    $branchName = vcSyncNormalizeBranch((string) ($payload['ref'] ?? ''));
    $remoteUrl = (string) (($payload['repository']['html_url'] ?? $payload['repository']['clone_url'] ?? ''));
    $sender = (string) (($payload['sender']['login'] ?? 'GitHub'));
    $commits = is_array($payload['commits'] ?? null) ? $payload['commits'] : [];
    $synced = 0;
    $lastHash = '';

    foreach ($commits as $commit) {
        $message = trim((string) ($commit['message'] ?? ''));
        $title = $message !== '' ? strtok($message, "\n") : 'GitHub push';
        $result = vcSyncExternalCommit(
            $conn,
            $repository,
            [
                'operation_type' => 'commit',
                'branch_name' => $branchName,
                'commit_hash' => (string) ($commit['id'] ?? ''),
                'commit_title' => $title ?: 'GitHub push',
                'commit_message' => $message,
                'files_changed' => count((array) ($commit['added'] ?? [])) + count((array) ($commit['modified'] ?? [])) + count((array) ($commit['removed'] ?? [])),
                'lines_added' => 0,
                'lines_removed' => 0,
                'author_name' => (string) ($commit['author']['name'] ?? $sender),
                'author_email' => (string) ($commit['author']['email'] ?? ''),
                'remote_url' => $remoteUrl,
                'provider' => 'github',
                'platform' => 'github',
                'notes' => 'GitHub webhook sync',
                'file_changes' => vcSyncBuildFileChanges(
                    (array) ($commit['added'] ?? []),
                    (array) ($commit['modified'] ?? []),
                    (array) ($commit['removed'] ?? [])
                ),
            ]
        );
        $lastHash = $result['commit_hash'];
        $synced++;
    }

    $afterHash = (string) ($payload['after'] ?? $lastHash);
    if ($afterHash !== '') {
        vcSyncExternalCommit(
            $conn,
            $repository,
            [
                'operation_type' => 'push',
                'branch_name' => $branchName,
                'commit_hash' => $afterHash,
                'commit_title' => 'GitHub push to ' . ($branchName !== '' ? $branchName : 'repository'),
                'commit_message' => 'Webhook push received from GitHub',
                'remote_url' => $remoteUrl,
                'provider' => 'github',
                'platform' => 'github',
                'author_name' => $sender,
                'notes' => 'GitHub push webhook',
            ]
        );
    }

    return [
        'synced' => max(1, $synced),
        'last_commit_hash' => $afterHash,
        'operation_type' => 'push',
    ];
}

function vcSyncHandleGitLabPayload(mysqli|PgsqlMysqliCompatConnection $conn, array $repository, array $payload): array
{
    $branchName = vcSyncNormalizeBranch((string) ($payload['ref'] ?? ''));
    $remoteUrl = (string) (($payload['project']['web_url'] ?? $payload['repository']['homepage'] ?? ''));
    $userName = (string) ($payload['user_username'] ?? $payload['user_name'] ?? 'GitLab');
    $commits = is_array($payload['commits'] ?? null) ? $payload['commits'] : [];
    $synced = 0;
    $lastHash = '';

    foreach ($commits as $commit) {
        $message = trim((string) ($commit['message'] ?? ''));
        $title = $message !== '' ? strtok($message, "\n") : 'GitLab push';
        $result = vcSyncExternalCommit(
            $conn,
            $repository,
            [
                'operation_type' => 'commit',
                'branch_name' => $branchName,
                'commit_hash' => (string) ($commit['id'] ?? ''),
                'commit_title' => $title ?: 'GitLab push',
                'commit_message' => $message,
                'author_name' => (string) ($commit['author']['name'] ?? $userName),
                'author_email' => (string) ($commit['author']['email'] ?? ''),
                'remote_url' => $remoteUrl,
                'provider' => 'gitlab',
                'platform' => 'gitlab',
                'notes' => 'GitLab webhook sync',
                'file_changes' => vcSyncBuildFileChanges(
                    (array) ($commit['added'] ?? []),
                    (array) ($commit['modified'] ?? []),
                    (array) ($commit['removed'] ?? [])
                ),
            ]
        );
        $lastHash = $result['commit_hash'];
        $synced++;
    }

    $afterHash = (string) ($payload['after'] ?? $lastHash);
    if ($afterHash !== '') {
        vcSyncExternalCommit(
            $conn,
            $repository,
            [
                'operation_type' => 'push',
                'branch_name' => $branchName,
                'commit_hash' => $afterHash,
                'commit_title' => 'GitLab push to ' . ($branchName !== '' ? $branchName : 'repository'),
                'commit_message' => 'Webhook push received from GitLab',
                'remote_url' => $remoteUrl,
                'provider' => 'gitlab',
                'platform' => 'gitlab',
                'author_name' => $userName,
                'notes' => 'GitLab push webhook',
            ]
        );
    }

    return [
        'synced' => max(1, $synced),
        'last_commit_hash' => $afterHash,
        'operation_type' => 'push',
    ];
}

function vcSyncHandleBitbucketPayload(mysqli|PgsqlMysqliCompatConnection $conn, array $repository, array $payload): array
{
    $changes = (array) ($payload['push']['changes'] ?? []);
    $synced = 0;
    $lastHash = '';

    foreach ($changes as $change) {
        $branchName = vcSyncNormalizeBranch((string) ($change['new']['name'] ?? $change['old']['name'] ?? ''));
        $target = (array) ($change['new']['target'] ?? []);
        $remoteUrl = (string) (($payload['repository']['links']['html']['href'] ?? ''));
        $authorName = (string) (($target['author']['user']['display_name'] ?? $target['author']['raw'] ?? 'Bitbucket'));
        $message = trim((string) ($target['message'] ?? ''));
        $title = $message !== '' ? strtok($message, "\n") : 'Bitbucket push';
        $lastHash = (string) ($target['hash'] ?? '');

        if ($lastHash === '') {
            continue;
        }

        vcSyncExternalCommit(
            $conn,
            $repository,
            [
                'operation_type' => 'push',
                'branch_name' => $branchName,
                'commit_hash' => $lastHash,
                'commit_title' => $title ?: 'Bitbucket push',
                'commit_message' => $message,
                'remote_url' => $remoteUrl,
                'provider' => 'bitbucket',
                'platform' => 'bitbucket',
                'author_name' => $authorName,
                'notes' => 'Bitbucket push webhook',
            ]
        );
        $synced++;
    }

    return [
        'synced' => $synced,
        'last_commit_hash' => $lastHash,
        'operation_type' => 'push',
    ];
}

$token = trim((string) ($_GET['token'] ?? $_SERVER['HTTP_X_PMS_WEBHOOK_TOKEN'] ?? ''));
if ($token === '') {
    vcSyncRespond(401, ['error' => 'Missing webhook token']);
}

$conn = getConnection();
$repository = vcFindRepositoryByWebhookToken($conn, $token);
if (!$repository) {
    vcSyncRespond(404, ['error' => 'Repository not found']);
}

$rawBody = file_get_contents('php://input') ?: '';
$payload = [];
if ($rawBody !== '') {
    $decoded = json_decode($rawBody, true);
    if (is_array($decoded)) {
        $payload = $decoded;
    }
}

if ($payload === [] && $_POST !== []) {
    $payload = $_POST;
}

try {
    if (isset($_SERVER['HTTP_X_GITHUB_EVENT']) && strtolower((string) $_SERVER['HTTP_X_GITHUB_EVENT']) === 'push') {
        $result = vcSyncHandleGitHubPayload($conn, $repository, $payload);
    } elseif (isset($_SERVER['HTTP_X_GITLAB_EVENT']) && stripos((string) $_SERVER['HTTP_X_GITLAB_EVENT'], 'push') !== false) {
        $result = vcSyncHandleGitLabPayload($conn, $repository, $payload);
    } elseif (isset($_SERVER['HTTP_X_EVENT_KEY']) && stripos((string) $_SERVER['HTTP_X_EVENT_KEY'], 'repo:push') !== false) {
        $result = vcSyncHandleBitbucketPayload($conn, $repository, $payload);
    } else {
        if ($payload === []) {
            vcSyncRespond(400, ['error' => 'No sync payload received']);
        }
        $result = vcSyncHandleGenericPayload($conn, $repository, $payload);
    }

    if (is_dir(vcGetRepositoryBarePath((string) ($repository['slug'] ?? '')))) {
        vcSaveRepositorySnapshot(
            $conn,
            (int) $repository['id'],
            (string) $repository['slug']
        );
    }

    vcSyncRespond(200, [
        'success' => true,
        'repository_id' => (int) $repository['id'],
        'project_id' => (int) $repository['project_id'],
        'result' => $result,
    ]);
} catch (Throwable $e) {
    error_log('Version control sync failed: ' . $e->getMessage());
    vcSyncRespond(500, ['error' => 'Failed to sync repository activity']);
}
?>
