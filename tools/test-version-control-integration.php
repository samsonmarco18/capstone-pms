<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli' || getenv('ERNI_PMS_ISOLATED_TEST') !== '1') exit(1);
require_once __DIR__ . '/../includes/version-control.php';
$conn = getConnection();
$mode = $argv[1] ?? '';
if ($mode === 'seed') {
    foreach ([1 => 'architect', 2 => 'developer', 3 => 'admin'] as $id => $role) {
        $password = password_hash('isolated-test-password', PASSWORD_DEFAULT);
        $stmt = $conn->prepare('INSERT INTO users (id, username, email, full_name, role, password) VALUES (?, ?, ?, ?, ?, ?)');
        $email = $role . '@example.invalid';
        $stmt->bind_param('isssss', $id, $role, $email, $role, $role, $password);
        $stmt->execute();
    }
    $conn->query("INSERT INTO clients (id, name, created_by) VALUES (1, 'Test Client', 1)");
    $conn->query("INSERT INTO projects (id, client_id, name, created_by, architect_id) VALUES (1, 1, 'Test Project', 1, 1)");
    $conn->query("INSERT INTO tasks (project_id, title, assigned_to) VALUES (1, 'Test Feature', 2)");
    $conn->query("INSERT INTO project_repositories (id, project_id, name, slug, webhook_token, created_by) VALUES (1, 1, 'Test Repo', 'test-repo', REPEAT('a', 64), 1)");
}
$repository = $conn->query('SELECT * FROM project_repositories WHERE id = 1')->fetch_assoc();
$users = $conn->query('SELECT * FROM users ORDER BY id')->fetch_all(MYSQLI_ASSOC);
if ($mode === 'seed') {
    $tokens = [];
    foreach ($users as $user) $tokens[$user['role']] = vcPersonalPushToken($repository, $user);
    echo json_encode($tokens);  
} elseif ($mode === 'hooks') {
    vcInstallServerRepositoryHooks(vcGetRepositoryBarePath('test-repo'), 'http://127.0.0.1:9/disabled-test-sync');
    echo 'OK';
} elseif ($mode === 'sync') {
    $result = vcSyncExternalCommit($conn, $repository, [
        'branch_name' => 'main', 'commit_hash' => $argv[2], 'commit_title' => 'External merge',
        'operation_type' => 'push', 'pms_username' => 'architect',
    ]);
    $branch = $conn->query("SELECT last_commit_hash FROM repository_branches WHERE repository_id = 1 AND name = 'main'")->fetch_assoc();
    $kind = $conn->query('SELECT commit_kind FROM repository_commits ORDER BY id DESC LIMIT 1')->fetch_assoc();
    $notifications = $conn->query('SELECT user_id, title FROM notifications ORDER BY id')->fetch_all(MYSQLI_ASSOC);
    echo json_encode(['result' => $result, 'head' => $branch['last_commit_hash'] ?? null, 'kind' => $kind['commit_kind'] ?? null, 'notifications' => $notifications]);
} elseif ($mode === 'archive') {
    $archive = vcCreateRepositoryArchive('test-repo', 'main', null, $repository['webhook_token'], $users[1], 'main');
    echo json_encode($archive);
} elseif ($mode === 'disable') {
    $conn->query("UPDATE users SET status = 'inactive' WHERE id = 2");
    echo 'OK';
} else {
    if ($mode !== 'seed') exit(1);
}
