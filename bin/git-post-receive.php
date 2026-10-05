#!/usr/bin/env php
<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/version-control.php';
require_once __DIR__ . '/../includes/workflow.php';
require_once __DIR__ . '/../includes/project-workflow.php';

try {
    [$script, $slug, $old, $new, $ref] = $argv;
    $conn = getConnection();
    $stmt = $conn->prepare("SELECT * FROM project_repositories WHERE slug = ? AND repository_status = 'active'");
    $stmt->bind_param('s', $slug);
    $stmt->execute();
    $repository = $stmt->get_result()->fetch_assoc();
    if (!$repository || !in_array($ref, ['refs/heads/main', 'refs/heads/' . $repository['default_branch_name']], true)) exit(0);
    $token = (string) getenv('ERNI_PMS_PUSH_TOKEN');
    for ($i = 0; $i < (int) getenv('GIT_PUSH_OPTION_COUNT'); $i++) {
        $option = (string) getenv('GIT_PUSH_OPTION_' . $i);
        if (str_starts_with($option, 'erni-pms-token=')) $token = substr($option, 15);
    }
    if (!preg_match('/^v1:([1-9][0-9]*):[a-f0-9]{64}$/D', $token, $parts)) exit(0);
    $id = (int) $parts[1];
    $stmt = $conn->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $architect = $stmt->get_result()->fetch_assoc();
    if (!$architect || $architect['role'] !== 'architect' || !vcValidPersonalPushToken($repository, $architect, $token)
        || !vcCanAccessProject($conn, $architect, (int) $repository['project_id'])) exit(0);
    $bare = vcGetRepositoryBarePath($slug);
    $branch = substr($ref, 11);
    $head = trim(vcRunGitCommand(['--git-dir=' . $bare, 'rev-parse', '--verify', $ref], APP_ROOT_PATH)['stdout']);
    if (!hash_equals($head, $new)) exit(0);
    vcSyncExternalCommit($conn, $repository, ['branch_name' => $branch, 'commit_hash' => $new,
        'operation_type' => 'push', 'pms_username' => $architect['username'], 'platform' => 'server-git']);

    $stmt = $conn->prepare("SELECT t.*, g.branch_name, g.commit_hash FROM tasks t
        JOIN task_git_updates g ON g.id = (SELECT g2.id FROM task_git_updates g2
            WHERE g2.task_id = t.id AND g2.branch_name <> ? ORDER BY g2.created_at DESC, g2.id DESC LIMIT 1)
        WHERE t.project_id = ? AND t.status = 'qa_passed'");
    $stmt->bind_param('si', $branch, $repository['project_id']);
    $stmt->execute();
    $tasks = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    foreach ($tasks as $task) {
        $hash = (string) $task['commit_hash'];
        if (!preg_match('/^[a-f0-9]{40,64}$/D', $hash)) continue;
        // Match the reviewed commit, not a branch label or a commit message.
        $included = vcRunProcess([vcGetGitBinary(), '--git-dir=' . $bare, 'merge-base', '--is-ancestor', $hash, $new], APP_ROOT_PATH);
        if ($included['exit_code'] !== 0) continue;
        if (trim($old, '0') !== '') {
            $already = vcRunProcess([vcGetGitBinary(), '--git-dir=' . $bare, 'merge-base', '--is-ancestor', $hash, $old], APP_ROOT_PATH);
            if ($already['exit_code'] === 0) continue;
        }
        $conn->begin_transaction();
        try {
            $update = $conn->prepare("UPDATE tasks SET status = 'completed', architect_reviewed_by = ?,
                architect_reviewed_at = NOW(), merged_at = NOW(), release_notified_at = NOW(), completed_at = NOW()
                WHERE id = ? AND status = 'qa_passed'");
            $update->bind_param('ii', $id, $task['id']);
            if (!$update->execute()) throw new RuntimeException('Could not complete merged task.');
            if ($conn->affected_rows === 0) { $conn->rollback(); continue; }
            $note = 'Architect merged ' . $task['branch_name'] . ' into ' . $branch . ' using Git.';
            $git = $conn->prepare("INSERT INTO task_git_updates (task_id, user_id, branch_name, commit_hash, git_action, status, notes)
                VALUES (?, ?, ?, ?, 'merged', 'merged', ?)");
            $git->bind_param('iisss', $task['id'], $id, $branch, $new, $note);
            if (!$git->execute()) throw new RuntimeException('Could not record task merge.');
            workflowAddTaskComment($conn, (int) $task['id'], $id, $note);
            workflowLogStatusChange($id, (int) $task['id'], 'qa_passed', 'completed', 'architect_merge_to_main', $note);
            workflowNotifyRoles($conn, ['sales'], 'Feature Ready for Release: ' . $task['title'], $note,
                BASE_URL . 'modules/sales/release-status.php?task_id=' . $task['id'], (int) $task['id']);
            workflowNotifyUser((int) $task['assigned_to'], 'Feature Merged: ' . $task['title'], $note,
                BASE_URL . 'modules/developer/task.php?id=' . $task['id'], (int) $task['id']);
            $conn->commit();
        } catch (Throwable $e) { $conn->rollback(); throw $e; }
    }
    projectWorkflowSyncRoadmap($conn, (int) $repository['project_id']);
    workflowMaybeCompleteProject($conn, (int) $repository['project_id'], $id);
    exit(0);
} catch (Throwable $e) {
    error_log('Git task merge sync failed: ' . $e->getMessage());
    fwrite(STDERR, "ERNI PMS: push accepted, but Final Approval sync failed. Check server logs.\n");
    exit(1);
}
