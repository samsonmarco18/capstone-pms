<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../includes/auth.php';
requireRole('admin');

$conn = getConnection();
$admin = getCurrentUser();
$logId = (int) ($_POST['log_id'] ?? 0);

if ($logId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Audit log ID required']);
    exit();
}

$stmt = $conn->prepare(
    'SELECT * FROM audit_logs
     WHERE id = ? AND LOWER(action) LIKE ? AND old_data IS NOT NULL
     LIMIT 1'
);
$deleteActionPattern = '%delete%';
$stmt->bind_param('is', $logId, $deleteActionPattern);
$stmt->execute();
$log = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$log) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Restorable audit record not found']);
    exit();
}

$tableName = strtolower((string) ($log['table_name'] ?? ''));
if (!preg_match('/^[a-zA-Z0-9_]+$/', $tableName)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid table name']);
    exit();
}

$oldData = json_decode((string) $log['old_data'], true);
if (!is_array($oldData) || !$oldData) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Deleted item data is not available']);
    exit();
}

$escapedTableName = $conn->real_escape_string($tableName);
$columnsSql = DB_DRIVER === 'pgsql'
    ? "SELECT column_name AS Field FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = '{$escapedTableName}'"
    : "SHOW COLUMNS FROM `{$escapedTableName}`";
$columnsResult = $conn->query($columnsSql);
if (!$columnsResult) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Unable to inspect deleted item table']);
    exit();
}

$columns = [];
while ($column = $columnsResult->fetch_assoc()) {
    $columns[] = (string) $column['Field'];
}

$restoreData = array_intersect_key($oldData, array_flip($columns));
if (!$restoreData) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'No matching columns were found to restore']);
    exit();
}

$identifierQuote = DB_DRIVER === 'pgsql' ? '"' : '`';
$quoteIdentifier = static function (string $identifier) use ($identifierQuote): string {
    return $identifierQuote . str_replace($identifierQuote, $identifierQuote . $identifierQuote, $identifier) . $identifierQuote;
};
$quotedTableName = $quoteIdentifier($tableName);

if (isset($restoreData['id'])) {
    $existingStmt = $conn->prepare("SELECT id FROM {$quotedTableName} WHERE id = ? LIMIT 1");
    $existingId = (int) $restoreData['id'];
    $existingStmt->bind_param('i', $existingId);
    $existingStmt->execute();
    $exists = $existingStmt->get_result()->fetch_assoc();
    $existingStmt->close();

    if ($exists) {
        $softRestoreStatuses = [
            'clients' => [
                'deleted_status' => 'inactive',
                'fallback_status' => 'active',
                'allowed_statuses' => ['active', 'inactive', 'suspended', 'pending'],
            ],
            'projects' => [
                'deleted_status' => 'cancelled',
                'fallback_status' => 'pending',
                'allowed_statuses' => [
                    'pending',
                    'requirements_gathering',
                    'architectural_review',
                    'tech_recommendation',
                    'developer_allocation',
                    'arch_documentation',
                    'in_development',
                    'review',
                    'in_progress',
                    'completed',
                    'cancelled',
                ],
            ],
        ];

        if (isset($softRestoreStatuses[$tableName])) {
            $softRestoreConfig = $softRestoreStatuses[$tableName];
            $restoreStatus = (string) ($restoreData['status'] ?? $softRestoreConfig['fallback_status']);
            if (!in_array($restoreStatus, $softRestoreConfig['allowed_statuses'], true)) {
                $restoreStatus = $softRestoreConfig['fallback_status'];
            }

            $deletedStatus = $softRestoreConfig['deleted_status'];
            $restoreStmt = $conn->prepare("UPDATE {$quotedTableName} SET status = ? WHERE id = ? AND status = ?");
            $restoreStmt->bind_param('sis', $restoreStatus, $existingId, $deletedStatus);
            if ($restoreStmt->execute() && $restoreStmt->affected_rows > 0) {
                $restoreStmt->close();
                if ($tableName === 'clients' && !empty($oldData['related_projects']) && is_array($oldData['related_projects'])) {
                    $restoreProjectStmt = $conn->prepare("UPDATE {$quoteIdentifier('projects')} SET status = ? WHERE id = ? AND client_id = ? AND status = 'cancelled'");
                    if ($restoreProjectStmt !== false) {
                        foreach ($oldData['related_projects'] as $relatedProject) {
                            if (!is_array($relatedProject)) {
                                continue;
                            }

                            $relatedProjectId = (int) ($relatedProject['id'] ?? 0);
                            $relatedProjectStatus = (string) ($relatedProject['status'] ?? 'pending');
                            if (
                                $relatedProjectId <= 0
                                || !in_array($relatedProjectStatus, $softRestoreStatuses['projects']['allowed_statuses'], true)
                            ) {
                                continue;
                            }

                            $restoreProjectStmt->bind_param('sii', $relatedProjectStatus, $relatedProjectId, $existingId);
                            $restoreProjectStmt->execute();
                        }
                        $restoreProjectStmt->close();
                    }
                }
                logActivity(
                    (int) $admin['id'],
                    'restore_deleted_item',
                    $tableName,
                    $existingId,
                    null,
                    array_merge($restoreData, ['restored_from_log_id' => $logId, 'soft_restored' => true])
                );
                echo json_encode(['success' => true, 'id' => $existingId]);
                exit();
            }
            $restoreStmt->close();
        }

        http_response_code(409);
        echo json_encode(['success' => false, 'error' => 'This item has already been restored']);
        exit();
    }
}

$columnSql = implode(', ', array_map($quoteIdentifier, array_keys($restoreData)));
$placeholders = implode(', ', array_fill(0, count($restoreData), '?'));
$types = str_repeat('s', count($restoreData));
$values = array_map(static fn ($value) => $value === null ? null : (string) $value, array_values($restoreData));

$insertStmt = $conn->prepare("INSERT INTO {$quotedTableName} ($columnSql) VALUES ($placeholders)");
$insertStmt->bind_param($types, ...$values);

if (!$insertStmt->execute()) {
    $error = $insertStmt->error;
    $insertStmt->close();
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $error ?: 'Restore failed']);
    exit();
}

$newId = $insertStmt->insert_id;
$insertStmt->close();

logActivity(
    (int) $admin['id'],
    'restore_deleted_item',
    $tableName,
    $newId ?: (int) ($log['record_id'] ?? 0),
    null,
    array_merge($restoreData, ['restored_from_log_id' => $logId])
);

echo json_encode(['success' => true, 'id' => $newId]);
?>
