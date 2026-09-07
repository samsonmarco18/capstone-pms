<?php
require_once '../../includes/auth.php';
require_once '../../includes/file-storage.php';
require_once '../../includes/project-progress.php';
require_once '../../includes/workflow.php';
require_once '../../includes/project-workflow.php';
require_once '../../includes/project-template.php';
requireRole('sales');

$conn = getConnection();
$user = getCurrentUser();
projectWorkflowEnsureSchema($conn);
projectTemplateEnsureSchema($conn);
$projectTemplates = projectTemplateAll($conn);
$hasSalesBudget = dbColumnExists($conn, 'projects', 'sales_budget');
$hasTimelineStartTime = dbColumnExists($conn, 'projects', 'timeline_start_time');
$hasTimelineEndTime = dbColumnExists($conn, 'projects', 'timeline_end_time');

function salesClientsFetchCount($conn, string $sql): int
{
    $result = $conn->query($sql);
    if (!is_object($result) || !method_exists($result, 'fetch_assoc')) {
        return 0;
    }

    $row = $result->fetch_assoc() ?: [];
    return (int) ($row['count'] ?? 0);
}

function salesClientsQuery($conn, string $sql)
{
    $result = $conn->query($sql);
    return (is_object($result) && method_exists($result, 'fetch_assoc')) ? $result : null;
}

function salesClientsFetchRows($conn, string $sql): array
{
    $result = salesClientsQuery($conn, $sql);
    $rows = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
    }
    return $rows;
}

function salesClientsDbError($conn): string
{
    $error = trim((string) ($conn->error ?? ''));
    return $error !== '' ? ' Database error: ' . $error : '';
}

if (!$hasTimelineStartTime) {
    $conn->query(DB_DRIVER === 'pgsql'
        ? 'ALTER TABLE projects ADD COLUMN timeline_start_time TIME NULL'
        : 'ALTER TABLE projects ADD COLUMN timeline_start_time TIME NULL AFTER timeline_start'
    );
    $hasTimelineStartTime = dbColumnExists($conn, 'projects', 'timeline_start_time');
}

if (!$hasTimelineEndTime) {
    $conn->query(DB_DRIVER === 'pgsql'
        ? 'ALTER TABLE projects ADD COLUMN timeline_end_time TIME NULL'
        : 'ALTER TABLE projects ADD COLUMN timeline_end_time TIME NULL AFTER timeline_end'
    );
    $hasTimelineEndTime = dbColumnExists($conn, 'projects', 'timeline_end_time');
}

function parseSalesBudgetInput(string $value): ?float
{
    $trimmed = trim($value);
    if ($trimmed === '') {
        return null;
    }

    $normalized = str_replace(',', '', $trimmed);
    if (!preg_match('/^\d+(?:\.\d{1,2})?$/', $normalized)) {
        return null;
    }

    return (float) $normalized;
}

function parseSalesTimeInput(string $value): ?string
{
    $trimmed = trim($value);
    if ($trimmed === '') {
        return null;
    }

    $parsed = DateTime::createFromFormat('H:i', $trimmed);
    if (!$parsed || $parsed->format('H:i') !== $trimmed) {
        return null;
    }

    return $trimmed . ':00';
}

function parseSalesDateInput(string $value): ?string
{
    $trimmed = trim($value);
    if ($trimmed === '') {
        return null;
    }

    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $trimmed);
    if (!$parsed || $parsed->format('Y-m-d') !== $trimmed) {
        return null;
    }

    return $trimmed;
}

function salesClientsProjectComplexityValue(string $complexity): string
{
    $value = match (strtolower(trim($complexity))) {
        'low', 'simple' => 'low',
        'high', 'critical', 'enterprise', 'complex' => 'high',
        default => 'medium',
    };

    if (defined('DB_DRIVER') && DB_DRIVER === 'pgsql') {
        return match ($value) {
            'low' => 'simple',
            'high' => 'complex',
            default => 'medium',
        };
    }

    return $value;
}

function salesClientsScopeAdjustedComplexity(?string $timelineStart, ?string $timelineEnd, ?float $budget, string $priority, string $requestedFeatures): string
{
    $base = strtolower(projectWorkflowClassifyComplexity($timelineStart, $timelineEnd, $budget, $priority));
    $featureCount = count(array_filter(array_map('trim', preg_split('/\R/', $requestedFeatures) ?: [])));
    $workingDays = 0;
    if ($timelineStart && $timelineEnd) {
        $start = DateTimeImmutable::createFromFormat('!Y-m-d', $timelineStart);
        $end = DateTimeImmutable::createFromFormat('!Y-m-d', $timelineEnd);
        if ($start && $end && $end >= $start) {
            for ($date = $start; $date <= $end; $date = $date->modify('+1 day')) {
                if ((int) $date->format('N') <= 5) $workingDays++;
            }
        }
    }
    if ($featureCount >= 13 || ($featureCount > 0 && $workingDays > 0 && $workingDays < ($featureCount * 3))) $base = 'high';
    elseif ($featureCount >= 8 && in_array($base, ['low', 'simple'], true)) $base = 'medium';
    return salesClientsProjectComplexityValue($base);
}

function salesClientsFormatProjectStartEmailDate(?string $date, ?string $time = null): string
{
    $date = trim((string) $date);
    if ($date === '') {
        return 'Not set';
    }

    $parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    if (!$parsedDate || $parsedDate->format('Y-m-d') !== $date) {
        return $date;
    }

    $label = $parsedDate->format('F j, Y');
    $time = trim((string) $time);
    if ($time !== '') {
        $parsedTime = DateTimeImmutable::createFromFormat('H:i:s', $time)
            ?: DateTimeImmutable::createFromFormat('H:i', $time);
        if ($parsedTime) {
            $label .= ' at ' . $parsedTime->format('g:i A');
        }
    }

    return $label;
}

function salesClientsSendProjectStartingEmail(
    string $clientEmail,
    string $contactPerson,
    string $clientName,
    string $projectName,
    string $projectType,
    ?string $timelineStart,
    ?string $timelineStartTime,
    ?string $timelineEnd,
    ?string $timelineEndTime,
    ?float $salesBudget
): array {
    $clientEmail = trim($clientEmail);
    if (!filter_var($clientEmail, FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'error' => 'Client email is missing or invalid.'];
    }

    $recipientName = trim($contactPerson) !== '' ? trim($contactPerson) : trim($clientName);
    if ($recipientName === '') {
        $recipientName = 'Client';
    }

    $projectLabel = trim($projectName) !== '' ? trim($projectName) : 'your project';
    $details = [
        'Project' => $projectLabel,
        'Project Type' => trim($projectType) !== '' ? trim($projectType) : 'Not specified',
        'Start Date' => salesClientsFormatProjectStartEmailDate($timelineStart, $timelineStartTime),
        'Target Deadline' => salesClientsFormatProjectStartEmailDate($timelineEnd, $timelineEndTime),
    ];

    if ($salesBudget !== null) {
        $details['Estimated Budget'] = 'PHP ' . number_format($salesBudget, 2);
    }

    $safeRecipientName = htmlspecialchars($recipientName, ENT_QUOTES, 'UTF-8');
    $safeProjectLabel = htmlspecialchars($projectLabel, ENT_QUOTES, 'UTF-8');
    $detailRows = '';
    foreach ($details as $label => $value) {
        $detailRows .= '<tr>'
            . '<td style="padding:8px 10px;border-bottom:1px solid #e5edf5;color:#64748b;width:150px;">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</td>'
            . '<td style="padding:8px 10px;border-bottom:1px solid #e5edf5;color:#0f172a;font-weight:600;">' . htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8') . '</td>'
            . '</tr>';
    }

    $subject = 'Your EVORTIX project is starting: ' . $projectLabel;
    $htmlBody = '<div style="font-family:Arial,sans-serif;max-width:660px;margin:0 auto;padding:24px;background:#f5f7fb;color:#1f2937;">'
        . '<div style="background:#ffffff;border:1px solid #dbe3ef;border-radius:16px;padding:24px;">'
        . '<h2 style="margin:0 0 12px;color:#1f6392;">Your project is starting</h2>'
        . '<p style="margin:0 0 12px;line-height:1.6;">Good day ' . $safeRecipientName . ',</p>'
        . '<p style="margin:0 0 16px;line-height:1.6;">Thank you for trusting EVORTIX. We have created your project record for <strong>' . $safeProjectLabel . '</strong>, and our team is starting the planning and review process.</p>'
        . '<table role="presentation" cellspacing="0" cellpadding="0" style="width:100%;border-collapse:collapse;margin:16px 0;background:#f8fafc;border:1px solid #e5edf5;border-radius:12px;overflow:hidden;">'
        . $detailRows
        . '</table>'
        . '<p style="margin:16px 0 0;line-height:1.6;">We will contact you if we need additional details before the next project step.</p>'
        . '<p style="margin:16px 0 0;color:#64748b;font-size:13px;">This email was sent automatically by EVORTIX PMS.</p>'
        . '</div></div>';

    $textLines = [
        'Good day ' . $recipientName . ',',
        '',
        'Thank you for trusting EVORTIX. We have created your project record for ' . $projectLabel . ', and our team is starting the planning and review process.',
        '',
        'Project details:',
    ];
    foreach ($details as $label => $value) {
        $textLines[] = $label . ': ' . (string) $value;
    }
    $textLines[] = '';
    $textLines[] = 'We will contact you if we need additional details before the next project step.';

    return sendSystemEmail($clientEmail, $subject, $htmlBody, implode("\n", $textLines));
}

function salesClientsBindAndExecute($stmt, string $types, array $params): bool
{
    $refs = [];
    foreach ($params as $index => $value) {
        $refs[$index] = &$params[$index];
    }

    return $stmt->bind_param($types, ...$refs) && $stmt->execute();
}

function salesClientsInsertProject($conn, array $data): int
{
    $columns = [
        'client_id',
        'name',
        'description',
        'status',
        'timeline_start',
        'timeline_end',
        'meeting_notes',
        'mom',
        'requested_features',
        'created_by',
    ];
    $placeholders = array_fill(0, count($columns), '?');
    $types = 'issssssssi';
    $params = [
        (int) $data['client_id'],
        (string) $data['name'],
        (string) $data['description'],
        'pending',
        $data['timeline_start'],
        $data['timeline_end'],
        (string) $data['meeting_notes'],
        (string) $data['mom'],
        (string) $data['requested_features'],
        (int) $data['created_by'],
    ];

    $optionalColumns = [
        'project_type' => ['s', $data['project_type']],
        'project_template_id' => ['i', $data['project_template_id']],
        'project_template_snapshot' => ['s', $data['project_template_snapshot']],
        'methodology_template' => ['s', $data['methodology_template']],
        'sales_budget' => ['s', $data['sales_budget']],
        'timeline_start_time' => ['s', $data['timeline_start_time']],
        'timeline_end_time' => ['s', $data['timeline_end_time']],
        'complexity_level' => ['s', $data['complexity_level']],
        'priority' => ['s', $data['priority']],
        'sales_notes' => ['s', $data['sales_notes']],
        'sales_attachments' => ['s', $data['sales_attachments']],
        'workflow_status' => ['s', 'sales_submitted'],
    ];

    foreach ($optionalColumns as $column => [$type, $value]) {
        if (!dbColumnExists($conn, 'projects', $column)) {
            continue;
        }

        $columns[] = $column;
        $placeholders[] = '?';
        $types .= $type;
        $params[] = $value;
    }

    $sql = 'INSERT INTO projects (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $placeholders) . ')';
    $stmt = $conn->prepare($sql);
    if ($stmt === false) {
        throw new RuntimeException('Unable to prepare project insert.' . salesClientsDbError($conn));
    }

    if (!salesClientsBindAndExecute($stmt, $types, $params)) {
        throw new RuntimeException('Unable to create project requirements.' . salesClientsDbError($conn));
    }

    $projectId = (int) $stmt->insert_id;
    $stmt->close();

    return $projectId;
}

function salesClientsEnsureClientFolder(mysqli|PgsqlMysqliCompatConnection $conn, int $clientId, string $clientName, int $createdBy): int
{
    $checkStmt = $conn->prepare(
        "SELECT id
         FROM folders
         WHERE parent_id IS NULL AND client_id = ? AND folder_type = 'client'
         LIMIT 1"
    );
    if ($checkStmt === false) {
        throw new RuntimeException('Unable to prepare client folder lookup.' . salesClientsDbError($conn));
    }

    $checkStmt->bind_param('i', $clientId);
    $checkStmt->execute();
    $existing = $checkStmt->get_result()->fetch_assoc();
    $checkStmt->close();

    if ($existing) {
        return (int) $existing['id'];
    }

    $createStmt = $conn->prepare(
        "INSERT INTO folders (name, folder_type, client_id, parent_id, role, created_by)
         VALUES (?, 'client', ?, NULL, NULL, ?)"
    );
    if ($createStmt === false) {
        throw new RuntimeException('Unable to prepare client folder creation.' . salesClientsDbError($conn));
    }

    $createStmt->bind_param('sii', $clientName, $clientId, $createdBy);
    if (!$createStmt->execute()) {
        throw new RuntimeException('Unable to create client folder.' . salesClientsDbError($conn));
    }

    $folderId = (int) $createStmt->insert_id;
    $createStmt->close();

    return $folderId;
}

function salesClientsCreateSharedProjectFolder(
    mysqli|PgsqlMysqliCompatConnection $conn,
    int $clientId,
    int $projectId,
    int $clientFolderId,
    string $projectName,
    int $createdBy
): void {
    $sharedFolderName = 'Shared - ' . $projectName;
    $sharedFolderStmt = $conn->prepare(
        "INSERT INTO folders (name, folder_type, client_id, project_id, parent_id, role, created_by)
         VALUES (?, 'project', ?, ?, ?, NULL, ?)"
    );
    if ($sharedFolderStmt === false) {
        throw new RuntimeException('Unable to prepare shared folder creation.' . salesClientsDbError($conn));
    }

    $sharedFolderStmt->bind_param('siiii', $sharedFolderName, $clientId, $projectId, $clientFolderId, $createdBy);
    if (!$sharedFolderStmt->execute()) {
        throw new RuntimeException('Unable to create shared project folder.' . salesClientsDbError($conn));
    }
    $sharedFolderStmt->close();
}

function salesClientsSaveAttachments(array $files): array
{
    $saved = [];
    if (empty($files['name']) || !is_array($files['name'])) {
        return $saved;
    }

    foreach ($files['name'] as $index => $name) {
        if (($files['error'][$index] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            continue;
        }

        $originalName = basename((string) $name);
        try {
            $target = storageBuildUploadTarget($originalName, 'sales-intake/' . date('Y/m/d'));
        } catch (Throwable $e) {
            continue;
        }

        if (move_uploaded_file((string) $files['tmp_name'][$index], $target['absolute_path'])) {
            try {
                storagePersistFile($target['relative_path'], $target['absolute_path'], $target['original_name'], (string) ($files['type'][$index] ?? ''));
            } catch (Throwable $e) {
                @unlink($target['absolute_path']);
                continue;
            }

            $saved[] = [
                'name' => $originalName,
                'path' => $target['relative_path'],
                'type' => (string) ($files['type'][$index] ?? ''),
                'size' => (int) ($files['size'][$index] ?? 0),
            ];
        }
    }

    return $saved;
}

function salesClientsDownloadableRepositoryForProject(mysqli|PgsqlMysqliCompatConnection $conn, int $projectId, int $salesUserId): ?array
{
    if ($projectId <= 0 || $salesUserId <= 0) {
        return null;
    }

    $featureCondition = projectProgressFeatureCondition('t');
    $mergedCondition = projectProgressMergedCondition('t');
    $stmt = $conn->prepare("
        SELECT
            p.id AS project_id,
            p.status AS project_status,
            p.created_by AS project_created_by,
            c.created_by AS client_created_by,
            pr.id AS repository_id,
            pr.default_branch_name,
            COUNT(t.id) AS total_features,
            SUM(CASE WHEN {$mergedCondition} THEN 1 ELSE 0 END) AS merged_features
        FROM projects p
        JOIN clients c ON c.id = p.client_id
        JOIN project_repositories pr ON pr.project_id = p.id AND pr.repository_status = 'active'
        LEFT JOIN tasks t ON t.project_id = p.id AND {$featureCondition}
        WHERE p.id = ?
          AND p.status != 'cancelled'
        GROUP BY p.id, p.status, p.created_by, c.created_by, pr.id, pr.default_branch_name, p.updated_at, pr.updated_at
        ORDER BY p.updated_at DESC, pr.updated_at DESC, pr.id DESC
        LIMIT 10
    ");

    if ($stmt === false) {
        return null;
    }

    $stmt->bind_param('i', $projectId);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $totalFeatures = (int) ($row['total_features'] ?? 0);
        $mergedFeatures = (int) ($row['merged_features'] ?? 0);
        $isOwner = (int) ($row['project_created_by'] ?? 0) === $salesUserId
            || (int) ($row['client_created_by'] ?? 0) === $salesUserId;

        if ($totalFeatures <= 0 || $mergedFeatures < $totalFeatures || !$isOwner) {
            continue;
        }

        $projectId = (int) ($row['project_id'] ?? 0);
        if ((string) ($row['project_status'] ?? '') !== 'completed' && $projectId > 0) {
            $completeStmt = $conn->prepare("UPDATE projects SET status = 'completed' WHERE id = ?");
            if ($completeStmt !== false) {
                $completeStmt->bind_param('i', $projectId);
                $completeStmt->execute();
                $completeStmt->close();
            }
            workflowEnsureFinalReceiptForProject($conn, $projectId, $salesUserId);
        }

        $stmt->close();

        return [
            'repository_id' => (int) ($row['repository_id'] ?? 0),
            'default_branch_name' => (string) (($row['default_branch_name'] ?? '') ?: 'main'),
        ];
    }

    $stmt->close();

    return null;
}

// Handle client operations (same logic as before)
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['add_client'])) {
        $name = trim((string) ($_POST['name'] ?? ''));
        $contactPerson = trim((string) ($_POST['contact_person'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $phone = trim((string) ($_POST['phone'] ?? ''));
        $address = trim((string) ($_POST['address'] ?? ''));
        $deadline = trim((string) ($_POST['deadline'] ?? ''));
        $deadline = $deadline !== '' ? $deadline : null;

        $projectName = trim((string) ($_POST['project_name'] ?? ''));
        $projectType = trim((string) ($_POST['project_type'] ?? ''));
        $requestedTemplateId = (int) ($_POST['project_template_id'] ?? 0);
        $projectTemplate = $requestedTemplateId > 0 ? projectTemplateFindById($conn, $requestedTemplateId) : null;
        if (!$projectTemplate || projectTemplateNormalizeType((string) ($projectTemplate['project_type'] ?? '')) !== projectTemplateNormalizeType($projectType)) {
            $projectTemplate = $projectType !== '' ? projectTemplateFindByType($conn, $projectType) : null;
        }
        $projectTemplateId = $projectTemplate ? (int) ($projectTemplate['id'] ?? 0) : null;
        $projectTemplateSnapshot = $projectTemplate ? json_encode($projectTemplate, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null;
        $projectDescription = trim((string) ($_POST['project_description'] ?? ''));
        $meetingNotes = trim((string) ($_POST['meeting_notes'] ?? ''));
        $mom = trim((string) ($_POST['mom'] ?? ''));
        $requestedFeatures = trim((string) ($_POST['requested_features'] ?? ''));
        $additionalRequirements = trim((string) ($_POST['additional_requirements'] ?? ''));
        $priority = trim((string) ($_POST['priority'] ?? 'medium'));
        if (!in_array($priority, ['low', 'medium', 'high', 'critical'], true)) {
            $priority = 'medium';
        }
        $salesNotes = trim((string) ($_POST['sales_notes'] ?? ''));
        $salesBudgetInput = trim((string) ($_POST['sales_budget'] ?? ''));
        $salesBudget = parseSalesBudgetInput($salesBudgetInput);
        $timelineStart = parseSalesDateInput((string) ($_POST['timeline_start'] ?? ''));
        $timelineEnd = parseSalesDateInput((string) ($_POST['timeline_end'] ?? '')) ?: parseSalesDateInput((string) $deadline);
        $timelineStartTimeInput = trim((string) ($_POST['timeline_start_time'] ?? ''));
        $timelineEndTimeInput = trim((string) ($_POST['timeline_end_time'] ?? ''));
        $timelineStartTime = parseSalesTimeInput($timelineStartTimeInput);
        $timelineEndTime = parseSalesTimeInput($timelineEndTimeInput);
        $generatedComplexity = salesClientsScopeAdjustedComplexity($timelineStart, $timelineEnd, $salesBudget, $priority, $requestedFeatures);
        $requirementsSummary = $requestedFeatures;
        if ($additionalRequirements !== '') {
            $requirementsSummary .= ($requirementsSummary !== '' ? "\n\n" : '') . 'Additional Requirements:' . "\n" . $additionalRequirements;
        }
        if (!$hasSalesBudget && $salesBudgetInput !== '') {
            $requirementsSummary .= ($requirementsSummary !== '' ? "\n\n" : '') . 'Budget:' . "\n" . $salesBudgetInput;
        }

        if ($name === '') {
            $_SESSION['error'] = 'Client name is required.';
        } elseif ($projectName !== '' && $projectType === '') {
            $_SESSION['error'] = 'Project type is required.';
        } elseif ($salesBudgetInput !== '' && $salesBudget === null) {
            $_SESSION['error'] = 'Budget must be a valid number.';
        } elseif ($timelineStartTimeInput !== '' && $timelineStartTime === null) {
            $_SESSION['error'] = 'Timeline start time must be valid.';
        } elseif ($timelineEndTimeInput !== '' && $timelineEndTime === null) {
            $_SESSION['error'] = 'Project deadline time must be valid.';
        } elseif ($timelineStart !== null && $timelineEnd !== null && $timelineEnd < $timelineStart) {
            $_SESSION['error'] = 'Project deadline cannot be earlier than the timeline start date.';
        } elseif ($timelineStart !== null && $timelineEnd !== null && $timelineStart === $timelineEnd
            && $timelineStartTime !== null && $timelineEndTime !== null && $timelineEndTime <= $timelineStartTime) {
            $_SESSION['error'] = 'Project deadline time must be later than the start time when both dates are the same.';
        } else {
            $deadline = $deadline ?: $timelineEnd;
            $conn->begin_transaction();

            try {
                $attachmentsJson = json_encode(salesClientsSaveAttachments($_FILES['attachments'] ?? []), JSON_UNESCAPED_SLASHES);
                $stmt = $conn->prepare("INSERT INTO clients (name, contact_person, email, phone, address, deadline, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
                if ($stmt === false) {
                    throw new RuntimeException('Unable to prepare client insert.');
                }
                $stmt->bind_param("ssssssi", $name, $contactPerson, $email, $phone, $address, $deadline, $user['id']);
                if (!$stmt->execute()) {
                    throw new RuntimeException('Unable to insert client.');
                }
                $clientId = $stmt->insert_id;
                $stmt->close();

                $clientFolderId = salesClientsEnsureClientFolder($conn, (int) $clientId, $name, (int) $user['id']);

                $projectId = null;
                if ($projectName !== '') {
                    $projectId = salesClientsInsertProject($conn, [
                        'client_id' => $clientId,
                        'name' => $projectName,
                        'description' => $projectDescription,
                        'timeline_start' => $timelineStart,
                        'timeline_end' => $timelineEnd,
                        'meeting_notes' => $meetingNotes,
                        'mom' => $mom,
                        'requested_features' => $requirementsSummary,
                        'sales_budget' => $salesBudget === null ? null : number_format($salesBudget, 2, '.', ''),
                        'timeline_start_time' => $timelineStartTime,
                        'timeline_end_time' => $timelineEndTime,
                        'created_by' => $user['id'],
                        'project_type' => $projectType,
                        'project_template_id' => $projectTemplateId,
                        'project_template_snapshot' => $projectTemplateSnapshot,
                        'methodology_template' => $projectTemplate ? (string) ($projectTemplate['methodology'] ?? '') : null,
                        'complexity_level' => $generatedComplexity,
                        'priority' => $priority,
                        'sales_notes' => $salesNotes,
                        'sales_attachments' => $attachmentsJson,
                    ]);

                    salesClientsCreateSharedProjectFolder($conn, (int) $clientId, (int) $projectId, (int) $clientFolderId, $projectName, (int) $user['id']);
                    projectTemplateCaptureProject($conn, (int) $projectId);
                    $learnedProjectTemplate = projectTemplateFindByType($conn, $projectType);
                    if ($learnedProjectTemplate) {
                        $learnedTemplateId = (int) ($learnedProjectTemplate['id'] ?? 0);
                        $learnedTemplateSnapshot = json_encode($learnedProjectTemplate, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                        $templateUpdateStmt = $conn->prepare('UPDATE projects SET project_template_id = ?, project_template_snapshot = ? WHERE id = ?');
                        if ($templateUpdateStmt !== false) {
                            $templateUpdateStmt->bind_param('isi', $learnedTemplateId, $learnedTemplateSnapshot, $projectId);
                            $templateUpdateStmt->execute();
                            $templateUpdateStmt->close();
                        }
                    }
                }

                $conn->commit();
                $clientStartEmailResult = null;
                if ($projectId) {
                    workflowNotifyRoles(
                        $conn,
                        ['architect'],
                        'New Project Requirements Ready',
                        'Sales submitted a new project for architectural review: ' . $projectName,
                        BASE_URL . 'modules/architect/project-review.php?id=' . $projectId,
                        (int) $projectId,
                        'project',
                        'projects'
                    );

                    $clientStartEmailResult = salesClientsSendProjectStartingEmail(
                        $email,
                        $contactPerson,
                        $name,
                        $projectName,
                        $projectType,
                        $timelineStart,
                        $timelineStartTime,
                        $timelineEnd,
                        $timelineEndTime,
                        $salesBudget
                    );
                    if (empty($clientStartEmailResult['success'])) {
                        error_log('Client project start email failed for client_id=' . (int) $clientId . ', project_id=' . (int) $projectId . ': ' . (string) ($clientStartEmailResult['error'] ?? 'Unknown mail error.'));
                    }
                }
                logActivity($user['id'], 'add_client', 'clients', $clientId, null, $_POST);
                $clientDraftId = trim((string) ($_POST['client_draft_id'] ?? ''));
                if ($clientDraftId !== '') {
                    $_SESSION['clear_client_draft_id'] = $clientDraftId;
                }

                if ($projectId) {
                    logActivity($user['id'], 'create_project_requirements', 'projects', $projectId, null, $_POST);
                    $clientEmailStatus = '';
                    if (is_array($clientStartEmailResult) && !empty($clientStartEmailResult['success'])) {
                        $clientEmailStatus = ' Client project start email sent.';
                    } elseif (is_array($clientStartEmailResult)) {
                        $clientEmailStatus = ' Client was saved, but the project start email was not sent: ' . (string) ($clientStartEmailResult['error'] ?? 'Unknown mail error.');
                    }
                    $_SESSION['success'] = 'Client and complete project intake sent to Architect review successfully.' . $clientEmailStatus;
                } else {
                    $_SESSION['success'] = "Client added and shared client folder created. No project created because project name was empty.";
                }
            } catch (Throwable $e) {
                $conn->rollback();
                error_log('Sales client save error: ' . $e->getMessage());
                $_SESSION['error'] = 'Unable to save client and requirements. ' . $e->getMessage();
            }
        }
    } elseif (isset($_POST['add_project'])) {
        $clientId = (int) ($_POST['client_id'] ?? 0);
        $projectName = trim((string) ($_POST['project_name'] ?? ''));
        $projectType = trim((string) ($_POST['project_type'] ?? ''));
        $requestedTemplateId = (int) ($_POST['project_template_id'] ?? 0);
        $projectTemplate = $requestedTemplateId > 0 ? projectTemplateFindById($conn, $requestedTemplateId) : null;
        if (!$projectTemplate || projectTemplateNormalizeType((string) ($projectTemplate['project_type'] ?? '')) !== projectTemplateNormalizeType($projectType)) {
            $projectTemplate = $projectType !== '' ? projectTemplateFindByType($conn, $projectType) : null;
        }
        $projectTemplateId = $projectTemplate ? (int) ($projectTemplate['id'] ?? 0) : null;
        $projectTemplateSnapshot = $projectTemplate ? json_encode($projectTemplate, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null;
        $projectDescription = trim((string) ($_POST['project_description'] ?? ''));
        $meetingNotes = trim((string) ($_POST['meeting_notes'] ?? ''));
        $mom = trim((string) ($_POST['mom'] ?? ''));
        $requestedFeatures = trim((string) ($_POST['requested_features'] ?? ''));
        $additionalRequirements = trim((string) ($_POST['additional_requirements'] ?? ''));
        $priority = trim((string) ($_POST['priority'] ?? 'medium'));
        if (!in_array($priority, ['low', 'medium', 'high', 'critical'], true)) {
            $priority = 'medium';
        }
        $salesNotes = trim((string) ($_POST['sales_notes'] ?? ''));
        $salesBudgetInput = trim((string) ($_POST['sales_budget'] ?? ''));
        $salesBudget = parseSalesBudgetInput($salesBudgetInput);
        $timelineStart = parseSalesDateInput((string) ($_POST['timeline_start'] ?? ''));
        $timelineEnd = parseSalesDateInput((string) ($_POST['timeline_end'] ?? ''));
        $timelineStartTimeInput = trim((string) ($_POST['timeline_start_time'] ?? ''));
        $timelineEndTimeInput = trim((string) ($_POST['timeline_end_time'] ?? ''));
        $timelineStartTime = parseSalesTimeInput($timelineStartTimeInput);
        $timelineEndTime = parseSalesTimeInput($timelineEndTimeInput);
        $generatedComplexity = salesClientsScopeAdjustedComplexity($timelineStart, $timelineEnd, $salesBudget, $priority, $requestedFeatures);
        $requirementsSummary = $requestedFeatures;
        if ($additionalRequirements !== '') {
            $requirementsSummary .= ($requirementsSummary !== '' ? "\n\n" : '') . 'Additional Requirements:' . "\n" . $additionalRequirements;
        }
        if (!$hasSalesBudget && $salesBudgetInput !== '') {
            $requirementsSummary .= ($requirementsSummary !== '' ? "\n\n" : '') . 'Budget:' . "\n" . $salesBudgetInput;
        }

        $clientStmt = $conn->prepare('SELECT id, name, contact_person, email FROM clients WHERE id = ? LIMIT 1');
        if ($clientStmt === false) {
            $_SESSION['error'] = 'Unable to prepare client lookup.';
        } else {
            $clientStmt->bind_param('i', $clientId);
            $clientStmt->execute();
            $projectClient = $clientStmt->get_result()->fetch_assoc();
            $clientStmt->close();

            if (!$projectClient) {
                $_SESSION['error'] = 'Client not found.';
            } elseif ($projectName === '') {
                $_SESSION['error'] = 'Project name is required.';
            } elseif ($projectType === '') {
                $_SESSION['error'] = 'Project type is required.';
            } elseif ($salesBudgetInput !== '' && $salesBudget === null) {
                $_SESSION['error'] = 'Budget must be a valid number.';
            } elseif ($timelineStartTimeInput !== '' && $timelineStartTime === null) {
                $_SESSION['error'] = 'Timeline start time must be valid.';
            } elseif ($timelineEndTimeInput !== '' && $timelineEndTime === null) {
                $_SESSION['error'] = 'Project deadline time must be valid.';
            } elseif ($timelineStart !== null && $timelineEnd !== null && $timelineEnd < $timelineStart) {
                $_SESSION['error'] = 'Project deadline cannot be earlier than the timeline start date.';
            } elseif ($timelineStart !== null && $timelineEnd !== null && $timelineStart === $timelineEnd
                && $timelineStartTime !== null && $timelineEndTime !== null && $timelineEndTime <= $timelineStartTime) {
                $_SESSION['error'] = 'Project deadline time must be later than the start time when both dates are the same.';
            } else {
                $conn->begin_transaction();

                try {
                    $attachmentsJson = json_encode(salesClientsSaveAttachments($_FILES['project_attachments'] ?? []), JSON_UNESCAPED_SLASHES);
                    $projectId = salesClientsInsertProject($conn, [
                        'client_id' => $clientId,
                        'name' => $projectName,
                        'description' => $projectDescription,
                        'timeline_start' => $timelineStart,
                        'timeline_end' => $timelineEnd,
                        'meeting_notes' => $meetingNotes,
                        'mom' => $mom,
                        'requested_features' => $requirementsSummary,
                        'sales_budget' => $salesBudget === null ? null : number_format($salesBudget, 2, '.', ''),
                        'timeline_start_time' => $timelineStartTime,
                        'timeline_end_time' => $timelineEndTime,
                        'created_by' => $user['id'],
                        'project_type' => $projectType,
                        'project_template_id' => $projectTemplateId,
                        'project_template_snapshot' => $projectTemplateSnapshot,
                        'methodology_template' => $projectTemplate ? (string) ($projectTemplate['methodology'] ?? '') : null,
                        'complexity_level' => $generatedComplexity,
                        'priority' => $priority,
                        'sales_notes' => $salesNotes,
                        'sales_attachments' => $attachmentsJson,
                    ]);

                    $clientFolderId = salesClientsEnsureClientFolder(
                        $conn,
                        $clientId,
                        (string) $projectClient['name'],
                        (int) $user['id']
                    );
                    salesClientsCreateSharedProjectFolder($conn, $clientId, $projectId, $clientFolderId, $projectName, (int) $user['id']);
                    projectTemplateCaptureProject($conn, (int) $projectId);
                    $learnedProjectTemplate = projectTemplateFindByType($conn, $projectType);
                    if ($learnedProjectTemplate) {
                        $learnedTemplateId = (int) ($learnedProjectTemplate['id'] ?? 0);
                        $learnedTemplateSnapshot = json_encode($learnedProjectTemplate, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                        $templateUpdateStmt = $conn->prepare('UPDATE projects SET project_template_id = ?, project_template_snapshot = ? WHERE id = ?');
                        if ($templateUpdateStmt !== false) {
                            $templateUpdateStmt->bind_param('isi', $learnedTemplateId, $learnedTemplateSnapshot, $projectId);
                            $templateUpdateStmt->execute();
                            $templateUpdateStmt->close();
                        }
                    }

                    if ($timelineEnd !== null) {
                        $deadlineStmt = $conn->prepare('UPDATE clients SET deadline = COALESCE(deadline, ?) WHERE id = ?');
                        if ($deadlineStmt !== false) {
                            $deadlineStmt->bind_param('si', $timelineEnd, $clientId);
                            $deadlineStmt->execute();
                            $deadlineStmt->close();
                        }
                    }

                    $conn->commit();

                    workflowNotifyRoles(
                        $conn,
                        ['architect'],
                        'New Project Requirements Ready',
                        'Sales submitted a new project for architectural review: ' . $projectName,
                        BASE_URL . 'modules/architect/project-review.php?id=' . $projectId,
                        (int) $projectId,
                        'project',
                        'projects'
                    );

                    $clientStartEmailResult = salesClientsSendProjectStartingEmail(
                        (string) ($projectClient['email'] ?? ''),
                        (string) ($projectClient['contact_person'] ?? ''),
                        (string) ($projectClient['name'] ?? ''),
                        $projectName,
                        $projectType,
                        $timelineStart,
                        $timelineStartTime,
                        $timelineEnd,
                        $timelineEndTime,
                        $salesBudget
                    );
                    if (empty($clientStartEmailResult['success'])) {
                        error_log('Client project start email failed for client_id=' . $clientId . ', project_id=' . $projectId . ': ' . (string) ($clientStartEmailResult['error'] ?? 'Unknown mail error.'));
                    }

                    logActivity($user['id'], 'create_project_requirements', 'projects', $projectId, null, $_POST);
                    $_SESSION['success'] = 'Project added to ' . (string) $projectClient['name'] . ' and sent to Architect review successfully.';
                } catch (Throwable $e) {
                    $conn->rollback();
                    error_log('Sales add project error: ' . $e->getMessage());
                    $_SESSION['error'] = 'Unable to add project. ' . $e->getMessage();
                }
            }
        }
    } elseif (isset($_POST['update_client'])) {
        $id = $_POST['id'];
        $name = $_POST['name'];
        $contact_person = $_POST['contact_person'];
        $email = $_POST['email'];
        $phone = $_POST['phone'];
        $address = $_POST['address'];
        $status = $_POST['status'];
        
        $oldResult = salesClientsQuery($conn, "SELECT * FROM clients WHERE id = $id");
        $oldData = $oldResult ? $oldResult->fetch_assoc() : null;
        
        $stmt = $conn->prepare("UPDATE clients SET name=?, contact_person=?, email=?, phone=?, address=?, status=? WHERE id=?");
        $stmt->bind_param("ssssssi", $name, $contact_person, $email, $phone, $address, $status, $id);
        
        if ($stmt->execute()) {
            logActivity($user['id'], 'update_client', 'clients', $id, $oldData, $_POST);
            $_SESSION['success'] = "Client updated successfully";
        }
    } elseif (isset($_POST['delete_client'])) {
        $id = (int) $_POST['id'];
        $oldResult = salesClientsQuery($conn, "SELECT * FROM clients WHERE id = $id");
        $oldData = $oldResult ? $oldResult->fetch_assoc() : null;

        if ($oldData) {
            $relatedProjects = salesClientsFetchRows($conn, "SELECT id, status FROM projects WHERE client_id = $id AND status != 'cancelled'");
            $oldData['related_projects'] = $relatedProjects;
        }
        
        $conn->begin_transaction();
        try {
            $projectStmt = $conn->prepare("UPDATE projects SET status = 'cancelled' WHERE client_id = ? AND status != 'cancelled'");
            if ($projectStmt === false) {
                throw new RuntimeException('Unable to prepare related project delete.' . salesClientsDbError($conn));
            }
            $projectStmt->bind_param("i", $id);
            $projectStmt->execute();
            $projectStmt->close();

            $stmt = $conn->prepare("UPDATE clients SET status = 'inactive' WHERE id=?");
            if ($stmt === false) {
                throw new RuntimeException('Unable to prepare client delete.' . salesClientsDbError($conn));
            }
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $stmt->close();

            $conn->commit();
            logActivity($user['id'], 'delete_client', 'clients', $id, $oldData, ['status' => 'inactive', 'related_projects_status' => 'cancelled']);
            $_SESSION['success'] = "Client and related projects deleted successfully";
        } catch (Throwable $e) {
            $conn->rollback();
            error_log('Sales delete client error: ' . $e->getMessage());
            $_SESSION['error'] = 'Unable to delete client. ' . $e->getMessage();
        }
    } elseif (isset($_POST['delete_project'])) {
        $id = (int) ($_POST['id'] ?? 0);
        $oldResult = salesClientsQuery($conn, "SELECT * FROM projects WHERE id = $id LIMIT 1");
        $oldData = $oldResult ? $oldResult->fetch_assoc() : null;

        if (!$oldData) {
            $_SESSION['error'] = 'Project not found.';
        } else {
            $stmt = $conn->prepare("UPDATE projects SET status = 'cancelled' WHERE id = ?");
            if ($stmt === false) {
                $_SESSION['error'] = 'Unable to prepare project delete.' . salesClientsDbError($conn);
            } else {
                $stmt->bind_param("i", $id);
                if ($stmt->execute()) {
                    logActivity($user['id'], 'delete_project', 'projects', $id, $oldData, ['status' => 'cancelled']);
                    $_SESSION['success'] = "Project deleted successfully";
                }
                $stmt->close();
            }
        }
    }
    
    header('Location: clients.php');
    exit();
}

$savedClientDraftId = '';
if (isset($_SESSION['clear_client_draft_id'])) {
    $savedClientDraftId = (string) $_SESSION['clear_client_draft_id'];
    unset($_SESSION['clear_client_draft_id']);
}

// Get all clients
$search = isset($_GET['search']) ? $_GET['search'] : '';
$statusFilter = isset($_GET['status']) ? $_GET['status'] : '';
$allowedClientPageSizes = [10, 25, 50];
$clientPageSize = isset($_GET['per_page']) ? (int) $_GET['per_page'] : 10;
if (!in_array($clientPageSize, $allowedClientPageSizes, true)) {
    $clientPageSize = 10;
}
$clientCurrentPage = max(1, isset($_GET['page']) ? (int) $_GET['page'] : 1);

$clientWhereSql = " WHERE 1=1";
if ($search) {
    $clientWhereSql .= " AND (c.name LIKE '%$search%' OR c.email LIKE '%$search%' OR c.contact_person LIKE '%$search%')";
}
if ($statusFilter) {
    $clientWhereSql .= " AND c.status = '$statusFilter'";
} else {
    $clientWhereSql .= " AND c.status != 'inactive'";
}

$totalFilteredClients = 0;
$clientCountResult = salesClientsQuery($conn, "SELECT COUNT(*) AS total FROM clients c{$clientWhereSql}");
if ($clientCountResult) {
    $clientCountRow = $clientCountResult->fetch_assoc();
    $totalFilteredClients = (int) ($clientCountRow['total'] ?? 0);
}

$clientTotalPages = max(1, (int) ceil($totalFilteredClients / $clientPageSize));
$clientCurrentPage = min($clientCurrentPage, $clientTotalPages);
$clientPageOffset = ($clientCurrentPage - 1) * $clientPageSize;

$query = "
    SELECT
        c.*
    FROM clients c
    {$clientWhereSql}
    ORDER BY c.created_at DESC
    LIMIT {$clientPageSize} OFFSET {$clientPageOffset}
";

$clients = salesClientsQuery($conn, $query);
$clientRows = [];
$projectsByClient = [];
if ($clients) {
    while ($clientRow = $clients->fetch_assoc()) {
        $clientId = (int) ($clientRow['id'] ?? 0);
        $clientRows[] = $clientRow;
        $projectsByClient[$clientId] = [];
    }
}

if ($clientRows) {
    $clientIds = array_map(static fn($clientRow) => (int) $clientRow['id'], $clientRows);
    $clientIdsSql = implode(',', array_filter($clientIds, static fn($clientId) => $clientId > 0));
    if ($clientIdsSql !== '') {
        $projectRows = salesClientsQuery($conn, "
            SELECT
                p.id,
                p.client_id,
                p.name,
                p.status,
                p.timeline_end,
                p.created_at
            FROM projects p
            WHERE p.client_id IN ({$clientIdsSql})
              AND p.status != 'cancelled'
            ORDER BY p.created_at DESC, p.id DESC
        ");

        if ($projectRows) {
            $projectRowsByClient = [];
            $projectIds = [];
            while ($projectRow = $projectRows->fetch_assoc()) {
                $clientId = (int) ($projectRow['client_id'] ?? 0);
                $projectId = (int) ($projectRow['id'] ?? 0);
                $projectRowsByClient[$clientId][] = $projectRow;
                $projectIds[] = $projectId;
            }

            $roadmapProgress = projectRoadmapProgressByProject($conn, $projectIds);
            foreach ($projectRowsByClient as $clientId => $clientProjects) {
                foreach ($clientProjects as $projectRow) {
                    $projectRow['progress'] = $roadmapProgress[(int) ($projectRow['id'] ?? 0)] ?? 0;
                    $projectsByClient[$clientId][] = $projectRow;
                }
            }
        }
    }
}

$clientPageStart = $totalFilteredClients > 0 ? $clientPageOffset + 1 : 0;
$clientPageEnd = min($clientPageOffset + count($clientRows), $totalFilteredClients);
$buildClientPageUrl = static function (int $page, ?int $pageSize = null) use ($search, $statusFilter, $clientPageSize): string {
    $params = [];

    if ($search !== '') {
        $params['search'] = $search;
    }
    if ($statusFilter !== '') {
        $params['status'] = $statusFilter;
    }

    $params['per_page'] = $pageSize ?? $clientPageSize;
    if ($page > 1) {
        $params['page'] = $page;
    }

    return 'clients.php?' . http_build_query($params) . '#clientDirectoryWorkspace';
};

if ($clientTotalPages <= 5) {
    $clientPaginationPages = range(1, $clientTotalPages);
} else {
    $clientPaginationStart = max(1, min($clientCurrentPage - 2, $clientTotalPages - 4));
    $clientPaginationPages = range($clientPaginationStart, $clientPaginationStart + 4);
}

// Get statistics
$totalClients = salesClientsFetchCount($conn, "SELECT COUNT(*) as count FROM clients");
$activeClients = salesClientsFetchCount($conn, "SELECT COUNT(*) as count FROM clients WHERE status = 'active'");
$pendingClients = salesClientsFetchCount($conn, "SELECT COUNT(*) as count FROM clients WHERE status = 'pending'");
include '../../includes/header.php';
include '../../includes/sidebar.php';
include '../../includes/navbar.php';
?>

<style>
:root {
    --theme-primary: #1f6392;
    --theme-primary-dark: #0f4a6e;
    --theme-primary-light: #3a7ca3;
    --gray-50: #f8f9fa;
    --gray-100: #f1f3f5;
    --gray-200: #e9ecef;
    --gray-300: #dee2e6;
    --gray-600: #6c757d;
    --gray-900: #212529;
    --shadow-sm: 0 2px 4px rgba(0,0,0,0.02), 0 1px 2px rgba(0,0,0,0.03);
    --shadow-md: 0 4px 6px -1px rgba(0,0,0,0.05), 0 2px 4px -1px rgba(0,0,0,0.03);
}

.client-flash {
    display: flex;
    align-items: center;
    gap: 0.8rem;
    margin-bottom: 1rem;
    padding: 0.9rem 3.25rem 0.9rem 1rem;
    border: 0 !important;
    border-left: 0 !important;
    border-radius: 14px;
    box-shadow: 0 8px 22px rgba(15, 42, 64, 0.08);
}

.client-flash--success {
    background: #ecfdf5;
    color: #166534;
}

.client-flash__icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex: 0 0 auto;
    width: 38px;
    height: 38px;
    border-radius: 50%;
    background: #16a34a;
    color: #ffffff;
    font-size: 1rem;
    box-shadow: 0 6px 14px rgba(22, 163, 74, 0.2);
}

.client-flash__content {
    display: grid;
    gap: 0.08rem;
    min-width: 0;
}

.client-flash__content strong {
    color: inherit;
    font-size: 0.84rem;
}

.client-flash__content span {
    font-size: 0.82rem;
    line-height: 1.4;
}

.client-flash .btn-close {
    top: 50%;
    transform: translateY(-50%);
}

/* Page Header */
.page-header-modern {
    background: linear-gradient(135deg, var(--theme-primary) 0%, var(--theme-primary-dark) 100%);
    border-radius: 24px;
    padding: 2rem;
    margin-bottom: 2rem;
    position: relative;
    overflow: hidden;
}

.page-header-modern::before {
    content: '';
    position: absolute;
    top: 0;
    right: 0;
    width: 300px;
    height: 100%;
    background: linear-gradient(90deg, transparent, rgba(255,255,255,0.1));
    pointer-events: none;
}

.page-header-modern h2 {
    font-size: 1.75rem;
    font-weight: 600;
    margin-bottom: 0.5rem;
    color: white;
}

.page-header-modern h2 i {
    color: white;
}

.page-header-modern p {
    opacity: 0.9;
    margin-bottom: 0;
    font-size: 0.9rem;
    color: rgba(255, 255, 255, 0.9);
}

/* Unified client workspace */
.client-directory-workspace {
    background: #ffffff;
    border: 1px solid rgba(31, 99, 146, 0.12);
    border-radius: 22px;
    overflow: hidden;
    box-shadow: 0 8px 24px rgba(15, 64, 91, 0.06);
}

/* Keep the unified Client Data workspace stationary. The shared app-wide
 * surface effect may still enhance the shadow, but it must not lift this
 * full-page table container when the pointer enters it. */
#page-content-wrapper .client-directory-workspace > .main-card.app-3d-surface,
#page-content-wrapper .client-directory-workspace > .main-card.app-3d-surface:hover,
#page-content-wrapper .client-directory-workspace > .main-card.app-3d-surface:focus-within {
    transform: none !important;
}

/* Client summary cards sit independently between the banner and directory. */
.client-summary-strip {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 0.9rem;
    margin: 0 0 2rem;
    padding: 0;
    background: transparent;
    border: 0;
    border-radius: 0;
    box-shadow: none;
    overflow: visible;
}

.client-summary-cell {
    position: relative;
    display: block;
    min-width: 0;
    min-height: 70px;
    padding: 0.85rem 3.75rem 0.8rem 1rem;
    background: linear-gradient(135deg, #ffffff 0%, #fbfdff 58%, #edf9fc 100%);
    border: 1px solid rgba(31, 99, 146, 0.14);
    border-radius: 12px;
    box-shadow: 0 7px 0 rgba(31, 99, 146, 0.08), 0 0 18px rgba(34, 211, 238, 0.05);
    outline: 0;
    transform: none !important;
    transition: border-color 0.18s ease, box-shadow 0.18s ease;
}

.client-summary-cell:hover,
.client-summary-cell:focus-within {
    border-color: rgba(31, 99, 146, 0.2);
    box-shadow: 0 7px 0 rgba(31, 99, 146, 0.08), 0 0 18px rgba(34, 211, 238, 0.08);
    transform: none !important;
}

.client-summary-icon {
    position: absolute;
    top: 0.75rem;
    right: 1rem;
    width: 38px;
    height: 38px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 10px;
    background: #e9f3f8;
    color: var(--theme-primary);
    font-size: 0.95rem;
}

.client-summary-copy {
    display: flex;
    flex-direction: column;
    gap: 0.2rem;
    min-width: 0;
}

.client-summary-label {
    display: block;
    font-size: 0.68rem;
    text-transform: uppercase;
    font-weight: 600;
    color: var(--gray-600);
    letter-spacing: 0.55px;
    line-height: 1.2;
    margin: 0;
}

.client-summary-value {
    display: block;
    font-size: 1.4rem;
    font-weight: 600;
    color: var(--theme-primary);
    line-height: 1;
}

/* Main Card */
.main-card {
    background: white;
    border-radius: 0;
    border: 0;
    overflow: hidden;
    box-shadow: none;
}

.card-header-modern {
    padding: 0.8rem 1.5rem 0.65rem;
    background: #ffffff;
    border-bottom: 0;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 1rem;
}

.card-header-modern h5 {
    font-size: 1.1rem;
    font-weight: 600;
    color: var(--gray-900);
    margin: 0;
}

.card-header-modern h5 i {
    color: var(--theme-primary);
}

.card-header-modern .text-muted {
    color: var(--gray-600) !important;
}

/* Filter Section */
.filter-section {
    padding: 0.75rem 1.5rem 0.9rem;
    background: #ffffff;
}

.client-tabs {
    display: flex;
    gap: 0.35rem;
    position: relative;
    padding: 0 1.5rem;
    background: #ffffff;
}

.client-tabs::after {
    content: "";
    position: absolute;
    right: 1.5rem;
    bottom: 0;
    left: 1.5rem;
    height: 1px;
    background: var(--gray-200);
    pointer-events: none;
}

.client-detail-panel {
    padding: 1rem;
    border-radius: 1rem;
    background: #f8fbff;
    border: 1px solid rgba(18, 80, 127, 0.12);
}

.client-project-item {
    background: #f8fbff;
    border: 1px solid rgba(18, 80, 127, 0.12);
    border-radius: 0.85rem;
    padding: 0.85rem 1rem;
}

.client-tab-btn {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    position: relative;
    z-index: 1;
    border: none;
    background: transparent;
    color: var(--gray-600);
    padding: 0.55rem 0.7rem;
    border-bottom: 2px solid transparent;
    font-size: 0.88rem;
    font-weight: 700;
}

.client-tab-btn.is-active {
    color: var(--theme-primary);
    border-bottom-color: var(--theme-primary);
}

.client-tab-badge {
    display: none;
    min-width: 20px;
    height: 20px;
    align-items: center;
    justify-content: center;
    border-radius: 999px;
    background: var(--theme-primary);
    color: #ffffff;
    font-size: 0.72rem;
    font-weight: 700;
}

.client-tab-badge.is-visible {
    display: inline-flex;
}

.client-tab-panel[hidden] {
    display: none;
}

.client-drafts-panel {
    padding: 1.25rem 1.5rem 1.5rem;
    background: var(--gray-50);
}

.client-draft-list {
    display: block;
    margin-bottom: 1rem;
    overflow: hidden;
    background: #ffffff;
    border: 1px solid var(--gray-200);
    border-radius: 12px;
}

.client-draft-table__head,
.client-draft-card {
    display: grid;
    grid-template-columns:
        minmax(210px, 1.15fr)
        minmax(170px, 0.95fr)
        minmax(180px, 1fr)
        minmax(150px, 0.75fr)
        minmax(190px, auto);
    gap: 1rem;
    align-items: center;
}

.client-draft-table__head {
    padding: 0.78rem 1.15rem;
    background: #f6f8fa;
    border-bottom: 1px solid var(--gray-200);
    color: var(--gray-600);
    font-size: 0.72rem;
    font-weight: 700;
    letter-spacing: 0.055em;
    text-transform: uppercase;
}

.client-draft-table__head span:last-child {
    text-align: center;
}

.client-draft-card {
    padding: 0.9rem 1.15rem;
    background: transparent;
    border: 0;
    border-bottom: 1px solid var(--gray-200);
    border-radius: 0;
    box-shadow: none;
    transform: none !important;
    transition: background-color 0.16s ease;
}

.client-draft-card:last-child {
    border-bottom: 0;
}

.client-draft-card:hover,
.client-draft-card:focus-within {
    background: #f7fbfd;
    box-shadow: none;
    transform: none !important;
}

.client-draft-card__identity {
    display: flex;
    align-items: center;
    gap: 0.7rem;
    min-width: 0;
}

.client-draft-card__icon {
    width: 2.3rem;
    height: 2.3rem;
    flex: 0 0 2.3rem;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 9px;
    background: #eaf4fa;
    color: var(--theme-primary);
}

.client-draft-card__title {
    font-size: 1rem;
    font-weight: 600;
    color: var(--gray-900);
    margin: 0;
    word-break: break-word;
}

.client-draft-card__cell,
.client-draft-card__subtitle {
    font-size: 0.82rem;
    color: var(--gray-600);
    margin: 0;
    min-width: 0;
    overflow-wrap: anywhere;
}

.client-draft-card__empty {
    color: var(--gray-500);
}

.client-draft-card__actions {
    display: flex;
    gap: 0.5rem;
    flex-wrap: nowrap;
    justify-content: flex-end;
}

.client-draft-card__actions .btn {
    min-width: 82px;
    border-radius: 8px;
    font-weight: 600;
}

.client-draft-card__actions .btn:hover,
.client-draft-card__actions .btn:focus-visible {
    transform: none !important;
}

@media (max-width: 980px) {
    .client-draft-table__head {
        display: none;
    }

    .client-draft-card {
        grid-template-columns: minmax(0, 1fr) auto;
        gap: 0.5rem 1rem;
        padding: 1rem;
    }

    .client-draft-card__identity,
    .client-draft-card__cell,
    .client-draft-card__subtitle {
        grid-column: 1;
    }

    .client-draft-card__cell::before,
    .client-draft-card__subtitle::before {
        content: attr(data-label) ": ";
        color: var(--gray-500);
        font-size: 0.7rem;
        font-weight: 700;
        letter-spacing: 0.04em;
        text-transform: uppercase;
    }

    .client-draft-card__actions {
        grid-column: 2;
        grid-row: 1 / span 4;
        align-self: center;
    }
}

@media (max-width: 620px) {
    .client-draft-card {
        grid-template-columns: 1fr;
    }

    .client-draft-card__actions {
        grid-column: 1;
        grid-row: auto;
        justify-content: flex-start;
        margin-top: 0.35rem;
    }
}

.filter-group {
    display: grid;
    grid-template-columns: minmax(280px, 520px) minmax(0, 1fr);
    gap: 1.25rem;
    align-items: center;
}

.client-filter-actions {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 0.75rem;
    min-width: 0;
}

.client-filter-actions .filter-input {
    flex: 0 1 210px;
    width: 210px;
}

.btn-add-client-toolbar {
    min-height: 42px;
    padding: 0.62rem 1.15rem;
    border: 1px solid var(--theme-primary);
    border-radius: 10px;
    background: var(--theme-primary);
    color: #ffffff;
    font-size: 0.84rem;
    font-weight: 600;
    line-height: 1;
    white-space: nowrap;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
    box-shadow: 0 6px 14px rgba(31, 99, 146, 0.16);
    transition: background-color 0.18s ease, border-color 0.18s ease,
        box-shadow 0.18s ease, transform 0.18s ease;
}

.btn-add-client-toolbar:hover,
.btn-add-client-toolbar:focus-visible {
    border-color: var(--theme-primary-dark);
    background: var(--theme-primary-dark);
    color: #ffffff;
    box-shadow: 0 8px 18px rgba(31, 99, 146, 0.22);
    transform: translateY(-1px);
}

.btn-add-client-toolbar:focus-visible {
    outline: 3px solid rgba(31, 99, 146, 0.2);
    outline-offset: 2px;
}

.filter-input {
    position: relative;
    min-width: 0;
}

.filter-input > i {
    position: absolute;
    top: 50%;
    left: 0.15rem;
    z-index: 1;
    color: #668096;
    font-size: 0.84rem;
    transform: translateY(-50%);
    pointer-events: none;
}

.filter-input input,
.filter-input select {
    width: 100%;
    min-height: 42px;
    padding: 0.52rem 1.8rem 0.52rem 1.65rem;
    border: 0;
    border-bottom: 1px solid #cbd8e3;
    border-radius: 0;
    outline: 0;
    background: transparent;
    color: #344b61;
    font-size: 0.82rem;
    transition: border-color 0.18s ease, box-shadow 0.18s ease;
}

.filter-input input:focus,
.filter-input select:focus {
    border-color: var(--theme-primary);
    box-shadow: 0 2px 0 rgba(31, 99, 146, 0.12);
}

.btn-apply {
    background: linear-gradient(135deg, var(--theme-primary) 0%, var(--theme-primary-dark) 100%);
    color: white;
    border: none;
    padding: 0.6rem 1.5rem;
    border-radius: 12px;
    font-weight: 500;
    transition: all 0.2s;
}

.btn-apply:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(31, 99, 146, 0.3);
}

/* Table Styles */
.table-container {
    padding: 0;
    overflow-x: auto;
}

.client-directory-workspace .client-tab-panel[data-client-panel="clients"] > .table-container {
    margin: 1rem 1.5rem 0;
    overflow: visible;
}

.client-table {
    width: 100%;
    border-collapse: collapse;
}

.client-table thead {
    background: var(--gray-50);
}

.client-table th {
    padding: 1rem 1.5rem;
    text-align: left;
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: var(--gray-600);
    border-bottom: 1px solid var(--gray-200);
}

.client-table td {
    padding: 1rem 1.5rem;
    vertical-align: middle;
    border-bottom: 1px solid var(--gray-200);
}

.client-table tbody .client-row {
    transition: background 0.18s ease, box-shadow 0.18s ease;
}

.client-table tbody .client-row:hover {
    background: #f6fbfe;
    box-shadow: inset 3px 0 0 #4b94ba;
}

.client-table th:last-child,
.client-table td:last-child {
    width: 4.5rem;
    text-align: right;
}

.client-contact-link,
.client-contact-empty {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    max-width: 18rem;
    color: var(--gray-600);
    font-size: 0.8rem;
    line-height: 1.35;
    text-decoration: none;
}

.client-contact-link i {
    flex: 0 0 auto;
    color: var(--theme-primary);
}

.client-contact-link span {
    overflow-wrap: anywhere;
}

.client-contact-link:hover {
    color: var(--theme-primary-dark);
}

.client-table .action-buttons {
    position: relative;
    display: flex;
    flex-direction: row;
    justify-content: flex-end;
}

.client-action-menu {
    position: relative;
}

.client-action-menu > summary {
    display: grid;
    width: 2.4rem;
    height: 2.4rem;
    margin-left: auto;
    padding: 0;
    place-items: center;
    list-style: none;
    border: 1px solid var(--gray-200);
    border-radius: 0.65rem;
    background: #fff;
    color: var(--theme-primary);
    cursor: pointer;
    transition: background 0.18s ease, border-color 0.18s ease, box-shadow 0.18s ease;
}

.client-action-menu > summary::-webkit-details-marker {
    display: none;
}

.client-action-menu > summary:hover,
.client-action-menu[open] > summary {
    border-color: #9bc6dd;
    background: #edf7fc;
    box-shadow: 0 4px 12px rgba(31, 99, 146, 0.12);
}

.client-action-menu__panel {
    position: absolute;
    top: calc(100% + 0.4rem);
    right: 0;
    z-index: 50;
    min-width: 12rem;
    padding: 0.35rem;
    border: 1px solid var(--gray-200);
    border-radius: 0.75rem;
    background: #fff;
    box-shadow: 0 14px 32px rgba(15, 43, 68, 0.16);
}

.client-action-menu__item {
    display: flex;
    align-items: center;
    gap: 0.65rem;
    width: 100%;
    padding: 0.65rem 0.75rem;
    border: 0;
    border-radius: 0.5rem;
    background: transparent;
    color: var(--gray-800);
    font: inherit;
    font-size: 0.82rem;
    line-height: 1.2;
    text-align: left;
    white-space: nowrap;
    cursor: pointer;
}

.client-action-menu__item:hover {
    background: var(--gray-50);
}

.client-action-menu__item i {
    width: 1rem;
    color: var(--theme-primary);
    text-align: center;
}

.client-action-menu__item--danger,
.client-action-menu__item--danger i {
    color: #dc2626;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .client-contact-link,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .client-contact-empty {
    color: #9db6cf;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .client-contact-link i {
    color: #8fd3ff;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .client-action-menu > summary,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .client-action-menu__panel {
    border-color: rgba(126, 165, 197, 0.28);
    background: #102235;
    color: #d7e4f2;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .client-action-menu__item {
    color: #d7e4f2;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .client-action-menu__item:hover {
    background: rgba(75, 148, 186, 0.16);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .client-action-menu__item--danger,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .client-action-menu__item--danger i {
    color: #fca5a5;
}

.client-pagination {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    padding: 0.9rem 1.25rem;
    border-top: 1px solid var(--gray-200);
    background: #fff;
}

.client-pagination__summary {
    color: var(--gray-600);
    font-size: 0.78rem;
    white-space: nowrap;
}

.client-pagination__meta {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    min-width: 0;
}

.client-pagination__size {
    height: 36px;
    min-width: 94px;
    padding: 0 1.8rem 0 0.7rem;
    border: 1px solid var(--gray-300);
    border-radius: 9px;
    background-color: #fff;
    color: var(--gray-700);
    font: inherit;
    font-size: 0.78rem;
    font-weight: 600;
}

.client-pagination__pages,
.client-pagination__controls {
    display: flex;
    align-items: center;
}

.client-pagination__controls {
    gap: 0.55rem;
}

.client-pagination__pages {
    overflow: hidden;
    border: 1px solid var(--gray-300);
    border-radius: 9px;
    background: #fff;
}

.client-pagination__page,
.client-pagination__step {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    height: 36px;
    color: var(--gray-700) !important;
    font-family: inherit;
    font-size: 0.78rem;
    font-weight: 600;
    text-decoration: none;
}

.client-pagination__page {
    min-width: 36px;
    padding: 0 0.55rem;
    border: 0;
    background: transparent;
}

.client-pagination__page:hover,
.client-pagination__page.is-current {
    background: #edf5fa;
    color: var(--theme-primary) !important;
}

.client-pagination__page.is-current {
    box-shadow: inset 0 0 0 1px #cfe1ec;
    pointer-events: none;
}

.client-pagination__step {
    min-width: 76px;
    padding: 0 0.75rem;
    border: 1px solid var(--gray-300);
    border-radius: 9px;
    background: #fff;
}

button.client-pagination__page,
button.client-pagination__step {
    cursor: pointer;
}

.client-pagination__step:hover {
    border-color: #9bbdd1;
    color: var(--theme-primary) !important;
}

.client-pagination__step.is-disabled {
    background: var(--gray-50);
    color: var(--gray-400) !important;
    pointer-events: none;
}

.client-draft-pagination {
    border-top: 0;
}

.client-name {
    font-weight: 600;
    color: var(--gray-900);
    margin-bottom: 0.25rem;
}

.client-email {
    font-size: 0.75rem;
    color: var(--gray-600);
}

/* Badges */
.badge-status {
    display: inline-block;
    padding: 0.25rem 0.75rem;
    border-radius: 20px;
    font-size: 0.7rem;
    font-weight: 600;
}

.badge-status.active {
    background: #d1fae5;
    color: #059669;
}

.badge-status.inactive {
    background: #fef3c7;
    color: #d97706;
}

.badge-status.pending {
    background: #fef9c3;
    color: #854d0e;
}

.project-row-toggle {
    min-width: 162px;
    min-height: 40px;
    border: 1px solid var(--gray-300);
    border-radius: 8px;
    background: #fff;
    color: var(--gray-900);
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.75rem;
    padding: 0.55rem 0.75rem;
    font-size: 0.82rem;
    font-weight: 600;
    transition: border-color 0.2s ease, box-shadow 0.2s ease, background 0.2s ease;
}

.project-row-toggle span {
    display: inline-flex;
    align-items: center;
    gap: 0.45rem;
}

.project-row-toggle[aria-expanded="true"] {
    border-color: var(--theme-primary);
    box-shadow: 0 0 0 3px rgba(31, 99, 146, 0.12);
}

.project-row-toggle[aria-expanded="true"] .project-row-chevron {
    transform: rotate(180deg);
}

.project-row-chevron {
    color: var(--gray-600);
    transition: transform 0.2s ease;
}

.client-table tr.client-row {
    transition: background 0.2s ease;
}

.client-table tr.client-row.has-projects {
    cursor: pointer;
}

.client-table tr.client-row.is-expanded,
.client-table tr.client-row.has-projects:hover {
    background: var(--gray-50);
}

.project-expansion-row td {
    padding: 0;
    border-bottom: 1px solid var(--gray-200);
    background: #fbfdff;
}

/* The expansion cell is also the row's last cell; undo the Actions-column
   alignment so project details begin beneath the first (Client) column. */
.client-table .project-expansion-row > td:last-child {
    width: auto;
    text-align: left;
}

.project-expansion-shell {
    max-height: 0;
    opacity: 0;
    overflow: hidden;
    transform: translateY(-6px);
    transition: max-height 0.35s ease, opacity 0.25s ease, transform 0.35s ease;
}

.project-expansion-row.is-open .project-expansion-shell {
    opacity: 1;
    transform: translateY(0);
}

.project-expansion-inner {
    padding: 0.95rem 1.5rem 1.1rem;
}

.project-expansion-list {
    border: 1px solid var(--gray-200);
    border-radius: 8px;
    background: #fff;
    box-shadow: 0 12px 26px rgba(15, 42, 64, 0.08);
    padding: 0.45rem;
}

.project-expansion-item {
    display: grid;
    grid-template-columns: minmax(0, 1.15fr) minmax(180px, 1fr) minmax(220px, 1fr) auto;
    align-items: center;
    gap: 1.25rem;
    padding: 1rem;
    border-radius: 8px;
}

.project-expansion-item + .project-expansion-item {
    border-top: 1px solid var(--gray-100);
}

.project-expansion-main {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.35rem 0.45rem;
    min-width: 0;
}

.project-expansion-main strong {
    flex-basis: 100%;
    color: var(--gray-900);
    font-size: 0.88rem;
}

.project-status-pill,
.project-progress-pill {
    display: inline-flex;
    align-items: center;
    white-space: nowrap;
    border-radius: 999px;
    padding: 0.18rem 0.5rem;
    font-size: 0.66rem;
    font-weight: 700;
}
.badge-status.overdue { background:#fee2e2; color:#b42318; }
.project-expansion-progress { display:grid; grid-template-columns:minmax(120px,1fr) auto; align-items:center; gap:.75rem; min-width:0; }
.project-progress-track { width:100%; height:8px; overflow:hidden; border-radius:999px; background:#dceaf4; }
.project-progress-fill { height:100%; border-radius:inherit; background:linear-gradient(90deg,#1597e5,#20b886); transition:width .3s ease; }
.project-team-notify { display:flex; align-items:center; gap:.65rem; min-width:0; width:100%; }
.project-team-notify.is-completed { justify-content:flex-end; }
.project-team-notify input { flex:1 1 auto; width:100%; min-width:0; border:1px solid #cbdde9; border-radius:9px; padding:.55rem .75rem; }
.project-team-notify button, .project-team-notify .project-download-button { white-space:nowrap; }
.project-expansion-actions { display:flex; justify-content:flex-end; }
.project-delete-button { width:34px; height:34px; display:inline-flex; align-items:center; justify-content:center; border-radius:9px; }

@media (max-width: 1100px) {
    .project-expansion-item { grid-template-columns:1fr 1fr; }
    .project-team-notify, .project-expansion-actions { grid-column:1 / -1; }
}

@media (max-width: 700px) {
    .project-expansion-item { grid-template-columns:1fr; gap:.9rem; }
    .project-team-notify { grid-column:auto; }
}

.project-status-pill {
    background: var(--gray-100);
    color: var(--gray-600);
}

.project-progress-pill {
    background: #e0f2fe;
    color: var(--theme-primary-dark);
}

/* Deadline */
.deadline-info {
    font-size: 0.75rem;
}

.deadline-date {
    font-weight: 500;
    color: var(--gray-900);
}

.deadline-days {
    font-size: 0.65rem;
    display: inline-block;
    padding: 0.15rem 0.4rem;
    border-radius: 12px;
    margin-top: 0.25rem;
}

.deadline-days.urgent {
    background: #fee2e2;
    color: #dc2626;
}

.deadline-days.warning {
    background: #fef3c7;
    color: #d97706;
}

.deadline-days.normal {
    background: #dbeafe;
    color: #2563eb;
}

/* Action Buttons */
.action-buttons {
    display: flex;
    gap: 0.5rem;
}

.btn-icon {
    width: 32px;
    height: 32px;
    border-radius: 10px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    transition: all 0.2s;
    cursor: pointer;
    border: none;
}

.btn-icon.view {
    background: #e9ecef;
    color: #6c757d;
}

.btn-icon.view:hover {
    background: linear-gradient(135deg, var(--theme-primary) 0%, var(--theme-primary-dark) 100%);
    color: white;
}

.btn-icon.edit {
    background: #e9ecef;
    color: var(--theme-primary);
}

.btn-icon.edit:hover {
    background: linear-gradient(135deg, var(--theme-primary) 0%, var(--theme-primary-dark) 100%);
    color: white;
}

.btn-icon.add-project {
    background: #eef6ff;
    color: var(--theme-primary);
}

.btn-icon.add-project:hover {
    background: var(--theme-primary);
    color: white;
}

.btn-icon.download {
    background: #ecfdf5;
    color: #059669;
}

.btn-icon.download:hover {
    background: #059669;
    color: white;
}

.btn-icon.delete {
    background: #fee2e2;
    color: #dc2626;
}

.btn-icon.delete:hover {
    background: #dc2626;
    color: white;
}

/* Add Button */
.btn-add-modern {
    background: rgba(255, 255, 255, 0.15);
    border: 1px solid rgba(255, 255, 255, 0.3);
    color: white !important;
    padding: 0.7rem 1.5rem;
    border-radius: 40px;
    font-weight: 500;
    transition: all 0.2s ease;
    backdrop-filter: blur(10px);
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
}

.btn-add-modern:hover {
    background: white;
    color: var(--theme-primary) !important;
    transform: translateY(-2px);
    border-color: white;
}

.btn-add-modern i {
    color: white !important;
}

.btn-add-modern:hover i {
    color: var(--theme-primary) !important;
}

/* Empty State */
.empty-state-modern {
    text-align: center;
    padding: 4rem 2rem;
}

.empty-state-modern i {
    font-size: 4rem;
    color: var(--gray-300);
    margin-bottom: 1rem;
}

.empty-state-modern h4 {
    color: var(--gray-900);
    margin-bottom: 0.5rem;
}

.empty-state-modern p {
    color: var(--gray-600);
}

/* Modal Styling */
.modal-modern .modal-content {
    border-radius: 24px;
    border: none;
    overflow: hidden;
}

.modal-modern .modal-header {
    background: linear-gradient(135deg, var(--theme-primary) 0%, var(--theme-primary-dark) 100%);
    color: white;
    padding: 1.5rem;
    border: none;
}

.modal-modern .modal-header .modal-title {
    color: white;
}

.modal-modern .modal-header .btn-close {
    filter: brightness(0) invert(1);
}

#editClientModal.modal-modern .modal-content {
    border: 1px solid rgba(13, 103, 134, 0.18);
    border-radius: 22px;
    box-shadow: 0 26px 70px rgba(7, 35, 54, 0.28);
}

#editClientModal.modal-modern .modal-header {
    min-height: 76px;
    padding: 1rem 1.5rem;
    background: linear-gradient(135deg, #0d6786 0%, #0a5570 68%, #083f58 100%) !important;
    color: #ffffff !important;
    border-bottom: 1px solid rgba(255, 255, 255, 0.14);
    box-shadow: 0 8px 24px rgba(8, 63, 88, 0.16);
}

#editClientModal.modal-modern .modal-title {
    display: flex;
    align-items: center;
    margin: 0;
    color: #ffffff !important;
    font-size: 1.28rem;
    font-weight: 600;
    letter-spacing: -0.01em;
}

#editClientModal.modal-modern .modal-title i {
    display: inline-grid;
    width: 38px;
    height: 38px;
    margin-right: 0.75rem !important;
    place-items: center;
    color: #ffffff !important;
    background: rgba(255, 255, 255, 0.12);
    border: 1px solid rgba(255, 255, 255, 0.18);
    border-radius: 11px;
}

#editClientModal.modal-modern .modal-header .btn-close {
    width: 38px;
    height: 38px;
    margin: 0;
    padding: 0;
    background-size: 15px;
    background-color: rgba(255, 255, 255, 0.1);
    border: 1px solid rgba(255, 255, 255, 0.2);
    border-radius: 11px;
    filter: brightness(0) invert(1);
    opacity: 0.88;
    transition: background-color 0.18s ease, opacity 0.18s ease;
}

#editClientModal.modal-modern .modal-header .btn-close:hover,
#editClientModal.modal-modern .modal-header .btn-close:focus-visible {
    background-color: rgba(255, 255, 255, 0.18);
    opacity: 1;
}

.modal-modern .modal-body {
    padding: 2rem;
    max-height: 70vh;
    overflow-y: auto;
}

.modal-modern.add-client-modal .modal-dialog {
    max-width: 900px;
}

.modal-modern.add-client-modal .modal-content {
    background: #f8fafc;
}

.modal-modern.add-client-modal .modal-body {
    padding: 1.5rem;
}

:is(#addClientModal, #addProjectModal).modal-modern.add-client-modal {
    --add-client-modal-top-offset: var(--add-client-page-top, 86px);
    top: var(--add-client-modal-top-offset);
    left: var(--shell-sidebar-width);
    width: calc(100vw - var(--shell-sidebar-width));
    height: calc(100vh - var(--add-client-modal-top-offset));
    height: calc(100dvh - var(--add-client-modal-top-offset));
    right: auto;
    bottom: auto;
    padding: 0 !important;
    background:
        linear-gradient(135deg, rgba(13, 103, 134, 0.10) 0%, rgba(255, 255, 255, 0) 42%),
        linear-gradient(180deg, #f7fbfe 0%, #edf5fa 100%);
    overflow-x: hidden;
    overflow-y: auto;
}

/* Theme backgrounds isolate the page for their ambient effects. Temporarily
   release that stacking context so Bootstrap's backdrop stays behind these
   full-page forms instead of blocking every field and action. */
body:has(#addClientModal.show, #addProjectModal.show) #page-content-wrapper {
    isolation: auto !important;
}

body:has(#addClientModal.show, #addProjectModal.show) #page-content-wrapper > .app-navbar {
    z-index: 1057;
    pointer-events: auto;
}

#page-content-wrapper.sidebar-collapsed :is(#addClientModal, #addProjectModal).modal-modern.add-client-modal {
    left: var(--shell-sidebar-collapsed-width);
    width: calc(100vw - var(--shell-sidebar-collapsed-width));
}

body:has(#addClientModal.show, #addProjectModal.show) .modal-backdrop {
    --add-client-modal-top-offset: var(--add-client-page-top, 86px);
    top: var(--add-client-modal-top-offset);
    left: var(--shell-sidebar-width);
    width: calc(100vw - var(--shell-sidebar-width));
    height: calc(100vh - var(--add-client-modal-top-offset));
    height: calc(100dvh - var(--add-client-modal-top-offset));
    bottom: auto;
}

body:has(#page-content-wrapper.sidebar-collapsed :is(#addClientModal, #addProjectModal).show) .modal-backdrop {
    left: var(--shell-sidebar-collapsed-width);
    width: calc(100vw - var(--shell-sidebar-collapsed-width));
}

:is(#addClientModal, #addProjectModal).modal-modern.add-client-modal .modal-dialog {
    width: 100%;
    max-width: none;
    min-height: 100%;
    height: auto;
    margin: 0;
    display: flex;
    align-items: stretch;
}

:is(#addClientModal, #addProjectModal).modal-modern.add-client-modal .modal-content {
    width: 100%;
    min-height: 100%;
    height: auto;
    max-height: none;
    border-radius: 0;
    border: 0;
    background:
        linear-gradient(135deg, rgba(13, 103, 134, 0.10) 0%, rgba(255, 255, 255, 0) 42%),
        linear-gradient(180deg, #f7fbfe 0%, #edf5fa 100%);
    box-shadow: none;
    display: flex;
    flex-direction: column;
    overflow: visible;
}

:is(#addClientModal, #addProjectModal).modal-modern.add-client-modal .modal-header {
    padding: 1rem 1.75rem;
    background: linear-gradient(135deg, #0d6786 0%, #0a5570 100%);
    box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.18), 0 10px 28px rgba(9, 80, 110, 0.16);
}

:is(#addClientModal, #addProjectModal).modal-modern.add-client-modal .modal-header .modal-title,
:is(#addClientModal, #addProjectModal).modal-modern.add-client-modal .modal-header .modal-title i {
    color: #ffffff !important;
}

:is(#addClientModal, #addProjectModal).modal-modern.add-client-modal .modal-header .btn-close {
    filter: brightness(0) invert(1);
    opacity: 0.82;
}

:is(#addClientModal, #addProjectModal).modal-modern.add-client-modal .modal-header .btn-close:hover {
    opacity: 1;
}

:is(#addClientModal, #addProjectModal).modal-modern.add-client-modal form {
    display: block;
    height: auto;
    overflow: visible;
}

:is(#addClientModal, #addProjectModal).modal-modern.add-client-modal .modal-body {
    position: relative;
    display: grid;
    grid-template-rows: auto auto auto;
    gap: 1rem;
    flex: 0 0 auto;
    min-height: 0;
    max-height: none;
    padding: 1.25rem 1.75rem;
    overflow: visible;
}

.modal-modern.add-client-modal .add-client-layout {
    display: grid;
    gap: 1rem;
}

:is(#addClientModal, #addProjectModal).modal-modern.add-client-modal .add-client-layout {
    display: flex;
    flex-direction: column;
    gap: 0;
    min-height: 0;
    grid-template-columns: none;
    align-items: stretch;
    background: #ffffff;
    border-radius: 0;
    overflow: visible;
}

:is(#addClientModal, #addProjectModal).modal-modern.add-client-modal .add-client-step:not([hidden]) {
    flex: 0 0 auto;
}

.modal-modern.add-client-modal .add-client-stepper {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 0.75rem;
    margin-bottom: 1rem;
}

.modal-modern.add-project-modal .add-client-stepper {
    grid-template-columns: repeat(3, minmax(0, 1fr));
}

.modal-modern.add-client-modal .stepper-item {
    display: flex;
    align-items: center;
    gap: 0.65rem;
    min-width: 0;
    width: 100%;
    height: 100%;
    padding: 0.75rem 2.6rem 0.75rem 0.75rem;
    border: 1px solid var(--gray-200);
    border-radius: 12px;
    background: #ffffff;
    color: var(--gray-600);
    text-align: left;
    transition: all 0.18s ease;
}

.modal-modern.add-client-modal .stepper-tooltip-wrap {
    position: relative;
    min-width: 0;
}

.modal-modern.add-client-modal .stepper-info {
    position: absolute;
    top: 50%;
    right: 0.65rem;
    z-index: 2;
    width: 23px;
    height: 23px;
    padding: 0;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    transform: translateY(-50%);
    border: 1px solid rgba(31, 99, 146, 0.22);
    border-radius: 50%;
    background: #e9f3fa;
    color: var(--theme-primary);
    font-size: 0.68rem;
    cursor: help;
    transition: background-color 0.18s ease, color 0.18s ease, transform 0.18s ease;
}

.modal-modern.add-client-modal .stepper-info:hover,
.modal-modern.add-client-modal .stepper-info:focus-visible {
    transform: translateY(-50%) scale(1.08);
    background: var(--theme-primary);
    color: #ffffff;
    outline: none;
}

.modal-modern.add-client-modal .stepper-item:disabled {
    cursor: default;
    opacity: 1;
}

.modal-modern.add-client-modal .stepper-item span {
    width: 30px;
    height: 30px;
    border-radius: 50%;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex: 0 0 auto;
    background: var(--gray-100);
    color: var(--gray-600);
    font-size: 0.78rem;
    font-weight: 700;
}

.modal-modern.add-client-modal .stepper-item strong {
    display: block;
    color: inherit;
    font-size: 0.84rem;
    font-weight: 700;
    line-height: 1.2;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.modal-modern.add-client-modal .stepper-item.is-active {
    border-color: rgba(31, 99, 146, 0.35);
    color: var(--theme-primary);
    box-shadow: 0 8px 18px rgba(31, 99, 146, 0.08);
}

.modal-modern.add-client-modal .stepper-item.is-active span {
    background: var(--theme-primary);
    color: #ffffff;
}

.modal-modern.add-client-modal .stepper-item.is-complete {
    border-color: rgba(5, 150, 105, 0.25);
    color: #047857;
}

.modal-modern.add-client-modal .stepper-item.is-complete span {
    background: #d1fae5;
    color: #047857;
}

:is(#addClientModal, #addProjectModal).modal-modern.add-client-modal .add-client-stepper {
    gap: 0;
    padding: 0.45rem 0.65rem;
    border: 1px solid rgba(31, 99, 146, 0.14);
    border-radius: 16px;
    background: rgba(255, 255, 255, 0.88);
    box-shadow: 0 10px 24px rgba(15, 76, 129, 0.06);
}

#addClientModal.modal-modern.add-client-modal .add-client-stepper {
    grid-template-columns: repeat(4, minmax(0, 1fr));
}

#addProjectModal.modal-modern.add-client-modal .add-client-stepper {
    grid-template-columns: repeat(3, minmax(0, 1fr));
}

:is(#addClientModal, #addProjectModal).modal-modern.add-client-modal .stepper-tooltip-wrap {
    display: flex;
    align-items: center;
    min-height: 54px;
    padding: 0 0.5rem;
}

:is(#addClientModal, #addProjectModal).modal-modern.add-client-modal .stepper-tooltip-wrap:not(:last-child)::after {
    content: "";
    position: absolute;
    top: 50%;
    left: 2.45rem;
    right: -0.55rem;
    z-index: 0;
    height: 2px;
    background: #dce8ef;
    transform: translateY(-50%);
}

:is(#addClientModal, #addProjectModal).modal-modern.add-client-modal .stepper-tooltip-wrap:has(.stepper-item.is-complete)::after {
    background: linear-gradient(90deg, #0d6786, #4aa3bd);
}

:is(#addClientModal, #addProjectModal).modal-modern.add-client-modal .stepper-item {
    position: relative;
    z-index: 1;
    width: auto;
    height: auto;
    padding: 0.42rem 2rem 0.42rem 0;
    border: 0;
    border-radius: 0;
    background: transparent;
    box-shadow: none;
}

:is(#addClientModal, #addProjectModal).modal-modern.add-client-modal .stepper-item span {
    width: 34px;
    height: 34px;
    border: 2px solid #d8e5ed;
    background: #f3f7f9;
    box-shadow: 0 0 0 5px #ffffff;
}

:is(#addClientModal, #addProjectModal).modal-modern.add-client-modal .stepper-item strong {
    position: relative;
    z-index: 1;
    padding: 0.18rem 0.45rem 0.18rem 0;
    background: #ffffff;
    color: #607386;
    font-size: 0.8rem;
}

:is(#addClientModal, #addProjectModal).modal-modern.add-client-modal .stepper-item.is-active,
:is(#addClientModal, #addProjectModal).modal-modern.add-client-modal .stepper-item.is-complete {
    border: 0;
    background: transparent;
    box-shadow: none;
}

:is(#addClientModal, #addProjectModal).modal-modern.add-client-modal .stepper-item.is-active strong {
    color: #0a5b78;
    font-weight: 800;
}

:is(#addClientModal, #addProjectModal).modal-modern.add-client-modal .stepper-item.is-active span {
    border-color: #0d6786;
    background: #0d6786;
    color: #ffffff;
    box-shadow: 0 0 0 5px #ffffff, 0 0 0 7px rgba(13, 103, 134, 0.14);
}

:is(#addClientModal, #addProjectModal).modal-modern.add-client-modal .stepper-item.is-complete strong {
    color: #176b78;
}

:is(#addClientModal, #addProjectModal).modal-modern.add-client-modal .stepper-item.is-complete span {
    border-color: #2a8798;
    background: #2a8798;
    color: #ffffff;
    font-size: 0;
}

:is(#addClientModal, #addProjectModal).modal-modern.add-client-modal .stepper-item.is-complete span::before {
    content: "\f00c";
    font-family: "Font Awesome 5 Free";
    font-size: 0.72rem;
    font-weight: 900;
}

:is(#addClientModal, #addProjectModal).modal-modern.add-client-modal .stepper-info {
    top: 50%;
    right: 0.45rem;
    z-index: 3;
    width: 21px;
    height: 21px;
    border-color: rgba(31, 99, 146, 0.16);
    background: #ffffff;
    box-shadow: 0 0 0 4px #ffffff;
}

@media (max-width: 650px) {
    :is(#addClientModal, #addProjectModal).modal-modern.add-client-modal .add-client-stepper {
        padding-inline: 0.3rem;
    }

    :is(#addClientModal, #addProjectModal).modal-modern.add-client-modal .stepper-tooltip-wrap {
        min-height: 62px;
        padding-inline: 0.25rem;
    }

    :is(#addClientModal, #addProjectModal).modal-modern.add-client-modal .stepper-item {
        align-items: flex-start;
        flex-direction: column;
        gap: 0.35rem;
        padding-right: 0.25rem;
    }

    :is(#addClientModal, #addProjectModal).modal-modern.add-client-modal .stepper-item strong {
        font-size: 0.66rem;
        white-space: normal;
    }

    :is(#addClientModal, #addProjectModal).modal-modern.add-client-modal .stepper-info {
        display: none;
    }
}

.modal-modern.add-client-modal .add-client-step[hidden] {
    display: none;
}

.modal-modern.add-client-modal .section-title {
    margin: 0 0 1rem;
    color: var(--gray-900);
    font-size: 0.92rem;
    font-weight: 700;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.modal-modern.add-client-modal .section-title i {
    color: var(--theme-primary);
}

.modal-modern.add-client-modal .field-group {
    padding: 1.15rem;
    border: 1px solid var(--gray-200);
    border-radius: 12px;
    background: #ffffff;
    margin-bottom: 0;
    height: 390px;
    overflow-y: auto;
}

:is(#addClientModal, #addProjectModal).modal-modern.add-client-modal .field-group {
    height: auto;
    min-height: 0;
    overflow: visible;
    padding: 1.35rem;
    border-radius: 18px;
    border-color: rgba(31, 99, 146, 0.16);
    background: rgba(255, 255, 255, 0.96);
    box-shadow: 0 18px 38px rgba(15, 76, 129, 0.09);
}

.modal-modern.add-client-modal .field-group:last-child {
    margin-bottom: 0;
}

.modal-modern.add-client-modal .project-intake-grid {
    display: grid;
    grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
    gap: 1rem;
    align-items: start;
}

.modal-modern.add-client-modal .project-description-field {
    grid-column: 1 / -1;
}

.modal-modern.add-client-modal .project-intake-support-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 1rem;
    margin-top: 1rem;
}

.modal-modern.add-client-modal .project-intake-support-grid > div,
.modal-modern.add-client-modal .project-intake-detail-grid > .project-intake-column {
    min-width: 0;
    padding: 1rem 1.05rem;
    border: 1px solid rgba(31, 99, 146, 0.14);
    border-radius: 18px;
    background:
        linear-gradient(135deg, rgba(13, 103, 134, 0.055), rgba(255, 255, 255, 0.88)),
        rgba(248, 252, 255, 0.92);
    box-shadow: 0 14px 30px rgba(15, 76, 129, 0.07);
}

.modal-modern.add-client-modal .project-intake-detail-grid {
    align-items: start;
    margin-top: 1rem;
}

.modal-modern.add-client-modal .project-intake-detail-grid > .project-intake-column {
    min-height: 0;
    height: auto;
}

.modal-modern.add-client-modal .project-intake-column {
    display: grid;
    gap: 1rem;
    align-content: start;
}

.modal-modern.add-client-modal .project-intake-inline {
    display: grid;
    grid-template-columns: minmax(0, 0.82fr) minmax(0, 1.18fr);
    gap: 1rem;
    align-items: end;
    margin-top: auto;
    padding-top: 0.15rem;
}

.modal-modern.add-client-modal .project-intake-step .requested-feature-list {
    max-height: none;
    overflow: visible;
    padding-right: 0;
}

.modal-modern.add-client-modal .timeline-budget-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 1rem;
}

.modal-modern.add-client-modal .timeline-budget-card {
    min-width: 0;
    position: relative;
    padding: 1rem 1.05rem;
    border: 1px solid rgba(31, 99, 146, 0.14);
    border-radius: 18px;
    background:
        linear-gradient(135deg, rgba(13, 103, 134, 0.055), rgba(255, 255, 255, 0.88)),
        rgba(248, 252, 255, 0.92);
    box-shadow: 0 14px 30px rgba(15, 76, 129, 0.07);
}

.modal-modern.add-client-modal .timeline-budget-card--wide {
    grid-column: 1 / -1;
}

.modal-modern.add-client-modal .timeline-budget-card-title {
    display: flex;
    align-items: center;
    gap: 0.45rem;
    margin-bottom: 0.85rem;
    color: #174769;
    font-size: 0.82rem;
    font-weight: 800;
}

.modal-modern.add-client-modal .timeline-budget-card-title i {
    color: #0d6786;
}

.modal-modern.add-client-modal .timeline-date-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 0.85rem;
}

.modal-modern.add-client-modal .timeline-budget-step textarea[name="sales_notes"] {
    min-height: 220px;
    overflow-y: hidden;
    resize: vertical;
    line-height: 1.45;
}

#addProjectForm textarea[name="additional_requirements"] {
    min-height: 118px;
    overflow-y: hidden;
    resize: vertical;
    line-height: 1.45;
}

.modal-modern.add-client-modal .timeline-budget-step .info-card {
    height: auto;
    margin-top: 0;
}

.modal-modern.add-client-modal .complexity-field-shell {
    position: relative;
}

.modal-modern.add-client-modal .complexity-insight-trigger {
    position: absolute;
    top: 0.55rem;
    right: 0.55rem;
    z-index: 4;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 25px;
    height: 25px;
    padding: 0;
    border: 1px solid rgba(13, 103, 134, 0.28);
    border-radius: 50%;
    background: #e9f6fb;
    color: #0d6786;
    box-shadow: 0 5px 12px rgba(13, 103, 134, 0.14);
    cursor: pointer;
    transition: background-color 0.18s ease, color 0.18s ease, transform 0.18s ease, box-shadow 0.18s ease;
}

.modal-modern.add-client-modal .complexity-insight-trigger i {
    font-size: 0.76rem;
}

.modal-modern.add-client-modal .complexity-insight-trigger:hover,
.modal-modern.add-client-modal .complexity-insight-trigger:focus-visible,
.modal-modern.add-client-modal .complexity-insight-trigger.is-open {
    background: #0d6786;
    color: #ffffff;
    box-shadow: 0 8px 18px rgba(13, 103, 134, 0.24);
    outline: none;
    transform: scale(1.08);
}

.modal-modern.add-client-modal .complexity-insight-popover {
    position: absolute;
    right: 0;
    bottom: calc(100% + 0.58rem);
    z-index: 68;
    display: grid;
    width: min(390px, 84vw);
    gap: 0.38rem;
    padding: 0.82rem 0.9rem;
    border: 1px solid rgba(31, 99, 146, 0.16);
    border-radius: 14px;
    background:
        linear-gradient(135deg, rgba(13, 103, 134, 0.10), rgba(255, 255, 255, 0.92)),
        #f8fcff;
    color: #1e4f78;
    font-size: 0.8rem;
    line-height: 1.45;
    box-shadow: 0 18px 38px rgba(15, 76, 129, 0.18);
}

.modal-modern.add-client-modal .complexity-insight-popover[hidden] {
    display: none;
}

.modal-modern.add-client-modal .complexity-insight-popover strong {
    color: #173f61;
    font-size: 0.86rem;
}

.modal-modern.add-client-modal .complexity-field-shell > .calculation-note {
    display: none;
}

.modal-modern.add-client-modal .template-type-picker {
    position: relative;
}

.modal-modern.add-client-modal .template-field-shell {
    position: relative;
}

.modal-modern.add-client-modal .template-recommendation-shell,
.modal-modern.add-client-modal .add-project-priority-insight-shell {
    position: relative;
}

.modal-modern.add-client-modal .template-recommendation-insight-trigger,
.modal-modern.add-client-modal .add-project-priority-insight-trigger {
    position: absolute;
    top: 1.78rem;
    right: 0.55rem;
    z-index: 71;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 25px;
    height: 25px;
    padding: 0;
    border: 1px solid rgba(13, 103, 134, 0.28);
    border-radius: 50%;
    background: #e9f6fb;
    color: #0d6786;
    box-shadow: 0 5px 12px rgba(13, 103, 134, 0.14);
    cursor: pointer;
    transition: background-color 0.18s ease, color 0.18s ease, transform 0.18s ease, box-shadow 0.18s ease;
}

.modal-modern.add-client-modal .template-recommendation-insight-trigger i,
.modal-modern.add-client-modal .add-project-priority-insight-trigger i {
    font-size: 0.76rem;
}

.modal-modern.add-client-modal .template-recommendation-insight-trigger:hover,
.modal-modern.add-client-modal .template-recommendation-insight-trigger:focus-visible,
.modal-modern.add-client-modal .template-recommendation-insight-trigger.is-open,
.modal-modern.add-client-modal .add-project-priority-insight-trigger:hover,
.modal-modern.add-client-modal .add-project-priority-insight-trigger:focus-visible,
.modal-modern.add-client-modal .add-project-priority-insight-trigger.is-open {
    background: #0d6786;
    color: #ffffff;
    box-shadow: 0 8px 18px rgba(13, 103, 134, 0.24);
    outline: none;
    transform: scale(1.08);
}

.modal-modern.add-client-modal .template-recommendation-shell [data-template-picker-input] {
    padding-right: 2.7rem !important;
}

.modal-modern.add-client-modal .add-project-priority-insight-trigger {
    top: 1.78rem;
    right: 0.55rem;
}

.modal-modern.add-client-modal .add-project-priority-insight-shell {
    flex: 0 0 420px;
    width: 420px;
    max-width: 100%;
}

.modal-modern.add-client-modal .add-project-priority-insight-shell .form-select {
    padding-right: 2.7rem !important;
}

#addClientForm .add-client-priority-insight-shell {
    flex: 0 0 50%;
    width: 50%;
    max-width: 50%;
}

#addClientForm .add-client-attachment-shell {
    flex: 0 0 50%;
    width: 50%;
    max-width: 50%;
}

@media (max-width: 575.98px) {
    #addClientForm .add-client-priority-insight-shell,
    #addClientForm .add-client-attachment-shell {
        flex-basis: 100%;
        width: 100%;
        max-width: 100%;
    }
}

#addClientForm .priority-select-wrap .form-select {
    padding-right: 2.7rem !important;
}

#addClientForm .priority-insight-trigger {
    top: 0.45rem;
    right: 0.5rem;
    width: 25px;
    height: 25px;
    border-color: rgba(13, 103, 134, 0.28);
    background: #e9f6fb;
    color: #0d6786;
    box-shadow: 0 5px 12px rgba(13, 103, 134, 0.14);
    animation: none;
}

#addClientForm .priority-insight-trigger:hover,
#addClientForm .priority-insight-trigger:focus-visible,
#addClientForm .priority-insight-trigger.is-open {
    background: #0d6786;
    color: #ffffff;
    box-shadow: 0 8px 18px rgba(13, 103, 134, 0.24);
    transform: scale(1.08);
}

#addClientForm .priority-insight-popover {
    top: -0.68rem;
    bottom: auto;
    width: min(390px, calc(100vw - 3rem));
    transform: translateY(-100%);
}

.modal-modern.add-client-modal .template-recommendation-insight-popover,
.modal-modern.add-client-modal .add-project-priority-insight-popover {
    position: absolute;
    top: 1.58rem;
    bottom: auto;
    right: 0;
    transform: translateY(-100%);
    z-index: 69;
    display: grid;
    width: min(390px, calc(100vw - 3rem));
    gap: 0.38rem;
    padding: 0.82rem 0.9rem;
    border: 1px solid rgba(31, 99, 146, 0.16);
    border-radius: 14px;
    background:
        linear-gradient(135deg, rgba(13, 103, 134, 0.10), rgba(255, 255, 255, 0.92)),
        #f8fcff;
    color: #1e4f78;
    font-size: 0.8rem;
    line-height: 1.45;
    box-shadow: 0 18px 38px rgba(15, 76, 129, 0.18);
}

.modal-modern.add-client-modal .template-recommendation-insight-popover[hidden],
.modal-modern.add-client-modal .add-project-priority-insight-popover[hidden] {
    display: none;
}

.modal-modern.add-client-modal .template-recommendation-insight-popover strong,
.modal-modern.add-client-modal .add-project-priority-insight-popover strong {
    color: #173f61;
    font-size: 0.86rem;
}

.modal-modern.add-client-modal .template-type-picker .form-control {
    padding-right: 2.5rem;
}

.modal-modern.add-client-modal .template-field-shell .template-type-picker .form-control {
    padding-right: 2.5rem;
}

.modal-modern.add-client-modal .template-type-picker::after {
    content: "\f078";
    position: absolute;
    top: 50%;
    right: 0.95rem;
    transform: translateY(-50%);
    color: #0d6786;
    font-family: "Font Awesome 5 Free";
    font-size: 0.72rem;
    font-weight: 900;
    pointer-events: none;
}

.modal-modern.add-client-modal .priority-field-shell {
    position: relative;
}

.modal-modern.add-client-modal .priority-select-wrap {
    position: relative;
}

.modal-modern.add-client-modal .priority-select-wrap .form-select {
    padding-right: 2.25rem;
}

.modal-modern.add-client-modal .priority-insight-trigger {
    position: absolute;
    top: -0.48rem;
    right: -0.36rem;
    z-index: 4;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 28px;
    height: 28px;
    border: 1px solid rgba(245, 158, 11, 0.35);
    border-radius: 50%;
    background: #fff7ed;
    color: #d97706;
    box-shadow: 0 8px 18px rgba(217, 119, 6, 0.18);
    transform-origin: 50% 88%;
    animation: priorityAttentionSway 1.6s ease-in-out infinite;
    transition: background 0.18s ease, color 0.18s ease, transform 0.18s ease, box-shadow 0.18s ease;
}

.modal-modern.add-client-modal .priority-insight-trigger i {
    font-size: 0.82rem;
}

@keyframes priorityAttentionSway {
    0%, 72%, 100% {
        transform: rotate(0deg);
    }

    78% {
        transform: rotate(-10deg) translateX(-1px);
    }

    84% {
        transform: rotate(10deg) translateX(1px);
    }

    90% {
        transform: rotate(-7deg) translateX(-1px);
    }

    96% {
        transform: rotate(5deg) translateX(1px);
    }
}

.modal-modern.add-client-modal .priority-insight-trigger:hover,
.modal-modern.add-client-modal .priority-insight-trigger:focus-visible,
.modal-modern.add-client-modal .priority-insight-trigger.is-open {
    background: #d97706;
    color: #ffffff;
    box-shadow: 0 10px 22px rgba(217, 119, 6, 0.26);
    outline: none;
    animation-play-state: paused;
    transform: rotate(0deg) scale(1.06);
}

.modal-modern.add-client-modal .priority-insight-popover {
    position: absolute;
    right: 0;
    bottom: calc(100% + 0.58rem);
    z-index: 65;
    display: grid;
    width: min(360px, 92vw);
    gap: 0.5rem;
    padding: 0.82rem 0.9rem;
    border: 1px solid rgba(31, 99, 146, 0.16);
    border-radius: 14px;
    background:
        linear-gradient(135deg, rgba(13, 103, 134, 0.10), rgba(255, 255, 255, 0.92)),
        #f8fcff;
    box-shadow: 0 18px 38px rgba(15, 76, 129, 0.18);
}

.modal-modern.add-client-modal .priority-insight-popover[hidden] {
    display: none;
}

.modal-modern.add-client-modal .priority-insight-popover strong {
    color: #173f61;
    font-size: 0.85rem;
}

.modal-modern.add-client-modal .priority-insight-popover span {
    color: #41637e;
    font-size: 0.76rem;
    line-height: 1.45;
}

.modal-modern.add-client-modal .priority-insight-popover .priority-impact-amount {
    display: inline-flex;
    width: fit-content;
    padding: 0.32rem 0.5rem;
    border: 1px solid rgba(13, 103, 134, 0.14);
    border-radius: 999px;
    background: rgba(255, 255, 255, 0.72);
    color: #0d6786;
    font-size: 0.72rem;
    font-weight: 800;
}

.modal-modern.add-client-modal .project-intake-detail-grid textarea[name="additional_requirements"] {
    min-height: 118px;
    overflow-y: hidden;
    resize: vertical;
    line-height: 1.45;
}

.modal-modern.add-client-modal .attachment-upload-field {
    display: grid;
    grid-template-columns: auto minmax(0, 1fr);
    align-items: stretch;
    min-height: 42px;
    border: 1px dashed rgba(31, 99, 146, 0.2);
    border-radius: 12px;
    background:
        linear-gradient(135deg, rgba(13, 103, 134, 0.055), rgba(255, 255, 255, 0.94)),
        #ffffff;
    overflow: hidden;
    transition: border-color 0.18s ease, box-shadow 0.18s ease, background 0.18s ease;
}

.modal-modern.add-client-modal .attachment-upload-field:focus-within,
.modal-modern.add-client-modal .attachment-upload-field:hover {
    border-color: rgba(13, 103, 134, 0.42);
    box-shadow: 0 10px 22px rgba(13, 103, 134, 0.12);
}

.modal-modern.add-client-modal .attachment-upload-button {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 0.45rem;
    min-height: 40px;
    margin: 0;
    padding: 0 0.75rem;
    border-right: 1px solid rgba(31, 99, 146, 0.12);
    background: rgba(226, 244, 250, 0.92);
    color: #0d6786;
    font-size: 0.86rem;
    font-weight: 800;
    cursor: pointer;
    white-space: nowrap;
    transition: background 0.18s ease, color 0.18s ease;
}

.modal-modern.add-client-modal .attachment-upload-button:hover {
    background: #0d6786;
    color: #ffffff;
}

.modal-modern.add-client-modal .attachment-upload-summary {
    align-self: center;
    min-width: 0;
    padding: 0 0.75rem;
    color: #17324d;
    font-size: 0.88rem;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.modal-modern.add-client-modal .template-suggestions-menu {
    position: absolute;
    top: calc(100% + 0.45rem);
    left: 0;
    right: 0;
    z-index: 70;
    max-height: 260px;
    overflow-y: auto;
    padding: 0.4rem;
    border: 1px solid rgba(31, 99, 146, 0.18);
    border-radius: 14px;
    background: #ffffff;
    box-shadow: 0 18px 38px rgba(15, 76, 129, 0.16);
}

.modal-modern.add-client-modal .template-suggestions-menu[hidden] {
    display: none;
}

.modal-modern.add-client-modal .template-suggestion-option {
    width: 100%;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.75rem;
    padding: 0.72rem 0.78rem;
    border: 0;
    border-radius: 10px;
    background: transparent;
    color: #17324d;
    font-size: 0.86rem;
    font-weight: 600;
    text-align: left;
}

.modal-modern.add-client-modal .template-suggestion-option:hover,
.modal-modern.add-client-modal .template-suggestion-option:focus-visible {
    background: #edf7fd;
    color: #0d6786;
    outline: none;
}

.modal-modern.add-client-modal .template-suggestion-option i {
    color: #0d6786;
    font-size: 0.78rem;
    opacity: 0.78;
}

.modal-modern.add-client-modal .template-suggestion-empty {
    padding: 0.72rem 0.78rem;
    color: var(--gray-500);
    font-size: 0.8rem;
}

.modal-modern.add-client-modal .dss-panel-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 0.85rem;
}

.modal-modern.add-client-modal .dss-panel-title {
    display: grid;
    gap: 0.25rem;
    min-width: 0;
}

.modal-modern.add-client-modal .dss-kicker {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    width: fit-content;
    color: #0d6786;
    font-size: 0.68rem;
    font-weight: 800;
    letter-spacing: 0.04em;
    text-transform: uppercase;
}

.modal-modern.add-client-modal .dss-panel-title strong {
    color: #143b5b;
    font-size: 0.94rem;
    line-height: 1.25;
}

.modal-modern.add-client-modal .dss-panel-title span {
    color: #55728c;
    font-size: 0.76rem;
    line-height: 1.35;
}

.modal-modern.add-client-modal .dss-score-pill {
    flex: 0 0 auto;
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    padding: 0.43rem 0.62rem;
    border: 1px solid rgba(13, 103, 134, 0.16);
    border-radius: 999px;
    background: rgba(255, 255, 255, 0.78);
    color: #0d6786;
    font-size: 0.72rem;
    font-weight: 800;
    white-space: nowrap;
}

.modal-modern.add-client-modal .dss-metric-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 0.55rem;
}

.modal-modern.add-client-modal .dss-metric {
    display: grid;
    gap: 0.15rem;
    min-width: 0;
    padding: 0.62rem 0.68rem;
    border: 1px solid rgba(31, 99, 146, 0.12);
    border-radius: 12px;
    background: rgba(255, 255, 255, 0.72);
}

.modal-modern.add-client-modal .dss-metric span {
    color: #6b7f90;
    font-size: 0.66rem;
    font-weight: 800;
    letter-spacing: 0.035em;
    text-transform: uppercase;
}

.modal-modern.add-client-modal .dss-metric strong {
    overflow: hidden;
    color: #164d78;
    font-size: 0.84rem;
    font-weight: 800;
    line-height: 1.25;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.modal-modern.add-client-modal .dss-section {
    display: grid;
    gap: 0.4rem;
}

.modal-modern.add-client-modal .dss-section-title {
    display: flex;
    align-items: center;
    gap: 0.4rem;
    color: #315a78;
    font-size: 0.72rem;
    font-weight: 800;
}

.modal-modern.add-client-modal .dss-section-title i {
    color: #0d6786;
}

.modal-modern.add-client-modal .dss-chip-row {
    display: flex;
    flex-wrap: wrap;
    gap: 0.38rem;
}

.modal-modern.add-client-modal .dss-chip {
    display: inline-flex;
    align-items: center;
    max-width: 100%;
    padding: 0.38rem 0.52rem;
    border: 1px solid rgba(31, 99, 146, 0.12);
    border-radius: 999px;
    background: rgba(255, 255, 255, 0.76);
    color: #274d6b;
    font-size: 0.72rem;
    font-weight: 700;
    line-height: 1;
}

.modal-modern.add-client-modal .dss-chip.is-more {
    color: #0d6786;
    background: rgba(13, 103, 134, 0.09);
}

.modal-modern.add-client-modal .dss-muted {
    color: #667d90;
    font-size: 0.76rem;
    line-height: 1.4;
}

.modal-modern.add-client-modal .dss-template-review-overlay {
    position: absolute;
    inset: 0;
    z-index: 90;
    display: grid;
    place-items: center;
    padding: 1.25rem;
    background: rgba(8, 37, 57, 0.34);
    backdrop-filter: blur(5px);
    animation: dssAssistantFadeIn 180ms ease-out both;
}

.modal-modern.add-client-modal .dss-template-review-overlay[hidden] {
    display: none;
}

.modal-modern.add-client-modal .dss-template-review-overlay.is-applying {
    cursor: progress;
}

.modal-modern.add-client-modal .dss-template-review-dialog {
    width: min(860px, 100%);
    max-height: min(78vh, 720px);
    overflow: hidden;
    border: 1px solid rgba(182, 218, 239, 0.8);
    border-radius: 20px;
    background: #ffffff;
    box-shadow: 0 28px 70px rgba(5, 38, 60, 0.28);
    animation: dssAssistantDialogIn 220ms cubic-bezier(.2,.8,.2,1) both;
}

.modal-modern.add-client-modal .dss-template-review-overlay.is-leaving {
    animation: dssAssistantFadeOut 160ms ease-in both;
}

.modal-modern.add-client-modal .dss-template-review-overlay.is-leaving .dss-template-review-dialog {
    animation: dssAssistantDialogOut 160ms ease-in both;
}

.chat-bot-speech.evortix-decision-warning {
    width: min(390px, calc(100vw - 190px));
    min-width: 320px;
    padding: 1rem 1.05rem;
    pointer-events: auto;
}

.chat-bot-speech .evortix-decision-actions {
    position: relative;
    z-index: 2;
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 0.55rem;
    margin-top: 0.85rem;
}

.chat-bot-speech .evortix-decision-actions .btn {
    min-height: 40px;
    border-radius: 11px;
    font-size: 0.76rem;
    font-weight: 800;
}

@media (max-width: 600px) {
    .chat-bot-speech.evortix-decision-warning {
        width: min(285px, calc(100vw - 135px));
        min-width: 235px;
    }
    .chat-bot-speech .evortix-decision-actions { grid-template-columns: 1fr; }
}

@keyframes dssAssistantFadeIn { from { opacity: 0; } to { opacity: 1; } }
@keyframes dssAssistantFadeOut { from { opacity: 1; } to { opacity: 0; } }
@keyframes dssAssistantDialogIn { from { opacity: 0; transform: translateY(14px) scale(.97); } to { opacity: 1; transform: translateY(0) scale(1); } }
@keyframes dssAssistantDialogOut { from { opacity: 1; transform: translateY(0) scale(1); } to { opacity: 0; transform: translateY(8px) scale(.98); } }

.modal-modern.add-client-modal .dss-review-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 1rem;
    padding: 1rem 1.1rem;
    background: linear-gradient(135deg, #0d6786 0%, #0a5570 100%);
    color: #ffffff;
}

.modal-modern.add-client-modal .dss-review-title {
    display: grid;
    gap: 0.22rem;
    min-width: 0;
}

.modal-modern.add-client-modal .dss-review-title span {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    color: rgba(255, 255, 255, 0.78);
    font-size: 0.7rem;
    font-weight: 800;
    letter-spacing: 0.06em;
    text-transform: uppercase;
}

.modal-modern.add-client-modal .dss-review-title strong {
    color: #ffffff;
    font-size: 1.05rem;
    line-height: 1.25;
}

.modal-modern.add-client-modal .dss-review-close {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex: 0 0 auto;
    width: 36px;
    height: 36px;
    border: 1px solid rgba(255, 255, 255, 0.24);
    border-radius: 12px;
    background: rgba(255, 255, 255, 0.08);
    color: #ffffff;
}

.modal-modern.add-client-modal .dss-review-close:disabled,
.modal-modern.add-client-modal .dss-template-review-overlay.is-applying [data-cancel-template-apply] {
    cursor: progress;
    opacity: 0.62;
}

.modal-modern.add-client-modal .dss-review-body {
    display: grid;
    gap: 0.9rem;
    max-height: calc(min(78vh, 720px) - 142px);
    overflow-y: auto;
    padding: 1rem 1.1rem;
    background:
        linear-gradient(135deg, rgba(13, 103, 134, 0.08), rgba(255, 255, 255, 0.92)),
        #f8fcff;
}

.modal-modern.add-client-modal .dss-review-summary {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.75rem;
    padding: 0.8rem 0.9rem;
    border: 1px solid rgba(31, 99, 146, 0.14);
    border-radius: 14px;
    background: rgba(255, 255, 255, 0.78);
}

.modal-modern.add-client-modal .dss-review-summary span {
    color: #486983;
    font-size: 0.78rem;
    line-height: 1.4;
}

.modal-modern.add-client-modal .dss-review-summary strong {
    color: #143b5b;
}

.modal-modern.add-client-modal .dss-review-footer {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.75rem;
    padding: 0.9rem 1.1rem;
    border-top: 1px solid rgba(31, 99, 146, 0.12);
    background: #ffffff;
}

.modal-modern.add-client-modal .dss-review-footer span {
    color: #55728c;
    font-size: 0.74rem;
    line-height: 1.35;
}

.modal-modern.add-client-modal .dss-review-actions {
    display: inline-flex;
    flex: 0 0 auto;
    gap: 0.55rem;
}

.modal-modern.add-client-modal .dss-review-actions .btn {
    border-radius: 12px;
    font-weight: 800;
}

    .modal-modern.add-client-modal .mini-note {
        margin-top: 0.5rem;
        color: var(--gray-500);
        font-size: 0.78rem;
        line-height: 1.4;
    }

    .modal-modern.add-client-modal .calculation-note {
        display: grid;
        gap: 0.28rem;
        margin-top: 0.65rem;
        padding: 0.82rem 0.9rem 0.82rem 2.65rem;
        border: 1px solid rgba(31, 99, 146, 0.18);
        border-radius: 14px;
        background:
            linear-gradient(135deg, rgba(13, 103, 134, 0.10), rgba(255, 255, 255, 0.82)),
            #f4f9fc;
        color: #1e4f78;
        font-size: 0.78rem;
        line-height: 1.45;
        position: relative;
        box-shadow: 0 10px 20px rgba(15, 76, 129, 0.06);
    }

    .modal-modern.add-client-modal .calculation-note::before {
        content: "\f0eb";
        position: absolute;
        left: 0.85rem;
        top: 0.9rem;
        width: 28px;
        height: 28px;
        border-radius: 10px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        background: linear-gradient(135deg, #0d6786 0%, #0a5570 100%);
        color: #ffffff;
        font-family: "Font Awesome 5 Free";
        font-size: 0.78rem;
        font-weight: 900;
        box-shadow: 0 8px 18px rgba(13, 103, 134, 0.16);
    }

    .modal-modern.add-client-modal .calculation-note[data-template-recommendation]::before {
        content: "\f542";
    }

    .modal-modern.add-client-modal [data-complexity-reason]::before {
        content: "\f5fd";
    }

    .modal-modern.add-client-modal [data-priority-price-preview]::before {
        content: "\f201";
    }

    .modal-modern.add-client-modal .calculation-note strong {
        color: #173f61;
        font-size: 0.86rem;
    }

    .modal-modern.add-client-modal .template-apply-status {
        display: flex;
        align-items: center;
        gap: 0.35rem;
        color: #047857;
        font-weight: 700;
    }

.modal-modern.add-client-modal .required-mark {
    color: #dc2626;
    margin-left: .18rem;
}

.modal-modern.add-client-modal .optional-label {
    color: var(--gray-500);
    font-size: .72rem;
    font-weight: 500;
}

.modal-modern.add-client-modal .architect-handoff-note {
    align-items: flex-start;
    display: flex;
    gap: .65rem;
    margin-bottom: .85rem;
    padding: .7rem .8rem;
    border-radius: 10px;
    background: #eff6ff;
    color: #1e4f78;
    font-size: .78rem;
    line-height: 1.45;
}

:is(#addClientModal, #addProjectModal).modal-modern.add-client-modal .architect-handoff-note {
    margin-bottom: 0;
    border: 1px solid rgba(31, 99, 146, 0.14);
    border-radius: 16px;
    background:
        linear-gradient(135deg, rgba(13, 103, 134, 0.10), rgba(255, 255, 255, 0.86)),
        #f6fbfe;
    box-shadow: 0 10px 24px rgba(15, 76, 129, 0.06);
}

.modal-modern.add-client-modal .architect-handoff-note i {
    flex: 0 0 auto;
    margin-top: .15rem;
    color: var(--theme-primary);
}

.modal-modern.add-client-modal .architect-handoff-note[hidden] {
    display: none;
}

.modal-modern.add-client-modal .info-card {
    display: grid;
    gap: 0.25rem;
    padding: 0.8rem 0.9rem;
    border-radius: 10px;
    background: #f8fafc;
    border: 1px solid var(--gray-100);
    margin-top: 1rem;
}

.modal-modern.add-client-modal .info-card span {
    color: var(--gray-500);
    font-size: 0.74rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.02em;
}

.modal-modern.add-client-modal .info-card strong {
    color: var(--gray-900);
    font-size: 0.92rem;
    font-weight: 700;
}

.modal-modern.add-client-modal .field-group.review-step {
    padding: 0.1rem 0.35rem 0.1rem 0.1rem;
    border: 0;
    background: transparent;
    overflow: visible;
}

.modal-modern.add-client-modal .review-summary {
    display: grid;
    gap: 0.7rem;
}

.modal-modern.add-client-modal .review-ready-banner {
    display: flex;
    align-items: center;
    gap: 0.7rem;
    padding: 0.72rem 0.85rem;
    border: 1px solid rgba(31, 99, 146, 0.2);
    border-radius: 14px;
    background: linear-gradient(135deg, #edf7fd 0%, #f8fbfe 100%);
    box-shadow: 0 6px 18px rgba(31, 99, 146, 0.06);
}

.modal-modern.add-client-modal .review-ready-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex: 0 0 auto;
    width: 34px;
    height: 34px;
    border-radius: 11px;
    background: var(--theme-primary);
    color: #ffffff;
    box-shadow: 0 8px 18px rgba(31, 99, 146, 0.2);
}

.modal-modern.add-client-modal .review-ready-copy {
    display: grid;
    gap: 0.12rem;
    min-width: 0;
}

.modal-modern.add-client-modal .review-ready-copy strong {
    color: var(--gray-900);
    font-size: 0.9rem;
}

.modal-modern.add-client-modal .review-ready-copy span {
    color: var(--gray-600);
    font-size: 0.76rem;
    line-height: 1.4;
}

.modal-modern.add-client-modal .review-ready-badge {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    flex: 0 0 auto;
    margin-left: auto;
    padding: 0.4rem 0.65rem;
    border-radius: 999px;
    background: #d1fae5;
    color: #047857;
    font-size: 0.7rem;
    font-weight: 700;
    white-space: nowrap;
}

.modal-modern.add-client-modal .review-primary-grid {
    display: grid;
    grid-template-columns: minmax(0, 0.9fr) minmax(0, 1.1fr);
    gap: 0.75rem;
}

.modal-modern.add-client-modal .review-card {
    min-width: 0;
    padding: 0.78rem;
    border: 1px solid #dfe7ef;
    border-radius: 12px;
    background: #ffffff;
    box-shadow: 0 8px 22px rgba(15, 42, 64, 0.055);
}

.modal-modern.add-client-modal .review-card-header {
    display: flex;
    align-items: center;
    gap: 0.55rem;
    margin-bottom: 0.55rem;
}

.modal-modern.add-client-modal .review-card-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex: 0 0 auto;
    width: 28px;
    height: 28px;
    border-radius: 8px;
    background: rgba(31, 99, 146, 0.1);
    color: var(--theme-primary);
    font-size: 0.78rem;
}

.modal-modern.add-client-modal .review-card-heading {
    display: grid;
    gap: 0.05rem;
    min-width: 0;
}

.modal-modern.add-client-modal .review-card-heading h6 {
    margin: 0;
    color: var(--gray-900);
    font-size: 0.84rem;
    font-weight: 750;
}

.modal-modern.add-client-modal .review-card-heading span {
    color: var(--gray-500);
    font-size: 0.68rem;
}

.modal-modern.add-client-modal .review-highlight {
    display: grid;
    gap: 0.15rem;
    margin-bottom: 0.55rem;
    padding: 0.55rem 0.65rem;
    border-radius: 9px;
    background: #f4f8fb;
}

.modal-modern.add-client-modal .review-highlight span,
.modal-modern.add-client-modal .review-data-label,
.modal-modern.add-client-modal .review-key-label,
.modal-modern.add-client-modal .review-block-label,
.modal-modern.add-client-modal .review-note-label {
    color: var(--gray-500);
    font-size: 0.64rem;
    font-weight: 750;
    letter-spacing: 0.045em;
    text-transform: uppercase;
}

.modal-modern.add-client-modal .review-highlight strong {
    color: var(--gray-900);
    font-size: 0.92rem;
    line-height: 1.3;
    overflow-wrap: anywhere;
}

.modal-modern.add-client-modal .review-data-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 0.46rem 0.65rem;
}

.modal-modern.add-client-modal .review-data-item {
    display: grid;
    gap: 0.14rem;
    min-width: 0;
}

.modal-modern.add-client-modal .review-data-item--wide {
    grid-column: 1 / -1;
}

.modal-modern.add-client-modal .review-card--client-profile .review-data-item--wide {
    grid-column: auto;
}

.modal-modern.add-client-modal .review-card--client-profile .review-data-item:last-child {
    grid-column: 1 / -1;
}

.modal-modern.add-client-modal .review-summary-value {
    color: var(--gray-900);
    font-size: 0.78rem;
    line-height: 1.32;
    overflow-wrap: anywhere;
    white-space: pre-wrap;
}

.modal-modern.add-client-modal .review-summary-value.is-empty {
    color: #94a3b8;
    font-style: italic;
}

.modal-modern.add-client-modal .review-chip-row {
    display: flex;
    flex-wrap: wrap;
    gap: 0.38rem;
    margin-bottom: 0.58rem;
}

.modal-modern.add-client-modal .review-chip {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    padding: 0.35rem 0.55rem;
    border: 1px solid rgba(31, 99, 146, 0.15);
    border-radius: 999px;
    background: #eef6fb;
    color: var(--theme-primary-dark);
    font-size: 0.68rem;
    font-weight: 700;
    text-transform: capitalize;
}

.modal-modern.add-client-modal .review-chip[data-value="high"],
.modal-modern.add-client-modal .review-chip[data-value="critical"] {
    border-color: rgba(220, 38, 38, 0.16);
    background: #fef2f2;
    color: #b91c1c;
}

.modal-modern.add-client-modal .review-key-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 0.48rem;
}

.modal-modern.add-client-modal .review-key-item {
    display: grid;
    gap: 0.15rem;
    min-width: 0;
    padding: 0.5rem 0.58rem;
    border: 1px solid #e8eef4;
    border-radius: 9px;
    background: #fbfcfd;
}

.modal-modern.add-client-modal .review-key-item--wide {
    grid-column: 1 / -1;
}

.modal-modern.add-client-modal .review-key-value {
    color: var(--gray-900);
    font-size: 0.76rem;
    font-weight: 650;
    line-height: 1.35;
    overflow-wrap: anywhere;
    white-space: pre-wrap;
}

.modal-modern.add-client-modal .review-requirements-grid {
    display: grid;
    grid-template-columns: minmax(0, 0.85fr) minmax(0, 1.15fr);
    gap: 0.65rem;
}

.modal-modern.add-project-modal .add-project-review-grid {
    grid-template-columns: minmax(0, 1fr);
}

.modal-modern.add-project-modal .add-project-review-requirements {
    grid-template-columns: repeat(2, minmax(0, 1fr));
}

.modal-modern.add-client-modal .review-block {
    display: grid;
    align-content: start;
    gap: 0.3rem;
    min-width: 0;
    padding: 0.7rem;
    border: 1px solid #e8eef4;
    border-radius: 10px;
    background: #fbfcfd;
}

.modal-modern.add-client-modal .review-block--wide {
    grid-column: 1 / -1;
}

.modal-modern.add-client-modal .review-feature-list {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 0.35rem;
    margin: 0;
    padding: 0;
    list-style: none;
}

.modal-modern.add-client-modal .review-feature-list li {
    position: relative;
    min-width: 0;
    padding: 0.42rem 0.5rem 0.42rem 1.45rem;
    border-radius: 8px;
    background: #f0f7fb;
    color: #24445d;
    font-size: 0.74rem;
    line-height: 1.35;
    overflow-wrap: anywhere;
}

.modal-modern.add-client-modal .review-feature-list li::before {
    content: '';
    position: absolute;
    top: 0.72rem;
    left: 0.62rem;
    width: 5px;
    height: 5px;
    border-radius: 50%;
    background: var(--theme-primary);
}

.modal-modern.add-client-modal .review-feature-list li.is-empty {
    grid-column: 1 / -1;
    color: #94a3b8;
    font-style: italic;
}

.modal-modern.add-client-modal .review-notes-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 0.55rem;
}

.modal-modern.add-client-modal .review-note-item {
    display: grid;
    align-content: start;
    gap: 0.28rem;
    min-width: 0;
    min-height: 72px;
    padding: 0.65rem;
    border: 1px solid #e8eef4;
    border-radius: 9px;
    background: #fbfcfd;
}

.modal-modern.add-client-modal .review-handoff {
    display: flex;
    align-items: center;
    gap: 0.65rem;
    padding: 0.75rem 0.85rem;
    border-radius: 12px;
    background: var(--theme-primary-dark);
    color: #ffffff;
}

.modal-modern.add-client-modal .review-handoff i {
    flex: 0 0 auto;
    font-size: 0.88rem;
}

.modal-modern.add-client-modal .review-handoff-copy {
    display: grid;
    gap: 0.08rem;
}

.modal-modern.add-client-modal .review-handoff-copy strong {
    font-size: 0.78rem;
}

.modal-modern.add-client-modal .review-handoff-copy span {
    color: rgba(255, 255, 255, 0.78);
    font-size: 0.68rem;
    line-height: 1.35;
}

.modal-modern.add-client-modal .form-label {
    font-weight: 600;
    color: var(--gray-700);
}

.modal-modern.add-client-modal .form-control,
.modal-modern.add-client-modal .form-select,
.modal-modern.add-client-modal textarea {
    border-radius: 10px;
    background: #ffffff;
}

:is(#addClientForm, #addProjectForm) .add-project-intake-columns {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    align-items: start;
    gap: 1rem;
}

:is(#addClientForm, #addProjectForm) .add-project-intake-column {
    display: grid;
    min-width: 0;
    align-content: start;
    gap: 1rem;
}

@media (max-width: 767.98px) {
    :is(#addClientForm, #addProjectForm) .add-project-intake-columns {
        grid-template-columns: minmax(0, 1fr);
    }
}

.modal-modern.add-client-modal .requested-feature-builder {
    display: grid;
    gap: 0.8rem;
}

.modal-modern.add-client-modal .requested-feature-list {
    display: grid;
    gap: 0.58rem;
}

.modal-modern.add-client-modal .requested-feature-item {
    display: grid;
    grid-template-columns: minmax(0, 1fr) 40px;
    gap: 0.55rem;
    align-items: center;
    padding: 0.18rem;
    border: 1px solid rgba(31, 99, 146, 0.10);
    border-radius: 14px;
    background: rgba(255, 255, 255, 0.74);
    transition: border-color 0.18s ease, box-shadow 0.18s ease, transform 0.18s ease;
}

.modal-modern.add-client-modal .requested-feature-item:focus-within {
    border-color: rgba(13, 103, 134, 0.28);
    box-shadow: 0 8px 20px rgba(15, 76, 129, 0.08);
    transform: translateY(-1px);
}

.modal-modern.add-client-modal .requested-feature-item .form-control {
    border-color: transparent;
    background: transparent;
    box-shadow: none;
}

.modal-modern.add-client-modal .requested-feature-item .btn {
    width: 40px;
    height: 40px;
    padding: 0;
    border-radius: 12px;
    background: #fff1f2;
    color: #e11d48;
}

.modal-modern.add-client-modal .requested-feature-item .btn:hover,
.modal-modern.add-client-modal .requested-feature-item .btn:focus-visible {
    background: #e11d48;
    color: #ffffff;
}

.modal-modern.add-client-modal .requested-feature-actions {
    display: flex;
    justify-content: flex-start;
}

:is(#addClientForm, #addProjectForm) .requested-feature-builder {
    gap: 0.75rem;
    margin-top: 0.2rem;
}

:is(#addClientForm, #addProjectForm) .requested-feature-chip-shell {
    position: relative;
    height: 362px;
    min-height: 362px;
    z-index: 12;
}

:is(#addClientForm, #addProjectForm) .requested-feature-chip-shell.is-expanded {
    z-index: 78;
}

:is(#addClientForm, #addProjectForm) .requested-feature-chip-editor {
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    z-index: 1;
    display: flex;
    flex-wrap: wrap;
    align-content: flex-start;
    align-items: center;
    gap: 0.6rem;
    height: 362px;
    min-height: 362px;
    max-height: 362px;
    overflow-x: hidden;
    overflow-y: auto;
    padding: 0.75rem;
    border: 1px solid #b8d2e4;
    border-radius: 12px;
    background: #ffffff;
    box-shadow: inset 0 1px 2px rgba(15, 76, 129, 0.04);
    scrollbar-width: thin;
    scrollbar-color: rgba(13, 103, 134, 0.38) transparent;
    transition: border-color 0.18s ease, box-shadow 0.18s ease;
}

:is(#addClientForm, #addProjectForm) .requested-feature-chip-shell.is-expanded .requested-feature-chip-editor {
    height: 362px;
    max-height: 362px;
    overflow-y: auto;
}

:is(#addClientForm, #addProjectForm) .requested-feature-chip-editor:focus-within {
    border-color: #0d6786;
    box-shadow: 0 0 0 0.2rem rgba(13, 103, 134, 0.12);
}

:is(#addClientForm, #addProjectForm) .requested-feature-chip {
    display: inline-flex;
    align-items: center;
    max-width: 100%;
    min-height: 36px;
    gap: 0.38rem;
    padding: 0.34rem 0.42rem 0.34rem 0.68rem;
    border: 1px solid rgba(13, 103, 134, 0.32);
    border-radius: 10px;
    background: #e5f2f8;
    color: #173f61;
    font-size: 0.86rem;
    line-height: 1.2;
    transition: background-color 0.18s ease, border-color 0.18s ease, transform 0.18s ease;
}

:is(#addClientForm, #addProjectForm) .requested-feature-chip > span {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

:is(#addClientForm, #addProjectForm) .requested-feature-chip.is-duplicate {
    border-color: #d97706;
    background: #fff7ed;
    transform: scale(1.03);
}

:is(#addClientForm, #addProjectForm) .requested-feature-chip-remove {
    flex: 0 0 auto;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 23px;
    height: 23px;
    padding: 0;
    border: 0;
    border-radius: 50%;
    background: transparent;
    color: #54758d;
    font-size: 0.72rem;
}

:is(#addClientForm, #addProjectForm) .requested-feature-chip-remove:hover,
:is(#addClientForm, #addProjectForm) .requested-feature-chip-remove:focus-visible {
    background: rgba(13, 103, 134, 0.12);
    color: #0d6786;
    outline: none;
}

:is(#addClientForm, #addProjectForm) .requested-feature-chip-input {
    flex: 1 1 150px;
    min-width: 120px;
    min-height: 34px;
    padding: 0.35rem 0.42rem;
    border: 0;
    background: transparent;
    color: #17324d;
    font: inherit;
    outline: none;
}

:is(#addClientForm, #addProjectForm) .requested-feature-chip-input::placeholder {
    color: #7890a3;
}

:is(#addClientForm, #addProjectForm) .requested-feature-chip-toggle {
    position: absolute;
    top: 0.58rem;
    right: 3.05rem;
    z-index: 4;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 76px;
    height: 34px;
    gap: 0.36rem;
    padding: 0 0.55rem;
    border: 1px solid rgba(13, 103, 134, 0.18);
    border-radius: 10px;
    background: rgba(244, 249, 252, 0.96);
    color: #0d6786;
    font-size: 0.76rem;
    font-weight: 700;
    transition: background-color 0.18s ease, color 0.18s ease;
}

:is(#addClientForm, #addProjectForm) .requested-feature-chip-toggle[hidden] {
    display: none;
}

:is(#addClientForm, #addProjectForm) .requested-feature-chip-toggle:hover,
:is(#addClientForm, #addProjectForm) .requested-feature-chip-toggle:focus-visible,
:is(#addClientForm, #addProjectForm) .requested-feature-chip-toggle[aria-expanded="true"] {
    background: #e2f2f9;
    color: #084f6a;
    outline: none;
}

:is(#addClientForm, #addProjectForm) .requested-feature-chip-toggle i {
    font-size: 0.68rem;
    transition: transform 0.18s ease;
}

:is(#addClientForm, #addProjectForm) .requested-feature-chip-toggle[aria-expanded="true"] i {
    transform: rotate(180deg);
}

@media (max-width: 575.98px) {
    :is(#addClientForm, #addProjectForm) .requested-feature-chip-shell,
    :is(#addClientForm, #addProjectForm) .requested-feature-chip-editor {
        height: 220px;
        min-height: 220px;
        max-height: 220px;
    }
}

.modal-modern.add-client-modal .add-client-footer {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.75rem;
}

.modal-modern.add-client-modal .draft-status {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    color: #1f638f;
    font-size: 0.78rem;
    font-weight: 600;
    line-height: 1;
    white-space: nowrap;
    padding: 0.48rem 0.7rem;
    border: 1px solid rgba(31, 99, 146, 0.16);
    border-radius: 999px;
    background: rgba(255, 255, 255, 0.84);
    box-shadow: 0 8px 18px rgba(31, 99, 146, 0.07);
}

.modal-modern.add-client-modal .draft-status::before {
    content: "\f0c7";
    font-family: "Font Awesome 5 Free";
    font-weight: 900;
    color: #0d6786;
    font-size: 0.78rem;
}

.modal-modern.add-client-modal [data-save-draft]:disabled {
    opacity: 0.45;
    cursor: not-allowed;
}

.modal-modern.add-client-modal .unsaved-close-panel {
    display: none;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    width: 100%;
    padding: 0.9rem 1rem;
    border: 1px solid rgba(217, 119, 6, 0.25);
    border-radius: 12px;
    background: #fffbeb;
}

.modal-modern.add-client-modal .unsaved-close-panel.is-visible {
    display: flex;
}

.modal-modern.add-client-modal .add-client-footer.has-close-panel .draft-status,
.modal-modern.add-client-modal .add-client-footer.has-close-panel .add-client-actions {
    display: none;
}

.modal-modern.add-client-modal .unsaved-close-panel__text {
    display: grid;
    gap: 0.15rem;
    color: #92400e;
    font-size: 0.84rem;
}

.modal-modern.add-client-modal .unsaved-close-panel__text strong {
    color: #78350f;
    font-size: 0.92rem;
}

.modal-modern.add-client-modal .unsaved-close-panel__actions {
    display: inline-flex;
    align-items: center;
    gap: 0.55rem;
    flex: 0 0 auto;
}

.client-draft-empty {
    padding: 2.25rem 1.5rem;
    border: 1px dashed var(--gray-300);
    border-radius: 12px;
    background: #ffffff;
    text-align: center;
    color: var(--gray-600);
}

.client-draft-empty i {
    display: block;
    margin-bottom: 0.75rem;
    color: var(--gray-300);
    font-size: 2rem;
}

.client-draft-empty strong {
    display: block;
    color: var(--gray-900);
    margin-bottom: 0.25rem;
}

.client-draft-banner.is-visible {
    display: flex;
}

.client-draft-banner__icon {
    width: 42px;
    height: 42px;
    border-radius: 12px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background: rgba(31, 99, 146, 0.12);
    color: var(--theme-primary);
    flex: 0 0 auto;
}

.client-draft-banner__text {
    display: grid;
    gap: 0.15rem;
    color: var(--gray-700);
    font-size: 0.86rem;
}

.client-draft-banner__text strong {
    color: var(--gray-900);
    font-size: 0.95rem;
}

.client-draft-banner__actions {
    display: inline-flex;
    align-items: center;
    gap: 0.65rem;
    flex: 0 0 auto;
}

.client-draft-banner__actions .btn-add-modern {
    background: linear-gradient(135deg, var(--theme-primary) 0%, var(--theme-primary-dark) 80%);
    border: 1px solid transparent;
    color: #ffffff !important;
    box-shadow: 0 8px 16px rgba(31, 99, 146, 0.14);
}

.client-draft-banner__actions .btn-add-modern:hover {
    background: var(--theme-primary-dark);
    color: #ffffff !important;
    border-color: transparent;
}

.client-draft-banner__actions .btn-add-modern i {
    color: #ffffff !important;
}

.client-draft-banner__body {
    display: flex;
    align-items: center;
    gap: 0.85rem;
    min-width: 0;
}

.client-draft-banner__text span {
    color: var(--gray-600);
}

.client-draft-summary {
    display: flex;
    flex-wrap: wrap;
    gap: 0.45rem;
    margin-top: 0.35rem;
}

.client-draft-summary span {
    display: inline-flex;
    align-items: center;
    padding: 0.25rem 0.5rem;
    border-radius: 999px;
    background: rgba(31, 99, 146, 0.08);
    color: var(--theme-primary);
    font-size: 0.75rem;
    font-weight: 600;
}

.modal-modern.add-client-modal .add-client-actions {
    display: inline-flex;
    align-items: center;
    justify-content: flex-end;
    gap: 0.8rem;
    flex-wrap: wrap;
}

:is(#addClientModal, #addProjectModal).modal-modern.add-client-modal .add-client-actions {
    padding-right: 2.25rem;
}

:is(#addClientModal, #addProjectModal).modal-modern.add-client-modal .add-client-actions > button {
    flex: 0 0 auto;
    min-width: 98px;
    margin: 0;
    white-space: nowrap;
}

:is(#addClientModal, #addProjectModal).modal-modern.add-client-modal .add-client-actions > :is([data-client-next], [data-add-project-next]) {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 88px;
    text-align: center;
    line-height: 1;
}

#addProjectModal.modal-modern.add-client-modal .add-client-footer {
    justify-content: flex-end;
}

#addProjectModal.modal-modern.add-client-modal .add-project-actions > [data-bs-dismiss="modal"] { order: 1; }
#addProjectModal.modal-modern.add-client-modal .add-project-actions > [data-generic-save-draft] { order: 2; }
#addProjectModal.modal-modern.add-client-modal .add-project-actions > [data-add-project-prev] { order: 3; }
#addProjectModal.modal-modern.add-client-modal .add-project-actions > :is([data-add-project-next], [data-add-project-submit]) { order: 4; }
#addProjectModal.modal-modern.add-client-modal .add-project-actions > [data-generic-draft-status] {
    order: 5;
    align-self: center;
    margin: 0 !important;
    white-space: nowrap;
}

@media (max-width: 991.98px) {
    :is(#addClientModal, #addProjectModal).modal-modern.add-client-modal,
    #page-content-wrapper.sidebar-collapsed :is(#addClientModal, #addProjectModal).modal-modern.add-client-modal,
    body:has(#page-content-wrapper.sidebar-collapsed :is(#addClientModal, #addProjectModal).show) .modal-backdrop,
    body:has(#addClientModal.show, #addProjectModal.show) .modal-backdrop {
        --add-client-modal-top-offset: var(--add-client-page-top, 68px);
        left: 0;
        width: 100vw;
    }

    :is(#addClientModal, #addProjectModal).modal-modern.add-client-modal .modal-body {
        overflow: visible;
    }

    :is(#addClientModal, #addProjectModal).modal-modern.add-client-modal .add-client-layout {
        grid-template-columns: 1fr;
        overflow: visible;
    }

    :is(#addClientModal, #addProjectModal).modal-modern.add-client-modal .field-group {
        overflow: visible;
    }

    .modal-modern.add-client-modal .project-intake-grid,
    .modal-modern.add-client-modal .project-intake-support-grid,
    .modal-modern.add-client-modal .project-intake-inline,
    .modal-modern.add-client-modal .timeline-budget-grid,
    .modal-modern.add-client-modal .timeline-date-grid {
        grid-template-columns: 1fr;
    }

    .modal-modern.add-client-modal .project-intake-step .requested-feature-list {
        max-height: none;
    }
}

@media (max-width: 768px) {
    :is(#addClientModal, #addProjectModal).modal-modern.add-client-modal .modal-header,
    :is(#addClientModal, #addProjectModal).modal-modern.add-client-modal .modal-body {
        padding-left: 1rem;
        padding-right: 1rem;
    }

    #addClientModal.modal-modern.add-client-modal .add-client-stepper {
        grid-template-columns: repeat(4, minmax(145px, 1fr));
        overflow-x: auto;
    }

    #addProjectModal.modal-modern.add-client-modal .add-client-stepper {
        grid-template-columns: repeat(3, minmax(145px, 1fr));
        overflow-x: auto;
    }

    .modal-modern.add-client-modal .review-primary-grid,
    .modal-modern.add-client-modal .review-requirements-grid,
    .modal-modern.add-client-modal .review-notes-grid {
        grid-template-columns: 1fr;
    }

    .modal-modern.add-client-modal .review-ready-banner {
        align-items: flex-start;
        flex-wrap: wrap;
    }

    .modal-modern.add-client-modal .review-ready-badge {
        margin-left: 46px;
    }

    .modal-modern.add-client-modal .review-feature-list {
        grid-template-columns: 1fr;
    }

    .modal-modern.add-client-modal .add-client-footer {
        flex-direction: column-reverse;
        align-items: stretch;
    }

    :is(#addClientModal, #addProjectModal).modal-modern.add-client-modal .modal-footer {
        margin: 0;
        padding: 0.65rem;
    }

    .modal-modern.add-client-modal .draft-status {
        justify-content: center;
    }

    .modal-modern.add-client-modal .dss-panel-header {
        align-items: stretch;
        flex-direction: column;
    }

    .modal-modern.add-client-modal .dss-score-pill {
        width: 100%;
        justify-content: center;
    }

    .modal-modern.add-client-modal .dss-template-review-overlay {
        padding: 0.8rem;
    }

    .modal-modern.add-client-modal .dss-template-review-dialog {
        max-height: calc(100dvh - 2rem);
    }

    .modal-modern.add-client-modal .dss-review-body {
        max-height: calc(100dvh - 12rem);
    }

    .modal-modern.add-client-modal .dss-review-summary,
    .modal-modern.add-client-modal .dss-review-footer {
        align-items: stretch;
        flex-direction: column;
    }

    .modal-modern.add-client-modal .dss-review-actions {
        display: grid;
        width: 100%;
        grid-template-columns: 1fr 1fr;
    }

    .modal-modern.add-client-modal .add-client-actions {
        display: grid;
        grid-template-columns: 1fr 1fr;
    }

    :is(#addClientModal, #addProjectModal).modal-modern.add-client-modal .add-client-actions {
        padding-right: 2.25rem;
    }

    #addClientModal.modal-modern.add-client-modal .add-client-footer [data-client-submit],
    #addProjectModal.modal-modern.add-client-modal .add-client-footer [data-add-project-submit],
    #addProjectModal.modal-modern.add-client-modal .add-client-footer [data-generic-draft-status] {
        grid-column: 1 / -1;
    }

    .modal-modern.add-client-modal .unsaved-close-panel {
        flex-direction: column;
        align-items: stretch;
    }

    .modal-modern.add-client-modal .unsaved-close-panel__actions {
        display: grid;
        grid-template-columns: 1fr;
    }

    .client-draft-banner {
        flex-direction: column;
        align-items: stretch;
    }

    .client-draft-banner__actions {
        display: grid;
        grid-template-columns: 1fr 1fr;
    }

}

.modal-modern .modal-footer {
    padding: 1rem 2rem 2rem;
    border-top: 1px solid var(--gray-200);
}

:is(#addClientModal, #addProjectModal).modal-modern.add-client-modal .modal-footer {
    flex: 0 0 auto;
    margin: 0;
    padding: 0.7rem 1.35rem 0.45rem;
    background: #ffffff;
    border: 0;
    border-radius: 0;
    box-shadow: none;
    backdrop-filter: none;
}

:is(#addClientModal, #addProjectModal).modal-modern.add-client-modal .add-client-footer .btn,
:is(#addClientModal, #addProjectModal).modal-modern.add-client-modal .add-client-footer .btn-add-modern {
    min-height: 2.45rem;
    padding: 0.52rem 1rem;
    border-radius: 14px;
    font-size: 0.9rem;
    font-weight: 700;
}

:is(#addClientModal, #addProjectModal).modal-modern.add-client-modal .add-client-footer .btn-add-modern {
    box-shadow: 0 10px 22px rgba(31, 99, 146, 0.18);
}

.modal-modern .form-label {
    font-weight: 500;
    color: var(--theme-primary);
    margin-bottom: 0.5rem;
}

.modal-modern .form-control,
.modal-modern .form-select,
.modal-modern textarea {
    border-radius: 12px;
    border: 1px solid var(--gray-300);
    padding: 0.6rem 1rem;
}

.modal-modern .form-control:focus,
.modal-modern .form-select:focus,
.modal-modern textarea:focus {
    border-color: var(--theme-primary);
    box-shadow: 0 0 0 3px rgba(31, 99, 146, 0.1);
}

.modal-modern .btn-add-modern {
    background: linear-gradient(135deg, var(--theme-primary) 0%, var(--theme-primary-dark) 100%);
    border: none;
    color: white !important;
}

.modal-modern .btn-add-modern:hover {
    background: var(--theme-primary-dark);
    color: white !important;
    transform: translateY(-1px);
}

.modal-modern .btn-add-modern i {
    color: white !important;
}

.delete-confirm-note {
    display: flex;
    align-items: flex-start;
    gap: 0.75rem;
    padding: 1rem 1rem;
    border-radius: 16px;
    background: rgba(220, 38, 38, 0.06);
    border: 1px solid rgba(220, 38, 38, 0.12);
    color: var(--gray-900);
}

.delete-confirm-note i {
    color: #dc2626;
    margin-top: 0.15rem;
    flex-shrink: 0;
}

.btn-delete-cancel {
    background: #f8fafc;
    border: 1px solid var(--gray-200);
    color: var(--gray-900);
    border-radius: 12px;
    padding: 0.65rem 1.15rem;
    font-weight: 600;
}

.btn-delete-cancel:hover {
    background: #eef2f7;
    color: var(--gray-900);
}

.btn-delete-confirm {
    background: linear-gradient(135deg, #dc2626 0%, #b91c1c 100%);
    border: 1px solid #dc2626;
    color: #fff;
    border-radius: 12px;
    padding: 0.65rem 1.15rem;
    font-weight: 600;
    box-shadow: 0 10px 22px rgba(220, 38, 38, 0.18);
}

.btn-delete-confirm:hover {
    background: linear-gradient(135deg, #b91c1c 0%, #991b1b 100%);
    color: #fff;
}

.modal-modern.delete-confirm-modal .modal-body {
    padding: 1.25rem 1.5rem;
}

.modal-modern.delete-confirm-modal .modal-footer {
    padding: 0 1.5rem 1.35rem;
}

.modal-modern.delete-confirm-modal .delete-confirm-note {
    padding: 0.85rem 0.9rem;
    border-radius: 14px;
}

.modal-modern.delete-confirm-modal .delete-confirm-note .fw-semibold {
    font-size: 0.95rem;
    line-height: 1.25;
    word-break: break-word;
}

.modal-modern.delete-confirm-modal .delete-confirm-note .small {
    line-height: 1.35;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #page-content-wrapper {
    background: #06111e;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .client-flash--success {
    background: rgba(22, 101, 52, 0.2);
    color: #bbf7d0;
    box-shadow: 0 12px 28px rgba(3, 14, 25, 0.24);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .client-flash__icon {
    background: #22c55e;
    color: #052e16;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .page-header-modern,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .client-directory-workspace,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .stat-card-modern,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .main-card,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .client-draft-card,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .modal-modern .modal-content,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .modal-modern.add-client-modal .modal-content,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .client-draft-empty {
    background: #071321;
    border-color: rgba(31, 99, 146, 0.24);
    box-shadow: 0 16px 36px rgba(3, 14, 25, 0.26);
    color: #d7e4f2;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .client-directory-workspace {
    border-color: #2a3b4f;
    background: #071321;
    box-shadow: 0 12px 30px rgba(0, 0, 0, 0.22);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .client-summary-strip {
    background: transparent;
    border-color: transparent;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .client-summary-cell {
    background: #102437;
    border-color: #2a3b4f;
    box-shadow: 0 7px 0 rgba(0, 0, 0, 0.22), 0 0 18px rgba(34, 211, 238, 0.04);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .client-summary-icon {
    background: rgba(90, 159, 196, 0.14);
    color: #8ed4f5;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .page-header-modern {
    border: 1px solid rgba(31, 99, 146, 0.18);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .page-header-modern::before {
    background: linear-gradient(90deg, transparent, rgba(31, 99, 146, 0.12));
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .card-header-modern,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .client-tabs,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .modal-modern .modal-header {
    background: #071321;
    border-color: rgba(31, 99, 146, 0.24);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .client-tabs::after {
    background: rgba(31, 99, 146, 0.24);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) :is(#addClientModal, #addProjectModal).modal-modern.add-client-modal .modal-content {
    background:
        linear-gradient(135deg, rgba(13, 103, 134, 0.16), rgba(7, 19, 33, 0) 42%),
        #071321;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) :is(#addClientModal, #addProjectModal).modal-modern.add-client-modal .modal-header {
    background: linear-gradient(135deg, #0b3654 0%, #0f5f94 100%);
    border-color: rgba(255, 255, 255, 0.12);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) :is(#addClientModal, #addProjectModal).modal-modern.add-client-modal .modal-footer {
    background: transparent;
    border-color: transparent;
    box-shadow: none;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #addClientModal.modal-modern.add-client-modal .draft-status {
    color: #dff2ff !important;
    background: rgba(11, 54, 84, 0.72);
    border-color: rgba(138, 210, 255, 0.2);
    box-shadow: 0 8px 18px rgba(3, 14, 25, 0.28);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #addClientModal.modal-modern.add-client-modal .draft-status::before {
    color: #8ad2ff;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) :is(#addClientModal, #addProjectModal).modal-modern.add-client-modal .field-group {
    background: #0a1b2c;
    border-color: rgba(138, 210, 255, 0.14);
    box-shadow: 0 18px 38px rgba(3, 14, 25, 0.28);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) :is(#addClientModal, #addProjectModal).modal-modern.add-client-modal .add-client-layout {
    background: #0a1b2c;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #addClientModal.modal-modern.add-client-modal .project-intake-support-grid > div,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #addClientModal.modal-modern.add-client-modal .project-intake-detail-grid > .project-intake-column,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #addClientModal.modal-modern.add-client-modal .timeline-budget-card {
    background: rgba(7, 19, 33, 0.58);
    border-color: rgba(138, 210, 255, 0.14);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #addClientModal.modal-modern.add-client-modal .timeline-budget-card-title {
    color: #d7e4f2;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #addClientModal.modal-modern.add-client-modal .timeline-budget-card-title i {
    color: #8fd3ff;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #addClientModal.modal-modern.add-client-modal .requested-feature-item,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #addClientModal.modal-modern.add-client-modal .attachment-upload-field {
    background: rgba(10, 27, 44, 0.64);
    border-color: rgba(138, 210, 255, 0.12);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #addClientModal.modal-modern.add-client-modal .requested-feature-item .form-control {
    color: #d7e4f2;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) :is(#addClientForm, #addProjectForm) .requested-feature-chip-editor {
    background: rgba(7, 19, 33, 0.72);
    border-color: rgba(138, 210, 255, 0.20);
    scrollbar-color: rgba(143, 211, 255, 0.42) transparent;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) :is(#addClientForm, #addProjectForm) .requested-feature-chip {
    background: rgba(13, 103, 134, 0.26);
    border-color: rgba(138, 210, 255, 0.24);
    color: #d7e4f2;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) :is(#addClientForm, #addProjectForm) .requested-feature-chip-input {
    color: #d7e4f2;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) :is(#addClientForm, #addProjectForm) .requested-feature-chip-toggle {
    background: rgba(13, 103, 134, 0.28);
    border-color: rgba(138, 210, 255, 0.20);
    color: #bfe6ff;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) :is(#addClientForm, #addProjectForm) .requested-feature-chip-toggle:hover,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) :is(#addClientForm, #addProjectForm) .requested-feature-chip-toggle:focus-visible,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) :is(#addClientForm, #addProjectForm) .requested-feature-chip-toggle[aria-expanded="true"] {
    background: rgba(143, 211, 255, 0.22);
    color: #ffffff;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) :is(#addClientModal, #addProjectModal).modal-modern.add-client-modal .add-client-stepper {
    background: rgba(7, 19, 33, 0.9);
    border-color: rgba(138, 210, 255, 0.16);
    box-shadow: 0 12px 28px rgba(3, 14, 25, 0.26);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) :is(#addClientModal, #addProjectModal).modal-modern.add-client-modal .stepper-tooltip-wrap:not(:last-child)::after {
    background: rgba(143, 211, 255, 0.16);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) :is(#addClientModal, #addProjectModal).modal-modern.add-client-modal .stepper-tooltip-wrap:has(.stepper-item.is-complete)::after {
    background: linear-gradient(90deg, #2a8798, #62bed2);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) :is(#addClientModal, #addProjectModal).modal-modern.add-client-modal .stepper-item span {
    border-color: rgba(143, 211, 255, 0.2);
    background: #102438;
    color: #9db6cf;
    box-shadow: 0 0 0 5px #071321;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) :is(#addClientModal, #addProjectModal).modal-modern.add-client-modal .stepper-item strong {
    background: #071321;
    color: #9db6cf;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) :is(#addClientModal, #addProjectModal).modal-modern.add-client-modal .stepper-item.is-active strong,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) :is(#addClientModal, #addProjectModal).modal-modern.add-client-modal .stepper-item.is-complete strong {
    color: #bfe6ff;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) :is(#addClientModal, #addProjectModal).modal-modern.add-client-modal .stepper-item.is-active span {
    border-color: #8fd3ff;
    background: #0d6786;
    color: #ffffff;
    box-shadow: 0 0 0 5px #071321, 0 0 0 7px rgba(143, 211, 255, 0.14);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) :is(#addClientModal, #addProjectModal).modal-modern.add-client-modal .stepper-item.is-complete span {
    border-color: #62bed2;
    background: #2a8798;
    color: #ffffff;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) :is(#addClientModal, #addProjectModal).modal-modern.add-client-modal .stepper-info {
    background: #071321;
    border-color: rgba(143, 211, 255, 0.2);
    color: #8fd3ff;
    box-shadow: 0 0 0 4px #071321;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #addClientModal.modal-modern.add-client-modal .attachment-upload-button {
    background: rgba(13, 103, 134, 0.24);
    color: #8fd3ff;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #addClientModal.modal-modern.add-client-modal .attachment-upload-button {
    border-color: rgba(138, 210, 255, 0.12);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #addClientModal.modal-modern.add-client-modal .attachment-upload-summary {
    color: #d7e4f2;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .modal-modern.add-client-modal .template-type-picker::after {
    color: #8fd3ff;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .modal-modern.add-client-modal .template-suggestions-menu {
    background: #071321;
    border-color: rgba(138, 210, 255, 0.18);
    box-shadow: 0 18px 38px rgba(3, 14, 25, 0.34);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .modal-modern.add-client-modal .template-suggestion-option {
    color: #d7e4f2;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .modal-modern.add-client-modal .template-suggestion-option:hover,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .modal-modern.add-client-modal .template-suggestion-option:focus-visible {
    background: rgba(31, 99, 146, 0.22);
    color: #8fd3ff;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .modal-modern.add-client-modal .template-suggestion-empty {
    color: #9db6cf;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #addClientModal.modal-modern.add-client-modal .priority-insight-trigger {
    background: rgba(13, 103, 134, 0.26);
    border-color: rgba(138, 210, 255, 0.28);
    color: #8fd3ff;
    box-shadow: 0 5px 12px rgba(3, 14, 25, 0.28);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #addClientModal.modal-modern.add-client-modal .complexity-insight-trigger,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #addProjectModal.modal-modern.add-client-modal .complexity-insight-trigger {
    background: rgba(13, 103, 134, 0.26);
    border-color: rgba(138, 210, 255, 0.28);
    color: #8fd3ff;
    box-shadow: 0 5px 12px rgba(3, 14, 25, 0.28);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) :is(#addClientModal, #addProjectModal).modal-modern.add-client-modal .template-recommendation-insight-trigger,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #addProjectModal.modal-modern.add-client-modal .add-project-priority-insight-trigger {
    background: rgba(13, 103, 134, 0.26);
    border-color: rgba(138, 210, 255, 0.28);
    color: #8fd3ff;
    box-shadow: 0 5px 12px rgba(3, 14, 25, 0.28);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) :is(#addClientModal, #addProjectModal).modal-modern.add-client-modal .template-recommendation-insight-trigger:hover,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) :is(#addClientModal, #addProjectModal).modal-modern.add-client-modal .template-recommendation-insight-trigger:focus-visible,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) :is(#addClientModal, #addProjectModal).modal-modern.add-client-modal .template-recommendation-insight-trigger.is-open,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #addProjectModal.modal-modern.add-client-modal .add-project-priority-insight-trigger:hover,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #addProjectModal.modal-modern.add-client-modal .add-project-priority-insight-trigger:focus-visible,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #addProjectModal.modal-modern.add-client-modal .add-project-priority-insight-trigger.is-open {
    background: #8fd3ff;
    color: #071321;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #addClientModal.modal-modern.add-client-modal .complexity-insight-trigger:hover,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #addClientModal.modal-modern.add-client-modal .complexity-insight-trigger:focus-visible,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #addClientModal.modal-modern.add-client-modal .complexity-insight-trigger.is-open,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #addProjectModal.modal-modern.add-client-modal .complexity-insight-trigger:hover,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #addProjectModal.modal-modern.add-client-modal .complexity-insight-trigger:focus-visible,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #addProjectModal.modal-modern.add-client-modal .complexity-insight-trigger.is-open {
    background: #8fd3ff;
    color: #071321;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #addClientModal.modal-modern.add-client-modal .priority-insight-trigger:hover,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #addClientModal.modal-modern.add-client-modal .priority-insight-trigger:focus-visible,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #addClientModal.modal-modern.add-client-modal .priority-insight-trigger.is-open {
    background: #8fd3ff;
    color: #071321;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #addClientModal.modal-modern.add-client-modal .priority-insight-popover {
    background:
        linear-gradient(135deg, rgba(13, 103, 134, 0.20), rgba(7, 19, 33, 0.9)),
        #091827;
    border-color: rgba(138, 210, 255, 0.18);
    box-shadow: 0 18px 38px rgba(3, 14, 25, 0.34);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #addClientModal.modal-modern.add-client-modal .priority-insight-popover strong {
    color: #d7e4f2;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #addClientModal.modal-modern.add-client-modal .priority-insight-popover span {
    color: #9db6cf;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #addClientModal.modal-modern.add-client-modal .priority-insight-popover .priority-impact-amount {
    background: rgba(10, 27, 44, 0.74);
    border-color: rgba(138, 210, 255, 0.14);
    color: #8fd3ff;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #addClientModal.modal-modern.add-client-modal .complexity-insight-popover,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #addProjectModal.modal-modern.add-client-modal .complexity-insight-popover,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) :is(#addClientModal, #addProjectModal).modal-modern.add-client-modal .template-recommendation-insight-popover,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #addProjectModal.modal-modern.add-client-modal .add-project-priority-insight-popover {
    background:
        linear-gradient(135deg, rgba(13, 103, 134, 0.20), rgba(7, 19, 33, 0.9)),
        #091827;
    border-color: rgba(138, 210, 255, 0.18);
    box-shadow: 0 18px 38px rgba(3, 14, 25, 0.34);
    color: #9db6cf;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #addClientModal.modal-modern.add-client-modal .complexity-insight-popover strong,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #addProjectModal.modal-modern.add-client-modal .complexity-insight-popover strong,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) :is(#addClientModal, #addProjectModal).modal-modern.add-client-modal .template-recommendation-insight-popover strong,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #addProjectModal.modal-modern.add-client-modal .add-project-priority-insight-popover strong {
    color: #d7e4f2;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #addClientModal.modal-modern.add-client-modal .dss-kicker,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #addClientModal.modal-modern.add-client-modal .dss-section-title i,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #addClientModal.modal-modern.add-client-modal .dss-score-pill {
    color: #8fd3ff;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #addClientModal.modal-modern.add-client-modal .dss-panel-title strong,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #addClientModal.modal-modern.add-client-modal .dss-metric strong {
    color: #d7e4f2;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #addClientModal.modal-modern.add-client-modal .dss-panel-title span,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #addClientModal.modal-modern.add-client-modal .dss-muted {
    color: #9db6cf;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #addClientModal.modal-modern.add-client-modal .dss-score-pill,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #addClientModal.modal-modern.add-client-modal .dss-metric,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #addClientModal.modal-modern.add-client-modal .dss-chip {
    background: rgba(10, 27, 44, 0.74);
    border-color: rgba(138, 210, 255, 0.14);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #addClientModal.modal-modern.add-client-modal .dss-metric span,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #addClientModal.modal-modern.add-client-modal .dss-section-title {
    color: #aac4dc;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #addClientModal.modal-modern.add-client-modal .dss-chip {
    color: #cde5f8;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #addClientModal.modal-modern.add-client-modal .dss-chip.is-more {
    color: #8fd3ff;
    background: rgba(31, 99, 146, 0.22);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #addClientModal.modal-modern.add-client-modal .dss-template-review-overlay {
    background: rgba(3, 14, 25, 0.58);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #addClientModal.modal-modern.add-client-modal .dss-template-review-dialog {
    background: #091827;
    border-color: rgba(138, 210, 255, 0.18);
    box-shadow: 0 28px 70px rgba(0, 0, 0, 0.48);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #addClientModal.modal-modern.add-client-modal .dss-review-body {
    background:
        linear-gradient(135deg, rgba(13, 103, 134, 0.18), rgba(7, 19, 33, 0.9)),
        #091827;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #addClientModal.modal-modern.add-client-modal .dss-review-summary,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #addClientModal.modal-modern.add-client-modal .dss-review-footer {
    background: rgba(10, 27, 44, 0.86);
    border-color: rgba(138, 210, 255, 0.14);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #addClientModal.modal-modern.add-client-modal .dss-review-summary span,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #addClientModal.modal-modern.add-client-modal .dss-review-footer span {
    color: #9db6cf;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #addClientModal.modal-modern.add-client-modal .dss-review-summary strong {
    color: #d7e4f2;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #addClientModal.modal-modern.add-client-modal .calculation-note {
    background: rgba(31, 99, 146, 0.16);
    border-color: rgba(138, 210, 255, 0.18);
    color: #c4e6ff;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #addClientModal.modal-modern.add-client-modal .calculation-note strong {
    color: #d7e4f2;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .filter-section,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .client-drafts-panel,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .client-table thead,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .client-table tr:hover,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .modal-modern.add-client-modal .stepper-item,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .modal-modern.add-client-modal .field-group,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .modal-modern.add-client-modal .info-card,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .modal-modern.add-client-modal .client-summary-panel,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .client-draft-card__summary span,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .client-draft-summary span,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .btn-icon.view,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .btn-icon.edit,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .client-detail-panel,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .client-project-item {
    background: #0a1b2c;
    border-color: rgba(31, 99, 146, 0.24);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .modal-modern.add-client-modal .stepper-info {
    border-color: rgba(138, 210, 255, 0.24);
    background: rgba(31, 99, 146, 0.22);
    color: #bfe6ff;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .modal-modern.add-client-modal .stepper-info:hover,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .modal-modern.add-client-modal .stepper-info:focus-visible {
    background: #8fd3ff;
    color: #071321;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .card-header-modern h5,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .client-name,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .deadline-date,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .empty-state-modern h4,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .client-draft-card__title,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .client-draft-empty strong,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .client-draft-banner__text strong,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .modal-modern.add-client-modal .section-title,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .modal-modern.add-client-modal .info-card strong,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .modal-modern.add-client-modal .client-summary-panel h6,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .modal-modern.add-client-modal .client-summary-list dd,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #viewClientContent,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #clientProjects {
    color: #d7e4f2;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .client-detail-panel strong,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .client-project-item strong {
    color: #d7e4f2;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .card-header-modern .text-muted,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .client-summary-label,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .client-tab-btn,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .client-table th,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .client-email,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .empty-state-modern p,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .empty-state-modern i,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .client-draft-card__subtitle,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .client-draft-card__summary span,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .client-draft-empty,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .client-draft-empty i,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .client-draft-banner__text,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .client-draft-banner__text span,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .modal-modern.add-client-modal .stepper-item,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .modal-modern.add-client-modal .stepper-item span,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .modal-modern.add-client-modal .mini-note,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .modal-modern.add-client-modal .info-card span,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .modal-modern.add-client-modal .client-summary-list dt,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .modal-modern.add-client-modal .draft-status,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .filter-input label {
    color: #9db6cf !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .modal-modern.add-client-modal .architect-handoff-note {
    background: rgba(31, 99, 146, .18);
    color: #c4e6ff;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .modal-modern.add-client-modal .review-ready-banner {
    border-color: rgba(86, 171, 224, 0.28);
    background: linear-gradient(135deg, rgba(31, 99, 146, 0.24), rgba(10, 27, 44, 0.92));
    box-shadow: 0 10px 24px rgba(3, 14, 25, 0.24);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .modal-modern.add-client-modal .review-card {
    border-color: rgba(31, 99, 146, 0.26);
    background: #0a1b2c;
    box-shadow: 0 12px 26px rgba(3, 14, 25, 0.2);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .modal-modern.add-client-modal .review-highlight,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .modal-modern.add-client-modal .review-key-item,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .modal-modern.add-client-modal .review-block,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .modal-modern.add-client-modal .review-note-item {
    border-color: rgba(31, 99, 146, 0.2);
    background: #071321;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .modal-modern.add-client-modal .review-ready-copy strong,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .modal-modern.add-client-modal .review-card-heading h6,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .modal-modern.add-client-modal .review-highlight strong,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .modal-modern.add-client-modal .review-summary-value,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .modal-modern.add-client-modal .review-key-value {
    color: #d7e4f2;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .modal-modern.add-client-modal .review-ready-copy span,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .modal-modern.add-client-modal .review-card-heading span,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .modal-modern.add-client-modal .review-highlight span,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .modal-modern.add-client-modal .review-data-label,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .modal-modern.add-client-modal .review-key-label,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .modal-modern.add-client-modal .review-block-label,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .modal-modern.add-client-modal .review-note-label {
    color: #8eabc5;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .modal-modern.add-client-modal .review-feature-list li {
    background: rgba(31, 99, 146, 0.18);
    color: #c4e6ff;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .modal-modern.add-client-modal .review-summary-value.is-empty,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .modal-modern.add-client-modal .review-feature-list li.is-empty {
    color: #7892aa;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .modal-modern.add-client-modal .review-chip {
    border-color: rgba(86, 171, 224, 0.25);
    background: rgba(31, 99, 146, 0.2);
    color: #bfe6ff;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .modal-modern.add-client-modal .review-chip[data-value="high"],
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .modal-modern.add-client-modal .review-chip[data-value="critical"] {
    border-color: rgba(248, 113, 113, 0.25);
    background: rgba(127, 29, 29, 0.22);
    color: #fca5a5;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .modal-modern.add-client-modal .review-ready-badge {
    background: rgba(16, 185, 129, 0.16);
    color: #6ee7b7;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .client-table th,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .client-table td,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .card-header-modern,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .filter-section,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .client-tabs,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .modal-modern .modal-footer,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .modal-modern.add-client-modal .stepper-item,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .modal-modern.add-client-modal .field-group,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .modal-modern.add-client-modal .info-card,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .modal-modern.add-client-modal .client-summary-panel,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .client-draft-card,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .client-draft-empty,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .client-detail-panel,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .client-project-item {
    border-color: rgba(31, 99, 146, 0.24);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .client-draft-list {
    background: #071321;
    border-color: #2a3b4f;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .client-draft-table__head {
    background: #0b1d2d;
    border-color: #2a3b4f;
    color: #9db6cf;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .client-draft-card {
    background: transparent;
    border-color: #2a3b4f;
    box-shadow: none;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .client-draft-card:hover,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .client-draft-card:focus-within {
    background: rgba(31, 99, 146, 0.12);
    box-shadow: none;
    transform: none !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .client-draft-card__icon {
    background: rgba(31, 99, 146, 0.2);
    color: #8fd3ff;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .client-draft-card__cell {
    color: #b9cad9;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .filter-input input,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .filter-input select,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .modal-modern .form-control,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .modal-modern .form-select,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .modal-modern textarea,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .modal-modern.add-client-modal .form-control,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .modal-modern.add-client-modal .form-select,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .modal-modern.add-client-modal textarea {
    background: #071321;
    border-color: rgba(31, 99, 146, 0.24);
    color: #d7e4f2;
    color-scheme: dark;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .modal-modern input[type="date"]::-webkit-calendar-picker-indicator,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .modal-modern input[type="time"]::-webkit-calendar-picker-indicator {
    cursor: pointer;
    opacity: 0.9;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .modal-modern input[type="date"]::-webkit-calendar-picker-indicator:hover,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .modal-modern input[type="time"]::-webkit-calendar-picker-indicator:hover {
    opacity: 1;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .filter-input input:focus,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .filter-input select:focus,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .modal-modern .form-control:focus,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .modal-modern .form-select:focus,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .modal-modern textarea:focus {
    background: #0a1b2c;
    border-color: rgba(31, 99, 146, 0.45);
    color: #d7e4f2;
    box-shadow: 0 0 0 3px rgba(31, 99, 146, 0.12);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .filter-input input::placeholder,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .modal-modern textarea::placeholder,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .modal-modern .form-control::placeholder {
    color: rgba(157, 182, 207, 0.65);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .filter-section .filter-input input,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .filter-section .filter-input select,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .filter-section .filter-input input:focus,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .filter-section .filter-input select:focus {
    background: transparent;
    border-color: #29435a;
    box-shadow: none;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .filter-section .filter-input input:focus,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .filter-section .filter-input select:focus {
    border-color: #5a9fc4;
    box-shadow: 0 2px 0 rgba(90, 159, 196, 0.14);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .client-table tbody .client-row:hover {
    background: #102235;
    box-shadow: inset 3px 0 0 #4b94ba;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .client-tab-btn.is-active,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .client-draft-summary span {
    color: var(--theme-primary) !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .project-row-toggle,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .project-expansion-list {
    background: #071321;
    border-color: rgba(31, 99, 146, 0.24);
    color: #d7e4f2;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .client-table tr.client-row.is-expanded,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .client-table tr.client-row.has-projects:hover,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .project-expansion-row td {
    background: rgba(31, 99, 146, 0.08);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .project-expansion-main strong {
    color: #d7e4f2;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .project-status-pill {
    background: rgba(157, 182, 207, 0.16);
    color: #9db6cf;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .project-progress-pill {
    background: rgba(31, 99, 146, 0.22);
    color: #66c8ff;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .modal-modern.add-client-modal .unsaved-close-panel {
    background: rgba(217, 119, 6, 0.12);
    border-color: rgba(217, 119, 6, 0.35);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .modal-modern.add-client-modal .unsaved-close-panel__text,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .modal-modern.add-client-modal .unsaved-close-panel__text strong {
    color: #fbbf24;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .delete-confirm-note {
    background: rgba(220, 38, 38, 0.12);
    border-color: rgba(220, 38, 38, 0.3);
    color: #d7e4f2;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .btn-delete-cancel {
    background: #0a1b2c;
    border-color: rgba(31, 99, 146, 0.24);
    color: #d7e4f2;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .btn-delete-cancel:hover {
    background: #0f2438;
    color: #fff;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .delete-confirm-modal .modal-header {
    background: linear-gradient(135deg, #0b3654 0%, #0f5f94 100%);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .delete-confirm-title {
    color: #d7e4f2;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .delete-confirm-text {
    color: #9db6cf;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .delete-confirm-target {
    background: #0a1b2c;
    border-color: rgba(31, 99, 146, 0.24);
    color: #d7e4f2;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .delete-confirm-target i {
    color: #66c8ff;
}

/* Shared modal polish: keep progress connectors behind labels */
:is(#addClientModal, #addProjectModal).modal-modern.add-client-modal .stepper-item strong {
    padding-inline: 0.4rem;
    border-radius: 4px;
    box-shadow: 0 0 0 0.42rem #ffffff;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) :is(#addClientModal, #addProjectModal).modal-modern.add-client-modal .stepper-item strong {
    background: #071321;
    box-shadow: 0 0 0 0.42rem #071321;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) :is(#addClientModal, #addProjectModal).modal-modern.add-client-modal .architect-handoff-note {
    border-color: rgba(143, 211, 255, 0.18);
    background:
        linear-gradient(135deg, rgba(13, 103, 134, 0.28), rgba(7, 19, 33, 0.9)),
        #0a1b2c;
    color: #c4e6ff;
    box-shadow: 0 10px 24px rgba(3, 14, 25, 0.22);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) :is(#addClientModal, #addProjectModal).modal-modern.add-client-modal .architect-handoff-note i {
    color: #8fd3ff;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) :is(#addClientModal, #addProjectModal).modal-modern.add-client-modal .architect-handoff-note strong {
    color: #ffffff;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode):has(#addClientModal.show, #addProjectModal.show) .tooltip .tooltip-inner {
    border: 1px solid rgba(143, 211, 255, 0.18);
    background: #071321;
    color: #d7e4f2;
    box-shadow: 0 10px 24px rgba(3, 14, 25, 0.32);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode):has(#addClientModal.show, #addProjectModal.show) .bs-tooltip-bottom .tooltip-arrow::before,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode):has(#addClientModal.show, #addProjectModal.show) .bs-tooltip-auto[data-popper-placement^="bottom"] .tooltip-arrow::before {
    border-bottom-color: #071321;
}

/* Add Client and Add Project: keep rounded cards clear of the layout background */
:is(#addClientModal, #addProjectModal).modal-modern.add-client-modal .add-client-layout {
    background: transparent;
    border-radius: 18px;
}

#addClientModal.modal-modern.add-client-modal .field-group.review-step {
    --review-surface: #ffffff;
    --review-soft-surface: #f6f9fc;
    --review-border: #dce8f0;
    padding: 1.15rem;
    border: 1px solid rgba(31, 99, 146, 0.16);
    border-radius: 18px;
    background: rgba(255, 255, 255, 0.97);
    box-shadow: 0 16px 34px rgba(15, 76, 129, 0.08);
}

#addClientModal.modal-modern.add-client-modal .review-step > .section-title {
    margin-bottom: 0.85rem;
    padding-bottom: 0.8rem;
    border-bottom: 1px solid var(--review-border);
}

#addClientModal.modal-modern.add-client-modal .review-summary {
    gap: 0.85rem;
}

#addClientModal.modal-modern.add-client-modal .review-ready-banner {
    padding: 0.85rem 1rem;
    border-color: var(--review-border);
    border-radius: 14px;
    background: linear-gradient(135deg, #eef8fc 0%, #f9fcfe 100%);
    box-shadow: none;
}

#addClientModal.modal-modern.add-client-modal .review-primary-grid {
    grid-template-columns: minmax(330px, 0.72fr) minmax(0, 1.28fr);
    align-items: start;
    gap: 0.85rem;
}

#addClientModal.modal-modern.add-client-modal .review-card {
    position: relative;
    align-self: start;
    padding: 1rem;
    overflow: hidden;
    border-color: var(--review-border);
    border-radius: 14px;
    background: var(--review-surface);
    box-shadow: 0 8px 22px rgba(15, 76, 129, 0.055);
}

#addClientModal.modal-modern.add-client-modal .review-card::before {
    content: none;
}

#addClientModal.modal-modern.add-client-modal .review-card-header {
    margin-bottom: 0.75rem;
    padding-bottom: 0.7rem;
    border-bottom: 1px solid var(--review-border);
}

#addClientModal.modal-modern.add-client-modal .review-card-icon {
    width: 32px;
    height: 32px;
    border-radius: 9px;
}

#addClientModal.modal-modern.add-client-modal .review-highlight,
#addClientModal.modal-modern.add-client-modal .review-key-item,
#addClientModal.modal-modern.add-client-modal .review-block,
#addClientModal.modal-modern.add-client-modal .review-note-item {
    border: 1px solid var(--review-border);
    border-radius: 10px;
    background: var(--review-soft-surface);
}

#addClientModal.modal-modern.add-client-modal .review-highlight {
    padding: 0.65rem 0.75rem;
}

#addClientModal.modal-modern.add-client-modal .review-data-grid {
    gap: 0.65rem 0.8rem;
    padding: 0.1rem 0.1rem 0.15rem;
}

#addClientModal.modal-modern.add-client-modal .review-key-grid {
    gap: 0.55rem;
}

#addClientModal.modal-modern.add-client-modal .review-handoff {
    border: 1px solid rgba(255, 255, 255, 0.14);
    border-radius: 14px;
    box-shadow: 0 10px 24px rgba(10, 85, 112, 0.16);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #addClientModal.modal-modern.add-client-modal .field-group.review-step {
    --review-surface: #0a1b2c;
    --review-soft-surface: #0d2236;
    --review-border: rgba(138, 210, 255, 0.15);
    background: rgba(7, 19, 33, 0.96);
    border-color: var(--review-border);
    box-shadow: 0 18px 38px rgba(3, 14, 25, 0.3);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #addClientModal.modal-modern.add-client-modal .review-ready-banner {
    background: linear-gradient(135deg, rgba(13, 103, 134, 0.24), rgba(10, 27, 44, 0.92));
}

:is(#addClientModal, #addProjectModal).modal-modern.add-client-modal .review-card {
    border-color: rgba(31, 99, 146, 0.15);
    background:
        radial-gradient(circle at 100% 0%, rgba(34, 211, 238, 0.1), transparent 34%),
        linear-gradient(135deg, rgba(255, 255, 255, 0.9) 0%, rgba(255, 255, 255, 0) 38%, rgba(31, 99, 146, 0.045) 100%),
        linear-gradient(180deg, rgba(255, 255, 255, 0.5), transparent 56%),
        #ffffff;
    box-shadow:
        inset 0 1px 0 rgba(255, 255, 255, 0.94),
        0 5px 0 rgba(15, 76, 129, 0.13),
        0 18px 30px rgba(15, 76, 129, 0.13),
        0 0 24px rgba(34, 211, 238, 0.055);
    transform: translateY(0);
    transition: transform 0.2s ease, box-shadow 0.22s ease, border-color 0.22s ease;
    isolation: isolate;
}

:is(#addClientModal, #addProjectModal).modal-modern.add-client-modal .review-card:hover {
    border-color: rgba(34, 161, 190, 0.26);
    box-shadow:
        inset 0 1px 0 rgba(255, 255, 255, 0.98),
        0 7px 0 rgba(15, 76, 129, 0.15),
        0 24px 40px rgba(15, 76, 129, 0.16),
        0 0 30px rgba(34, 211, 238, 0.08);
    transform: translateY(-2px);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) :is(#addClientModal, #addProjectModal).modal-modern.add-client-modal .review-card {
    border-color: rgba(78, 155, 199, 0.22);
    background:
        radial-gradient(circle at 100% 0%, rgba(76, 184, 255, 0.14), transparent 34%),
        linear-gradient(135deg, rgba(255, 255, 255, 0.035) 0%, rgba(255, 255, 255, 0) 38%, rgba(76, 184, 255, 0.09) 100%),
        linear-gradient(180deg, rgba(138, 210, 255, 0.055), transparent 56%),
        #0a1b2c;
    box-shadow:
        inset 0 1px 0 rgba(138, 210, 255, 0.1),
        0 6px 0 rgba(2, 11, 20, 0.7),
        0 20px 36px rgba(2, 11, 20, 0.34),
        0 0 28px rgba(34, 211, 238, 0.06);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) :is(#addClientModal, #addProjectModal).modal-modern.add-client-modal .review-card:hover {
    border-color: rgba(78, 155, 199, 0.34);
    box-shadow:
        inset 0 1px 0 rgba(138, 210, 255, 0.13),
        0 8px 0 rgba(2, 11, 20, 0.72),
        0 26px 44px rgba(2, 11, 20, 0.4),
        0 0 34px rgba(34, 211, 238, 0.09);
}

@media (prefers-reduced-motion: reduce) {
    :is(#addClientModal, #addProjectModal).modal-modern.add-client-modal .review-card {
        transition: none;
    }

    :is(#addClientModal, #addProjectModal).modal-modern.add-client-modal .review-card:hover {
        transform: none;
    }
}

/* Keep the intake fields and actions on one continuous surface. */
:is(#addClientModal, #addProjectModal).modal-modern.add-client-modal .add-client-layout {
    overflow: visible;
    border: 1px solid rgba(31, 99, 146, 0.16);
    border-radius: 18px;
    background: rgba(255, 255, 255, 0.97);
    box-shadow: 0 18px 38px rgba(15, 76, 129, 0.09);
}

:is(#addClientModal, #addProjectModal).modal-modern.add-client-modal .add-client-layout > .field-group {
    border: 0;
    border-radius: 0;
    background: transparent;
    box-shadow: none;
}

:is(#addClientModal, #addProjectModal).modal-modern.add-client-modal .add-client-layout > .modal-footer.add-client-footer {
    padding: 0.85rem 1.15rem;
    border-top: 1px solid rgba(31, 99, 146, 0.13);
    border-radius: 0 0 17px 17px;
    background: #fbfdff;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) :is(#addClientModal, #addProjectModal).modal-modern.add-client-modal .add-client-layout {
    border-color: rgba(138, 210, 255, 0.15);
    background: rgba(7, 19, 33, 0.96);
    box-shadow: 0 18px 38px rgba(3, 14, 25, 0.3);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) :is(#addClientModal, #addProjectModal).modal-modern.add-client-modal .add-client-layout > .modal-footer.add-client-footer {
    border-top-color: rgba(138, 210, 255, 0.13);
    background: rgba(10, 27, 44, 0.9);
}

/* Final Review: clean document-style summary with one information row per line. */
:is(#addClientModal, #addProjectModal).modal-modern.add-client-modal .review-step {
    --review-sheet-surface: #ffffff;
    --review-sheet-soft: #f8fbfd;
    --review-sheet-border: #dce7ef;
    --review-sheet-label: #64788a;
    --review-sheet-value: #17324d;
    --review-label-width: 185px;
}

:is(#addClientModal, #addProjectModal).modal-modern.add-client-modal .review-step > .section-title {
    margin: 0 0 0.8rem;
    padding: 0 0 0.75rem;
    border-bottom: 1px solid var(--review-sheet-border);
}

:is(#addClientModal, #addProjectModal).modal-modern.add-client-modal .review-summary {
    gap: 0.8rem;
}

:is(#addClientModal, #addProjectModal).modal-modern.add-client-modal .review-ready-banner {
    padding: 0.7rem 0.8rem;
    border-color: var(--review-sheet-border);
    border-radius: 10px;
    background: #f2f8fc;
    box-shadow: none;
}

:is(#addClientModal, #addProjectModal).modal-modern.add-client-modal .review-ready-icon {
    width: 30px;
    height: 30px;
    border-radius: 8px;
    box-shadow: none;
}

:is(#addClientModal, #addProjectModal).modal-modern.add-client-modal .review-primary-grid,
:is(#addClientModal, #addProjectModal).modal-modern.add-client-modal .add-project-review-grid {
    grid-template-columns: minmax(0, 1fr);
    gap: 0.8rem;
}

:is(#addClientModal, #addProjectModal).modal-modern.add-client-modal .review-card,
:is(#addClientModal, #addProjectModal).modal-modern.add-client-modal .review-card:hover {
    min-width: 0;
    padding: 0;
    overflow: hidden;
    isolation: auto;
    border: 1px solid var(--review-sheet-border);
    border-radius: 10px;
    background: var(--review-sheet-surface);
    box-shadow: none;
    transform: none;
    transition: border-color 0.18s ease;
}

:is(#addClientModal, #addProjectModal).modal-modern.add-client-modal .review-card-header {
    display: flex;
    align-items: center;
    gap: 0.55rem;
    margin: 0;
    padding: 0.62rem 0.85rem;
    border: 0;
    background: linear-gradient(135deg, #0d6786 0%, #0a5570 100%);
}

:is(#addClientModal, #addProjectModal).modal-modern.add-client-modal .review-card-icon {
    width: 24px;
    height: 24px;
    border-radius: 6px;
    background: rgba(255, 255, 255, 0.14);
    color: #ffffff;
    font-size: 0.7rem;
}

:is(#addClientModal, #addProjectModal).modal-modern.add-client-modal .review-card-heading h6 {
    color: #ffffff;
    font-size: 0.78rem;
    font-weight: 750;
    letter-spacing: 0.035em;
    text-transform: uppercase;
}

:is(#addClientModal, #addProjectModal).modal-modern.add-client-modal .review-card-heading > span {
    display: none;
}

:is(#addClientModal, #addProjectModal).modal-modern.add-client-modal .review-highlight,
:is(#addClientModal, #addProjectModal).modal-modern.add-client-modal .review-data-item,
:is(#addClientModal, #addProjectModal).modal-modern.add-client-modal .review-key-item,
:is(#addClientModal, #addProjectModal).modal-modern.add-client-modal .review-block,
:is(#addClientModal, #addProjectModal).modal-modern.add-client-modal .review-note-item {
    display: grid;
    grid-template-columns: minmax(130px, var(--review-label-width)) minmax(0, 1fr);
    align-content: start;
    align-items: start;
    gap: 1rem;
    min-width: 0;
    min-height: 0;
    margin: 0;
    padding: 0.68rem 0.85rem;
    border: 0;
    border-bottom: 1px solid var(--review-sheet-border);
    border-radius: 0;
    background: var(--review-sheet-surface);
}

:is(#addClientModal, #addProjectModal).modal-modern.add-client-modal .review-highlight {
    margin: 0;
}

:is(#addClientModal, #addProjectModal).modal-modern.add-client-modal .review-highlight > span,
:is(#addClientModal, #addProjectModal).modal-modern.add-client-modal .review-data-label,
:is(#addClientModal, #addProjectModal).modal-modern.add-client-modal .review-key-label,
:is(#addClientModal, #addProjectModal).modal-modern.add-client-modal .review-block-label,
:is(#addClientModal, #addProjectModal).modal-modern.add-client-modal .review-note-label {
    align-self: start;
    color: var(--review-sheet-label);
    font-size: 0.68rem;
    font-weight: 700;
    letter-spacing: 0.04em;
    line-height: 1.4;
    text-transform: uppercase;
}

:is(#addClientModal, #addProjectModal).modal-modern.add-client-modal .review-highlight strong,
:is(#addClientModal, #addProjectModal).modal-modern.add-client-modal .review-summary-value,
:is(#addClientModal, #addProjectModal).modal-modern.add-client-modal .review-key-value {
    color: var(--review-sheet-value);
    font-size: 0.8rem;
    font-weight: 600;
    line-height: 1.45;
}

:is(#addClientModal, #addProjectModal).modal-modern.add-client-modal .review-data-grid,
:is(#addClientModal, #addProjectModal).modal-modern.add-client-modal .review-key-grid,
:is(#addClientModal, #addProjectModal).modal-modern.add-client-modal .review-requirements-grid,
:is(#addClientModal, #addProjectModal).modal-modern.add-client-modal .add-project-review-requirements,
:is(#addClientModal, #addProjectModal).modal-modern.add-client-modal .review-notes-grid {
    display: grid;
    grid-template-columns: minmax(0, 1fr);
    gap: 0;
    padding: 0;
}

:is(#addClientModal, #addProjectModal).modal-modern.add-client-modal :is(.review-data-item--wide, .review-key-item--wide, .review-block--wide),
:is(#addClientModal, #addProjectModal).modal-modern.add-client-modal .review-card--client-profile .review-data-item--wide,
:is(#addClientModal, #addProjectModal).modal-modern.add-client-modal .review-card--client-profile .review-data-item:last-child {
    grid-column: auto;
}

:is(#addClientModal, #addProjectModal).modal-modern.add-client-modal .review-chip-row {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 0.4rem;
    margin: 0;
    padding: 0.58rem 0.85rem;
    border-bottom: 1px solid var(--review-sheet-border);
    background: var(--review-sheet-soft);
}

:is(#addClientModal, #addProjectModal).modal-modern.add-client-modal .review-chip-row::before {
    content: "Delivery signals";
    flex: 0 0 var(--review-label-width);
    color: var(--review-sheet-label);
    font-size: 0.68rem;
    font-weight: 700;
    letter-spacing: 0.04em;
    text-transform: uppercase;
}

:is(#addClientModal, #addProjectModal).modal-modern.add-client-modal .review-chip {
    padding: 0.28rem 0.5rem;
    border-radius: 7px;
    font-size: 0.68rem;
}

:is(#addClientModal, #addProjectModal).modal-modern.add-client-modal .review-feature-list {
    display: grid;
    grid-template-columns: minmax(0, 1fr);
    gap: 0;
    margin: -0.18rem 0;
}

:is(#addClientModal, #addProjectModal).modal-modern.add-client-modal .review-feature-list li {
    padding: 0.28rem 0 0.28rem 1rem;
    border-bottom: 1px solid var(--review-sheet-border);
    border-radius: 0;
    background: transparent;
    color: var(--review-sheet-value);
    font-size: 0.78rem;
}

:is(#addClientModal, #addProjectModal).modal-modern.add-client-modal .review-feature-list li:last-child {
    border-bottom: 0;
}

:is(#addClientModal, #addProjectModal).modal-modern.add-client-modal .review-feature-list li::before {
    top: 0.72rem;
    left: 0.18rem;
    width: 4px;
    height: 4px;
}

:is(#addClientModal, #addProjectModal).modal-modern.add-client-modal :is(.review-data-grid, .review-key-grid, .review-requirements-grid, .review-notes-grid) > :last-child {
    border-bottom: 0;
}

:is(#addClientModal, #addProjectModal).modal-modern.add-client-modal .review-handoff {
    gap: 0.65rem;
    padding: 0.7rem 0.85rem;
    border: 1px solid #cfe2ee;
    border-radius: 10px;
    background: #eef7fb;
    color: #0d6786;
    box-shadow: none;
}

:is(#addClientModal, #addProjectModal).modal-modern.add-client-modal .review-handoff-copy strong {
    color: #174769;
    font-size: 0.76rem;
}

:is(#addClientModal, #addProjectModal).modal-modern.add-client-modal .review-handoff-copy span {
    color: #5f7487;
    font-size: 0.72rem;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) :is(#addClientModal, #addProjectModal).modal-modern.add-client-modal .review-step {
    --review-sheet-surface: #0a1b2c;
    --review-sheet-soft: #0d2236;
    --review-sheet-border: rgba(138, 210, 255, 0.15);
    --review-sheet-label: #8eabc1;
    --review-sheet-value: #d7e4f2;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) :is(#addClientModal, #addProjectModal).modal-modern.add-client-modal .review-ready-banner,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) :is(#addClientModal, #addProjectModal).modal-modern.add-client-modal .review-handoff {
    border-color: rgba(138, 210, 255, 0.15);
    background: #0d2236;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) :is(#addClientModal, #addProjectModal).modal-modern.add-client-modal .review-handoff-copy strong {
    color: #d7e4f2;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) :is(#addClientModal, #addProjectModal).modal-modern.add-client-modal .review-handoff-copy span {
    color: #9db4c7;
}

@media (max-width: 640px) {
    :is(#addClientModal, #addProjectModal).modal-modern.add-client-modal .review-step {
        --review-label-width: 100%;
    }

    :is(#addClientModal, #addProjectModal).modal-modern.add-client-modal .review-highlight,
    :is(#addClientModal, #addProjectModal).modal-modern.add-client-modal .review-data-item,
    :is(#addClientModal, #addProjectModal).modal-modern.add-client-modal .review-key-item,
    :is(#addClientModal, #addProjectModal).modal-modern.add-client-modal .review-block,
    :is(#addClientModal, #addProjectModal).modal-modern.add-client-modal .review-note-item {
        grid-template-columns: minmax(0, 1fr);
        gap: 0.25rem;
    }

    :is(#addClientModal, #addProjectModal).modal-modern.add-client-modal .review-chip-row {
        align-items: flex-start;
        flex-direction: column;
    }

    :is(#addClientModal, #addProjectModal).modal-modern.add-client-modal .review-chip-row::before {
        flex-basis: auto;
    }

    :is(#addClientModal, #addProjectModal).modal-modern.add-client-modal .review-ready-badge {
        display: none;
    }
}

/* Client Details: account and delivery dashboard */
#viewClientModal .client-details-dialog {
    width: min(1100px, calc(100vw - 2rem));
    max-width: 1100px;
}

#page-content-wrapper #viewClientModal .modal-content {
    overflow: hidden;
    border: 1px solid rgba(31, 99, 146, 0.16);
    border-radius: 20px;
    background: #f4f8fb;
    box-shadow:
        inset 0 1px 0 rgba(255, 255, 255, 0.9),
        0 24px 60px rgba(15, 42, 64, 0.22);
}

#page-content-wrapper #viewClientModal .modal-header {
    padding: 1rem 1.25rem;
    border-bottom: 1px solid rgba(255, 255, 255, 0.14);
    background: linear-gradient(135deg, #0d6786 0%, #0a5570 62%, #083f58 100%);
    color: #ffffff;
    box-shadow: 0 8px 22px rgba(8, 63, 88, 0.18);
}

#viewClientModal .modal-header .btn-close {
    opacity: 0.82;
}

#viewClientModal .modal-header .btn-close:hover {
    opacity: 1;
}

#viewClientModal .client-details-modal-title {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    margin: 0;
}

#viewClientModal .client-details-title-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex: 0 0 auto;
    width: 38px;
    height: 38px;
    border: 1px solid rgba(255, 255, 255, 0.18);
    border-radius: 11px;
    background: rgba(255, 255, 255, 0.13);
    box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.16), 0 8px 18px rgba(3, 29, 42, 0.2);
}

#viewClientModal .client-details-title-copy {
    display: grid;
    gap: 0.05rem;
}

#viewClientModal .client-details-title-copy strong {
    color: #ffffff;
    font-size: 1rem;
    font-weight: 800;
}

#viewClientModal .client-details-title-copy small {
    color: rgba(230, 247, 255, 0.78);
    font-size: 0.7rem;
    font-weight: 600;
}

#viewClientModal .modal-body {
    max-height: min(76vh, 760px);
    padding: 1.25rem;
    overflow-y: auto;
    background:
        radial-gradient(circle at 100% 0%, rgba(34, 211, 238, 0.075), transparent 32%),
        linear-gradient(180deg, #f8fbfd 0%, #eef5f9 100%);
}

#viewClientModal .client-details-overview {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    align-items: start;
    gap: 1rem;
}

#viewClientModal .client-detail-panel {
    min-width: 0;
    padding: 1rem;
    border: 1px solid rgba(31, 99, 146, 0.15);
    border-radius: 16px;
    background:
        radial-gradient(circle at 100% 0%, rgba(34, 211, 238, 0.1), transparent 34%),
        linear-gradient(135deg, rgba(255, 255, 255, 0.9), rgba(31, 99, 146, 0.045)),
        #ffffff;
    box-shadow:
        inset 0 1px 0 rgba(255, 255, 255, 0.94),
        0 12px 28px rgba(15, 76, 129, 0.1);
}

#viewClientModal .client-detail-panel-header,
#viewClientModal .client-associated-header {
    display: flex;
    align-items: center;
    gap: 0.7rem;
}

#viewClientModal .client-detail-panel-header {
    margin-bottom: 0.75rem;
    padding-bottom: 0.72rem;
    border-bottom: 1px solid rgba(31, 99, 146, 0.12);
}

#viewClientModal .client-detail-panel-header > span:last-child,
#viewClientModal .client-associated-copy {
    display: grid;
    gap: 0.05rem;
    min-width: 0;
}

#viewClientModal .client-detail-panel-header strong,
#viewClientModal .client-associated-copy strong {
    color: #173f61;
    font-size: 0.86rem;
    font-weight: 800;
}

#viewClientModal .client-detail-panel-header small,
#viewClientModal .client-associated-copy small {
    color: #6f879a;
    font-size: 0.68rem;
    line-height: 1.35;
}

#viewClientModal .client-detail-panel-icon,
#viewClientModal .client-associated-icon,
#viewClientModal .client-project-folder {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex: 0 0 auto;
    width: 34px;
    height: 34px;
    border: 1px solid rgba(31, 99, 146, 0.12);
    border-radius: 10px;
    background: linear-gradient(145deg, #f7fbfe, #deebf3);
    color: #0d6786;
    box-shadow: inset 1px 1px 0 rgba(255, 255, 255, 0.9), 0 4px 0 rgba(15, 76, 129, 0.1);
}

#viewClientModal .client-detail-list {
    display: grid;
}

#viewClientModal .client-detail-row {
    display: grid;
    grid-template-columns: minmax(105px, 0.38fr) minmax(0, 0.62fr);
    gap: 0.8rem;
    padding: 0.55rem 0.25rem;
    border-bottom: 1px solid rgba(31, 99, 146, 0.09);
}

#viewClientModal .client-detail-row:last-child {
    border-bottom: 0;
}

#viewClientModal .client-detail-row > span,
#viewClientModal .client-delivery-metric > span,
#viewClientModal .client-project-progress-head > span {
    color: #72889a;
    font-size: 0.68rem;
    font-weight: 750;
    letter-spacing: 0.035em;
    text-transform: uppercase;
}

#viewClientModal .client-detail-row > strong {
    min-width: 0;
    color: #17324d;
    font-size: 0.79rem;
    font-weight: 700;
    overflow-wrap: anywhere;
}

#viewClientModal .client-delivery-metrics {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 0.6rem;
}

#viewClientModal .client-delivery-metric {
    display: grid;
    gap: 0.35rem;
    min-width: 0;
    padding: 0.72rem;
    border: 1px solid rgba(31, 99, 146, 0.11);
    border-radius: 11px;
    background: rgba(244, 249, 252, 0.88);
}

#viewClientModal .client-delivery-metric > strong {
    color: #17324d;
    font-size: 0.82rem;
}

#viewClientModal .client-details-add-project {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
    margin-top: 0.8rem;
    padding: 0.62rem 0.85rem;
    border: 1px solid #0d6786;
    border-radius: 10px;
    background: linear-gradient(135deg, #0d6786, #0a5570);
    color: #ffffff;
    font-size: 0.78rem;
    font-weight: 800;
    box-shadow: 0 8px 18px rgba(13, 103, 134, 0.22);
    transition: transform 0.18s ease, box-shadow 0.18s ease;
}

#viewClientModal .client-details-add-project:hover,
#viewClientModal .client-details-add-project:focus-visible {
    color: #ffffff;
    transform: translateY(-2px);
    box-shadow: 0 12px 24px rgba(13, 103, 134, 0.26);
    outline: none;
}

#viewClientModal .client-associated-section {
    margin-top: 1rem;
    padding: 1rem;
    border: 1px solid rgba(31, 99, 146, 0.15);
    border-radius: 16px;
    background: rgba(255, 255, 255, 0.76);
    box-shadow: 0 12px 28px rgba(15, 76, 129, 0.08);
}

#viewClientModal .client-associated-header {
    margin-bottom: 0.9rem;
}

#viewClientModal .client-project-count {
    margin-left: auto;
    padding: 0.3rem 0.58rem;
    border: 1px solid rgba(31, 99, 146, 0.14);
    border-radius: 999px;
    background: #eaf4fa;
    color: #0d6786;
    font-size: 0.68rem;
    font-weight: 800;
    white-space: nowrap;
}

#viewClientModal .client-projects-loading {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.55rem;
    min-height: 100px;
    color: #6f879a;
    font-size: 0.76rem;
}

#viewClientModal .client-project-list {
    display: grid;
    gap: 0.85rem;
    width: 100%;
}

#viewClientModal .client-project-item {
    display: grid;
    grid-template-columns: minmax(190px, 0.9fr) minmax(220px, 1.1fr) auto;
    align-items: center;
    gap: 1rem;
    padding: 1rem;
    border: 1px solid rgba(31, 99, 146, 0.14);
    border-radius: 15px;
    background:
        radial-gradient(circle at 100% 0%, rgba(34, 211, 238, 0.08), transparent 32%),
        linear-gradient(135deg, #ffffff, #f1f7fb);
    box-shadow:
        inset 0 1px 0 rgba(255, 255, 255, 0.95),
        0 10px 22px rgba(15, 76, 129, 0.09);
}

#viewClientModal .client-project-identity {
    display: flex;
    align-items: center;
    gap: 0.7rem;
    min-width: 0;
}

#viewClientModal .client-project-identity > span:last-child {
    display: grid;
    gap: 0.16rem;
    min-width: 0;
}

#viewClientModal .client-project-identity strong {
    color: #17324d;
    font-size: 0.82rem;
    overflow-wrap: anywhere;
}

#viewClientModal .client-project-identity small {
    color: #7890a3;
    font-size: 0.68rem;
}

#viewClientModal .client-project-progress {
    display: grid;
    gap: 0.42rem;
    min-width: 0;
}

#viewClientModal .client-project-progress-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.6rem;
}

#viewClientModal .client-project-progress-head strong {
    color: #0d6786;
    font-size: 0.7rem;
}

#viewClientModal .client-project-state {
    display: flex;
    justify-content: flex-end;
}

#viewClientModal .client-project-notify {
    grid-column: 1 / -1;
    padding-top: 0.75rem;
    border-top: 1px solid rgba(31, 99, 146, 0.1);
}

#viewClientModal .client-project-empty {
    display: grid;
    justify-items: center;
    gap: 0.3rem;
    padding: 2rem 1rem;
    border: 1px dashed rgba(31, 99, 146, 0.2);
    border-radius: 15px;
    background: rgba(255, 255, 255, 0.58);
    text-align: center;
}

#viewClientModal .client-project-empty > span {
    display: grid;
    place-items: center;
    width: 42px;
    height: 42px;
    border-radius: 12px;
    background: #eaf4fa;
    color: #0d6786;
}

#viewClientModal .client-project-empty strong {
    color: #17324d;
    font-size: 0.84rem;
}

#viewClientModal .client-project-empty small {
    color: #7890a3;
    font-size: 0.7rem;
}

#viewClientModal .client-project-item.is-evortix-target {
    outline: 2px solid #1597e5;
    outline-offset: 3px;
    background: linear-gradient(135deg, #f5fbff, #e8f6ff);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #viewClientModal .modal-content {
    border-color: rgba(138, 210, 255, 0.17);
    background: #071321;
    box-shadow:
        inset 0 1px 0 rgba(138, 210, 255, 0.08),
        0 8px 0 rgba(2, 11, 20, 0.72),
        0 34px 78px rgba(2, 11, 20, 0.5);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #viewClientModal .modal-header {
    border-bottom-color: rgba(138, 210, 255, 0.15);
    background: linear-gradient(135deg, #0a3f5c 0%, #082f47 62%, #061d2f 100%);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #viewClientModal .modal-body {
    background:
        radial-gradient(circle at 100% 0%, rgba(76, 184, 255, 0.1), transparent 32%),
        linear-gradient(180deg, #091827 0%, #06111d 100%);
    color: #d7e4f2;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #viewClientModal .client-detail-panel,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #viewClientModal .client-associated-section,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #viewClientModal .client-project-item {
    border-color: rgba(138, 210, 255, 0.15);
    background:
        radial-gradient(circle at 100% 0%, rgba(76, 184, 255, 0.13), transparent 34%),
        linear-gradient(135deg, rgba(255, 255, 255, 0.035), rgba(76, 184, 255, 0.075)),
        #0a1b2c;
    box-shadow:
        inset 0 1px 0 rgba(138, 210, 255, 0.09),
        0 5px 0 rgba(2, 11, 20, 0.7),
        0 20px 34px rgba(2, 11, 20, 0.32);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #viewClientModal .client-detail-panel-header,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #viewClientModal .client-associated-section,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #viewClientModal .client-project-notify {
    border-color: rgba(138, 210, 255, 0.12);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #viewClientModal .client-detail-panel-header strong,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #viewClientModal .client-associated-copy strong,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #viewClientModal .client-detail-row > strong,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #viewClientModal .client-delivery-metric > strong,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #viewClientModal .client-project-identity strong,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #viewClientModal .client-project-empty strong {
    color: #e3f1fc;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #viewClientModal .client-detail-panel-header small,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #viewClientModal .client-associated-copy small,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #viewClientModal .client-detail-row > span,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #viewClientModal .client-delivery-metric > span,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #viewClientModal .client-project-progress-head > span,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #viewClientModal .client-project-identity small,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #viewClientModal .client-project-empty small {
    color: #8eabc5;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #viewClientModal .client-detail-row {
    border-color: rgba(138, 210, 255, 0.09);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #viewClientModal .client-delivery-metric,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #viewClientModal .client-project-empty {
    border-color: rgba(138, 210, 255, 0.13);
    background: rgba(7, 19, 33, 0.58);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #viewClientModal .client-detail-panel-icon,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #viewClientModal .client-associated-icon,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #viewClientModal .client-project-folder,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #viewClientModal .client-project-empty > span {
    border-color: rgba(138, 210, 255, 0.16);
    background: linear-gradient(145deg, #143650, #081a2a);
    color: #8fd3ff;
    box-shadow: inset 1px 1px 0 rgba(138, 210, 255, 0.09), 0 4px 0 rgba(2, 11, 20, 0.72);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #viewClientModal .client-project-count {
    border-color: rgba(138, 210, 255, 0.15);
    background: rgba(13, 103, 134, 0.22);
    color: #8fd3ff;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #viewClientModal .client-project-notify input {
    border-color: rgba(138, 210, 255, 0.16);
    background: #071321;
    color: #d7e4f2;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #viewClientModal .client-project-notify input::placeholder {
    color: #7892aa;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #viewClientModal .client-project-item.is-evortix-target {
    outline-color: #56bfff;
    background: linear-gradient(135deg, #0c263b, #0b3652);
}

@media (max-width: 820px) {
    #viewClientModal .client-details-overview,
    #viewClientModal .client-project-item {
        grid-template-columns: 1fr;
    }

    #viewClientModal .client-project-state {
        justify-content: flex-start;
    }

    #viewClientModal .client-project-notify {
        grid-column: auto;
    }
}

@media (max-width: 560px) {
    #viewClientModal .modal-body {
        padding: 1rem;
    }

    #viewClientModal .client-detail-row,
    #viewClientModal .client-delivery-metrics {
        grid-template-columns: 1fr;
    }

    #viewClientModal .client-associated-header {
        align-items: flex-start;
        flex-wrap: wrap;
    }

    #viewClientModal .client-project-count {
        margin-left: 0;
    }

    #viewClientModal .client-project-notify {
        align-items: stretch;
        flex-direction: column;
    }
}

@media (max-width: 1100px) {
    #addClientModal.modal-modern.add-client-modal .review-primary-grid {
        grid-template-columns: 1fr;
    }
}

/* Responsive */
@media (max-width: 768px) {
    .page-header-modern {
        padding: 1.5rem;
    }
    
    .client-summary-strip {
        grid-template-columns: 1fr;
        gap: 0.55rem;
        margin: 0 0 1.5rem;
    }
    
    .client-summary-cell {
        min-width: auto;
        min-height: 62px;
        padding: 0.7rem 3.5rem 0.7rem 0.85rem;
        border: 1px solid var(--gray-200);
    }

    .client-summary-icon {
        top: 0.65rem;
        right: 0.85rem;
        width: 34px;
        height: 34px;
    }

    .client-directory-workspace {
        border-radius: 18px;
    }
    
    .card-header-modern {
        flex-direction: column;
        align-items: stretch;
        padding: 0.75rem 1rem 0.55rem;
    }
    
    .filter-group {
        grid-template-columns: minmax(0, 1fr);
    }

    .client-filter-actions {
        width: 100%;
        justify-content: flex-end;
    }

    .client-filter-actions .filter-input {
        flex: 1 1 auto;
        width: auto;
    }

    .btn-add-client-toolbar {
        flex: 0 0 auto;
    }

    .client-tabs {
        padding: 0 1rem;
        overflow-x: auto;
    }

    .client-tabs::after {
        right: 1rem;
        left: 1rem;
    }

    .client-drafts-panel {
        padding: 1rem;
    }
    
    .filter-input {
        width: 100%;
    }
    
    .client-table th,
    .client-table td {
        padding: 0.75rem 1rem;
    }

    .client-directory-workspace .client-tab-panel[data-client-panel="clients"] > .table-container {
        margin: 0.75rem 1rem 0;
    }

    .client-pagination {
        align-items: stretch;
        flex-direction: column;
        padding: 0.9rem 1rem;
    }

    .client-pagination__summary {
        text-align: center;
    }

    .client-pagination__meta {
        justify-content: center;
        flex-wrap: wrap;
    }

    .client-pagination__controls {
        justify-content: center;
        min-width: 0;
    }

    .client-pagination__pages {
        max-width: 100%;
        overflow-x: auto;
    }

    .client-pagination__step {
        min-width: 40px;
        padding: 0 0.65rem;
    }
    
    .action-buttons {
        flex-direction: column;
        gap: 0.25rem;
    }
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .client-pagination,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .client-pagination__pages,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .client-pagination__step,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .client-pagination__size {
    background: #102435;
    border-color: rgba(148, 163, 184, 0.24);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .client-pagination__summary,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .client-pagination__page,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .client-pagination__step,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .client-pagination__size {
    color: #d9e7f1 !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .client-pagination__page:hover,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .client-pagination__page.is-current {
    background: #173247;
    color: #fff !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .client-pagination__step.is-disabled {
    background: #0b1a28;
    color: #6f8496 !important;
}
</style>

<div class="container-fluid px-4 py-4">
    <?php if (!empty($_SESSION['success'])): ?>
        <div class="alert alert-success client-flash client-flash--success alert-dismissible fade show" role="status">
            <span class="client-flash__icon" aria-hidden="true"><i class="fas fa-check"></i></span>
            <div class="client-flash__content">
                <strong>Success</strong>
                <span><?php echo htmlspecialchars((string) $_SESSION['success']); ?></span>
            </div>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
        <?php unset($_SESSION['success']); ?>
    <?php endif; ?>

    <?php if (!empty($_SESSION['error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars((string) $_SESSION['error']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
        <?php unset($_SESSION['error']); ?>
    <?php endif; ?>

    <!-- Page Header -->
    <div class="page-header-modern">
        <div class="d-flex flex-wrap justify-content-between align-items-center">
            <div>
                <h2>
                    <i class="fas fa-building me-2"></i> 
                    Client Management
                </h2>
                <p>Manage client relationships, track progress, and monitor project deadlines</p>
            </div>
        </div>
    </div>

    <!-- Client summary -->
    <div class="client-summary-strip" aria-label="Client summary">
        <div class="client-summary-cell">
            <span class="client-summary-icon" aria-hidden="true"><i class="fas fa-users"></i></span>
            <span class="client-summary-copy">
                <span class="client-summary-label">Total Clients</span>
                <strong class="client-summary-value"><?php echo $totalClients; ?></strong>
            </span>
        </div>
        <div class="client-summary-cell">
            <span class="client-summary-icon" aria-hidden="true"><i class="fas fa-user-check"></i></span>
            <span class="client-summary-copy">
                <span class="client-summary-label">Active Clients</span>
                <strong class="client-summary-value"><?php echo $activeClients; ?></strong>
            </span>
        </div>
        <div class="client-summary-cell">
            <span class="client-summary-icon" aria-hidden="true"><i class="fas fa-hourglass-half"></i></span>
            <span class="client-summary-copy">
                <span class="client-summary-label">Pending</span>
                <strong class="client-summary-value"><?php echo $pendingClients; ?></strong>
            </span>
        </div>
    </div>

    <section class="client-directory-workspace" id="clientDirectoryWorkspace" aria-label="Client directory workspace">
        <!-- Client directory -->
        <div class="main-card">
        <div class="card-header-modern">
            <h5>
                <i class="fas fa-list me-2"></i>
                Client Data
            </h5>
            <div>
                <span class="text-muted small">
                    Showing <?php echo $clientPageStart; ?>&ndash;<?php echo $clientPageEnd; ?> of <?php echo $totalFilteredClients; ?> clients
                </span>
            </div>
        </div>

        <div class="client-tabs" role="tablist" aria-label="Client management views">
            <button type="button" class="client-tab-btn is-active" data-client-tab="clients" role="tab" aria-selected="true">
                <i class="fas fa-users"></i> Clients
            </button>
            <button type="button" class="client-tab-btn" data-client-tab="drafts" role="tab" aria-selected="false">
                <i class="fas fa-file-alt"></i> Drafts
                <span class="client-tab-badge" id="draftTabBadge">1</span>
            </button>
        </div>

        <div class="client-tab-panel client-drafts-panel" data-client-panel="drafts" role="tabpanel" hidden>
            <div class="client-draft-list" id="clientDraftList"></div>
            <div class="client-draft-empty" id="clientDraftEmpty">
                <i class="fas fa-file-alt"></i>
                <strong>No saved client drafts</strong>
                <span>Drafts you save from the Add New Client form will appear here.</span>
            </div>
        </div>

        <div class="client-tab-panel" data-client-panel="clients" role="tabpanel">

        <!-- Filter Section -->
        <div class="filter-section">
            <div class="filter-group">
                <div class="filter-input">
                    <label class="visually-hidden" for="searchInput">Search clients</label>
                    <i class="fas fa-search" aria-hidden="true"></i>
                    <input type="search" id="searchInput" placeholder="Search by name, email, or contact person" value="<?php echo htmlspecialchars($search); ?>" autocomplete="off">
                </div>
                <div class="client-filter-actions">
                    <div class="filter-input">
                        <label class="visually-hidden" for="statusFilter">Filter clients by status</label>
                        <i class="fas fa-filter" aria-hidden="true"></i>
                        <select id="statusFilter">
                            <option value="">All statuses</option>
                            <option value="active" <?php echo $statusFilter == 'active' ? 'selected' : ''; ?>>Active</option>
                            <option value="inactive" <?php echo $statusFilter == 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                            <option value="pending" <?php echo $statusFilter == 'pending' ? 'selected' : ''; ?>>Pending</option>
                        </select>
                    </div>
                    <button type="button" class="btn-add-client-toolbar" data-bs-toggle="modal" data-bs-target="#addClientModal">
                        <i class="fas fa-plus" aria-hidden="true"></i>
                        <span>Add New</span>
                    </button>
                </div>
            </div>
        </div>

        <!-- Clients Table -->
        <div class="table-container">
            <?php if(count($clientRows) > 0): ?>
            <table class="client-table">
                <thead>
                    <tr>
                        <th>Client</th>
                        <th>Contact Person</th>
                        <th>Contact Info</th>
                        <th>Status</th>
                        <th>Projects</th>
                        <th>Actions</th>
                    </tr>
                    </thead>
                <tbody>
    <?php foreach($clientRows as $client): ?>
    <?php 
    $clientId = (int) ($client['id'] ?? 0);
    $clientProjects = $projectsByClient[(int) $client['id']] ?? [];
    $projectPanelId = 'clientProjectsPanel' . $clientId;
    $clientEmail = trim((string) ($client['email'] ?? ''));
    ?>
    <tr class="client-row <?php echo $clientProjects ? 'has-projects' : ''; ?>" <?php echo $clientProjects ? 'data-project-row="' . $projectPanelId . '"' : ''; ?>>
        <td>
            <div class="client-name"><?php echo htmlspecialchars($client['name']); ?></div>
        </td>
        <td><?php echo htmlspecialchars($client['contact_person'] ?: '—'); ?></td>
        <td>
            <?php if($clientEmail !== ''): ?>
                <a class="client-contact-link" href="mailto:<?php echo htmlspecialchars($clientEmail); ?>">
                    <i class="fas fa-envelope" aria-hidden="true"></i>
                    <span><?php echo htmlspecialchars($clientEmail); ?></span>
                </a>
            <?php else: ?>
                <span class="client-contact-empty">Not provided</span>
            <?php endif; ?>
        </td>
        <td>
            <span class="badge-status <?php echo $client['status']; ?>">
                <?php echo ucfirst($client['status']); ?>
            </span>
        </td>
        <td>
            <?php if($clientProjects): ?>
                <button type="button" class="project-row-toggle" aria-expanded="false" aria-controls="<?php echo $projectPanelId; ?>" data-project-toggle="<?php echo $projectPanelId; ?>">
                    <span>
                        <i class="fas fa-folder-open"></i>
                        <?php echo count($clientProjects); ?> project<?php echo count($clientProjects) === 1 ? '' : 's'; ?>
                    </span>
                    <i class="fas fa-chevron-down project-row-chevron"></i>
                </button>
            <?php else: ?>
                <span class="text-muted small">No projects yet</span>
            <?php endif; ?>
        </td>
        <td>
            <div class="action-buttons">
                <details class="client-action-menu">
                    <summary aria-label="Open actions for <?php echo htmlspecialchars((string) $client['name']); ?>" title="Client actions">
                        <i class="fas fa-ellipsis-v" aria-hidden="true"></i>
                    </summary>
                    <div class="client-action-menu__panel">
                        <button type="button" class="client-action-menu__item" onclick="viewClient(<?php echo $client['id']; ?>)">
                            <i class="fas fa-eye" aria-hidden="true"></i>
                            <span>View details</span>
                        </button>
                        <button type="button" class="client-action-menu__item" onclick="editClient(<?php echo htmlspecialchars(json_encode($client)); ?>)">
                            <i class="fas fa-edit" aria-hidden="true"></i>
                            <span>Edit client</span>
                        </button>
                        <button type="button" class="client-action-menu__item" onclick="openAddProjectModal(<?php echo (int) $client['id']; ?>, <?php echo htmlspecialchars(json_encode((string) $client['name'])); ?>)">
                            <i class="fas fa-folder-plus" aria-hidden="true"></i>
                            <span>Add project</span>
                        </button>
                        <button type="button" class="client-action-menu__item client-action-menu__item--danger" onclick="openDeleteClientModal(<?php echo (int) $client['id']; ?>, <?php echo htmlspecialchars(json_encode($client['name'])); ?>)">
                            <i class="fas fa-trash" aria-hidden="true"></i>
                            <span>Delete client</span>
                        </button>
                    </div>
                </details>
            </div>
        </td>
    </tr>
    <?php if($clientProjects): ?>
    <tr class="project-expansion-row" id="<?php echo $projectPanelId; ?>">
        <td colspan="6">
            <div class="project-expansion-shell">
                <div class="project-expansion-inner">
                    <div class="project-expansion-list">
                        <?php foreach($clientProjects as $project): ?>
                            <?php
                            $projectDeadline = (string) ($project['timeline_end'] ?? '');
                            $projectDeadlineTimestamp = $projectDeadline !== '' ? strtotime($projectDeadline) : false;
                            $projectDaysLeft = $projectDeadlineTimestamp !== false ? ceil(($projectDeadlineTimestamp - time()) / (60 * 60 * 24)) : null;
                            $projectProgress = (int) ($project['progress'] ?? 0);
                            $projectIsCompleted = $projectProgress >= 100 || (string) ($project['status'] ?? '') === 'completed';
                            $projectRepository = $projectIsCompleted
                                ? salesClientsDownloadableRepositoryForProject($conn, (int) $project['id'], (int) ($user['id'] ?? 0))
                                : null;
                            $projectIsOverdue = !$projectIsCompleted && $projectDaysLeft !== null && $projectDaysLeft < 0;
                            $projectDisplayStatus = $projectIsCompleted ? 'Completed' : ($projectIsOverdue ? 'Overdue' : ucwords(str_replace('_', ' ', (string) $project['status'])));
                            $projectDeadlineClass = $projectIsOverdue || ($projectDaysLeft !== null && $projectDaysLeft <= 7) ? 'urgent' : (($projectDaysLeft !== null && $projectDaysLeft <= 30) ? 'warning' : 'normal');
                            ?>
                            <div class="project-expansion-item">
                                <div class="project-expansion-main">
                                    <strong><?php echo htmlspecialchars((string) $project['name']); ?></strong>
                                    <span class="project-status-pill <?php echo $projectIsOverdue ? 'badge-status overdue' : ''; ?>"><?php echo htmlspecialchars($projectDisplayStatus); ?></span>
                                    <?php if($projectDeadlineTimestamp !== false && !$projectIsCompleted): ?>
                                        <span class="deadline-days <?php echo $projectDeadlineClass; ?>">
                                            <?php echo date('M d, Y', $projectDeadlineTimestamp); ?>
                                            <?php if($projectDaysLeft !== null): ?>
                                                (<?php echo $projectIsOverdue ? abs($projectDaysLeft) . ' days overdue' : $projectDaysLeft . ' days left'; ?>)
                                            <?php endif; ?>
                                        </span>
                                    <?php elseif($projectIsCompleted): ?>
                                        <span class="text-muted small">Delivery completed</span>
                                    <?php else: ?>
                                        <span class="text-muted small">No deadline</span>
                                    <?php endif; ?>
                                </div>
                                <div class="project-expansion-progress">
                                    <div class="project-progress-track" role="progressbar" aria-valuenow="<?php echo $projectProgress; ?>" aria-valuemin="0" aria-valuemax="100"><div class="project-progress-fill" style="width:<?php echo $projectProgress; ?>%"></div></div>
                                    <span class="project-progress-pill"><?php echo $projectProgress; ?>% Complete</span>
                                </div>
                                <div class="project-team-notify<?php echo $projectIsCompleted ? ' is-completed' : ''; ?>">
                                    <?php if (!$projectIsCompleted): ?>
                                        <input type="text" maxlength="500" placeholder="Message the responsible team" aria-label="Team notification for <?php echo htmlspecialchars((string) $project['name']); ?>">
                                        <button class="btn btn-outline-primary btn-sm" type="button" data-notify-project-team="<?php echo (int) $project['id']; ?>"><i class="fas fa-bell me-1"></i> Notify Team</button>
                                    <?php endif; ?>
                                    <?php if ($projectRepository && (int) ($projectRepository['repository_id'] ?? 0) > 0): ?>
                                        <a
                                            class="btn btn-success btn-sm project-download-button"
                                            href="<?php echo htmlspecialchars(BASE_URL . 'api/version-control/download-repository.php?repository_id=' . (int) $projectRepository['repository_id']); ?>"
                                            title="Download <?php echo htmlspecialchars((string) ($projectRepository['default_branch_name'] ?? 'main')); ?> branch for <?php echo htmlspecialchars((string) $project['name']); ?>"
                                        >
                                            <i class="fas fa-download me-1"></i> Download
                                        </a>
                                    <?php endif; ?>
                                </div>
                                <div class="project-expansion-actions">
                                    <button
                                        type="button"
                                        class="btn btn-outline-danger btn-sm project-delete-button"
                                        title="Delete project"
                                        aria-label="Delete <?php echo htmlspecialchars((string) $project['name']); ?>"
                                        onclick="openDeleteProjectModal(<?php echo (int) $project['id']; ?>, <?php echo htmlspecialchars(json_encode((string) $project['name'])); ?>)"
                                    >
                                        <i class="fas fa-trash" aria-hidden="true"></i>
                                    </button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </td>
    </tr>
    <?php endif; ?>
    <?php endforeach; ?>
</tbody>
            </table>
            <?php else: ?>
            <div class="empty-state-modern">
                <i class="fas fa-building"></i>
                <h4>No Clients Found</h4>
                <p>Try adjusting your filters or add a new client</p>
                <button class="btn-add-modern mt-2" data-bs-toggle="modal" data-bs-target="#addClientModal">
                    <i class="fas fa-plus me-2"></i> Add Your First Client
                </button>
            </div>
            <?php endif; ?>
        </div>
        <?php if ($totalFilteredClients > 0): ?>
        <div class="client-pagination" aria-label="Client directory pagination">
            <div class="client-pagination__meta">
                <span class="client-pagination__summary">
                    <?php echo $clientPageStart; ?>&ndash;<?php echo $clientPageEnd; ?> of <?php echo $totalFilteredClients; ?>
                </span>
                <label class="visually-hidden" for="clientPageSize">Clients per page</label>
                <select class="client-pagination__size" id="clientPageSize" aria-label="Clients per page">
                    <?php foreach ($allowedClientPageSizes as $pageSizeOption): ?>
                    <option value="<?php echo $pageSizeOption; ?>" <?php echo $pageSizeOption === $clientPageSize ? 'selected' : ''; ?>>
                        <?php echo $pageSizeOption; ?> items
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <nav class="client-pagination__controls" aria-label="Client pages">
                <?php if ($clientCurrentPage > 1): ?>
                    <a class="client-pagination__step" href="<?php echo htmlspecialchars($buildClientPageUrl($clientCurrentPage - 1)); ?>">Previous</a>
                <?php else: ?>
                    <span class="client-pagination__step is-disabled" aria-disabled="true">Previous</span>
                <?php endif; ?>
                <span class="client-pagination__pages">
                    <?php foreach ($clientPaginationPages as $pageNumber): ?>
                    <a
                        class="client-pagination__page<?php echo $pageNumber === $clientCurrentPage ? ' is-current' : ''; ?>"
                        href="<?php echo htmlspecialchars($buildClientPageUrl($pageNumber)); ?>"
                        <?php echo $pageNumber === $clientCurrentPage ? 'aria-current="page"' : ''; ?>
                    ><?php echo $pageNumber; ?></a>
                    <?php endforeach; ?>
                </span>
                <?php if ($clientCurrentPage < $clientTotalPages): ?>
                    <a class="client-pagination__step" href="<?php echo htmlspecialchars($buildClientPageUrl($clientCurrentPage + 1)); ?>">Next</a>
                <?php else: ?>
                    <span class="client-pagination__step is-disabled" aria-disabled="true">Next</span>
                <?php endif; ?>
            </nav>
        </div>
        <?php endif; ?>
        </div>
    </div>
    </section>
</div>

<!-- Add Client Modal (same content as before) -->
<div class="modal fade modal-modern add-client-modal" id="addClientModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false" data-bs-focus="false">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-user-plus me-2"></i> Add New Client
                </h5>
            </div>
            <form method="POST" id="addClientForm" enctype="multipart/form-data" novalidate data-generic-draft-disabled="1" data-app-loading data-loading-title="Sending to Architect" data-loading-text="Please wait while the qualified client intake, project folder, and Architect notification are saved.">
                <input type="hidden" name="add_client" value="1">
                <input type="hidden" name="client_draft_id" data-client-draft-id value="">
                <div class="modal-body">
                    <div class="add-client-stepper" aria-label="Add client progress">
                        <div class="stepper-tooltip-wrap">
                            <button type="button" class="stepper-item is-active" data-step-target="0" aria-current="step" disabled>
                                <span>1</span>
                                <strong>Client Profile</strong>
                            </button>
                            <button type="button" class="stepper-info" data-bs-toggle="tooltip" data-bs-placement="bottom" data-bs-container="body" title="Enter the client's company and contact details." aria-label="About Client Profile">
                                <i class="fas fa-info" aria-hidden="true"></i>
                            </button>
                        </div>
                        <div class="stepper-tooltip-wrap">
                            <button type="button" class="stepper-item" data-step-target="1" disabled>
                                <span>2</span>
                                <strong>Project Intake</strong>
                            </button>
                            <button type="button" class="stepper-info" data-bs-toggle="tooltip" data-bs-placement="bottom" data-bs-container="body" title="Capture the project scope, requested features, meeting notes, requirements, priority, and attachments." aria-label="About Project Intake">
                                <i class="fas fa-info" aria-hidden="true"></i>
                            </button>
                        </div>
                        <div class="stepper-tooltip-wrap">
                            <button type="button" class="stepper-item" data-step-target="2" disabled>
                                <span>3</span>
                                <strong>Timeline & Budget</strong>
                            </button>
                            <button type="button" class="stepper-info" data-bs-toggle="tooltip" data-bs-placement="bottom" data-bs-container="body" title="Set the schedule and budget, then review the automatically generated project complexity." aria-label="About Timeline and Budget">
                                <i class="fas fa-info" aria-hidden="true"></i>
                            </button>
                        </div>
                        <div class="stepper-tooltip-wrap">
                            <button type="button" class="stepper-item" data-step-target="3" disabled>
                                <span>4</span>
                                <strong>Review</strong>
                            </button>
                            <button type="button" class="stepper-info" data-bs-toggle="tooltip" data-bs-placement="bottom" data-bs-container="body" title="Confirm the client and project information before sending it for Architect review." aria-label="About Review">
                                <i class="fas fa-info" aria-hidden="true"></i>
                            </button>
                        </div>
                    </div>

                    <div class="architect-handoff-note">
                        <i class="fas fa-clipboard-check" aria-hidden="true"></i>
                        <span>Fields marked <strong>*</strong> are required before the project can be sent to Architect. Additional requirements and other fields marked optional may be left blank.</span>
                    </div>

                    <div class="add-client-layout">
                        <div class="field-group add-client-step" data-client-step="0">
                            <div class="section-title"><i class="fas fa-id-card"></i> Client Profile</div>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label">Company Name<span class="required-mark">*</span></label>
                                    <input type="text" name="name" class="form-control" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Contact Person<span class="required-mark">*</span></label>
                                    <input type="text" name="contact_person" class="form-control" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Email<span class="required-mark">*</span></label>
                                    <input type="email" name="email" class="form-control" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Phone<span class="required-mark">*</span></label>
                                    <input type="text" name="phone" class="form-control" required>
                                </div>
                                <div class="col-12">
                                    <label class="form-label">Address</label>
                                    <textarea name="address" class="form-control" rows="2"></textarea>
                                </div>
                            </div>
                        </div>

                        <div class="field-group add-client-step project-intake-step" data-client-step="1" hidden>
                            <div class="section-title"><i class="fas fa-clipboard-list"></i> Project Intake</div>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label">Project Name<span class="required-mark">*</span></label>
                                    <input type="text" name="project_name" class="form-control" required>
                                </div>
                                <div class="col-md-6 template-field-shell template-recommendation-shell">
                                    <label class="form-label">Project Type<span class="required-mark">*</span></label>
                                    <div class="template-type-picker" data-template-picker>
                                        <input type="text" name="project_type" class="form-control" placeholder="e.g. E-commerce Website" required autocomplete="off" data-template-picker-input>
                                        <div class="template-suggestions-menu" data-template-picker-menu hidden></div>
                                    </div>
                                    <select name="project_template_id" class="form-select mt-2" data-template-version-select hidden aria-label="Template version"></select>
                                    <button type="button" class="template-recommendation-insight-trigger" data-template-recommendation data-client-template-insight-trigger aria-label="Show project template details" aria-expanded="false">
                                        <i class="fas fa-lightbulb" aria-hidden="true"></i>
                                    </button>
                                    <div class="template-recommendation-insight-popover" data-client-template-recommendation hidden>
                                        <strong>Project template search</strong>
                                        <span>Choose a project type to apply saved template details.</span>
                                    </div>
                                </div>
                                <div class="col-12">
                                    <div class="add-project-intake-columns">
                                        <div class="add-project-intake-column">
                                            <div>
                                                <label class="form-label">Project Description<span class="required-mark">*</span></label>
                                                <textarea name="project_description" class="form-control" rows="3" placeholder="Short overview of the requested system or solution" required></textarea>
                                            </div>
                                             <div>
                                                 <label class="form-label">Meeting Notes</label>
                                                 <textarea name="meeting_notes" class="form-control" rows="3" placeholder="Important notes from discovery meetings"></textarea>
                                             </div>
                                             <div>
                                                 <label class="form-label">Minutes of the Meeting (MOM)</label>
                                                 <textarea name="mom" class="form-control" rows="3">Topic:
Date:
Company Name:
Location:
Attendees:</textarea>
                                             </div>
                                         </div>
                                        <div class="add-project-intake-column">
                                            <div>
                                                <label class="form-label">Requested System Features<span class="required-mark">*</span></label>
                                                <div class="requested-feature-builder">
                                                    <input type="hidden" name="requested_features" id="requestedFeaturesField">
                                                    <div class="requested-feature-chip-shell" data-client-feature-shell>
                                                        <div class="requested-feature-list requested-feature-chip-editor" id="requestedFeatureList" role="group" aria-label="Requested system features">
                                                            <input type="text" class="requested-feature-chip-input" data-requested-feature-input placeholder="Type a feature" aria-label="New requested feature">
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                         </div>
                                    </div>
                                </div>
                                <div class="col-12">
                                    <label class="form-label">Additional Requirements <span class="optional-label">(Optional)</span></label>
                                    <textarea name="additional_requirements" class="form-control" rows="3" placeholder="Constraints, integrations, priorities, compliance needs, or extra requests"></textarea>
                                </div>
                                <div class="col-sm-6 priority-field-shell add-client-priority-insight-shell">
                                    <label class="form-label">Priority</label>
                                    <div class="priority-select-wrap">
                                        <select name="priority" class="form-select">
                                            <option value="low">Low</option>
                                            <option value="medium" selected>Medium</option>
                                            <option value="high">High</option>
                                            <option value="critical">Critical</option>
                                        </select>
                                        <button type="button" class="priority-insight-trigger" data-priority-insight-trigger aria-label="View priority impact" aria-expanded="false">
                                            <i class="fas fa-lightbulb" aria-hidden="true"></i>
                                        </button>
                                        <div class="priority-insight-popover" data-priority-insight-popover hidden></div>
                                    </div>
                                </div>
                                <div class="col-sm-6 add-client-attachment-shell">
                                    <label class="form-label">Attachments <span class="optional-label">(Optional)</span></label>
                                    <div class="attachment-upload-field">
                                        <label for="addClientAttachments" class="attachment-upload-button">
                                            <i class="fas fa-paperclip" aria-hidden="true"></i>
                                            <span>Choose Files</span>
                                        </label>
                                        <span class="attachment-upload-summary" data-attachment-summary>No file chosen</span>
                                        <input id="addClientAttachments" type="file" name="attachments[]" class="visually-hidden" multiple data-attachment-input>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="field-group add-client-step timeline-budget-step" data-client-step="2" hidden>
                            <div class="section-title"><i class="fas fa-calendar-check"></i> Timeline & Budget</div>
                            <div class="timeline-budget-grid">
                                <div class="timeline-budget-card timeline-budget-card--wide">
                                    <div class="timeline-budget-card-title"><i class="fas fa-clock"></i> Delivery Window</div>
                                    <div class="timeline-date-grid">
                                        <div>
                                            <label class="form-label">Timeline Start</label>
                                            <input type="date" name="timeline_start" class="form-control">
                                        </div>
                                        <div>
                                            <label class="form-label">Start Time<span class="required-mark">*</span></label>
                                            <input type="time" name="timeline_start_time" class="form-control" required>
                                        </div>
                                        <div>
                                            <label class="form-label">Project Deadline</label>
                                            <input type="date" name="timeline_end" class="form-control">
                                        </div>
                                        <div>
                                            <label class="form-label">Deadline Time</label>
                                            <input type="time" name="timeline_end_time" class="form-control">
                                        </div>
                                    </div>
                                </div>
                                <div class="timeline-budget-card">
                                    <div class="timeline-budget-card-title"><i class="fas fa-coins"></i> Budget Baseline</div>
                                    <label class="form-label">Budget<span class="required-mark">*</span></label>
                                    <input type="number" name="sales_budget" class="form-control" min="0.01" step="0.01" inputmode="decimal" placeholder="e.g. 1500000.00" required>
                                    <div class="mini-note" data-budget-helper>Required as the decision insight budget baseline.</div>
                                </div>
                                <div class="timeline-budget-card complexity-field-shell">
                                    <button type="button" class="complexity-insight-trigger" data-complexity-insight-trigger aria-label="Show generated complexity explanation" aria-expanded="false">
                                        <i class="fas fa-lightbulb" aria-hidden="true"></i>
                                    </button>
                                    <div class="complexity-insight-popover" data-complexity-popover hidden>
                                        <strong>Low complexity</strong>
                                        <span>Calculated automatically from budget, timeline, and priority.</span>
                                    </div>
                                    <div class="timeline-budget-card-title"><i class="fas fa-layer-group"></i> Complexity Signal</div>
                                    <label class="form-label">Generated Complexity</label>
                                    <input type="text" class="form-control" data-generated-complexity readonly value="Low">
                                    <div class="calculation-note" data-complexity-reason>
                                        <strong>Low complexity</strong>
                                        <span>Calculated automatically from budget, timeline, and priority.</span>
                                    </div>
                                </div>
                                <div class="timeline-budget-card timeline-budget-card--wide">
                                    <div class="timeline-budget-card-title"><i class="fas fa-chart-line"></i> Priority Pricing</div>
                                    <div class="calculation-note" data-priority-price-preview>
                                        <strong>Priority price preview</strong>
                                        <span>Enter a budget to preview the added priority price during client intake. Final pricing is recalculated later from the Expert project cost estimate.</span>
                                    </div>
                                </div>
                                <div class="timeline-budget-card timeline-budget-card--wide">
                                    <div class="timeline-budget-card-title"><i class="fas fa-note-sticky"></i> Architect Handoff Notes</div>
                                    <label class="form-label">Sales Notes <span class="optional-label">(Optional)</span></label>
                                    <textarea name="sales_notes" class="form-control" rows="3" placeholder="Internal notes for Architect review"></textarea>
                                </div>
                                <div class="timeline-budget-card timeline-budget-card--wide">
                                    <div class="info-card">
                                        <span>Outcome</span>
                                        <strong>Creates the client and sends the project to Architect review</strong>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="field-group add-client-step review-step" data-client-step="3" hidden>
                            <div class="section-title"><i class="fas fa-clipboard-check"></i> Final Review</div>
                            <div class="review-summary" data-client-summary>
                                <div class="review-ready-banner">
                                    <span class="review-ready-icon"><i class="fas fa-check"></i></span>
                                    <div class="review-ready-copy">
                                        <strong>Ready for confirmation</strong>
                                        <span>Review the intake details below before handing the project to the Architect.</span>
                                    </div>
                                    <span class="review-ready-badge"><i class="fas fa-check-circle"></i> Intake complete</span>
                                </div>

                                <div class="review-primary-grid">
                                    <section class="review-card review-card--client-profile" aria-labelledby="reviewClientHeading">
                                        <header class="review-card-header">
                                            <span class="review-card-icon"><i class="fas fa-building"></i></span>
                                            <div class="review-card-heading">
                                                <h6 id="reviewClientHeading">Client Profile</h6>
                                                <span>Primary account and contact</span>
                                            </div>
                                        </header>
                                        <div class="review-highlight">
                                            <span>Company</span>
                                            <strong class="review-summary-value" data-summary-field="name">Not provided</strong>
                                        </div>
                                        <div class="review-data-grid">
                                            <div class="review-data-item review-data-item--wide">
                                                <span class="review-data-label">Contact person</span>
                                                <span class="review-summary-value" data-summary-field="contact_person">Not provided</span>
                                            </div>
                                            <div class="review-data-item">
                                                <span class="review-data-label">Email</span>
                                                <span class="review-summary-value" data-summary-field="email">Not provided</span>
                                            </div>
                                            <div class="review-data-item">
                                                <span class="review-data-label">Phone</span>
                                                <span class="review-summary-value" data-summary-field="phone">Not provided</span>
                                            </div>
                                            <div class="review-data-item review-data-item--wide">
                                                <span class="review-data-label">Address</span>
                                                <span class="review-summary-value" data-summary-field="address">Not provided</span>
                                            </div>
                                        </div>
                                    </section>

                                    <section class="review-card" aria-labelledby="reviewProjectHeading">
                                        <header class="review-card-header">
                                            <span class="review-card-icon"><i class="fas fa-folder-open"></i></span>
                                            <div class="review-card-heading">
                                                <h6 id="reviewProjectHeading">Project Overview</h6>
                                                <span>Commercial and delivery snapshot</span>
                                            </div>
                                        </header>
                                        <div class="review-highlight">
                                            <span>Project</span>
                                            <strong class="review-summary-value" data-summary-field="project_name">Not provided</strong>
                                        </div>
                                        <div class="review-chip-row">
                                            <span class="review-chip" data-summary-chip="priority"><i class="fas fa-flag"></i><span data-summary-field="priority">Medium</span></span>
                                            <span class="review-chip" data-summary-chip="generated_complexity"><i class="fas fa-layer-group"></i><span data-summary-field="generated_complexity">Low</span> complexity</span>
                                        </div>
                                        <div class="review-key-grid">
                                            <div class="review-key-item">
                                                <span class="review-key-label">Project type</span>
                                                <span class="review-key-value review-summary-value" data-summary-field="project_type">Not provided</span>
                                            </div>
                                            <div class="review-key-item review-key-item--wide">
                                                <span class="review-key-label">Delivery timeline</span>
                                                <span class="review-key-value review-summary-value" data-summary-field="timeline">Not set</span>
                                            </div>
                                            <div class="review-key-item">
                                                <span class="review-key-label">Budget</span>
                                                <span class="review-key-value review-summary-value" data-summary-field="sales_budget">Not provided</span>
                                            </div>
                                            <div class="review-key-item">
                                                <span class="review-key-label">Scope size</span>
                                                <span class="review-key-value review-summary-value" data-summary-field="requested_feature_count">0 features</span>
                                            </div>
                                            <div class="review-key-item review-key-item--wide">
                                                <span class="review-key-label">Priority price preview</span>
                                                <span class="review-key-value review-summary-value" data-summary-field="priority_markup_detail">Not provided</span>
                                            </div>
                                            <div class="review-key-item review-key-item--wide">
                                                <span class="review-key-label">Complexity reason</span>
                                                <span class="review-key-value review-summary-value" data-summary-field="complexity_reason">Not provided</span>
                                            </div>
                                            <div class="review-key-item review-key-item--wide">
                                                <span class="review-key-label">Attachments</span>
                                                <span class="review-key-value review-summary-value" data-summary-field="attachments">No attachments</span>
                                            </div>
                                        </div>
                                    </section>
                                </div>

                                <section class="review-card" aria-labelledby="reviewRequirementsHeading">
                                    <header class="review-card-header">
                                        <span class="review-card-icon"><i class="fas fa-tasks"></i></span>
                                        <div class="review-card-heading">
                                            <h6 id="reviewRequirementsHeading">Requirements &amp; Scope</h6>
                                            <span>What the Architect will receive for evaluation</span>
                                        </div>
                                    </header>
                                    <div class="review-requirements-grid">
                                        <div class="review-block">
                                            <span class="review-block-label">Project description</span>
                                            <span class="review-summary-value" data-summary-field="project_description">Not provided</span>
                                        </div>
                                        <div class="review-block">
                                            <span class="review-block-label">Requested features</span>
                                            <ul class="review-feature-list" data-summary-feature-list>
                                                <li class="is-empty">Not provided</li>
                                            </ul>
                                        </div>
                                        <div class="review-block review-block--wide">
                                            <span class="review-block-label">Additional requirements</span>
                                            <span class="review-summary-value" data-summary-field="additional_requirements">Not provided</span>
                                        </div>
                                    </div>
                                </section>

                                <section class="review-card" aria-labelledby="reviewNotesHeading">
                                    <header class="review-card-header">
                                        <span class="review-card-icon"><i class="fas fa-note-sticky"></i></span>
                                        <div class="review-card-heading">
                                            <h6 id="reviewNotesHeading">Supporting Notes</h6>
                                            <span>Discovery context and internal handoff notes</span>
                                        </div>
                                    </header>
                                    <div class="review-notes-grid">
                                        <div class="review-note-item">
                                            <span class="review-note-label">Meeting notes</span>
                                            <span class="review-summary-value" data-summary-field="meeting_notes">Not provided</span>
                                        </div>
                                        <div class="review-note-item">
                                            <span class="review-note-label">Minutes of meeting</span>
                                            <span class="review-summary-value" data-summary-field="mom">Not provided</span>
                                        </div>
                                        <div class="review-note-item">
                                            <span class="review-note-label">Internal sales notes</span>
                                            <span class="review-summary-value" data-summary-field="sales_notes">Not provided</span>
                                        </div>
                                    </div>
                                </section>

                                <div class="review-handoff">
                                    <i class="fas fa-paper-plane"></i>
                                    <div class="review-handoff-copy">
                                        <strong>What happens after submission?</strong>
                                        <span>The client record and shared project folder are created, then the Architect is notified to begin project review.</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                    <div class="modal-footer add-client-footer">
                        <span class="draft-status" data-draft-status>Draft not started</span>
                        <div class="add-client-actions">
                            <button type="button" class="btn btn-outline-secondary add-client-close" data-bs-dismiss="modal" aria-label="Cancel and close Add New Client">Cancel</button>
                            <button type="button" class="btn btn-outline-secondary" data-client-prev hidden>Previous</button>
                            <button type="button" class="btn-add-modern" data-client-next>Next</button>
                            <button type="submit" name="add_client" class="btn-add-modern" data-client-submit hidden><i class="fas fa-paper-plane me-1"></i> Add &amp; Send to Architect</button>
                        </div>
                        <div class="unsaved-close-panel" data-unsaved-close-panel>
                            <div class="unsaved-close-panel__text">
                                <strong>Save this client intake before closing?</strong>
                                <span>You have entered details that are not saved yet.</span>
                            </div>
                            <div class="unsaved-close-panel__actions">
                                <button type="button" class="btn btn-light" data-keep-editing>Keep Editing</button>
                                <button type="button" class="btn btn-outline-danger" data-discard-unsaved>Discard</button>
                            </div>
                        </div>
                        </div>
                    </div>
                    <div class="dss-template-review-overlay" data-template-review-overlay hidden aria-hidden="true"></div>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Add Project Modal -->
<div class="modal fade modal-modern add-client-modal add-project-modal" id="addProjectModal" tabindex="-1" data-bs-focus="false">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-folder-plus me-2"></i> Add Project
                </h5>
            </div>
            <form method="POST" id="addProjectForm" enctype="multipart/form-data" novalidate data-app-loading data-loading-title="Adding project" data-loading-text="Please wait while the project is added and sent to Architect review.">
                <input type="hidden" name="add_project" value="1">
                <input type="hidden" name="client_id" id="add_project_client_id">
                <div class="modal-body">
                    <div class="add-client-stepper" aria-label="Add project progress">
                        <div class="stepper-tooltip-wrap">
                            <button type="button" class="stepper-item is-active" data-add-project-step-target="0" aria-current="step" disabled>
                                <span>1</span>
                                <strong>Project Intake</strong>
                            </button>
                            <button type="button" class="stepper-info" data-bs-toggle="tooltip" data-bs-placement="bottom" data-bs-container="body" title="Capture the project scope, requested features, meeting notes, requirements, priority, and attachments." aria-label="About Project Intake">
                                <i class="fas fa-info" aria-hidden="true"></i>
                            </button>
                        </div>
                        <div class="stepper-tooltip-wrap">
                            <button type="button" class="stepper-item" data-add-project-step-target="1" disabled>
                                <span>2</span>
                                <strong>Timeline &amp; Budget</strong>
                            </button>
                            <button type="button" class="stepper-info" data-bs-toggle="tooltip" data-bs-placement="bottom" data-bs-container="body" title="Set the schedule and budget, then review the automatically generated project complexity." aria-label="About Timeline and Budget">
                                <i class="fas fa-info" aria-hidden="true"></i>
                            </button>
                        </div>
                        <div class="stepper-tooltip-wrap">
                            <button type="button" class="stepper-item" data-add-project-step-target="2" disabled>
                                <span>3</span>
                                <strong>Review</strong>
                            </button>
                            <button type="button" class="stepper-info" data-bs-toggle="tooltip" data-bs-placement="bottom" data-bs-container="body" title="Confirm the project information before sending it for Architect review." aria-label="About Review">
                                <i class="fas fa-info" aria-hidden="true"></i>
                            </button>
                        </div>
                    </div>

                    <div class="architect-handoff-note">
                        <i class="fas fa-building" aria-hidden="true"></i>
                        <span>Adding project for <strong id="addProjectClientName">Selected client</strong>. Fields marked <strong>*</strong> are required before the project can be sent to Architect.</span>
                    </div>

                    <div class="add-client-layout">
                        <div class="field-group add-client-step" data-add-project-step="0">
                            <div class="section-title"><i class="fas fa-clipboard-list"></i> Project Intake</div>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label">Project Name<span class="required-mark">*</span></label>
                                    <input type="text" name="project_name" class="form-control" required>
                                </div>
                                <div class="col-md-6 template-recommendation-shell">
                                    <label class="form-label">Project Type<span class="required-mark">*</span></label>
                                    <div class="template-type-picker" data-template-picker>
                                        <input type="text" name="project_type" class="form-control" placeholder="e.g. E-commerce Website" required autocomplete="off" data-template-picker-input>
                                        <div class="template-suggestions-menu" data-template-picker-menu hidden></div>
                                    </div>
                                    <select name="project_template_id" class="form-select mt-2" data-template-version-select hidden aria-label="Template version"></select>
                                    <button type="button" class="template-recommendation-insight-trigger" data-add-project-template-insight-trigger aria-label="Show project template details" aria-expanded="false">
                                        <i class="fas fa-lightbulb" aria-hidden="true"></i>
                                    </button>
                                    <div class="template-recommendation-insight-popover" data-add-project-template-recommendation hidden>
                                        <strong>Project template search</strong>
                                        <span>Choose a project type to apply saved template details.</span>
                                    </div>
                                </div>
                                <div class="col-12">
                                    <div class="add-project-intake-columns">
                                        <div class="add-project-intake-column">
                                            <div>
                                                <label class="form-label">Project Description<span class="required-mark">*</span></label>
                                                <textarea name="project_description" class="form-control" rows="3" placeholder="Short overview of the requested system or solution" required></textarea>
                                            </div>
                                             <div>
                                                 <label class="form-label">Meeting Notes</label>
                                                 <textarea name="meeting_notes" class="form-control" rows="3" placeholder="Important notes from discovery meetings"></textarea>
                                             </div>
                                             <div>
                                                 <label class="form-label">Minutes of the Meeting (MOM)</label>
                                                 <textarea name="mom" class="form-control" rows="3">Topic:
Date:
Company Name:
Location:
Attendees:</textarea>
                                             </div>
                                         </div>
                                        <div class="add-project-intake-column">
                                            <div>
                                                <label class="form-label">Requested System Features<span class="required-mark">*</span></label>
                                                <div class="requested-feature-builder">
                                                    <input type="hidden" name="requested_features" id="addProjectRequestedFeaturesField">
                                                    <div class="requested-feature-chip-shell" data-add-project-feature-shell>
                                                        <div class="requested-feature-list requested-feature-chip-editor" id="addProjectRequestedFeatureList" role="group" aria-label="Requested system features">
                                                            <input type="text" class="requested-feature-chip-input" data-add-project-feature-input placeholder="Type a feature" aria-label="New requested feature">
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                         </div>
                                    </div>
                                </div>
                                <div class="col-12">
                                    <label class="form-label">Additional Requirements <span class="optional-label">(Optional)</span></label>
                                    <textarea name="additional_requirements" class="form-control" rows="3" placeholder="Constraints, integrations, priorities, compliance needs, or extra requests"></textarea>
                                </div>
                                <div class="col-sm-6 add-project-priority-insight-shell">
                                    <label class="form-label">Priority</label>
                                    <select name="priority" class="form-select">
                                        <option value="low">Low</option>
                                        <option value="medium" selected>Medium</option>
                                        <option value="high">High</option>
                                        <option value="critical">Critical</option>
                                    </select>
                                    <button type="button" class="add-project-priority-insight-trigger" data-add-project-priority-insight-trigger aria-label="Show priority impact" aria-expanded="false">
                                        <i class="fas fa-lightbulb" aria-hidden="true"></i>
                                    </button>
                                    <div class="add-project-priority-insight-popover" data-add-project-priority-markup-preview hidden>
                                        <strong>Medium priority adds +10%</strong>
                                        <span>Requires more planning, testing, and attention than the standard development process.</span>
                                    </div>
                                </div>
                                <div class="col-sm-6">
                                    <label class="form-label">Attachments <span class="optional-label">(Optional)</span></label>
                                    <input type="file" name="project_attachments[]" class="form-control" multiple>
                                </div>
                            </div>
                        </div>

                        <div class="field-group add-client-step" data-add-project-step="1" hidden>
                            <div class="section-title"><i class="fas fa-calendar-check"></i> Timeline &amp; Budget</div>
                            <div class="row g-3">
                                <div class="col-md-3">
                                    <label class="form-label">Timeline Start</label>
                                    <input type="date" name="timeline_start" class="form-control">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Start Time<span class="required-mark">*</span></label>
                                    <input type="time" name="timeline_start_time" class="form-control" required>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Project Deadline</label>
                                    <input type="date" name="timeline_end" class="form-control">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Deadline Time</label>
                                    <input type="time" name="timeline_end_time" class="form-control">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Budget<span class="required-mark">*</span></label>
                                    <input type="number" name="sales_budget" class="form-control" min="0.01" step="0.01" inputmode="decimal" placeholder="e.g. 1500000.00" required>
                                    <div class="mini-note" data-add-project-budget-helper>Required as the decision insight budget baseline.</div>
                                </div>
                                <div class="col-md-6 complexity-field-shell">
                                    <button type="button" class="complexity-insight-trigger" data-complexity-insight-trigger aria-label="Show generated complexity explanation" aria-expanded="false">
                                        <i class="fas fa-lightbulb" aria-hidden="true"></i>
                                    </button>
                                    <div class="complexity-insight-popover" data-complexity-popover hidden>
                                        <strong>Low complexity</strong>
                                        <span>Calculated automatically from budget, timeline, and priority.</span>
                                    </div>
                                    <label class="form-label">Generated Complexity</label>
                                    <input type="text" class="form-control" data-add-project-generated-complexity readonly value="Low">
                                    <div class="calculation-note" data-add-project-complexity-reason>
                                        <strong>Low complexity</strong>
                                        <span>Calculated automatically from budget, timeline, and priority.</span>
                                    </div>
                                </div>
                                <div class="col-12">
                                    <div class="calculation-note" data-add-project-priority-price-preview>
                                        <strong>Priority price preview</strong>
                                        <span>Enter a budget to preview the added priority price during project intake. Final pricing is recalculated later from the Expert project cost estimate.</span>
                                    </div>
                                </div>
                                <div class="col-12">
                                    <label class="form-label">Sales Notes <span class="optional-label">(Optional)</span></label>
                                    <textarea name="sales_notes" class="form-control" rows="3" placeholder="Internal notes for Architect review"></textarea>
                                </div>
                                <div class="col-md-6">
                                    <div class="info-card">
                                        <span>Outcome</span>
                                        <strong>Adds this project to the selected client and sends it to Architect review</strong>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="field-group add-client-step review-step" data-add-project-step="2" hidden>
                            <div class="section-title"><i class="fas fa-clipboard-check"></i> Final Review</div>
                            <div class="review-summary">
                                <div class="review-ready-banner">
                                    <span class="review-ready-icon"><i class="fas fa-check"></i></span>
                                    <div class="review-ready-copy">
                                        <strong>Ready for confirmation</strong>
                                        <span>Review the project intake details before handing it to the Architect.</span>
                                    </div>
                                    <span class="review-ready-badge"><i class="fas fa-check-circle"></i> Intake complete</span>
                                </div>

                                <div class="review-primary-grid add-project-review-grid">
                                    <section class="review-card" aria-labelledby="addProjectReviewProjectHeading">
                                        <header class="review-card-header">
                                            <span class="review-card-icon"><i class="fas fa-folder-open"></i></span>
                                            <div class="review-card-heading">
                                                <h6 id="addProjectReviewProjectHeading">Project Overview</h6>
                                                <span>Commercial and delivery snapshot</span>
                                            </div>
                                        </header>
                                        <div class="review-highlight">
                                            <span>Project</span>
                                            <strong class="review-summary-value" data-add-project-summary-field="project_name">Not provided</strong>
                                        </div>
                                        <div class="review-chip-row">
                                            <span class="review-chip" data-add-project-summary-chip="priority"><i class="fas fa-flag"></i><span data-add-project-summary-field="priority">Medium</span></span>
                                            <span class="review-chip" data-add-project-summary-chip="generated_complexity"><i class="fas fa-layer-group"></i><span data-add-project-summary-field="generated_complexity">Low</span> complexity</span>
                                        </div>
                                        <div class="review-key-grid">
                                            <div class="review-key-item">
                                                <span class="review-key-label">Project type</span>
                                                <span class="review-key-value review-summary-value" data-add-project-summary-field="project_type">Not provided</span>
                                            </div>
                                            <div class="review-key-item review-key-item--wide">
                                                <span class="review-key-label">Delivery timeline</span>
                                                <span class="review-key-value review-summary-value" data-add-project-summary-field="timeline">Not set</span>
                                            </div>
                                            <div class="review-key-item">
                                                <span class="review-key-label">Budget</span>
                                                <span class="review-key-value review-summary-value" data-add-project-summary-field="sales_budget">Not provided</span>
                                            </div>
                                            <div class="review-key-item">
                                                <span class="review-key-label">Scope size</span>
                                                <span class="review-key-value review-summary-value" data-add-project-summary-field="requested_feature_count">0 features</span>
                                            </div>
                                            <div class="review-key-item review-key-item--wide">
                                                <span class="review-key-label">Priority price preview</span>
                                                <span class="review-key-value review-summary-value" data-add-project-summary-field="priority_markup_detail">Not provided</span>
                                            </div>
                                            <div class="review-key-item review-key-item--wide">
                                                <span class="review-key-label">Complexity reason</span>
                                                <span class="review-key-value review-summary-value" data-add-project-summary-field="complexity_reason">Not provided</span>
                                            </div>
                                            <div class="review-key-item review-key-item--wide">
                                                <span class="review-key-label">Attachments</span>
                                                <span class="review-key-value review-summary-value" data-add-project-summary-field="attachments">No attachments</span>
                                            </div>
                                        </div>
                                    </section>

                                    <section class="review-card" aria-labelledby="addProjectReviewRequirementsHeading">
                                        <header class="review-card-header">
                                            <span class="review-card-icon"><i class="fas fa-tasks"></i></span>
                                            <div class="review-card-heading">
                                                <h6 id="addProjectReviewRequirementsHeading">Requirements &amp; Notes</h6>
                                                <span>What the Architect will receive</span>
                                            </div>
                                        </header>
                                        <div class="review-requirements-grid add-project-review-requirements">
                                            <div class="review-block">
                                                <span class="review-block-label">Project description</span>
                                                <span class="review-summary-value" data-add-project-summary-field="project_description">Not provided</span>
                                            </div>
                                            <div class="review-block">
                                                <span class="review-block-label">Requested features</span>
                                                <ul class="review-feature-list" data-add-project-summary-feature-list>
                                                    <li class="is-empty">Not provided</li>
                                                </ul>
                                            </div>
                                            <div class="review-block review-block--wide">
                                                <span class="review-block-label">Additional requirements</span>
                                                <span class="review-summary-value" data-add-project-summary-field="additional_requirements">Not provided</span>
                                            </div>
                                            <div class="review-block">
                                                <span class="review-block-label">Meeting notes</span>
                                                <span class="review-summary-value" data-add-project-summary-field="meeting_notes">Not provided</span>
                                            </div>
                                            <div class="review-block">
                                                <span class="review-block-label">Minutes of meeting</span>
                                                <span class="review-summary-value" data-add-project-summary-field="mom">Not provided</span>
                                            </div>
                                            <div class="review-block review-block--wide">
                                                <span class="review-block-label">Internal sales notes</span>
                                                <span class="review-summary-value" data-add-project-summary-field="sales_notes">Not provided</span>
                                            </div>
                                        </div>
                                    </section>
                                </div>

                                <div class="review-handoff">
                                    <i class="fas fa-paper-plane"></i>
                                    <div class="review-handoff-copy">
                                        <strong>What happens after submission?</strong>
                                        <span>The project is added under the selected client, a shared project folder is created, and Architect is notified to begin review.</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer add-client-footer">
                            <div class="add-client-actions add-project-actions">
                                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                                <button type="button" class="btn btn-outline-secondary" data-add-project-prev hidden>Previous</button>
                                <button type="button" class="btn-add-modern" data-add-project-next>Next</button>
                                <button type="submit" name="add_project" class="btn-add-modern" data-add-project-submit hidden>
                                    <i class="fas fa-paper-plane me-1"></i> Add Project
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Client Modal -->
<div class="modal fade modal-modern" id="editClientModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-edit me-2"></i> Edit Client
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" data-app-loading data-loading-title="Updating client" data-loading-text="Please wait while the client details are updated.">
                <input type="hidden" name="id" id="edit_id">
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Company Name</label>
                            <input type="text" name="name" id="edit_name" class="form-control" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Contact Person</label>
                            <input type="text" name="contact_person" id="edit_contact" class="form-control" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Email</label>
                            <input type="email" name="email" id="edit_email" class="form-control" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Phone</label>
                            <input type="text" name="phone" id="edit_phone" class="form-control" required>
                        </div>
                        <div class="col-12 mb-3">
                            <label class="form-label">Address</label>
                            <textarea name="address" id="edit_address" class="form-control" rows="2"></textarea>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Status</label>
                            <select name="status" id="edit_status" class="form-select">
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                                <option value="pending">Pending</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="update_client" class="btn-add-modern">Update Client</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- View Client Modal -->
<div class="modal fade modal-modern" id="viewClientModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered client-details-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title client-details-modal-title">
                    <span class="client-details-title-icon"><i class="fas fa-building"></i></span>
                    <span class="client-details-title-copy">
                        <strong>Client Details</strong>
                        <small>Account, delivery, and project overview</small>
                    </span>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="viewClientContent">
                <div class="text-center py-4">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <p class="mt-2 text-muted">Loading client details...</p>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Delete Client Modal -->
<div class="modal fade modal-modern delete-confirm-modal" id="deleteClientModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-building-circle-xmark me-2"></i> Delete Client
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="delete-confirm-note mb-0">
                    <i class="fas fa-triangle-exclamation fa-lg"></i>
                    <div>
                        <div class="fw-semibold mb-1" id="deleteClientName">Selected client</div>
                        <div class="small text-muted">This will hide the client and cancel its projects. You can restore them from the audit log.</div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-delete-cancel" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn-delete-confirm" id="deleteClientConfirmBtn">
                    <i class="fas fa-trash-alt me-1"></i> Yes, delete it
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Delete Project Modal -->
<div class="modal fade modal-modern delete-confirm-modal" id="deleteProjectModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-folder-minus me-2"></i> Delete Project
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="delete-confirm-note mb-0">
                    <i class="fas fa-triangle-exclamation fa-lg"></i>
                    <div>
                        <div class="fw-semibold mb-1" id="deleteProjectName">Selected project</div>
                        <div class="small text-muted">This will cancel the project and hide it from the client list. Files, plans, tasks, and progress are kept for restore.</div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-delete-cancel" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn-delete-confirm" id="deleteProjectConfirmBtn">
                    <i class="fas fa-trash-alt me-1"></i> Yes, delete it
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Delete Draft Modal -->
<div class="modal fade modal-modern delete-confirm-modal" id="deleteDraftModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-file-circle-xmark me-2"></i> Delete Draft
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="delete-confirm-note mb-0">
                    <i class="fas fa-triangle-exclamation fa-lg"></i>
                    <div>
                        <div class="fw-semibold mb-1" id="deleteDraftName">Untitled draft</div>
                        <div class="small text-muted">This saved draft will be removed from this browser. This action cannot be undone.</div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-delete-cancel" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn-delete-confirm" id="deleteDraftConfirmBtn" onclick="confirmDeleteClientDraft()">
                    <i class="fas fa-trash-alt me-1"></i> Yes, delete it
                </button>
            </div>
        </div>
    </div>
</div>

<style>
body.sales-assistant-open #chat-bot-float { display:block !important; width:125px; height:145px; left:auto; top:auto; right:24px; bottom:92px; margin:0; transform:none; animation:salesBotArrive .38s cubic-bezier(.2,.8,.2,1) both; }
body.sales-assistant-open #chat-bot-float[hidden] { display:none !important; }
body.sales-assistant-open #chat-bot-float .chat-bot-speech { width:330px; min-width:330px; max-height:none; overflow:visible; pointer-events:auto; padding:1rem; top:24%; animation:salesBubbleArrive .32s ease both; }
body.sales-assistant-open #chat-bot-float .chat-bot-float-animation { animation:salesRobotFloat 3.2s ease-in-out infinite; will-change:transform; }
#addClientForm .template-type-picker::after, #addProjectForm .template-type-picker::after { display:none !important; }
#addClientForm [data-template-picker-input], #addProjectForm [data-template-picker-input] { padding-right:.9rem !important; }
:is(#addClientForm, #addProjectForm) .template-recommendation-shell [data-template-picker-input] { padding-right:2.7rem !important; }
#addClientForm [data-template-version-select], #addProjectForm [data-template-version-select] { display:none !important; }
.sales-bot-head { padding-left:.75rem; font-weight:800; color:#083a5d; }
.sales-bot-heading-row { position:relative; z-index:5; display:flex; align-items:center; justify-content:space-between; gap:.6rem; }
.sales-bot-dismiss { flex:0 0 auto; border:0; border-radius:999px; background:rgba(255,255,255,.72); color:#0b5d89; width:27px; height:27px; cursor:pointer; transition:transform .18s ease,background .18s ease; }
.sales-bot-dismiss:hover { transform:translateX(-2px); background:#fff; }
.sales-bot-copy { margin:.45rem 0 .7rem !important; font-weight:600 !important; line-height:1.4; white-space:normal; }
.sales-bot-version { display:inline-block !important; width:auto; margin:0 0 .45rem !important; padding:.2rem .55rem; border-radius:999px; background:rgba(255,255,255,.72); font-size:.7rem !important; }
.sales-bot-actions { display:flex; gap:.5rem; position:relative; z-index:4; }
.sales-bot-actions button { flex:1; border:0; border-radius:9px; padding:.48rem .65rem; font-size:.76rem; font-weight:800; cursor:pointer; transition:transform .18s ease,box-shadow .18s ease; }
.sales-bot-actions button:hover { transform:translateY(-2px); box-shadow:0 6px 14px rgba(8,58,93,.18); }
.sales-bot-accept { background:#087bb9; color:#fff; }
.sales-bot-reject { background:rgba(255,255,255,.78); color:#174c6d; }
.sales-bot-progress { margin:.6rem 0 0 !important; font-size:.68rem !important; opacity:.78; }
.sales-bot-choice-list { position:relative; z-index:4; display:grid; gap:.4rem; max-height:245px; overflow-y:auto; overflow-x:hidden; padding:.1rem .2rem .1rem 0; scrollbar-width:thin; }
.sales-bot-choice { display:flex; align-items:center; justify-content:space-between; gap:.65rem; width:100%; border:1px solid rgba(8,123,185,.2); border-radius:10px; background:rgba(255,255,255,.82); color:#0b466d; padding:.58rem .7rem; text-align:left; cursor:pointer; transition:transform .18s ease,background .18s ease; }
.sales-bot-choice:hover { transform:translateX(3px); background:#fff; }
.sales-bot-choice strong { padding:0 !important; font-size:.76rem !important; }
.sales-bot-choice em { flex:0 0 auto; border-radius:999px; background:#dff5ff; padding:.18rem .45rem; color:#087bb9; font-size:.66rem; font-style:normal; font-weight:800; }
.sales-bot-action-links { position:relative; z-index:4; display:grid; gap:.42rem; max-height:275px; overflow-y:auto; padding:.1rem .15rem .1rem 0; }
.sales-bot-action-link { display:flex; align-items:center; gap:.6rem; border:1px solid rgba(8,123,185,.2); border-radius:10px; background:rgba(255,255,255,.84); color:#0b466d; padding:.58rem .66rem; text-decoration:none; }
.sales-bot-action-link:hover { background:#fff; color:#087bb9; transform:translateX(3px); }
.sales-bot-action-link i { color:#087bb9; }
.sales-bot-action-link span { display:grid; min-width:0; }
.sales-bot-action-link strong { overflow:hidden; text-overflow:ellipsis; white-space:nowrap; font-size:.76rem !important; }
.sales-bot-action-link small { color:#66839a; font-size:.66rem; }
html[data-theme="dark"] body.sales-assistant-open #chat-bot-float .chat-bot-speech,
body[data-theme="dark"].sales-assistant-open #chat-bot-float .chat-bot-speech,
body.dark-mode.sales-assistant-open #chat-bot-float .chat-bot-speech { background:radial-gradient(circle at 14% 12%,rgba(86,191,255,.16),transparent 34%),linear-gradient(145deg,#0b2940,#0a3854 58%,#071827);border-color:rgba(143,211,255,.28);box-shadow:inset 0 1px 0 rgba(143,211,255,.1),0 7px 0 rgba(2,11,20,.62),0 22px 42px rgba(2,11,20,.44),0 0 28px rgba(34,211,238,.11); }
html[data-theme="dark"] body.sales-assistant-open .sales-bot-head,
body[data-theme="dark"].sales-assistant-open .sales-bot-head,
body.dark-mode.sales-assistant-open .sales-bot-head { color:#e7f8ff; }
html[data-theme="dark"] body.sales-assistant-open .sales-bot-copy,
body[data-theme="dark"].sales-assistant-open .sales-bot-copy,
body.dark-mode.sales-assistant-open .sales-bot-copy { color:#bfe6ff !important; }
html[data-theme="dark"] body.sales-assistant-open .sales-bot-dismiss,
body[data-theme="dark"].sales-assistant-open .sales-bot-dismiss,
body.dark-mode.sales-assistant-open .sales-bot-dismiss { border:1px solid rgba(143,211,255,.18);background:rgba(7,25,41,.72);color:#8fd3ff;box-shadow:0 4px 0 rgba(2,11,20,.62); }
html[data-theme="dark"] body.sales-assistant-open .sales-bot-dismiss:hover,
body[data-theme="dark"].sales-assistant-open .sales-bot-dismiss:hover,
body.dark-mode.sales-assistant-open .sales-bot-dismiss:hover { background:#123d59;color:#fff; }
html[data-theme="dark"] body.sales-assistant-open .sales-bot-choice,
body[data-theme="dark"].sales-assistant-open .sales-bot-choice,
body.dark-mode.sales-assistant-open .sales-bot-choice { border-color:rgba(143,211,255,.18);background:linear-gradient(145deg,rgba(16,52,77,.96),rgba(7,25,41,.96));color:#dff4ff;box-shadow:inset 0 1px 0 rgba(143,211,255,.07),0 3px 0 rgba(2,11,20,.6),0 9px 16px rgba(2,11,20,.2); }
html[data-theme="dark"] body.sales-assistant-open .sales-bot-choice strong,
body[data-theme="dark"].sales-assistant-open .sales-bot-choice strong,
body.dark-mode.sales-assistant-open .sales-bot-choice strong { color:#e7f8ff !important; }
html[data-theme="dark"] body.sales-assistant-open .sales-bot-choice em,
body[data-theme="dark"].sales-assistant-open .sales-bot-choice em,
body.dark-mode.sales-assistant-open .sales-bot-choice em { border:1px solid rgba(143,211,255,.13);background:rgba(13,103,134,.3);color:#8fd3ff; }
html[data-theme="dark"] body.sales-assistant-open .sales-bot-choice:hover,
body[data-theme="dark"].sales-assistant-open .sales-bot-choice:hover,
body.dark-mode.sales-assistant-open .sales-bot-choice:hover { border-color:rgba(143,211,255,.34);background:linear-gradient(145deg,#164967,#0b2d45);box-shadow:inset 0 1px 0 rgba(143,211,255,.1),0 4px 0 rgba(2,11,20,.66),0 12px 20px rgba(2,11,20,.28); }
html[data-theme="dark"] body.sales-assistant-open .sales-bot-choice-list,
body[data-theme="dark"].sales-assistant-open .sales-bot-choice-list,
body.dark-mode.sales-assistant-open .sales-bot-choice-list { scrollbar-color:rgba(143,211,255,.48) rgba(7,25,41,.55); }
html[data-theme="dark"] body.sales-assistant-open .sales-bot-version,
body[data-theme="dark"].sales-assistant-open .sales-bot-version,
body.dark-mode.sales-assistant-open .sales-bot-version { border:1px solid rgba(143,211,255,.14);background:rgba(7,25,41,.68);color:#8fd3ff !important; }
html[data-theme="dark"] body.sales-assistant-open .sales-bot-reject,
body[data-theme="dark"].sales-assistant-open .sales-bot-reject,
body.dark-mode.sales-assistant-open .sales-bot-reject { border:1px solid rgba(143,211,255,.15);background:rgba(7,25,41,.72);color:#bfe6ff; }
html[data-theme="dark"] body.sales-assistant-open .sales-bot-action-link,
body[data-theme="dark"].sales-assistant-open .sales-bot-action-link,
body.dark-mode.sales-assistant-open .sales-bot-action-link { border-color:rgba(143,211,255,.18);background:rgba(7,25,41,.82);color:#dff4ff; }
html[data-theme="dark"] body.sales-assistant-open .sales-bot-action-link strong,
body[data-theme="dark"].sales-assistant-open .sales-bot-action-link strong,
body.dark-mode.sales-assistant-open .sales-bot-action-link strong { color:#e7f8ff !important; }
html[data-theme="dark"] body.sales-assistant-open .sales-bot-action-link small,
body[data-theme="dark"].sales-assistant-open .sales-bot-action-link small,
body.dark-mode.sales-assistant-open .sales-bot-action-link small { color:#8eabc5; }
.client-project-item.is-evortix-target { outline:2px solid #1597e5; outline-offset:2px; background:#edf8ff; }
#chat-bot-float.is-sad .chat-bot-speech { border-color:rgba(231,139,57,.62); box-shadow:0 16px 34px rgba(180,91,20,.22),0 0 22px rgba(255,169,74,.22); }
.sales-lottie-head,.sales-lottie-left-hand,.sales-lottie-left-forearm,.sales-lottie-right-hand,.sales-lottie-right-forearm { transform-box:fill-box; transform-origin:center; }
.sales-lottie-head { translate:0 7px; }
@keyframes salesBotArrive { from{opacity:0;transform:translateY(18px) scale(.9)} to{opacity:1;transform:none} }
@keyframes salesBubbleArrive { from{opacity:0;transform:translateY(-45%) translateX(18px) scale(.94)} to{opacity:1;transform:translateY(-50%)} }
@keyframes salesRobotFloat { 0%,100%{transform:translateY(0)} 50%{transform:translateY(-6px)} }
@media(max-width:900px){ body.sales-assistant-open #chat-bot-float{width:108px;height:126px;right:10px;bottom:75px;} body.sales-assistant-open #chat-bot-float .chat-bot-speech{width:min(280px,calc(100vw - 132px));min-width:230px;right:calc(100% + 6px);top:10%;} }
</style>

<script>
const savedClientDraftId = <?php echo json_encode($savedClientDraftId); ?>;
const projectTemplates = <?php echo json_encode($projectTemplates, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
function getPriorityAdjustedTemplateWeeks(template, priorityValue = 'medium') {
    let minimumWeeks = Math.max(0, Math.round(Number(template?.duration_min_weeks || 0)));
    let maximumWeeks = Math.max(0, Math.round(Number(template?.duration_max_weeks || 0)));
    if (!minimumWeeks && !maximumWeeks) return 0;
    if (!minimumWeeks) minimumWeeks = maximumWeeks;
    if (!maximumWeeks) maximumWeeks = minimumWeeks;
    if (minimumWeeks > maximumWeeks) [minimumWeeks, maximumWeeks] = [maximumWeeks, minimumWeeks];

    const priority = String(priorityValue || 'medium').toLowerCase();
    const hasRange = minimumWeeks < maximumWeeks;
    if (priority === 'critical') {
        return Math.max(1, hasRange ? Math.floor(minimumWeeks * .8) : Math.floor(maximumWeeks * .65));
    }
    if (priority === 'high') {
        return Math.max(1, hasRange ? minimumWeeks : Math.floor(maximumWeeks * .8));
    }
    if (priority === 'low') {
        return Math.max(1, hasRange ? maximumWeeks : Math.ceil(maximumWeeks * 1.2));
    }
    return Math.max(1, hasRange ? Math.round((minimumWeeks + maximumWeeks) / 2) : maximumWeeks);
}

function getPriorityDurationName(priorityValue = 'medium') {
    const normalized = String(priorityValue || 'medium').toLowerCase();
    return normalized.charAt(0).toUpperCase() + normalized.slice(1);
}

const salesEvortixEnabled = window.APP_USER_PREFERENCES?.evortixEnabled !== false;

const salesTemplateAssistant = (() => {
    let queue = [];
    let index = 0;
    let activeForm = null;
    let continueAction = null;
    let backAction = null;
    let feedbackSequence = 0;
    let emotionTimer = null;
    let winkTimer = null;
    let idleBlinkTimer = null;
    let messageDismissTimer = null;
    let reactionAnimations = [];
    let featureSession = { accepted:0, rejected:0 };
    let browsingChoices = false;
    let visibleNextActionsSignature = '';

    const nextActionsStorageKey = 'evortix.sales.next-best-actions.handled.v1';
    const handledNextActionsSignature = () => {
        try {
            return window.sessionStorage.getItem(nextActionsStorageKey) || '';
        } catch (error) {
            return '';
        }
    };
    const markVisibleNextActionsHandled = () => {
        if (!visibleNextActionsSignature) return;
        try {
            window.sessionStorage.setItem(nextActionsStorageKey, visibleNextActionsSignature);
        } catch (error) {
            // The assistant still works when storage is unavailable.
        }
    };

    const robotLottie = () => window.chatBotFloatRobot || window.chatBotFloatLottie || null;
    const robotLayer = (name) => {
        const controlledPart = window.chatBotFloatRobot?.getPart?.(name);
        if (controlledPart) return controlledPart;
        const elements = robotLottie()?.renderer?.elements || [];
        const layer = elements.find(element => String(element?.data?.nm || '').toLowerCase() === name.toLowerCase());
        return layer?.baseElement || layer?.layerElement || null;
    };
    const robotEyeLayer = () => robotLayer('eyes');
    const prepareRobotLayers = () => {
        const layers = {
            head:'sales-lottie-head',
            'l hand':'sales-lottie-left-hand',
            'l hand 2':'sales-lottie-left-forearm',
            'r hand':'sales-lottie-right-hand',
            'r hand 2':'sales-lottie-right-forearm'
        };
        Object.entries(layers).forEach(([name, className]) => robotLayer(name)?.classList.add(className));
    };
    const stopReactionAnimations = () => {
        reactionAnimations.forEach(animation => animation.cancel());
        reactionAnimations = [];
    };
    const animateRobotLayer = (name, keyframes, options) => {
        const layer = robotLayer(name);
        if (!layer?.animate) return;
        reactionAnimations.push(layer.animate(keyframes, { easing:'ease-in-out', fill:'none', ...options }));
    };
    const animateRobotEmotion = emotion => {
        const repeat = emotion === 'amazed' ? Infinity : 2;
        const duration = emotion === 'winking' ? 520 : 720;
        if (emotion === 'happy' || emotion === 'winking') {
            animateRobotLayer('l hand', [{translate:'0 0'},{translate:'0 -7px'},{translate:'0 0'}], {duration,iterations:repeat});
            animateRobotLayer('l hand 2', [{translate:'0 0'},{translate:'0 -7px'},{translate:'0 0'}], {duration,iterations:repeat});
            animateRobotLayer('r hand', [{translate:'0 0'},{translate:'0 -8px'},{translate:'0 0'}], {duration,iterations:emotion === 'winking' ? 3 : repeat});
            animateRobotLayer('r hand 2', [{translate:'0 0'},{translate:'0 -8px'},{translate:'0 0'}], {duration,iterations:emotion === 'winking' ? 3 : repeat});
        } else if (emotion === 'sad') {
            animateRobotLayer('l hand', [{translate:'0 0'},{translate:'0 5px'},{translate:'0 0'}], {duration,iterations:repeat});
            animateRobotLayer('r hand', [{translate:'0 0'},{translate:'0 5px'},{translate:'0 0'}], {duration,iterations:repeat});
        } else if (emotion === 'amazed') {
            animateRobotLayer('l hand', [{translate:'0 0'},{translate:'0 -9px'}], {duration,iterations:repeat,direction:'alternate'});
            animateRobotLayer('l hand 2', [{translate:'0 0'},{translate:'0 -9px'}], {duration,iterations:repeat,direction:'alternate'});
            animateRobotLayer('r hand', [{translate:'0 0'},{translate:'0 -9px'}], {duration,iterations:repeat,direction:'alternate'});
            animateRobotLayer('r hand 2', [{translate:'0 0'},{translate:'0 -9px'}], {duration,iterations:repeat,direction:'alternate'});
        }
    };
    const blinkRobotEyes = () => {
        if (window.chatBotFloatRobot?.blink) {
            window.chatBotFloatRobot.blink();
            return;
        }
        const eyes = robotEyeLayer();
        if (!eyes?.animate) return;
        eyes.animate([{transform:'scaleY(1)'},{transform:'scaleY(.08)',offset:.45},{transform:'scaleY(.08)',offset:.58},{transform:'scaleY(1)'}], {duration:280,easing:'ease-in-out'});
    };
    const winkRobotEye = (side = 'right') => {
        const controller = window.chatBotFloatRobot;
        if (controller?.wink?.(side)?.length) {
            return;
        }
        blinkRobotEyes();
    };
    const scheduleIdleBlink = () => {
        window.clearTimeout(idleBlinkTimer);
        idleBlinkTimer = window.setTimeout(() => {
            const bot = document.getElementById('chat-bot-float');
            const animation = robotLottie();
            const isReacting = bot?.classList.contains('is-happy') || bot?.classList.contains('is-sad')
                || bot?.classList.contains('is-amazed') || bot?.classList.contains('is-winking');
            if (bot && !bot.hidden && !browsingChoices && !isReacting && animation) {
                blinkRobotEyes();
            }
            scheduleIdleBlink();
        }, 2800 + Math.round(Math.random() * 2200));
    };
    const dismissSpeech = () => {
        window.clearTimeout(messageDismissTimer);
        markVisibleNextActionsHandled();
        visibleNextActionsSignature = '';
        browsingChoices = false;
        setEmotion('');
        const speech = document.querySelector('#chat-bot-float .chat-bot-speech');
        if (speech) speech.hidden = true;
    };
    const scheduleMessageDismiss = (delay = 5000) => {
        window.clearTimeout(messageDismissTimer);
        messageDismissTimer = window.setTimeout(dismissSpeech, delay);
    };

    const setEmotion = (emotion = '', duration = 1100) => {
        const bot = document.getElementById('chat-bot-float');
        const assistant = window.chatBotFloatRobot;
        if (!bot) return;
        window.clearTimeout(emotionTimer);
        window.clearTimeout(winkTimer);
        window.clearTimeout(idleBlinkTimer);
        stopReactionAnimations();
        bot.classList.remove('is-happy', 'is-sad', 'is-amazed', 'is-winking');
        if (emotion) bot.classList.add(`is-${emotion}`);
        assistant?.setEmotion?.(emotion, 0);
        if (!emotion) {
            assistant?.resetLook?.();
            scheduleIdleBlink();
            return;
        }
        if (emotion === 'happy') {
            winkTimer = window.setTimeout(() => {
                if (bot.classList.contains('is-happy')) assistant?.wink?.('right');
            }, Math.max(1250, duration));
        } else if (emotion === 'winking') {
            assistant?.wink?.('right', duration > 0 ? duration : 1080);
        }
        if (duration > 0) {
            const clearDelay = emotion === 'happy' ? Math.max(1250, duration) + 1150 : duration;
            emotionTimer = window.setTimeout(() => {
                bot.classList.remove(`is-${emotion}`);
                assistant?.setEmotion?.('');
                assistant?.resetLook?.();
                scheduleIdleBlink();
            }, clearDelay);
        }
    };

    const rememberViewport = () => {
        const positions = [];
        let node = activeForm;
        while (node) {
            if (node.scrollTop || node.scrollLeft || node.scrollHeight > node.clientHeight || node.scrollWidth > node.clientWidth) {
                positions.push({ node, top:node.scrollTop, left:node.scrollLeft });
            }
            node = node.parentElement;
        }
        activeForm?.querySelectorAll('.modal-body, .field-group, .requested-feature-list').forEach(scrollNode => {
            if (!positions.some(position => position.node === scrollNode)) {
                positions.push({ node:scrollNode, top:scrollNode.scrollTop, left:scrollNode.scrollLeft });
            }
        });
        const pageX = window.scrollX;
        const pageY = window.scrollY;
        return () => {
            const restore = () => {
                window.scrollTo(pageX, pageY);
                positions.forEach(position => {
                    position.node.scrollTop = position.top;
                    position.node.scrollLeft = position.left;
                });
            };
            restore();
            requestAnimationFrame(() => { restore(); requestAnimationFrame(restore); });
            [0, 50, 150, 300].forEach(delay => window.setTimeout(restore, delay));
        };
    };

    const feedbackMessage = (item, action) => {
        const field = item.field || 'suggestion';
        const value = String(item.label || 'this suggestion');
        const accepted = {
            project_type:[`Great choice. ${value} is now the active project baseline.`, `I linked ${value} and its selected version to this intake.`],
            project_description:['Project description updated. You can still refine the wording.', 'The suggested project summary is now in the description field.'],
            requested_features:[`Added “${value}” to the requested features.`, `Feature accepted. “${value}” is now included for Architect review.`],
            additional_requirements:['Added that requirement without replacing your existing notes.', 'The requirement was appended to the current project details.'],
            sales_budget:['Budget suggestion applied. Priority adjustments can still be reviewed.', 'The historical template budget is now your starting estimate.'],
            timeline_end:['Timeline suggestion applied from the selected template duration.', 'The proposed deadline is now set and remains editable.'],
            priority:[`Priority updated to ${value}.`, `The ${value.toLowerCase()} priority recommendation is now applied.`],
            sales_notes:['Added the recommendation to Sales notes.', 'That historical insight is now included in the Sales handoff notes.']
        };
        const rejected = {
            project_description:['Description suggestion skipped. Your current wording stays unchanged.', 'No description was applied; you can write a custom project summary.'],
            requested_features:[`Skipped “${value}”. It was not added to the project.`, `Feature rejected. “${value}” remains outside the requested scope.`],
            additional_requirements:['Requirement skipped. Existing requirements were not changed.', 'I left that recommendation out of the additional requirements.'],
            sales_budget:['Budget suggestion skipped. Your current amount stays unchanged.', 'No template budget was applied; you can enter a custom estimate.'],
            timeline_end:['Timeline suggestion skipped. The current dates remain unchanged.', 'I did not apply the suggested duration to this project.'],
            priority:['Priority suggestion skipped. Your current selection remains.', 'No priority change was made.'],
            sales_notes:['Sales note skipped. Your current notes remain unchanged.', 'I left that recommendation out of the Sales handoff.']
        };
        const pool = (action === 'accepted' ? accepted : rejected)[field]
            || (action === 'accepted' ? ['Suggestion accepted and applied to this field.'] : ['Suggestion rejected; no change was made.']);
        const messageNumber = feedbackSequence++;
        let message = pool[messageNumber % pool.length];
        if (messageNumber % 3 === 1) {
            const encouragement = action === 'accepted'
                ? {
                    project_type:'Nice start — the rest of the intake will be easier to shape from here.',
                    project_description:'Good progress — the project is becoming clearer.',
                    requested_features:'You’re building a focused scope one feature at a time.',
                    additional_requirements:'Great detail — this will help the Architect plan confidently.',
                    sales_budget:'Good step — a clear baseline makes estimation easier.',
                    timeline_end:'Nice work — the delivery plan is taking shape.',
                    priority:'Good decision — this keeps planning aligned with urgency.',
                    sales_notes:'Well done — the handoff context is getting stronger.'
                }
                : {
                    project_description:'That’s okay — keeping your own wording is a valid choice.',
                    requested_features:'Good call — a focused scope is often stronger than a crowded one.',
                    additional_requirements:'No problem — only keep requirements that truly add value.',
                    sales_budget:'That’s fine — you can use the amount that best fits this client.',
                    timeline_end:'No worries — choose a schedule that feels realistic.',
                    priority:'That’s okay — your judgment should lead the final priority.',
                    sales_notes:'Good choice — concise notes can make the handoff clearer.'
                };
            message += ' ' + (encouragement[field] || (action === 'accepted' ? 'Nice progress — keep going.' : 'That’s okay — you’re still in control.'));
        }
        return message;
    };

    const showFeedback = (item, action, after) => {
        visibleNextActionsSignature = '';
        const speech = document.querySelector('#chat-bot-float .chat-bot-speech');
        if (!speech) { after(); return; }
        setEmotion(action === 'accepted' ? 'happy' : 'sad', 1500);
        continueAction = after;
        backAction = action === 'rejected' ? () => { setEmotion(''); render(); } : null;
        const heading = action === 'accepted'
            ? 'Done — suggestion applied'
            : (item.field === 'requested_features' ? 'Scope warning — feature skipped' : 'Okay — suggestion skipped');
        speech.hidden = false;
        speech.innerHTML = `<div class="sales-bot-heading-row"><div class="sales-bot-head">${escapeClientHtml(heading)}</div><button type="button" class="sales-bot-dismiss" data-sales-bot-dismiss title="Hide this message" aria-label="Hide this message"><i class="fas fa-arrow-left"></i></button></div>
            <span class="sales-bot-copy">${escapeClientHtml(feedbackMessage(item, action))}</span>
            <div class="sales-bot-actions">${action === 'rejected' ? '<button type="button" class="sales-bot-reject" data-sales-bot-back><i class="fas fa-arrow-left" aria-hidden="true"></i> Go back</button>' : ''}<button type="button" class="sales-bot-accept" data-sales-bot-continue>Continue</button></div>`;
        scheduleMessageDismiss(5000);
    };

    const showFeatureSummary = () => {
        visibleNextActionsSignature = '';
        const speech = document.querySelector('#chat-bot-float .chat-bot-speech');
        if (!speech) { close(); return; }
        setEmotion(featureSession.accepted > 0 ? 'winking' : 'sad', 0);
        continueAction = close;
        backAction = null;
        const acceptedText = `${featureSession.accepted} feature${featureSession.accepted === 1 ? '' : 's'} added`;
        const rejectedText = `${featureSession.rejected} skipped`;
        const encouragement = featureSession.accepted > 0
            ? 'Nice work — your requested scope is ready for the next field.'
            : 'No changes were made. You can add the features that fit this project best.';
        speech.hidden = false;
        speech.innerHTML = `<div class="sales-bot-heading-row"><div class="sales-bot-head">Feature review complete</div><button type="button" class="sales-bot-dismiss" data-sales-bot-dismiss title="Hide this message" aria-label="Hide this message"><i class="fas fa-arrow-left"></i></button></div>
            <span class="sales-bot-copy">${escapeClientHtml(`${acceptedText}, ${rejectedText}. ${encouragement}`)}</span>
            <div class="sales-bot-actions"><button type="button" class="sales-bot-accept" data-sales-bot-continue>Finish</button></div>`;
        scheduleMessageDismiss(6000);
    };

    const advanceFeatureQueue = () => {
        index++;
        index < queue.length ? render() : showFeatureSummary();
    };

    const templateFor = (form) => {
        const selectedId = Number(form?.querySelector('[data-template-version-select]')?.value || 0);
        const type = normalizeSalesTemplateType(form?.elements?.project_type?.value);
        return projectTemplates.find(item => selectedId && Number(item.id) === selectedId)
            || projectTemplates.find(item => normalizeSalesTemplateType(item.project_type) === type)
            || null;
    };
    const setField = async (name, value) => {
        const field = activeForm?.elements?.[name];
        if (!field) return false;
        if (name === 'project_type') {
            const currentTypedType = String(field.value || '').trim();
            const previousType = String(field.dataset.previousProjectType || currentTypedType).trim();
            const nextType = String(value || '').trim();
            const hadPreviousType = normalizeSalesTemplateType(previousType) !== '';
            const trackedNames = ['project_description', 'meeting_notes', 'mom', 'additional_requirements', 'sales_notes', 'sales_budget', 'timeline_start', 'timeline_start_time', 'timeline_end', 'timeline_end_time'];
            const hasProgress = trackedNames.some((fieldName) => String(activeForm.elements[fieldName]?.value || '').trim() !== '')
                || (activeForm.id === 'addProjectForm' ? getAddProjectFeatureValues().length > 0 : activeForm.querySelectorAll('[data-requested-feature-value]').length > 0);
            let clearedProgress = false;
            if (normalizeSalesTemplateType(previousType) && normalizeSalesTemplateType(previousType) !== normalizeSalesTemplateType(nextType) && hasProgress) {
                const shouldChange = await showSalesAssistantProjectTypeWarning(activeForm.closest('.modal'), previousType, nextType);
                if (!shouldChange) return false;
                if (activeForm.id === 'addProjectForm') clearAddProjectTemplateValues();
                else window.clearSalesClientProjectTypeProgress?.();
                clearedProgress = true;
            }
            field.dataset.previousProjectType = previousType;
            field.dataset.evortixClearedProgress = clearedProgress ? '1' : '0';
            field.dataset.evortixHadPreviousType = hadPreviousType ? '1' : '0';
        }
        field.value = value;
        field.dispatchEvent(new Event('input', { bubbles:true }));
        field.dispatchEvent(new Event('change', { bubbles:true }));
        if (name === 'project_type') {
            field.dataset.previousProjectType = field.value;
            const clearedProgress = field.dataset.evortixClearedProgress === '1';
            const hadPreviousType = field.dataset.evortixHadPreviousType === '1';
            delete field.dataset.evortixClearedProgress;
            delete field.dataset.evortixHadPreviousType;
            window.setTimeout(() => announceSalesAssistant(
                hadPreviousType ? 'Project type changed' : 'Project type selected',
                clearedProgress
                    ? `${field.value} is ready. I deleted the previous project progress and prepared new suggestions.`
                    : `${field.value} is ready. You can now review its suggestions.`
            ), 220);
        }
        return true;
    };
    const addFeature = (value) => {
        if (activeForm?.id === 'addProjectForm') {
            addAddProjectFeatureValue(value, false);
            return;
        }
        if (activeForm?.id === 'addClientForm' && typeof window.addClientRequestedFeatureValue === 'function') {
            window.addClientRequestedFeatureValue(value, false);
            return;
        }
        const selector = '[data-requested-feature-input]';
        const inputs = Array.from(activeForm?.querySelectorAll(selector) || []);
        let input = inputs.find(item => !String(item.value || '').trim());
        if (!input && inputs.length) {
            const row = inputs[inputs.length - 1].closest('.requested-feature-item')?.cloneNode(true);
            input = row?.querySelector(selector);
            if (input && row) {
                input.value = '';
                inputs[inputs.length - 1].closest('.requested-feature-item')?.parentElement?.appendChild(row);
            }
        }
        if (input) {
            input.value = value;
            input.dispatchEvent(new Event('input', { bubbles:true }));
            input.dispatchEvent(new Event('change', { bubbles:true }));
        }
    };
    const render = () => {
        const bot = document.getElementById('chat-bot-float');
        const speech = bot?.querySelector('.chat-bot-speech');
        if (!bot || !speech || !queue[index]) return;
        const item = queue[index];
        visibleNextActionsSignature = String(item.nextActionsSignature || '');
        const wasOpen = document.body.classList.contains('sales-assistant-open');
        if (!wasOpen) {
            bot.style.left = '';
            bot.style.top = '';
            bot.style.right = '';
            bot.style.bottom = '';
        }
        bot.hidden = false;
        bot.setAttribute('aria-hidden', 'false');
        window.chatBotWidgetRobot?.wake?.();
        document.body.classList.add('sales-assistant-open');
        const choices = Array.isArray(item.choices) ? `<div class="sales-bot-choice-list">${item.choices.map((choice, choiceIndex) =>
            `<button type="button" class="sales-bot-choice" data-sales-bot-choice="${choiceIndex}"><strong>${escapeClientHtml(choice.label)}</strong><em>${escapeClientHtml(choice.version)}</em></button>`
        ).join('')}</div>` : '';
        const actionLinks = Array.isArray(item.links) ? `<div class="sales-bot-action-links">${item.links.map(link =>
            `<a class="sales-bot-action-link" href="${escapeClientHtml(link.url)}"><i class="fas ${escapeClientHtml(link.icon || 'fa-arrow-right')}" aria-hidden="true"></i><span><strong>${escapeClientHtml(link.label)}</strong><small>${escapeClientHtml(link.context)}</small></span></a>`
        ).join('')}</div>` : '';
        const actions = item.information || item.choices ? '' : '<div class="sales-bot-actions"><button type="button" class="sales-bot-reject" data-sales-bot-reject>Reject</button><button type="button" class="sales-bot-accept" data-sales-bot-accept>Accept</button></div>';
        const progress = item.information || item.choices || queue.length < 2 ? '' : `<span class="sales-bot-progress">Suggestion ${index + 1} of ${queue.length}</span>`;
        browsingChoices = Boolean(item.choices);
        if (browsingChoices) {
            window.chatBotFloatRobot?.resetLook?.();
        }
        speech.hidden = false;
        speech.innerHTML = `<div class="sales-bot-heading-row"><div class="sales-bot-head">${escapeClientHtml(item.heading || 'My suggestion')}</div><button type="button" class="sales-bot-dismiss" data-sales-bot-dismiss title="Hide this message" aria-label="Hide this message"><i class="fas fa-arrow-left"></i></button></div>
            ${item.version ? `<span class="sales-bot-version">${escapeClientHtml(item.version)}</span>` : ''}
            ${item.label ? `<span class="sales-bot-copy">${escapeClientHtml(item.label)}</span>` : ''}
            ${choices}${actionLinks}${actions}${progress}`;
        scheduleIdleBlink();
        scheduleMessageDismiss(item.choices ? 9000 : 6000);
        if (item.pointingSide) {
            window.chatBotFloatRobot?.point?.(item.pointingSide, 0);
        } else if (item.choices) {
            setEmotion('amazed', 0);
        }
    };
    const show = (form, items) => {
        activeForm = form;
        queue = items.filter(item => item && (String(item.label || '').trim() || item.links?.length));
        index = 0;
        if (queue.length) render();
    };
    const projectTypes = (form) => {
        const query = normalizeSalesTemplateType(form?.elements?.project_type?.value);
        const projectName = normalizeSalesTemplateType(form?.elements?.project_name?.value);
        const terms = (query || projectName).split(' ').filter(term => term.length > 2);
        let matches = projectTemplates.filter(template => {
            if (!terms.length) return true;
            const candidate = normalizeSalesTemplateType(template.project_type);
            return terms.some(term => candidate.includes(term));
        });
        if (!matches.length && query) {
            matches = projectTemplates.filter(template => normalizeSalesTemplateType(template.project_type).split(' ').some(word => query.includes(word) || word.includes(query)));
        }
        const choices = matches.map(template => ({
            label:template.project_type,
            field:'project_type',
            version:formatTemplateVersionLabel(template),
            apply:async () => {
                const changed = await setField('project_type', template.project_type);
                if (changed === false) return false;
                const select = activeForm.querySelector('[data-template-version-select]');
                if (select) { select.value = String(template.id); select.dispatchEvent(new Event('change', { bubbles:true })); }
                return true;
            }
        }));
        show(form, [{
            heading: query ? 'Matching project types' : 'Choose a project type',
            label: choices.length ? 'Select the best match. The list updates as you type.' : `No learned template matches “${form?.elements?.project_type?.value || ''}”. You may continue with a new project type.`,
            choices,
            information: choices.length === 0,
            pointingSide: 'left'
        }]);
    };
    const forField = (form, field) => {
        if (!salesEvortixEnabled) {
            close();
            return;
        }
        const template = templateFor(form);
        if (field === 'project_type') return projectTypes(form);
        if (!template) return show(form, [{ heading:'Choose a project type first', label:'I will suggest this field after you choose a matching project type.', information:true }]);
        const existingFeatureValues = form.id === 'addProjectForm'
            ? getAddProjectFeatureValues()
            : (form.id === 'addClientForm'
                ? Array.from(form.querySelectorAll('[data-requested-feature-value]')).map((chip) => chip.dataset.requestedFeatureValue || '')
                : Array.from(form.querySelectorAll('[data-requested-feature-input]')).map((input) => input.value));
        const existingFeatures = new Set(existingFeatureValues.map((value) => normalizeSalesTemplateType(value)).filter(Boolean));
        const availableFeatureSuggestions = (template.feature_items || [])
            .filter(value => !existingFeatures.has(normalizeSalesTemplateType(value)));
        if (field === 'requested_features') {
            featureSession = { accepted:0, rejected:0 };
            if (!availableFeatureSuggestions.length) {
                setEmotion('winking', 1800);
                return show(form, [{ heading:'Feature suggestions complete', label:'All learned template features have already been reviewed or added. Nice work!', information:true }]);
            }
        }
        const priorityValue = form.elements.priority?.value || 'medium';
        const timelineWeeks = getPriorityAdjustedTemplateWeeks(template, priorityValue) || 1;
        const timelineDays = timelineWeeks * 7;
        const timelineStartValue = form.elements.timeline_start?.value || new Date().toISOString().slice(0, 10);
        const timelineStartDate = new Date(`${timelineStartValue}T00:00:00`);
        const timelineDeadlineDate = new Date(timelineStartDate);
        timelineDeadlineDate.setDate(timelineDeadlineDate.getDate() + timelineDays);
        const timelineDeadlineValue = timelineDeadlineDate.toISOString().slice(0, 10);
        const readableDate = value => new Intl.DateTimeFormat('en-PH', { year:'numeric', month:'short', day:'numeric' }).format(new Date(`${value}T00:00:00`));
        const timelineSuggestion = {
            field:'timeline_end',
            heading:'Suggested project timeline',
            label:`Priority: ${getPriorityDurationName(priorityValue)} · Start: ${readableDate(timelineStartValue)} · Deadline: ${readableDate(timelineDeadlineValue)} · ${timelineDays} calendar days (${timelineWeeks} weeks)`,
            apply:() => {
                setField('timeline_start', timelineStartValue);
                setField('timeline_end', timelineDeadlineValue);
                const timelineEnd = activeForm?.elements?.timeline_end;
                if (timelineEnd) timelineEnd.dataset.templateDurationAuto = '1';
            }
        };
        const maps = {
            project_description:[{field:'project_description',heading:'Project description',label:template.project_description,apply:()=>setField('project_description',template.project_description)}],
            requested_features:availableFeatureSuggestions.map(value=>({field:'requested_features',heading:'Suggested feature',label:value,version:`From ${template.project_type} V${template.template_version || 1}`,apply:()=>addFeature(value)})),
            additional_requirements:[template.non_functional_requirements,template.integrations,template.third_party_services].map(value=>({field:'additional_requirements',heading:'Additional requirement',label:value,apply:()=>setField('additional_requirements',[activeForm.elements.additional_requirements?.value,value].filter(Boolean).join('\n'))})),
            sales_budget:[{field:'sales_budget',heading:'Suggested starting budget',label:new Intl.NumberFormat('en-PH',{style:'currency',currency:'PHP'}).format(Number(template.budget_min || 0)),apply:()=>setField('sales_budget',Number(template.budget_min || 0).toFixed(2))}],
            timeline_start:[timelineSuggestion],
            timeline_end:[timelineSuggestion],
            priority:[{field:'priority',heading:'Suggested priority',label:String(template.complexity_level || 'medium').toLowerCase()==='high'?'High':'Medium',apply:()=>setField('priority',String(template.complexity_level || '').toLowerCase()==='high'?'high':'medium')}],
            sales_notes:[template.sales_notes,template.suggested_milestones,template.maintenance_support].map(value=>({field:'sales_notes',heading:'Sales note suggestion',label:value,apply:()=>setField('sales_notes',[activeForm.elements.sales_notes?.value,value].filter(Boolean).join('\n'))}))
        };
        show(form, maps[field] || []);
    };
    document.addEventListener('pointerdown', event => {
        if (!event.target.closest('[data-sales-bot-choice], [data-sales-bot-accept], [data-sales-bot-reject], [data-sales-bot-continue], [data-sales-bot-back], [data-sales-bot-dismiss]')) {
            return;
        }
        const restore = rememberViewport();
        event.preventDefault();
        event.stopImmediatePropagation();
        restore();
    }, true);
    document.addEventListener('click', async event => {
        const actionLink = event.target.closest('.sales-bot-action-link');
        if (actionLink) {
            markVisibleNextActionsHandled();
            visibleNextActionsSignature = '';
        }
        const assistantControl = event.target.closest('[data-sales-bot-choice], [data-sales-bot-accept], [data-sales-bot-reject], [data-sales-bot-continue], [data-sales-bot-back], [data-sales-bot-dismiss]');
        if (assistantControl) {
            event.preventDefault();
            event.stopImmediatePropagation();
            window.clearTimeout(messageDismissTimer);
        } else if (event.target.closest('.chat-bot-speech')) {
            event.stopPropagation();
        }
        const dismissButton = event.target.closest('[data-sales-bot-dismiss]');
        if (dismissButton) {
            const restore = rememberViewport();
            dismissSpeech();
            restore();
            return;
        }
        const backButton = event.target.closest('[data-sales-bot-back]');
        if (backButton) {
            const restore = rememberViewport();
            const previous = backAction;
            backAction = null;
            previous?.();
            restore();
            return;
        }
        const continueButton = event.target.closest('[data-sales-bot-continue]');
        if (continueButton) {
            const restore = rememberViewport();
            const next = continueAction;
            continueAction = null;
            next?.();
            restore();
            return;
        }
        const choiceButton = event.target.closest('[data-sales-bot-choice]');
        if (choiceButton) {
            const restore = rememberViewport();
            const choice = queue[index]?.choices?.[Number(choiceButton.dataset.salesBotChoice)];
            browsingChoices = false;
            window.chatBotFloatRobot?.resetLook?.();
            const applied = await choice?.apply?.();
            if (applied === false) {
                render();
                restore();
                return;
            }
            showFeedback(choice || { field:'project_type', label:'Project type' }, 'accepted', close);
            restore();
            return;
        }
        if (event.target.closest('[data-sales-bot-accept]')) {
            const restore = rememberViewport();
            const item = queue[index];
            item?.apply?.();
            if (item?.field === 'requested_features') {
                featureSession.accepted++;
                setEmotion('happy', 900);
                advanceFeatureQueue();
                restore();
                return;
            }
            showFeedback(item || {}, 'accepted', () => {
                if (item?.complete) { close(); return; }
                index++;
                index < queue.length ? render() : close();
            });
            restore();
            return;
        }
        if (event.target.closest('[data-sales-bot-reject]')) {
            const restore = rememberViewport();
            const item = queue[index];
            if (item?.field === 'requested_features') {
                featureSession.rejected++;
                showFeedback(item, 'rejected', advanceFeatureQueue);
                backAction = () => {
                    featureSession.rejected = Math.max(0, featureSession.rejected - 1);
                    setEmotion('');
                    render();
                };
                restore();
                return;
            }
            showFeedback(item || {}, 'rejected', () => {
                index++;
                index < queue.length ? render() : close();
            });
            restore();
        }
    }, true);
    document.addEventListener('pointerover', event => {
        if (event.target.closest?.('.chat-bot-speech')) {
            window.clearTimeout(messageDismissTimer);
        }
    });
    document.addEventListener('pointerout', event => {
        const speech = event.target.closest?.('.chat-bot-speech');
        if (speech && !event.relatedTarget?.closest?.('.chat-bot-speech')) {
            scheduleMessageDismiss(speech.querySelector('.sales-bot-choice-list') ? 9000 : 5000);
        }
    });
    document.addEventListener('pointermove', event => {
        const bot = document.getElementById('chat-bot-float');
        if (!bot || bot.hidden || !browsingChoices) return;
        window.chatBotFloatRobot?.lookAt?.(event.clientX, event.clientY);
    }, { passive:true });
    const close = () => {
        visibleNextActionsSignature = '';
        continueAction = null;
        backAction = null;
        browsingChoices = false;
        setEmotion('');
        window.chatBotFloatRobot?.resetLook?.();
        const bot = document.getElementById('chat-bot-float');
        const speech = bot?.querySelector('.chat-bot-speech');
        if (!bot || !speech) return;
        document.body.classList.add('sales-assistant-open');
        bot.hidden = false;
        bot.setAttribute('aria-hidden', 'false');
        speech.hidden = false;
        speech.innerHTML = '<div class="sales-bot-heading-row"><div class="sales-bot-head">Hi, good day!</div><button type="button" class="sales-bot-dismiss" data-sales-bot-dismiss title="Hide this message" aria-label="Hide this message"><i class="fas fa-arrow-left"></i></button></div><span class="sales-bot-copy">I am ready whenever you want help with another field.</span>';
        setEmotion('winking', 4500);
        scheduleMessageDismiss(4500);
    };
    const announceNextActions = actions => {
        const pendingProjects = Array.isArray(actions?.pending_projects) ? actions.pending_projects : [];
        const inactiveClients = Array.isArray(actions?.inactive_clients) ? actions.inactive_clients : [];
        const overdueDeliverables = Array.isArray(actions?.overdue_deliverables) ? actions.overdue_deliverables : [];
        const projectUrl = item => `clients.php?view=${encodeURIComponent(item.client_id)}&project=${encodeURIComponent(item.id)}`;
        const clientUrl = item => `clients.php?view=${encodeURIComponent(item.id)}`;
        const links = [
            ...pendingProjects.map(item => ({
                label: `Follow up: ${item.project_name}`,
                context: `${item.client_name} · Pending project`,
                icon: 'fa-clock',
                url: projectUrl(item)
            })),
            ...inactiveClients.map(item => ({
                label: `Send proposal: ${item.client_name}`,
                context: 'Inactive client',
                icon: 'fa-paper-plane',
                url: clientUrl(item)
            })),
            ...overdueDeliverables.map(item => ({
                label: `Review: ${item.project_name}`,
                context: `${item.client_name} · Due ${item.timeline_end}`,
                icon: 'fa-triangle-exclamation',
                url: projectUrl(item)
            }))
        ];
        const nextActionsSignature = JSON.stringify(links.map(link => [
            link.url,
            link.label,
            link.context
        ]));
        if (handledNextActionsSignature() === nextActionsSignature) return;

        show(null, [{
            heading: 'Next best actions',
            label: links.length
                ? `${pendingProjects.length} pending, ${inactiveClients.length} inactive, and ${overdueDeliverables.length} overdue. Select an item to open it.`
                : 'You are all caught up. There are no pending projects, inactive clients, or overdue deliverables requiring attention.',
            links,
            information: true,
            pointingSide: links.length ? 'left' : '',
            nextActionsSignature
        }]);
    };

    return { forField, close, announceNextActions };
})();

document.addEventListener('click', async event => {
    const button = event.target.closest('[data-notify-project-team]');
    if (!button) return;

    event.preventDefault();
    event.stopPropagation();
    const field = button.closest('.project-team-notify')?.querySelector('input');
    const message = String(field?.value || '').trim();
    if (!message) {
        window.appToast?.('Message required', 'Enter the message Evortix should announce to the responsible team.', 'warning');
        field?.focus();
        return;
    }

    button.disabled = true;
    const originalHtml = button.innerHTML;
    button.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Sending';
    try {
        const body = new URLSearchParams({ project_id: button.dataset.notifyProjectTeam, message });
        const response = await fetch('../../api/notify-project-team.php', {
            method: 'POST',
            headers: { 'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8' },
            body
        });
        const result = await response.json();
        if (!response.ok || !result.success) throw new Error(result.error || 'Unable to notify the project team.');
        if (field) field.value = '';
        window.appToast?.('Team notified', result.message, 'success');
    } catch (error) {
        window.appToast?.('Notification failed', error.message || 'Unable to notify the project team.', 'error');
    } finally {
        button.disabled = false;
        button.innerHTML = originalHtml;
    }
});

document.addEventListener('focusin', event => {
    const form = event.target.closest('#addClientForm, #addProjectForm');
    if (!form) return;
    const field = event.target.matches('[data-requested-feature-input],[data-add-project-feature-input]') ? 'requested_features' : event.target.name;
    if (['project_type','project_description','requested_features','additional_requirements','sales_budget','timeline_start','timeline_end','priority','sales_notes'].includes(field)) {
        salesTemplateAssistant.forField(form, field);
    }
});
document.addEventListener('input', event => {
    const form = event.target.closest?.('#addClientForm, #addProjectForm');
    if (form && event.target.name === 'project_type' && document.activeElement === event.target) {
        salesTemplateAssistant.forField(form, 'project_type');
    }
});
document.addEventListener('keydown', event => {
    const form = event.target.closest?.('#addClientForm, #addProjectForm');
    if (form && event.target.name === 'project_type' && event.key === 'Enter') {
        event.preventDefault();
    }
});
document.querySelectorAll('#addClientModal, #addProjectModal').forEach(modal => modal.addEventListener('hidden.bs.modal', () => salesTemplateAssistant.close()));

function applyFilters() {
    const search = document.getElementById('searchInput')?.value.trim() || '';
    const status = document.getElementById('statusFilter')?.value || '';
    const pageSize = document.getElementById('clientPageSize')?.value || '';
    const params = new URLSearchParams();

    if (search) params.set('search', search);
    if (status) params.set('status', status);
    if (pageSize) params.set('per_page', pageSize);

    const query = params.toString();
    window.location.href = 'clients.php' + (query ? '?' + query : '');
}

function editClient(client) {
    document.getElementById('edit_id').value = client.id;
    document.getElementById('edit_name').value = client.name;
    document.getElementById('edit_contact').value = client.contact_person;
    document.getElementById('edit_email').value = client.email;
    document.getElementById('edit_phone').value = client.phone;
    document.getElementById('edit_address').value = client.address;
    document.getElementById('edit_status').value = client.status;
    
    new bootstrap.Modal(document.getElementById('editClientModal')).show();
}

const SALES_MOM_TEMPLATE = `Topic:
Date:
Company Name:
Location:
Attendees:`;

function ensureMomTemplate(form) {
    const field = form?.elements?.mom;
    if (field && !String(field.value || '').trim()) {
        field.value = SALES_MOM_TEMPLATE;
    }
}

function openAddProjectModal(clientId, clientName) {
    const modalElement = document.getElementById('addProjectModal');
    const form = document.getElementById('addProjectForm');
    const clientIdField = document.getElementById('add_project_client_id');
    const clientNameElement = document.getElementById('addProjectClientName');

    if (!modalElement || !form || !clientIdField) {
        return;
    }

    form.reset();
    ensureMomTemplate(form);
    clientIdField.value = clientId;
    renderAddProjectFeatureRows('');
    resetAddProjectTemplateState();
    if (clientNameElement) {
        clientNameElement.textContent = clientName || 'Selected client';
    }
    updateAddProjectTemplateRecommendation();
    updateAddProjectGeneratedComplexity();
    showAddProjectStep(0);

    bootstrap.Modal.getOrCreateInstance(modalElement).show();
}

let appliedAddProjectTemplateType = '';
let addProjectAutoBudgetBase = null;
let addProjectAutoBudgetFromTemplate = false;

function normalizeSalesTemplateType(value) {
    return String(value || '')
        .normalize('NFKD')
        .replace(/[\u0300-\u036f]/g, '')
        .replace(/[^a-z0-9]+/gi, ' ')
        .trim()
        .replace(/\s+/g, ' ')
        .toLowerCase();
}

function latestTemplateVersionForType(projectType) {
    const normalizedType = normalizeSalesTemplateType(projectType);
    const versions = projectTemplates
        .filter((template) => normalizeSalesTemplateType(template.project_type) === normalizedType)
        .map((template) => Number(template.template_version || 1));
    return versions.length ? Math.max(...versions) : 1;
}

function formatTemplateVersionLabel(template, latestSuffix = ' · Latest') {
    const version = Number(template?.template_version || 1);
    return `V${version}${version === latestTemplateVersionForType(template?.project_type) ? latestSuffix : ''}`;
}

function escapeClientHtml(value) {
    const div = document.createElement('div');
    div.textContent = String(value ?? '');
    return div.innerHTML;
}

function syncComplexityPopover(reasonElement, popoverElement) {
    if (reasonElement && popoverElement) {
        popoverElement.innerHTML = reasonElement.innerHTML;
    }
}

function closeComplexityInsight(scope = document, returnFocus = false) {
    scope.querySelectorAll('[data-complexity-popover]').forEach((popover) => {
        const shell = popover.closest('.complexity-field-shell');
        const trigger = shell?.querySelector('[data-complexity-insight-trigger]');
        if (!popover.hidden) {
            popover.hidden = true;
            trigger?.classList.remove('is-open');
            trigger?.setAttribute('aria-expanded', 'false');
            if (returnFocus) {
                trigger?.focus();
            }
        }
    });
}

function toggleComplexityInsight(trigger, scope = document) {
    const shell = trigger?.closest('.complexity-field-shell');
    const popover = shell?.querySelector('[data-complexity-popover]');
    if (!popover) {
        return;
    }

    const shouldOpen = popover.hidden;
    closeComplexityInsight(scope, false);
    popover.hidden = !shouldOpen;
    trigger.classList.toggle('is-open', shouldOpen);
    trigger.setAttribute('aria-expanded', shouldOpen ? 'true' : 'false');
}

function closeAddProjectTemplateInsight(returnFocus = false) {
    const form = document.getElementById('addProjectForm');
    const shell = form?.querySelector('.template-recommendation-shell');
    const panel = shell?.querySelector('[data-add-project-template-recommendation]');
    const trigger = shell?.querySelector('[data-add-project-template-insight-trigger]');
    if (!panel || panel.hidden) {
        return;
    }

    panel.hidden = true;
    trigger?.classList.remove('is-open');
    trigger?.setAttribute('aria-expanded', 'false');
    if (returnFocus) {
        trigger?.focus();
    }
}

function toggleAddProjectTemplateInsight(trigger) {
    const shell = trigger?.closest('.template-recommendation-shell');
    const panel = shell?.querySelector('[data-add-project-template-recommendation]');
    if (!panel) {
        return;
    }

    const shouldOpen = panel.hidden;
    closeAddProjectTemplateInsight(false);
    panel.hidden = !shouldOpen;
    trigger.classList.toggle('is-open', shouldOpen);
    trigger.setAttribute('aria-expanded', shouldOpen ? 'true' : 'false');
}

function setAddProjectTemplateRecommendation(panel, content) {
    const isOpen = !panel.hidden;
    panel.innerHTML = content;
    panel.hidden = !isOpen;
}

function closeAddProjectPriorityInsight(returnFocus = false) {
    const form = document.getElementById('addProjectForm');
    const shell = form?.querySelector('.add-project-priority-insight-shell');
    const panel = shell?.querySelector('[data-add-project-priority-markup-preview]');
    const trigger = shell?.querySelector('[data-add-project-priority-insight-trigger]');
    if (!panel || panel.hidden) {
        return;
    }

    panel.hidden = true;
    trigger?.classList.remove('is-open');
    trigger?.setAttribute('aria-expanded', 'false');
    if (returnFocus) {
        trigger?.focus();
    }
}

function toggleAddProjectPriorityInsight(trigger) {
    const shell = trigger?.closest('.add-project-priority-insight-shell');
    const panel = shell?.querySelector('[data-add-project-priority-markup-preview]');
    if (!panel) {
        return;
    }

    const shouldOpen = panel.hidden;
    closeAddProjectPriorityInsight(false);
    panel.hidden = !shouldOpen;
    trigger.classList.toggle('is-open', shouldOpen);
    trigger.setAttribute('aria-expanded', shouldOpen ? 'true' : 'false');
}

function setAddProjectPriorityInsight(panel, content) {
    const isOpen = !panel.hidden;
    panel.innerHTML = content;
    panel.hidden = !isOpen;
}

function resetAddProjectPriorityInsight() {
    const form = document.getElementById('addProjectForm');
    const panel = form?.querySelector('[data-add-project-priority-markup-preview]');
    const trigger = form?.querySelector('[data-add-project-priority-insight-trigger]');
    if (panel) {
        panel.hidden = true;
        panel.innerHTML = '<strong>Medium priority adds +10%</strong><span>Requires more planning, testing, and attention than the standard development process.</span>';
    }
    trigger?.classList.remove('is-open');
    trigger?.setAttribute('aria-expanded', 'false');
}

const projectTemplateTypeNames = Array.from(new Set(
    (Array.isArray(projectTemplates) ? projectTemplates : [])
        .map((template) => String(template.project_type || '').trim())
        .filter(Boolean)
)).sort((a, b) => a.localeCompare(b));

function closeTemplatePickers(exceptMenu = null) {
    document.querySelectorAll('[data-template-picker-menu]').forEach((menu) => {
        if (menu !== exceptMenu) {
            menu.hidden = true;
        }
    });
}

function initProjectTemplatePicker(picker) {
    const input = picker.querySelector('[data-template-picker-input]');
    const menu = picker.querySelector('[data-template-picker-menu]');
    const versionSelect = picker.closest('form')?.querySelector('[data-template-version-select]');
    if (!input || !menu) {
        return;
    }
    if (salesEvortixEnabled) {
        menu.hidden = true;
        return;
    }

    let selectingTemplate = false;
    const syncVersions = () => {
        if (!versionSelect) return;
        const needle = normalizeSalesTemplateType(input.value);
        const versions = projectTemplates
            .filter((template) => normalizeSalesTemplateType(template.project_type) === needle)
            .sort((a, b) => Number(b.template_version || 1) - Number(a.template_version || 1));
        versionSelect.innerHTML = versions.map((template) =>
            `<option value="${Number(template.id || 0)}">${formatTemplateVersionLabel(template, ' (Latest)')}</option>`
        ).join('');
        versionSelect.hidden = versions.length < 2;
        if (versions.length === 1) versionSelect.value = String(versions[0].id || '');
    };
    const renderMenu = () => {
        if (selectingTemplate) {
            return;
        }

        const query = normalizeSalesTemplateType(input.value);
        const matches = projectTemplateTypeNames
            .filter((name) => {
                if (!query) {
                    return true;
                }
                return normalizeSalesTemplateType(name).includes(query);
            });

        menu.innerHTML = matches.length
            ? matches.map((name) => `
                <button type="button" class="template-suggestion-option" data-template-suggestion="${escapeClientHtml(name)}">
                    <span>${escapeClientHtml(name)}</span>
                    <i class="fas fa-arrow-right" aria-hidden="true"></i>
                </button>
            `).join('')
            : '<div class="template-suggestion-empty">No saved template matches this type.</div>';
        menu.hidden = false;
        closeTemplatePickers(menu);
    };

    input.addEventListener('focus', renderMenu);
    input.addEventListener('input', () => { syncVersions(); renderMenu(); });
    input.addEventListener('click', renderMenu);
    input.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            menu.hidden = true;
        }
    });

    menu.addEventListener('mousedown', (event) => {
        event.preventDefault();
    });

    menu.addEventListener('click', (event) => {
        const option = event.target.closest('[data-template-suggestion]');
        if (!option) {
            return;
        }

        input.value = option.dataset.templateSuggestion || '';
        menu.hidden = true;
        selectingTemplate = true;
        input.dispatchEvent(new Event('input', { bubbles: true }));
        input.dispatchEvent(new Event('change', { bubbles: true }));
        selectingTemplate = false;
        input.focus();
    });

    versionSelect?.addEventListener('change', () => {
        input.dispatchEvent(new Event('change', { bubbles: true }));
    });
}

document.querySelectorAll('[data-template-picker]').forEach(initProjectTemplatePicker);
document.addEventListener('click', (event) => {
    if (!event.target.closest('[data-template-picker]')) {
        closeTemplatePickers();
    }
});

function formatProjectPeso(value) {
    return new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(Number(value || 0));
}

function addProjectDatePlusDays(dateValue, daysToAdd) {
    const baseDate = dateValue ? new Date(dateValue + 'T00:00:00') : new Date();
    if (Number.isNaN(baseDate.getTime())) {
        return '';
    }
    baseDate.setDate(baseDate.getDate() + Math.max(0, Number(daysToAdd) || 0));
    return [
        baseDate.getFullYear(),
        String(baseDate.getMonth() + 1).padStart(2, '0'),
        String(baseDate.getDate()).padStart(2, '0')
    ].join('-');
}

function getAddProjectPriorityMarkup(priorityValue) {
    const normalized = String(priorityValue || 'medium').toLowerCase();
    if (normalized === 'low') {
        return {
            label: 'Low',
            percent: 0,
            rate: 0,
            reason: 'Standard development process with no urgency premium.'
        };
    }
    if (normalized === 'high') {
        return {
            label: 'High',
            percent: 25,
            rate: 0.25,
            reason: 'Urgent or critical features require extra effort, overtime, and more experienced developers.'
        };
    }
    if (normalized === 'critical') {
        return {
            label: 'Critical',
            percent: 30,
            rate: 0.30,
            reason: 'Critical delivery pressure uses the top priority premium for overtime, senior oversight, and rapid turnaround.'
        };
    }
    return {
        label: 'Medium',
        percent: 10,
        rate: 0.10,
        reason: 'Requires more planning, testing, and attention than the standard development process.'
    };
}

function findSalesProjectTemplate(value) {
    const needle = normalizeSalesTemplateType(value);
    if (!needle) {
        return null;
    }

    const selectedId = Number(document.querySelector('#addProjectForm [data-template-version-select]')?.value || 0);
    const exactMatch = projectTemplates.find((template) => selectedId > 0 && Number(template.id) === selectedId)
        || projectTemplates.find((template) => normalizeSalesTemplateType(template.project_type) === needle);
    if (exactMatch) {
        return exactMatch;
    }

    const genericTokens = new Set(['system', 'app', 'application', 'management', 'platform', 'website', 'portal']);
    const needleTokens = needle.split(' ').filter(Boolean);
    const distinctiveNeedleTokens = needleTokens.filter((token) => !genericTokens.has(token));
    if (distinctiveNeedleTokens.length === 0) {
        return null;
    }

    const scoredMatches = projectTemplates
        .map((template) => {
            const candidate = normalizeSalesTemplateType(template.project_type);
            const candidateTokens = candidate.split(' ').filter(Boolean);
            const sharedTokens = needleTokens.filter((token) => candidateTokens.includes(token));
            const sharedDistinctiveTokens = distinctiveNeedleTokens.filter((token) => candidateTokens.includes(token));
            const directMatch = candidate.includes(needle) || needle.includes(candidate);
            const score = (sharedTokens.length / Math.max(needleTokens.length, candidateTokens.length))
                + (sharedDistinctiveTokens.length * 0.5)
                + (directMatch ? 0.5 : 0);

            return { template, score, sharedDistinctiveCount: sharedDistinctiveTokens.length };
        })
        .filter((match) => match.sharedDistinctiveCount > 0 && match.score >= 0.65)
        .sort((a, b) => b.score - a.score);

    if (scoredMatches.length === 0 || (scoredMatches.length > 1 && scoredMatches[0].score === scoredMatches[1].score)) {
        return null;
    }

    return scoredMatches[0].template;
}

function syncAddProjectTimelineWithPriority(force = false) {
    const form = document.getElementById('addProjectForm');
    const timelineEnd = form?.elements?.timeline_end;
    const template = form ? findSalesProjectTemplate(form.elements.project_type?.value || '') : null;
    if (!form || !timelineEnd || !template) return;
    if (!force && timelineEnd.value && timelineEnd.dataset.templateDurationAuto !== '1') return;

    const weeks = getPriorityAdjustedTemplateWeeks(template, form.elements.priority?.value || 'medium');
    if (!weeks) return;
    const startValue = form.elements.timeline_start?.value || addProjectDatePlusDays('', 0);
    timelineEnd.value = addProjectDatePlusDays(startValue, weeks * 7);
    timelineEnd.dataset.templateDurationAuto = '1';
    timelineEnd.dataset.templateDurationWeeks = String(weeks);
}

function syncAddProjectBudgetWithPriority() {
    const form = document.getElementById('addProjectForm');
    if (!form) {
        return;
    }

    if (!Number.isFinite(Number(addProjectAutoBudgetBase)) || Number(addProjectAutoBudgetBase) <= 0) {
        const currentBudget = Number(String(form.elements.sales_budget?.value || '').replace(/,/g, ''));
        addProjectAutoBudgetBase = Number.isFinite(currentBudget) && currentBudget > 0 ? currentBudget : null;
    }
    if (!Number.isFinite(Number(addProjectAutoBudgetBase)) || Number(addProjectAutoBudgetBase) <= 0) return;

    const markup = getAddProjectPriorityMarkup(form.elements.priority?.value || 'medium');
    form.elements.sales_budget.value = (Number(addProjectAutoBudgetBase) * (1 + markup.rate)).toFixed(2);
}

function resizeAddProjectAdditionalRequirements() {
    const field = document.querySelector('#addProjectForm textarea[name="additional_requirements"]');
    if (!field || field.offsetParent === null) {
        return;
    }

    field.style.height = 'auto';
    field.style.height = `${Math.max(118, field.scrollHeight + 2)}px`;
    field.scrollTop = 0;
}

function queueAddProjectAdditionalRequirementsResize() {
    window.requestAnimationFrame(() => {
        resizeAddProjectAdditionalRequirements();
        window.requestAnimationFrame(resizeAddProjectAdditionalRequirements);
    });
}

function resetAddProjectTemplateState() {
    appliedAddProjectTemplateType = '';
    addProjectAutoBudgetBase = null;
    addProjectAutoBudgetFromTemplate = false;
    const panel = document.querySelector('#addProjectForm [data-add-project-template-recommendation]');
    if (panel) {
        panel.hidden = true;
        panel.innerHTML = '<strong>Project template search</strong><span>Choose a project type to apply saved template details.</span>';
    }
    const trigger = document.querySelector('#addProjectForm [data-add-project-template-insight-trigger]');
    trigger?.classList.remove('is-open');
    trigger?.setAttribute('aria-expanded', 'false');
    resetAddProjectPriorityInsight();
}

function announceSalesAssistant(heading, message) {
    if (typeof window.evortixAnnounceNotification === 'function') {
        window.evortixAnnounceNotification(heading, message, '');
    } else {
        window.appToast?.(heading, message, 'info');
    }
}

function showSalesAssistantProjectTypeWarning(modalElement, previousType, nextType) {
    return new Promise((resolve) => {
        const botFloat = document.getElementById('chat-bot-float');
        const speech = botFloat?.querySelector('.chat-bot-speech');
        if (!botFloat || !speech) {
            resolve(false);
            return;
        }

        speech.className = 'chat-bot-speech evortix-project-notification evortix-decision-warning';
        speech.hidden = false;
        speech.setAttribute('role', 'alertdialog');
        speech.setAttribute('aria-hidden', 'false');
        speech.style.pointerEvents = 'auto';
        speech.innerHTML = `
            <strong><i class="fas fa-triangle-exclamation me-1"></i> Should I change the project type?</strong>
            <span class="evortix-project-notification-copy">Changing from ${escapeClientHtml(previousType || 'the current type')} to ${escapeClientHtml(nextType || 'the new type')} will delete your current project progress.</span>
            <div class="evortix-decision-actions">
                <button type="button" class="btn btn-light btn-sm" data-assistant-warning-cancel>Keep progress</button>
                <button type="button" class="btn btn-danger btn-sm" data-assistant-warning-confirm>Delete &amp; change</button>
            </div>`;
        botFloat.hidden = false;
        botFloat.setAttribute('aria-hidden', 'false');
        botFloat.classList.remove('is-returning-bed', 'is-preparing-speech');
        botFloat.classList.add('is-speaking');
        window.chatBotFloatRobot?.wake?.();
        window.chatBotFloatRobot?.setEmotion?.('thinking');

        const finish = (confirmed) => {
            speech.querySelectorAll('button').forEach((button) => { button.disabled = true; });
            document.removeEventListener('click', warningClickHandler, true);
            if (!confirmed) announceSalesAssistant('Progress kept', 'Okay, I kept your current project type and all existing progress.');
            resolve(confirmed);
        };
        const warningClickHandler = (event) => {
            if (event.target.closest('[data-assistant-warning-cancel]')) {
                event.preventDefault(); finish(false);
            } else if (event.target.closest('[data-assistant-warning-confirm]')) {
                event.preventDefault(); finish(true);
            }
        };
        document.addEventListener('click', warningClickHandler, true);
        speech.querySelector('[data-assistant-warning-cancel]')?.focus();
    });
}

function showSalesAssistantFeatureRemoval(featureName) {
    return new Promise((resolve) => {
        const botFloat = document.getElementById('chat-bot-float');
        const speech = botFloat?.querySelector('.chat-bot-speech');
        if (!botFloat || !speech) {
            resolve(false);
            return;
        }

        speech.className = 'chat-bot-speech evortix-project-notification evortix-decision-warning';
        speech.hidden = false;
        speech.setAttribute('role', 'alertdialog');
        speech.setAttribute('aria-hidden', 'false');
        speech.style.pointerEvents = 'auto';
        speech.innerHTML = `
            <strong><i class="fas fa-robot me-1"></i> Remove ${escapeClientHtml(featureName)}?</strong>
            <span class="evortix-project-notification-copy">I recommended this feature from the selected project template. Removing it may reduce the requirements and Architect handoff context.</span>
            <div class="evortix-decision-actions">
                <button type="button" class="btn btn-light btn-sm" data-assistant-feature-keep>Keep feature</button>
                <button type="button" class="btn btn-danger btn-sm" data-assistant-feature-remove>Remove feature</button>
            </div>`;
        botFloat.hidden = false;
        botFloat.setAttribute('aria-hidden', 'false');
        botFloat.classList.remove('is-returning-bed', 'is-preparing-speech');
        botFloat.classList.add('is-speaking');
        window.chatBotFloatRobot?.wake?.();
        window.chatBotFloatRobot?.setEmotion?.('thinking');

        const finish = (confirmed) => {
            speech.querySelectorAll('button').forEach((button) => { button.disabled = true; });
            document.removeEventListener('click', featureRemovalHandler, true);
            window.chatBotFloatRobot?.setEmotion?.(confirmed ? 'sad' : 'happy', 700);
            resolve(confirmed);
        };
        const featureRemovalHandler = (event) => {
            if (event.target.closest('[data-assistant-feature-keep]')) {
                event.preventDefault();
                finish(false);
            } else if (event.target.closest('[data-assistant-feature-remove]')) {
                event.preventDefault();
                finish(true);
            }
        };
        document.addEventListener('click', featureRemovalHandler, true);
        speech.querySelector('[data-assistant-feature-keep]')?.focus();
    });
}

function clearAddProjectTemplateValues() {
    const form = document.getElementById('addProjectForm');
    if (!form) return;
    if (form.elements.project_description) form.elements.project_description.value = '';
    renderAddProjectFeatureRows('');
    if (form.elements.sales_budget) form.elements.sales_budget.value = '';
    if (form.elements.timeline_start) form.elements.timeline_start.value = '';
    if (form.elements.timeline_end) {
        form.elements.timeline_end.value = '';
        delete form.elements.timeline_end.dataset.templateDurationAuto;
        delete form.elements.timeline_end.dataset.templateDurationWeeks;
    }
    if (form.elements.additional_requirements) form.elements.additional_requirements.value = '';
    if (form.elements.sales_notes) form.elements.sales_notes.value = '';
    addProjectAutoBudgetBase = null;
    addProjectAutoBudgetFromTemplate = false;
    appliedAddProjectTemplateType = '';
    syncAddProjectFeatureField();
    queueAddProjectAdditionalRequirementsResize();
}

function applyAddProjectTemplate(template, options = {}) {
    const form = document.getElementById('addProjectForm');
    if (!form || !template) {
        return;
    }

    const force = Boolean(options.force);
    appliedAddProjectTemplateType = normalizeSalesTemplateType(template.project_type);

    if (form.elements.project_type) {
        form.elements.project_type.value = template.project_type || '';
    }
    if (form.elements.project_description && (force || !form.elements.project_description.value.trim())) {
        form.elements.project_description.value = template.project_description || `${template.project_type} based on the ${template.methodology || 'recommended'} project template.`;
    }
    if (form.elements.requested_features && Array.isArray(template.feature_items) && template.feature_items.length > 0 && (force || !getAddProjectFeatureValues().length)) {
        renderAddProjectFeatureRows(template.feature_items.join("\n"));
    }
    if (form.elements.sales_budget && Number(template.budget_min || 0) > 0 && (force || !form.elements.sales_budget.value.trim() || addProjectAutoBudgetFromTemplate)) {
        addProjectAutoBudgetBase = Number(template.budget_min || 0);
        addProjectAutoBudgetFromTemplate = true;
        syncAddProjectBudgetWithPriority();
    }
    if (form.elements.timeline_start && (force || !form.elements.timeline_start.value)) {
        form.elements.timeline_start.value = addProjectDatePlusDays('', 0);
    }
    if (form.elements.timeline_end && getPriorityAdjustedTemplateWeeks(template, form.elements.priority?.value || 'medium') > 0 && (force || !form.elements.timeline_end.value)) {
        const startValue = form.elements.timeline_start?.value || addProjectDatePlusDays('', 0);
        const durationWeeks = getPriorityAdjustedTemplateWeeks(template, form.elements.priority?.value || 'medium');
        form.elements.timeline_end.value = addProjectDatePlusDays(startValue, durationWeeks * 7);
        form.elements.timeline_end.dataset.templateDurationAuto = '1';
        form.elements.timeline_end.dataset.templateDurationWeeks = String(durationWeeks);
    }
    if (form.elements.additional_requirements && (force || !form.elements.additional_requirements.value.trim())) {
        form.elements.additional_requirements.value = [
            template.functional_requirements ? 'Functional requirements: ' + template.functional_requirements : '',
            template.non_functional_requirements ? 'Non-functional requirements: ' + template.non_functional_requirements : '',
            template.integrations ? 'Suggested integrations: ' + template.integrations : '',
            template.third_party_services ? 'Third-party services/APIs: ' + template.third_party_services : '',
            template.payment_terms ? 'Suggested payment terms: ' + template.payment_terms : ''
        ].filter(Boolean).join("\n");
        queueAddProjectAdditionalRequirementsResize();
    }
    if (form.elements.sales_notes && (force || !form.elements.sales_notes.value.trim())) {
        form.elements.sales_notes.value = [
            template.sales_notes || '',
            template.suggested_milestones ? 'Suggested milestones: ' + template.suggested_milestones : '',
            template.methodology ? 'Recommended methodology: ' + template.methodology : '',
            template.tech_stack ? 'Architect recommendation preview: ' + template.tech_stack : '',
            template.team_composition ? 'Suggested team: ' + template.team_composition : '',
            template.maintenance_support ? 'Maintenance/support: ' + template.maintenance_support : ''
        ].filter(Boolean).join("\n");
    }

    updateAddProjectTemplateRecommendation();
    updateAddProjectGeneratedComplexity();
}

function updateAddProjectTemplateRecommendation() {
    const form = document.getElementById('addProjectForm');
    const panel = document.querySelector('#addProjectForm [data-add-project-template-recommendation]');
    if (!form || !panel) {
        return;
    }

    const projectType = String(form.elements.project_type?.value || '').trim();
    const template = findSalesProjectTemplate(projectType);
    if (!projectType) {
        setAddProjectTemplateRecommendation(panel, '<strong>Project template search</strong><span>Choose a project type to apply saved template details.</span>');
        return;
    }
    if (!template) {
        setAddProjectTemplateRecommendation(panel, `<strong>New project type</strong><span>No saved template matches "${escapeClientHtml(projectType)}". Fill the project details manually.</span>`);
        return;
    }

    const templateType = normalizeSalesTemplateType(template.project_type);
    const budgetRange = `${formatProjectPeso(template.budget_min || 0)} - ${formatProjectPeso(template.budget_max || 0)}`;
    const appliedStatus = appliedAddProjectTemplateType === templateType
        ? '<span class="template-apply-status"><i class="fas fa-check-circle"></i>Template suggestions applied.</span>'
        : '';
    setAddProjectTemplateRecommendation(panel, `
        <strong>Recommended template: ${escapeClientHtml(template.project_type)}</strong>
        <span>${escapeClientHtml(template.duration_label || 'Timeline TBD')} | ${escapeClientHtml(budgetRange)} | ${escapeClientHtml(template.methodology || 'Methodology TBD')}</span>
        <span>Features: ${escapeClientHtml(template.core_features || 'No feature list saved yet.')}</span>
        ${appliedStatus}
        <button type="button" class="btn btn-outline-primary btn-sm" data-apply-add-project-template>Apply template suggestions</button>
    `);
}

function setAddProjectFeatureEditorExpanded(expanded, focusInput = false) {
    const shell = document.querySelector('#addProjectForm [data-add-project-feature-shell]');
    const toggle = shell?.querySelector('[data-add-project-feature-toggle]');
    const toggleLabel = toggle?.querySelector('[data-add-project-feature-toggle-label]');
    if (!shell || !toggle) {
        return;
    }

    const shouldExpand = Boolean(expanded && !toggle.hidden);
    shell.classList.toggle('is-expanded', shouldExpand);
    toggle.setAttribute('aria-expanded', shouldExpand ? 'true' : 'false');
    toggle.setAttribute('aria-label', shouldExpand ? 'Show fewer requested features' : 'See more requested features');
    if (toggleLabel) {
        toggleLabel.textContent = shouldExpand ? 'Less' : 'More';
    }

    if (focusInput) {
        window.requestAnimationFrame(() => {
            const input = shell.querySelector('[data-add-project-feature-input]');
            const editor = shell.querySelector('#addProjectRequestedFeatureList');
            input?.focus();
            if (editor && shouldExpand) editor.scrollTop = editor.scrollHeight;
        });
    }
}

function updateAddProjectFeatureEditorAvailability() {
    const shell = document.querySelector('#addProjectForm [data-add-project-feature-shell]');
    const editor = shell?.querySelector('#addProjectRequestedFeatureList');
    const toggle = shell?.querySelector('[data-add-project-feature-toggle]');
    if (!shell || !editor || !toggle || editor.offsetParent === null) {
        return false;
    }

    const wasExpanded = shell.classList.contains('is-expanded');
    shell.classList.remove('is-expanded', 'has-more');
    toggle.hidden = true;
    const flowItems = Array.from(editor.children).filter((item) => !item.matches('.requested-feature-chip-add'));
    const firstRowTop = flowItems.length ? Math.min(...flowItems.map((item) => item.offsetTop)) : 0;
    const hasMore = flowItems.some((item) => item.offsetTop > firstRowTop + 4);
    shell.classList.toggle('has-more', hasMore);
    toggle.hidden = !hasMore;
    setAddProjectFeatureEditorExpanded(hasMore && wasExpanded, false);
    return hasMore;
}

function queueAddProjectFeatureEditorAvailability() {
    window.requestAnimationFrame(() => {
        updateAddProjectFeatureEditorAvailability();
        window.requestAnimationFrame(updateAddProjectFeatureEditorAvailability);
    });
}

function toggleAddProjectFeatureEditor() {
    const shell = document.querySelector('#addProjectForm [data-add-project-feature-shell]');
    if (shell?.querySelector('[data-add-project-feature-toggle]')?.hidden) {
        return;
    }
    setAddProjectFeatureEditorExpanded(!shell?.classList.contains('is-expanded'), true);
}

function createAddProjectFeatureChip(value) {
    const chip = document.createElement('span');
    const label = document.createElement('span');
    const removeButton = document.createElement('button');
    const normalizedValue = String(value || '').trim();

    chip.className = 'requested-feature-chip';
    chip.dataset.addProjectFeatureValue = normalizedValue;
    chip.setAttribute('role', 'listitem');
    label.textContent = normalizedValue;

    removeButton.type = 'button';
    removeButton.className = 'requested-feature-chip-remove';
    removeButton.dataset.removeAddProjectFeature = '';
    removeButton.setAttribute('aria-label', `Remove ${normalizedValue}`);
    removeButton.innerHTML = '<i class="fas fa-times" aria-hidden="true"></i>';

    chip.append(label, removeButton);
    return chip;
}

function createAddProjectFeatureInput() {
    const input = document.createElement('input');
    input.type = 'text';
    input.className = 'requested-feature-chip-input';
    input.dataset.addProjectFeatureInput = '';
    input.placeholder = 'Type a feature';
    input.setAttribute('aria-label', 'New requested feature');
    return input;
}

function getAddProjectFeatureValues() {
    return Array.from(document.querySelectorAll('#addProjectRequestedFeatureList [data-add-project-feature-value]'))
        .map((chip) => String(chip.dataset.addProjectFeatureValue || '').trim())
        .filter((value) => value !== '');
}

function syncAddProjectFeatureField() {
    const field = document.getElementById('addProjectRequestedFeaturesField');
    if (field) {
        field.value = getAddProjectFeatureValues().join("\n");
    }
}

function addAddProjectFeatureValue(value, focusInput = true) {
    const featureList = document.getElementById('addProjectRequestedFeatureList');
    const input = featureList?.querySelector('[data-add-project-feature-input]');
    const normalizedValue = String(value || '').trim().replace(/\s+/g, ' ');
    if (!featureList || !input || !normalizedValue) {
        return false;
    }

    const duplicate = Array.from(featureList.querySelectorAll('[data-add-project-feature-value]'))
        .find((chip) => normalizeSalesTemplateType(chip.dataset.addProjectFeatureValue) === normalizeSalesTemplateType(normalizedValue));
    input.value = '';
    input.setCustomValidity('');

    if (duplicate) {
        duplicate.classList.add('is-duplicate');
        duplicate.scrollIntoView({ block: 'nearest', inline: 'nearest' });
        window.setTimeout(() => duplicate.classList.remove('is-duplicate'), 650);
        if (focusInput) setAddProjectFeatureEditorExpanded(true, true);
        return false;
    }

    featureList.insertBefore(createAddProjectFeatureChip(normalizedValue), input);
    syncAddProjectFeatureField();
    featureList.scrollTop = featureList.scrollHeight;
    updateAddProjectFeatureEditorAvailability();
    if (focusInput) setAddProjectFeatureEditorExpanded(true, true);
    return true;
}

function commitAddProjectFeatureInput(focusInput = true) {
    const input = document.querySelector('#addProjectRequestedFeatureList [data-add-project-feature-input]');
    if (!input || !String(input.value || '').trim()) {
        if (focusInput) setAddProjectFeatureEditorExpanded(true, true);
        return false;
    }
    return addAddProjectFeatureValue(input.value, focusInput);
}

function renderAddProjectFeatureRows(rawValue = '') {
    const featureList = document.getElementById('addProjectRequestedFeatureList');
    if (!featureList) {
        return;
    }

    const seen = new Set();
    const features = String(rawValue || '')
        .split(/\r?\n/)
        .map((feature) => feature.trim())
        .filter((feature) => {
            const normalized = normalizeSalesTemplateType(feature);
            if (!normalized || seen.has(normalized)) return false;
            seen.add(normalized);
            return true;
        });

    featureList.replaceChildren();
    features.forEach((feature) => featureList.appendChild(createAddProjectFeatureChip(feature)));
    featureList.append(createAddProjectFeatureInput());

    syncAddProjectFeatureField();
    setAddProjectFeatureEditorExpanded(false, false);
    queueAddProjectFeatureEditorAvailability();
}

function getAddProjectValue(name) {
    const form = document.getElementById('addProjectForm');
    const field = form?.elements[name];
    return field ? String(field.value || '').trim() : '';
}

function formatAddProjectDate(value) {
    if (!value) {
        return '';
    }

    const parts = value.split('-').map(Number);
    if (parts.length !== 3 || parts.some((part) => Number.isNaN(part))) {
        return value;
    }

    return new Date(parts[0], parts[1] - 1, parts[2]).toLocaleDateString([], {
        month: 'short',
        day: 'numeric',
        year: 'numeric'
    });
}

function formatAddProjectTime(value) {
    if (!value) {
        return '';
    }

    const parts = value.split(':').map(Number);
    if (parts.length < 2 || parts.some((part) => Number.isNaN(part))) {
        return value;
    }

    const parsed = new Date();
    parsed.setHours(parts[0], parts[1], 0, 0);
    return parsed.toLocaleTimeString([], {
        hour: 'numeric',
        minute: '2-digit'
    });
}

function formatAddProjectDateTime(dateValue, timeValue) {
    const date = formatAddProjectDate(dateValue);
    const time = formatAddProjectTime(timeValue);
    if (date && time) {
        return `${date}, ${time}`;
    }
    return date || time;
}

function buildAddProjectTimelineSummary() {
    const start = formatAddProjectDateTime(getAddProjectValue('timeline_start'), getAddProjectValue('timeline_start_time'));
    const end = formatAddProjectDateTime(getAddProjectValue('timeline_end'), getAddProjectValue('timeline_end_time'));

    if (start && end) {
        return `${start} to ${end}`;
    }
    if (start) {
        return `Starts ${start}`;
    }
    if (end) {
        return `Due ${end}`;
    }
    return '';
}

function getAddProjectComplexityAssessment() {
    const startValue = getAddProjectValue('timeline_start');
    const endValue = getAddProjectValue('timeline_end');
    const budgetRaw = getAddProjectValue('sales_budget').replace(/,/g, '');
    const priorityValue = getAddProjectValue('priority') || 'medium';
    const featureCount = getAddProjectFeatureValues().length;
    const budget = Number(budgetRaw);
    let days = 0;
    let workingDays = 0;

    if (startValue && endValue) {
        const startDate = new Date(startValue + 'T00:00:00');
        const endDate = new Date(endValue + 'T00:00:00');
        if (!Number.isNaN(startDate.getTime()) && !Number.isNaN(endDate.getTime()) && endDate >= startDate) {
            days = Math.floor((endDate - startDate) / 86400000) + 1;
            for (const date = new Date(startDate); date <= endDate; date.setDate(date.getDate() + 1)) {
                if (date.getDay() !== 0 && date.getDay() !== 6) workingDays++;
            }
        }
    }

    const budgetBand = Number.isFinite(budget) && budget >= 500000
        ? (budget <= 1500000 ? 'medium' : 'high')
        : 'low';
    const timelineBand = days > 0 && days <= 30
        ? 'short'
        : (days > 0 && days <= 90 ? 'moderate' : 'long');
    const score = (
        ({ low: 0, medium: 1, high: 2 }[budgetBand] ?? 1)
        + ({ long: 0, moderate: 1, short: 2 }[timelineBand] ?? 1)
        + ({ low: 0, medium: 1, high: 2, critical: 2 }[String(priorityValue).toLowerCase()] ?? 1)
        + (featureCount >= 13 ? 2 : (featureCount >= 8 ? 1 : 0))
    );
    const scheduleIsCompressed = featureCount > 0 && workingDays > 0 && workingDays < (featureCount * 3);
    const level = featureCount >= 13 || scheduleIsCompressed || score >= 6 ? 'High' : (score >= 3 ? 'Medium' : 'Low');
    const budgetLabel = ({ low: 'Low budget', medium: 'Medium budget', high: 'High budget' }[budgetBand] || 'Medium budget');
    const timelineLabel = ({ long: 'Long timeline', moderate: 'Moderate timeline', short: 'Short timeline' }[timelineBand] || 'Moderate timeline');
    const priorityLabel = getAddProjectPriorityMarkup(priorityValue).label + ' priority';
    const scheduleReason = featureCount > 0 && workingDays > 0
        ? ` The schedule provides ${workingDays} working day${workingDays === 1 ? '' : 's'} against an estimated ${featureCount * 3}–${featureCount * 5} working days for the selected features.`
        : '';
    const reason = `${level} complexity based on ${featureCount} requested features, ${budgetLabel.toLowerCase()}, ${timelineLabel.toLowerCase()}, and ${priorityLabel.toLowerCase()} for a score of ${score}/8.${scheduleReason}`;

    return { level, reason, score };
}

function updateAddProjectGeneratedComplexity() {
    const form = document.getElementById('addProjectForm');
    if (!form) {
        return;
    }

    const assessment = getAddProjectComplexityAssessment();
    const priorityMarkup = getAddProjectPriorityMarkup(form.elements.priority?.value || 'medium');
    const budgetRaw = getAddProjectValue('sales_budget').replace(/,/g, '');
    const budget = Number(budgetRaw);
    const hasBudget = budgetRaw !== '' && Number.isFinite(budget);
    const baseBudget = Number.isFinite(Number(addProjectAutoBudgetBase)) && Number(addProjectAutoBudgetBase) > 0
        ? Number(addProjectAutoBudgetBase)
        : budget;
    const previewAmount = hasBudget ? baseBudget * priorityMarkup.rate : 0;
    const adjustedBudget = hasBudget ? baseBudget + previewAmount : 0;

    const complexityField = form.querySelector('[data-add-project-generated-complexity]');
    if (complexityField) {
        complexityField.value = assessment.level;
    }

    const complexityReason = form.querySelector('[data-add-project-complexity-reason]');
    if (complexityReason) {
        complexityReason.innerHTML = `<strong>${escapeClientHtml(assessment.level)} complexity</strong><span>${escapeClientHtml(assessment.reason)}</span>`;
    }
    syncComplexityPopover(complexityReason, form.querySelector('[data-complexity-popover]'));

    const priorityMarkupPreview = form.querySelector('[data-add-project-priority-markup-preview]');
    if (priorityMarkupPreview) {
        setAddProjectPriorityInsight(priorityMarkupPreview, `<strong>${escapeClientHtml(priorityMarkup.label)} priority adds +${priorityMarkup.percent}%</strong><span>${escapeClientHtml(priorityMarkup.reason)}</span>`);
    }

    const priorityPricePreview = form.querySelector('[data-add-project-priority-price-preview]');
    if (priorityPricePreview) {
        const amountText = hasBudget ? formatProjectPeso(previewAmount) : 'Enter a budget to calculate the amount';
        const totalText = hasBudget ? ` Adjusted intake budget: ${formatProjectPeso(adjustedBudget)}.` : '';
        priorityPricePreview.innerHTML = `<strong>Priority price preview: ${escapeClientHtml(amountText)}</strong><span>${escapeClientHtml(priorityMarkup.label)} priority adds ${priorityMarkup.percent}% to the base budget during intake.${escapeClientHtml(totalText)} Final pricing is recalculated later from the Expert project cost estimate.</span>`;
    }

    const budgetHelper = form.querySelector('[data-add-project-budget-helper]');
    if (budgetHelper) {
        budgetHelper.textContent = addProjectAutoBudgetFromTemplate && hasBudget
            ? `Auto-adjusted from template base ${formatProjectPeso(baseBudget)} plus ${priorityMarkup.label.toLowerCase()} priority markup ${formatProjectPeso(previewAmount)}. You can still type a custom budget.`
            : 'Required as the decision insight budget baseline.';
    }
}

function renderAddProjectSummary() {
    const form = document.getElementById('addProjectForm');
    if (!form) {
        return;
    }

    syncAddProjectFeatureField();
    updateAddProjectGeneratedComplexity();
    const features = getAddProjectFeatureValues();
    const attachmentInput = form.querySelector('input[name="project_attachments[]"]');
    const attachmentNames = Array.from(attachmentInput?.files || []).map((file) => file.name).filter(Boolean);
    const priorityMarkup = getAddProjectPriorityMarkup(getAddProjectValue('priority') || 'medium');
    const assessment = getAddProjectComplexityAssessment();
    const budgetRaw = getAddProjectValue('sales_budget').replace(/,/g, '');
    const budget = Number(budgetRaw);
    const hasBudget = budgetRaw !== '' && Number.isFinite(budget);
    const summaryBaseBudget = Number.isFinite(Number(addProjectAutoBudgetBase)) && Number(addProjectAutoBudgetBase) > 0
        ? Number(addProjectAutoBudgetBase)
        : budget;
    const priorityMarkupAmount = hasBudget ? summaryBaseBudget * priorityMarkup.rate : 0;
    const priorityMarkupDetail = hasBudget
        ? `${priorityMarkup.label} priority adds ${priorityMarkup.percent}% (${formatProjectPeso(priorityMarkupAmount)}) based on a ${formatProjectPeso(summaryBaseBudget)} base budget. ${priorityMarkup.reason}`
        : `${priorityMarkup.label} priority adds ${priorityMarkup.percent}%. ${priorityMarkup.reason}`;
    const summaryData = {
        project_name: getAddProjectValue('project_name'),
        project_type: getAddProjectValue('project_type'),
        timeline: buildAddProjectTimelineSummary(),
        sales_budget: hasBudget ? formatProjectPeso(budget) : '',
        priority: getAddProjectValue('priority') || 'medium',
        generated_complexity: assessment.level,
        priority_markup_detail: priorityMarkupDetail,
        complexity_reason: assessment.reason,
        attachments: attachmentNames.join("\n"),
        requested_feature_count: `${features.length} feature${features.length === 1 ? '' : 's'}`,
        project_description: getAddProjectValue('project_description'),
        additional_requirements: getAddProjectValue('additional_requirements'),
        meeting_notes: getAddProjectValue('meeting_notes'),
        mom: getAddProjectValue('mom'),
        sales_notes: getAddProjectValue('sales_notes')
    };

    form.querySelectorAll('[data-add-project-summary-field]').forEach((field) => {
        const key = field.dataset.addProjectSummaryField || '';
        const fallback = key === 'timeline'
            ? 'Not set'
            : (key === 'attachments' ? 'No attachments' : 'Not provided');
        const value = summaryData[key] || '';
        field.textContent = value || fallback;
        field.classList.toggle('is-empty', value === '');

        const chip = field.closest('[data-add-project-summary-chip]');
        if (chip) {
            chip.dataset.value = String(value || '').toLowerCase();
        }
    });

    const featureList = form.querySelector('[data-add-project-summary-feature-list]');
    if (featureList) {
        featureList.replaceChildren();
        if (features.length === 0) {
            const emptyItem = document.createElement('li');
            emptyItem.className = 'is-empty';
            emptyItem.textContent = 'Not provided';
            featureList.appendChild(emptyItem);
        } else {
            features.forEach((feature) => {
                const item = document.createElement('li');
                item.textContent = feature;
                featureList.appendChild(item);
            });
        }
    }
}

function prepareAddProjectFeatureValidation(shouldReport = true) {
    const input = document.querySelector('#addProjectRequestedFeatureList [data-add-project-feature-input]');
    input?.setCustomValidity('');
    commitAddProjectFeatureInput(false);

    if (getAddProjectFeatureValues().length > 0) {
        return true;
    }

    if (input) {
        input.setCustomValidity('Add at least one requested feature before sending the project to Architect.');
        if (shouldReport) {
            input.reportValidity();
        }
    }
    return false;
}

function prepareAddProjectTimelineValidation(shouldReport = true) {
    const form = document.getElementById('addProjectForm');
    const startDateField = form?.elements.timeline_start;
    const startTimeField = form?.elements.timeline_start_time;
    const endDateField = form?.elements.timeline_end;
    const endTimeField = form?.elements.timeline_end_time;
    if (!startDateField || !startTimeField || !endDateField || !endTimeField) {
        return true;
    }

    endDateField.setCustomValidity('');
    endTimeField.setCustomValidity('');
    endDateField.min = startDateField.value || '';
    if (startDateField.value && endDateField.value && endDateField.value < startDateField.value) {
        endDateField.setCustomValidity('Project deadline cannot be earlier than the timeline start date.');
        if (shouldReport) {
            endDateField.reportValidity();
        }
        return false;
    }
    if (!startDateField.value || !startTimeField.value || !endDateField.value || !endTimeField.value) {
        return true;
    }

    const startAt = new Date(`${startDateField.value}T${startTimeField.value}:00`);
    const endAt = new Date(`${endDateField.value}T${endTimeField.value}:00`);
    if (Number.isNaN(startAt.getTime()) || Number.isNaN(endAt.getTime()) || endAt <= startAt) {
        endTimeField.setCustomValidity('Project deadline must be later than the timeline start date and time.');
        if (shouldReport) {
            endTimeField.reportValidity();
        }
        return false;
    }

    return true;
}

function validateAddProjectStep(stepIndex, shouldReport = true) {
    const form = document.getElementById('addProjectForm');
    const panel = form?.querySelector(`[data-add-project-step="${stepIndex}"]`);
    if (!panel) {
        return true;
    }

    if (stepIndex === 0 && !prepareAddProjectFeatureValidation(shouldReport)) {
        return false;
    }
    if (stepIndex === 1 && !prepareAddProjectTimelineValidation(shouldReport)) {
        return false;
    }

    const fields = Array.from(panel.querySelectorAll('input, select, textarea'));
    for (const field of fields) {
        if (!field.checkValidity()) {
            if (shouldReport) {
                field.reportValidity();
            }
            return false;
        }
    }

    return true;
}

let currentAddProjectStep = 0;

function showAddProjectStep(stepIndex) {
    const modal = document.getElementById('addProjectModal');
    if (!modal) {
        return;
    }

    const panels = Array.from(modal.querySelectorAll('[data-add-project-step]'));
    const buttons = Array.from(modal.querySelectorAll('[data-add-project-step-target]'));
    const previousButton = modal.querySelector('[data-add-project-prev]');
    const nextButton = modal.querySelector('[data-add-project-next]');
    const submitButton = modal.querySelector('[data-add-project-submit]');
    const handoffNote = modal.querySelector('.architect-handoff-note');

    currentAddProjectStep = Math.max(0, Math.min(stepIndex, panels.length - 1));
    if (currentAddProjectStep === panels.length - 1) {
        renderAddProjectSummary();
    }

    panels.forEach((panel, index) => {
        panel.hidden = index !== currentAddProjectStep;
    });
    buttons.forEach((button, index) => {
        button.classList.toggle('is-active', index === currentAddProjectStep);
        button.classList.toggle('is-complete', index < currentAddProjectStep);
        button.setAttribute('aria-current', index === currentAddProjectStep ? 'step' : 'false');
    });

    if (previousButton) {
        previousButton.hidden = currentAddProjectStep === 0;
    }
    if (nextButton) {
        nextButton.hidden = currentAddProjectStep === panels.length - 1;
    }
    if (submitButton) {
        submitButton.hidden = currentAddProjectStep !== panels.length - 1;
    }
    if (handoffNote) {
        handoffNote.hidden = currentAddProjectStep === panels.length - 1;
    }
    if (currentAddProjectStep === 0) {
        queueAddProjectAdditionalRequirementsResize();
        queueAddProjectFeatureEditorAvailability();
    }
}

const addProjectFormElement = document.getElementById('addProjectForm');
if (addProjectFormElement) {
    addProjectFormElement.addEventListener('focusin', function (event) {
        if (event.target?.name === 'project_type' && !event.target.dataset.previousProjectType) {
            event.target.dataset.previousProjectType = event.target.value;
        }
        if (event.target?.matches('[data-add-project-feature-input]')) {
            setAddProjectFeatureEditorExpanded(true, false);
        }
    });

    addProjectFormElement.addEventListener('input', function (event) {
        if (event.target?.name === 'project_type') {
            updateAddProjectTemplateRecommendation();
        }
        if (event.target?.name === 'sales_budget') {
            const enteredBudget = Number(String(event.target.value || '').replace(/,/g, ''));
            addProjectAutoBudgetBase = Number.isFinite(enteredBudget) && enteredBudget > 0 ? enteredBudget : null;
            addProjectAutoBudgetFromTemplate = false;
        }
        if (event.target?.name === 'additional_requirements') {
            resizeAddProjectAdditionalRequirements();
        }
        if (event.target?.name === 'timeline_end') {
            delete event.target.dataset.templateDurationAuto;
            delete event.target.dataset.templateDurationWeeks;
        }
        if (event.target?.name === 'timeline_start' && addProjectFormElement.elements.timeline_end?.dataset.templateDurationAuto === '1') {
            syncAddProjectTimelineWithPriority(true);
        }
        if (event.target?.matches('[data-add-project-feature-input]')) {
            event.target.setCustomValidity('');
            syncAddProjectFeatureField();
        }
        if (['timeline_start', 'timeline_start_time', 'timeline_end', 'timeline_end_time', 'sales_budget', 'priority'].includes(event.target?.name || '')) {
            addProjectFormElement.elements.timeline_end?.setCustomValidity('');
            addProjectFormElement.elements.timeline_end_time?.setCustomValidity('');
            if (addProjectFormElement.elements.timeline_end) {
                addProjectFormElement.elements.timeline_end.min = addProjectFormElement.elements.timeline_start?.value || '';
            }
            updateAddProjectGeneratedComplexity();
        }
    });

    addProjectFormElement.addEventListener('change', async function (event) {
        if (event.target?.name === 'project_type') {
            const previousType = String(event.target.dataset.previousProjectType || '').trim();
            const previousNormalized = normalizeSalesTemplateType(previousType) || appliedAddProjectTemplateType;
            const nextNormalized = normalizeSalesTemplateType(event.target.value);
            const trackedNames = ['project_description', 'additional_requirements', 'sales_notes', 'sales_budget', 'timeline_start', 'timeline_start_time', 'timeline_end', 'timeline_end_time'];
            const hasProgress = trackedNames.some((name) => String(addProjectFormElement.elements[name]?.value || '').trim() !== '') || getAddProjectFeatureValues().length > 0;
            if (previousNormalized && nextNormalized !== previousNormalized && hasProgress) {
                const shouldChange = await showSalesAssistantProjectTypeWarning(document.getElementById('addProjectModal'), previousType, event.target.value);
                if (!shouldChange) {
                    event.target.value = previousType;
                    updateAddProjectTemplateRecommendation();
                    return;
                }
                clearAddProjectTemplateValues();
                window.setTimeout(() => announceSalesAssistant('Project type changed', `${event.target.value} is ready. Review the new DSS recommendation before applying it.`), 220);
            }
            event.target.dataset.previousProjectType = event.target.value;
            updateAddProjectTemplateRecommendation();
        }
        if (event.target?.name === 'priority') {
            syncAddProjectBudgetWithPriority();
            syncAddProjectTimelineWithPriority(false);
            updateAddProjectTemplateRecommendation();
            updateAddProjectGeneratedComplexity();
        }
        if (event.target?.name === 'sales_budget') {
            syncAddProjectBudgetWithPriority();
            updateAddProjectGeneratedComplexity();
        }
    });

    addProjectFormElement.addEventListener('click', function (event) {
        const featureToggleButton = event.target.closest('[data-add-project-feature-toggle]');
        if (featureToggleButton) {
            toggleAddProjectFeatureEditor();
            return;
        }

        const templateInsightButton = event.target.closest('[data-add-project-template-insight-trigger]');
        if (templateInsightButton) {
            closeComplexityInsight(addProjectFormElement, false);
            closeAddProjectPriorityInsight(false);
            closeTemplatePickers();
            toggleAddProjectTemplateInsight(templateInsightButton);
            return;
        }

        const priorityInsightButton = event.target.closest('[data-add-project-priority-insight-trigger]');
        if (priorityInsightButton) {
            closeComplexityInsight(addProjectFormElement, false);
            closeAddProjectTemplateInsight(false);
            toggleAddProjectPriorityInsight(priorityInsightButton);
            return;
        }

        const complexityInsightButton = event.target.closest('[data-complexity-insight-trigger]');
        if (complexityInsightButton) {
            closeAddProjectTemplateInsight(false);
            closeAddProjectPriorityInsight(false);
            toggleComplexityInsight(complexityInsightButton, addProjectFormElement);
            return;
        }

        if (!event.target.closest('.complexity-field-shell')) {
            closeComplexityInsight(addProjectFormElement, false);
        }
        if (!event.target.closest('.template-recommendation-shell')) {
            closeAddProjectTemplateInsight(false);
        }
        if (!event.target.closest('.add-project-priority-insight-shell')) {
            closeAddProjectPriorityInsight(false);
        }
        if (!event.target.closest('[data-add-project-feature-shell]')) {
            setAddProjectFeatureEditorExpanded(false, false);
        }

        const nextButton = event.target.closest('[data-add-project-next]');
        if (nextButton) {
            if (validateAddProjectStep(currentAddProjectStep, true)) {
                showAddProjectStep(currentAddProjectStep + 1);
            }
            return;
        }

        const previousButton = event.target.closest('[data-add-project-prev]');
        if (previousButton) {
            showAddProjectStep(currentAddProjectStep - 1);
            return;
        }

        const removeFeatureButton = event.target.closest('[data-remove-add-project-feature]');
        if (removeFeatureButton) {
            removeFeatureButton.closest('[data-add-project-feature-value]')?.remove();
            syncAddProjectFeatureField();
            updateAddProjectFeatureEditorAvailability();
            document.querySelector('#addProjectRequestedFeatureList [data-add-project-feature-input]')?.focus();
            return;
        }

        const applyButton = event.target.closest('[data-apply-add-project-template]');
        if (!applyButton) {
            return;
        }

        applyAddProjectTemplate(findSalesProjectTemplate(addProjectFormElement.elements.project_type?.value), { force: true });
    });

    addProjectFormElement.addEventListener('keydown', function (event) {
        if (event.target?.matches('[data-add-project-feature-input]') && event.key === 'Enter') {
            event.preventDefault();
            commitAddProjectFeatureInput(true);
            return;
        }
        if (event.target?.matches('[data-add-project-feature-input]') && event.key === 'Backspace' && !event.target.value) {
            const chips = document.querySelectorAll('#addProjectRequestedFeatureList [data-add-project-feature-value]');
            chips[chips.length - 1]?.remove();
            syncAddProjectFeatureField();
            updateAddProjectFeatureEditorAvailability();
            return;
        }
        if (event.key === 'Escape') {
            const featureShell = addProjectFormElement.querySelector('[data-add-project-feature-shell]');
            if (featureShell?.classList.contains('is-expanded')) {
                setAddProjectFeatureEditorExpanded(false, false);
                featureShell.querySelector('[data-add-project-feature-toggle]')?.focus();
                return;
            }
            closeComplexityInsight(addProjectFormElement, true);
            closeAddProjectTemplateInsight(true);
            closeAddProjectPriorityInsight(true);
        }
    });

    window.addEventListener('resize', function () {
        const modal = document.getElementById('addProjectModal');
        if (modal?.classList.contains('show') && currentAddProjectStep === 0) {
            queueAddProjectAdditionalRequirementsResize();
            queueAddProjectFeatureEditorAvailability();
        }
    });

    addProjectFormElement.addEventListener('submit', function (event) {
        syncAddProjectFeatureField();
        if (currentAddProjectStep < 2) {
            event.preventDefault();
            if (validateAddProjectStep(currentAddProjectStep, true)) {
                showAddProjectStep(currentAddProjectStep + 1);
            }
            return;
        }

        for (let index = 0; index <= 1; index += 1) {
            if (!validateAddProjectStep(index, false)) {
                event.preventDefault();
                showAddProjectStep(index);
                validateAddProjectStep(index, true);
                return;
            }
        }
    });
}

function setProjectRowExpanded(panelId, shouldExpand) {
    const panel = document.getElementById(panelId);
    const toggle = document.querySelector(`[data-project-toggle="${panelId}"]`);
    const row = document.querySelector(`[data-project-row="${panelId}"]`);

    if (!panel || !toggle || !row) {
        return;
    }

    const shell = panel.querySelector('.project-expansion-shell');
    panel.classList.toggle('is-open', shouldExpand);
    row.classList.toggle('is-expanded', shouldExpand);
    toggle.setAttribute('aria-expanded', shouldExpand ? 'true' : 'false');

    if (shell) {
        shell.style.maxHeight = shouldExpand ? `${shell.scrollHeight}px` : '0px';
    }
}

document.addEventListener('click', function (event) {
    const clickedActionMenu = event.target.closest('.client-action-menu');
    document.querySelectorAll('.client-action-menu[open]').forEach(function (menu) {
        if (menu !== clickedActionMenu || event.target.closest('.client-action-menu__item')) {
            menu.removeAttribute('open');
        }
    });

    const toggle = event.target.closest('[data-project-toggle]');
    if (toggle) {
        event.stopPropagation();
        const panelId = toggle.getAttribute('data-project-toggle');
        setProjectRowExpanded(panelId, toggle.getAttribute('aria-expanded') !== 'true');
        return;
    }

    const row = event.target.closest('[data-project-row]');
    if (!row || event.target.closest('.action-buttons, button, a, input, select, textarea')) {
        return;
    }

    const panelId = row.getAttribute('data-project-row');
    const toggleForRow = document.querySelector(`[data-project-toggle="${panelId}"]`);
    if (toggleForRow) {
        setProjectRowExpanded(panelId, toggleForRow.getAttribute('aria-expanded') !== 'true');
    }
});

window.addEventListener('resize', function () {
    document.querySelectorAll('.project-expansion-row.is-open .project-expansion-shell').forEach((shell) => {
        shell.style.maxHeight = `${shell.scrollHeight}px`;
    });
});

function viewClient(id) {
    $.ajax({
        url: '../../api/get-client-details.php',
        method: 'GET',
        data: { id: id },
        success: function(response) {
            const client = typeof response === 'string' ? JSON.parse(response) : response;
            if (client.error) {
                window.appToast?.('Unable to load client', client.error, 'error');
                return;
            }
            const clientStatus = String(client.status || 'pending').toLowerCase();
            const clientStatusLabel = clientStatus.replaceAll('_', ' ');
            const clientDeadline = client.deadline ? new Date(client.deadline).toLocaleDateString() : 'Not set';
            let html = `
                <div class="client-details-overview">
                    <section class="client-detail-panel" aria-labelledby="clientContactHeading">
                        <header class="client-detail-panel-header">
                            <span class="client-detail-panel-icon"><i class="fas fa-address-card"></i></span>
                            <span>
                                <strong id="clientContactHeading">Contact Information</strong>
                                <small>Primary account and communication details</small>
                            </span>
                        </header>
                        <div class="client-detail-list">
                            <div class="client-detail-row"><span>Company</span><strong>${escapeClientHtml(client.name || 'Not provided')}</strong></div>
                            <div class="client-detail-row"><span>Contact person</span><strong>${escapeClientHtml(client.contact_person || 'Not provided')}</strong></div>
                            <div class="client-detail-row"><span>Email</span><strong>${escapeClientHtml(client.email || 'Not provided')}</strong></div>
                            <div class="client-detail-row"><span>Phone</span><strong>${escapeClientHtml(client.phone || 'Not provided')}</strong></div>
                            <div class="client-detail-row"><span>Address</span><strong>${escapeClientHtml(client.address || 'Not provided')}</strong></div>
                        </div>
                    </section>

                    <section class="client-detail-panel client-delivery-panel" aria-labelledby="clientDeliveryHeading">
                        <header class="client-detail-panel-header">
                            <span class="client-detail-panel-icon"><i class="fas fa-chart-line"></i></span>
                            <span>
                                <strong id="clientDeliveryHeading">Delivery Overview</strong>
                                <small>Current account and project readiness</small>
                            </span>
                        </header>
                        <div class="client-delivery-metrics">
                            <div class="client-delivery-metric">
                                <span>Client status</span>
                                <strong><span class="badge-status ${escapeClientHtml(clientStatus)}">${escapeClientHtml(clientStatusLabel)}</span></strong>
                            </div>
                            <div class="client-delivery-metric">
                                <span>Nearest deadline</span>
                                <strong>${escapeClientHtml(clientDeadline)}</strong>
                            </div>
                        </div>
                        <button type="button" class="client-details-add-project" id="viewAddProjectButton">
                            <i class="fas fa-folder-plus"></i>
                            <span>Add Project</span>
                        </button>
                    </section>
                </div>

                <section class="client-associated-section" aria-labelledby="clientProjectsHeading">
                    <header class="client-associated-header">
                        <span class="client-associated-icon"><i class="fas fa-diagram-project"></i></span>
                        <span class="client-associated-copy">
                            <strong id="clientProjectsHeading">Associated Projects</strong>
                            <small>Delivery progress and team communication</small>
                        </span>
                        <span class="client-project-count" id="clientProjectsCount">Loading</span>
                    </header>
                    <div id="clientProjects" class="client-projects-loading">
                        <div class="spinner-border text-primary spinner-border-sm" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                        <span>Loading projects...</span>
                    </div>
                </section>
            `;
            $('#viewClientContent').html(html);
            const viewAddProjectButton = document.getElementById('viewAddProjectButton');
            if (viewAddProjectButton) {
                viewAddProjectButton.addEventListener('click', function () {
                    openAddProjectModal(Number(client.id) || 0, client.name || 'Selected client');
                });
            }
            
            $.ajax({
                url: '../../api/get-client-projects.php',
                method: 'GET',
                data: { client_id: id },
                success: function(projectsResponse) {
                    const projects = typeof projectsResponse === 'string' ? JSON.parse(projectsResponse) : projectsResponse;
                    const projectsContainer = $('#clientProjects');
                    projectsContainer.removeClass('client-projects-loading');
                    const projectCount = document.getElementById('clientProjectsCount');
                    if (projectCount) {
                        projectCount.textContent = `${projects.length} ${projects.length === 1 ? 'project' : 'projects'}`;
                    }
                    if(projects.length > 0) {
                        let projectsHtml = '<div class="client-project-list">';
                        projects.forEach(project => {
                            const projectId = Number(project.id || 0);
                            const progress = Math.max(0, Math.min(100, Number(project.progress || 0)));
                            const isCompleted = progress >= 100 || project.status === 'completed';
                            const deadline = project.timeline_end ? new Date(`${project.timeline_end}T23:59:59`) : null;
                            const isOverdue = !isCompleted && deadline && deadline.getTime() < Date.now();
                            const displayStatus = isCompleted ? 'Completed' : (isOverdue ? 'Overdue' : String(project.status || '').replaceAll('_', ' '));
                            const createdDate = project.created_at ? new Date(project.created_at).toLocaleDateString() : 'Date unavailable';
                            projectsHtml += `
                                <article class="client-project-item" data-client-project-id="${projectId}">
                                    <div class="client-project-identity">
                                        <span class="client-project-folder"><i class="fas fa-folder"></i></span>
                                        <span>
                                            <strong>${escapeClientHtml(project.name || 'Untitled project')}</strong>
                                            <small>Created ${escapeClientHtml(createdDate)}</small>
                                        </span>
                                    </div>
                                    <div class="client-project-progress">
                                        <div class="client-project-progress-head">
                                            <span>Delivery progress</span>
                                            <strong>${progress}%</strong>
                                        </div>
                                        <div class="project-progress-track" role="progressbar" aria-valuenow="${progress}" aria-valuemin="0" aria-valuemax="100"><div class="project-progress-fill" style="width:${progress}%"></div></div>
                                    </div>
                                    <div class="client-project-state">
                                        <span class="badge-status ${isCompleted ? 'active' : (isOverdue ? 'overdue' : 'pending')}">${escapeClientHtml(displayStatus)}</span>
                                    </div>
                                    ${isCompleted ? '' : `
                                        <div class="project-team-notify client-project-notify">
                                            <input type="text" maxlength="500" placeholder="Message the responsible team" aria-label="Team message for ${escapeClientHtml(project.name || 'project')}">
                                            <button class="btn btn-outline-primary btn-sm" type="button" data-notify-project-team="${projectId}"><i class="fas fa-bell me-1"></i> Notify Team</button>
                                        </div>
                                    `}
                                </article>
                            `;
                        });
                        projectsHtml += '</div>';
                        projectsContainer.html(projectsHtml);
                        const selectedProjectId = new URLSearchParams(window.location.search).get('project');
                        const selectedProject = selectedProjectId
                            ? document.querySelector(`[data-client-project-id="${CSS.escape(selectedProjectId)}"]`)
                            : null;
                        if (selectedProject) {
                            selectedProject.classList.add('is-evortix-target');
                            window.setTimeout(() => selectedProject.scrollIntoView({ behavior:'smooth', block:'center' }), 250);
                        }
                    } else {
                        projectsContainer.html(`
                            <div class="client-project-empty">
                                <span><i class="fas fa-folder-open"></i></span>
                                <strong>No associated projects yet</strong>
                                <small>Use Add Project above to create the first delivery record.</small>
                            </div>
                        `);
                    }
                },
                error: function() {
                    const projectCount = document.getElementById('clientProjectsCount');
                    if (projectCount) {
                        projectCount.textContent = 'Unavailable';
                    }
                    $('#clientProjects')
                        .removeClass('client-projects-loading')
                        .html(`
                            <div class="client-project-empty">
                                <span><i class="fas fa-triangle-exclamation"></i></span>
                                <strong>Projects could not be loaded</strong>
                                <small>Close this window and try opening the client again.</small>
                            </div>
                        `);
                }
            });
            
            new bootstrap.Modal(document.getElementById('viewClientModal')).show();
        },
        error: function(xhr) {
            let message = 'Unable to load client details';
            try {
                message = JSON.parse(xhr.responseText).error || message;
            } catch (error) {}
            window.appToast?.('Unable to load client', message, 'error');
        }
    });
}

let pendingDeleteClientId = null;
const deleteClientModalElement = document.getElementById('deleteClientModal');
const deleteClientConfirmBtn = document.getElementById('deleteClientConfirmBtn');
const deleteClientName = document.getElementById('deleteClientName');
let pendingDeleteProjectId = null;
const deleteProjectModalElement = document.getElementById('deleteProjectModal');
const deleteProjectConfirmBtn = document.getElementById('deleteProjectConfirmBtn');
const deleteProjectName = document.getElementById('deleteProjectName');
let pendingDeleteDraftId = null;
const deleteDraftModalElement = document.getElementById('deleteDraftModal');
const deleteDraftConfirmBtn = document.getElementById('deleteDraftConfirmBtn');
const deleteDraftName = document.getElementById('deleteDraftName');

function openDeleteClientModal(id, name) {
    pendingDeleteClientId = Number(id) || null;

    if (deleteClientName) {
        deleteClientName.textContent = name || 'Selected client';
    }

    new bootstrap.Modal(deleteClientModalElement).show();
}

function openDeleteProjectModal(id, name) {
    pendingDeleteProjectId = Number(id) || null;

    if (deleteProjectName) {
        deleteProjectName.textContent = name || 'Selected project';
    }

    new bootstrap.Modal(deleteProjectModalElement).show();
}

function openDeleteDraftModal(draftId, draftLabel) {
    pendingDeleteDraftId = String(draftId || '').trim();
    if (!pendingDeleteDraftId) {
        return;
    }

    if (deleteDraftName) {
        deleteDraftName.textContent = draftLabel || 'Untitled draft';
    }

    new bootstrap.Modal(deleteDraftModalElement).show();
}

function confirmDeleteClientDraft() {
    if (!pendingDeleteDraftId) {
        return;
    }

    const draftId = pendingDeleteDraftId;
    const storageKey = 'erniPms.sales.addClientDrafts.v1';

    try {
        const storedDrafts = JSON.parse(localStorage.getItem(storageKey) || '[]');
        const remainingDrafts = Array.isArray(storedDrafts)
            ? storedDrafts.filter((draft) => String(draft?.id || '') !== draftId)
            : [];
        localStorage.setItem(storageKey, JSON.stringify(remainingDrafts));
        localStorage.removeItem('erniPms.sales.addClientDraft.v2');
        localStorage.removeItem('erniPms.sales.addClientDraft');
    } catch (error) {
        window.appToast?.('Unable to delete draft', 'The saved draft could not be removed from this browser.', 'error');
        return;
    }

    pendingDeleteDraftId = null;
    bootstrap.Modal.getOrCreateInstance(deleteDraftModalElement).hide();
    window.location.reload();
}

if (deleteClientConfirmBtn) {
    deleteClientConfirmBtn.addEventListener('click', function () {
        if (!pendingDeleteClientId) {
            return;
        }

        if (window.appShowLoading) {
            window.appShowLoading('Deleting client', 'Please wait while the client and related records are removed.');
        }

        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="delete_client" value="1">
            <input type="hidden" name="id" value="${pendingDeleteClientId}">
        `;
        document.body.appendChild(form);
        form.submit();
    });
}

if (deleteProjectConfirmBtn) {
    deleteProjectConfirmBtn.addEventListener('click', function () {
        if (!pendingDeleteProjectId) {
            return;
        }

        if (window.appShowLoading) {
            window.appShowLoading('Deleting project', 'Please wait while the project is cancelled.');
        }

        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="delete_project" value="1">
            <input type="hidden" name="id" value="${pendingDeleteProjectId}">
        `;
        document.body.appendChild(form);
        form.submit();
    });
}

const addClientModalElement = document.getElementById('addClientModal');
const addClientForm = document.getElementById('addClientForm');

if (addClientModalElement && addClientForm) {
    const addClientDraftsKey = 'erniPms.sales.addClientDrafts.v1';
    const draftPageSizeKey = 'erniPms.sales.draftPageSize';
    const draftPageSizeOptions = [10, 25, 50];
    const legacyDraftKeys = [
        'erniPms.sales.addClientDraft.v2',
        'erniPms.sales.addClientDraft'
    ];
    const addClientModal = bootstrap.Modal.getOrCreateInstance(addClientModalElement);
    const clientTabButtons = Array.from(document.querySelectorAll('[data-client-tab]'));
    const clientTabPanels = Array.from(document.querySelectorAll('[data-client-panel]'));
    const draftList = document.getElementById('clientDraftList');
    const draftEmpty = document.getElementById('clientDraftEmpty');
    const draftTabBadge = document.getElementById('draftTabBadge');
    const stepButtons = Array.from(addClientModalElement.querySelectorAll('[data-step-target]'));
    const stepPanels = Array.from(addClientModalElement.querySelectorAll('[data-client-step]'));
    const previousButton = addClientModalElement.querySelector('[data-client-prev]');
    const nextButton = addClientModalElement.querySelector('[data-client-next]');
    const submitButton = addClientModalElement.querySelector('[data-client-submit]');
    const saveDraftButton = addClientModalElement.querySelector('[data-save-draft]');
    const generatedComplexityField = addClientModalElement.querySelector('[data-generated-complexity]');
    const closeButtons = Array.from(addClientModalElement.querySelectorAll('.add-client-close'));
    const draftStatus = addClientModalElement.querySelector('[data-draft-status]');
    const addClientFooter = addClientModalElement.querySelector('.add-client-footer');
    const unsavedClosePanel = addClientModalElement.querySelector('[data-unsaved-close-panel]');
    const keepEditingButton = addClientModalElement.querySelector('[data-keep-editing]');
    const discardUnsavedButton = addClientModalElement.querySelector('[data-discard-unsaved]');
    const architectHandoffNote = addClientModalElement.querySelector('.architect-handoff-note');
    const templateReviewOverlay = addClientModalElement.querySelector('[data-template-review-overlay]');
    const summaryFields = Array.from(addClientModalElement.querySelectorAll('[data-summary-field]'));
    const summaryFeatureList = addClientModalElement.querySelector('[data-summary-feature-list]');
    const draftIdField = addClientModalElement.querySelector('[data-client-draft-id]');
    const requestedFeaturesField = document.getElementById('requestedFeaturesField');
    const requestedFeatureList = document.getElementById('requestedFeatureList');
    let currentStep = 0;
    let formTouched = false;
    let activeDraftLoaded = false;
    let activeDraftId = null;
    let draftCurrentPage = 1;
    let draftPageSize = Number(localStorage.getItem(draftPageSizeKey)) || 10;
    if (!draftPageSizeOptions.includes(draftPageSize)) {
        draftPageSize = 10;
    }
    let isSubmittingClient = false;
    let appliedProjectTemplateType = '';
    let recommendedFeatureTitles = new Set();
    let templateApplyMessage = '';
    let acceptedTemplateSections = new Set();
    let autoBudgetBase = null;
    let autoBudgetFromTemplate = false;

    function syncAddClientPageBounds() {
        const navbar = document.querySelector('#page-content-wrapper > .app-navbar');
        const fallbackTop = window.matchMedia('(max-width: 991.98px)').matches ? 68 : 86;
        const pageTop = navbar ? Math.round(navbar.getBoundingClientRect().bottom) : fallbackTop;
        document.documentElement.style.setProperty('--add-client-page-top', `${Math.max(0, pageTop)}px`);
    }

    function normalizeTemplateType(value) {
        return normalizeSalesTemplateType(value);
    }

    function formatPeso(value) {
        return new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(Number(value || 0));
    }

    function resizeTextareaField(name, minHeight = 118) {
        const field = addClientForm.elements[name];
        if (!field) {
            return;
        }

        const computedStyle = window.getComputedStyle(field);
        const fontSize = parseFloat(computedStyle.fontSize) || 16;
        const lineHeight = parseFloat(computedStyle.lineHeight) || (fontSize * 1.45);
        const paddingTop = parseFloat(computedStyle.paddingTop) || 0;
        const paddingBottom = parseFloat(computedStyle.paddingBottom) || 0;
        const paddingLeft = parseFloat(computedStyle.paddingLeft) || 0;
        const paddingRight = parseFloat(computedStyle.paddingRight) || 0;
        const availableWidth = Math.max(240, (field.clientWidth || field.getBoundingClientRect().width || 720) - paddingLeft - paddingRight);
        const averageCharWidth = Math.max(7, fontSize * 0.54);
        const charsPerLine = Math.max(28, Math.floor(availableWidth / averageCharWidth));
        const estimatedLines = String(field.value || '')
            .split('\n')
            .reduce((lineCount, line) => lineCount + Math.max(1, Math.ceil(line.length / charsPerLine)), 0);
        const estimatedHeight = Math.ceil((estimatedLines * lineHeight) + paddingTop + paddingBottom + 8);

        field.style.height = 'auto';
        field.style.height = `${Math.max(minHeight, field.scrollHeight + 2, estimatedHeight)}px`;
    }

    function resizeAdditionalRequirementsField() {
        resizeTextareaField('additional_requirements', 118);
    }

    function resizeSalesNotesField() {
        resizeTextareaField('sales_notes', 220);
    }

    function queueResizeSalesNotesField() {
        window.requestAnimationFrame(() => {
            resizeSalesNotesField();
            window.requestAnimationFrame(resizeSalesNotesField);
        });
    }

    function updateAttachmentSummary() {
        const input = addClientForm.querySelector('[data-attachment-input]');
        const summary = addClientForm.querySelector('[data-attachment-summary]');
        if (!input || !summary) {
            return;
        }

        const files = Array.from(input.files || []).filter((file) => file?.name);
        if (files.length === 0) {
            summary.textContent = 'No file chosen';
            summary.removeAttribute('title');
            return;
        }

        summary.textContent = files.length === 1 ? files[0].name : `${files.length} files selected`;
        summary.title = files.map((file) => file.name).join(', ');
    }

    function findProjectTemplate(value = getFormValue('project_type')) {
        const needle = normalizeTemplateType(value);
        if (!needle) {
            return null;
        }

        const selectedId = Number(addClientForm.querySelector('[data-template-version-select]')?.value || 0);
        const exactMatch = projectTemplates.find((template) => selectedId > 0 && Number(template.id) === selectedId)
            || projectTemplates.find((template) => normalizeTemplateType(template.project_type) === needle);
        if (exactMatch) {
            return exactMatch;
        }

        const genericTokens = new Set(['system', 'app', 'application', 'management', 'platform', 'website', 'portal']);
        const needleTokens = needle.split(' ').filter(Boolean);
        const distinctiveNeedleTokens = needleTokens.filter((token) => !genericTokens.has(token));
        if (distinctiveNeedleTokens.length === 0) {
            return null;
        }

        const scoredMatches = projectTemplates
            .map((template) => {
                const candidate = normalizeTemplateType(template.project_type);
                const candidateTokens = candidate.split(' ').filter(Boolean);
                const sharedTokens = needleTokens.filter((token) => candidateTokens.includes(token));
                const sharedDistinctiveTokens = distinctiveNeedleTokens.filter((token) => candidateTokens.includes(token));
                const directMatch = candidate.includes(needle) || needle.includes(candidate);
                const score = (sharedTokens.length / Math.max(needleTokens.length, candidateTokens.length))
                    + (sharedDistinctiveTokens.length * 0.5)
                    + (directMatch ? 0.5 : 0);

                return { template, score, sharedDistinctiveCount: sharedDistinctiveTokens.length };
            })
            .filter((match) => match.sharedDistinctiveCount > 0 && match.score >= 0.65)
            .sort((a, b) => b.score - a.score);

        if (scoredMatches.length === 0) {
            return null;
        }

        if (scoredMatches.length > 1 && scoredMatches[0].score === scoredMatches[1].score) {
            return null;
        }

        return scoredMatches[0].template;
    }

    function isRecommendedFeature(value) {
        return recommendedFeatureTitles.has(normalizeTemplateType(value));
    }

    function getDraftData() {
        syncRequestedFeaturesField();
        const data = {};
        for (const [name, value] of new FormData(addClientForm).entries()) {
            if (name === 'attachments[]' || (typeof File !== 'undefined' && value instanceof File)) {
                continue;
            }
            data[name] = value;
        }
        return data;
    }

    function setClientFeatureEditorExpanded(expanded, focusInput = false) {
        const shell = addClientForm.querySelector('[data-client-feature-shell]');
        const toggle = shell?.querySelector('[data-client-feature-toggle]');
        const toggleLabel = toggle?.querySelector('[data-client-feature-toggle-label]');
        if (!shell || !toggle) {
            return;
        }

        const shouldExpand = Boolean(expanded && !toggle.hidden);
        shell.classList.toggle('is-expanded', shouldExpand);
        toggle.setAttribute('aria-expanded', shouldExpand ? 'true' : 'false');
        toggle.setAttribute('aria-label', shouldExpand ? 'Show fewer requested features' : 'See more requested features');
        if (toggleLabel) {
            toggleLabel.textContent = shouldExpand ? 'Less' : 'More';
        }

        if (focusInput) {
            window.requestAnimationFrame(() => {
                const input = shell.querySelector('[data-requested-feature-input]');
                input?.focus();
                if (requestedFeatureList && shouldExpand) {
                    requestedFeatureList.scrollTop = requestedFeatureList.scrollHeight;
                }
            });
        }
    }

    function updateClientFeatureEditorAvailability() {
        const shell = addClientForm.querySelector('[data-client-feature-shell]');
        const toggle = shell?.querySelector('[data-client-feature-toggle]');
        if (!shell || !requestedFeatureList || !toggle || requestedFeatureList.offsetParent === null) {
            return false;
        }

        const wasExpanded = shell.classList.contains('is-expanded');
        shell.classList.remove('is-expanded', 'has-more');
        toggle.hidden = true;
        const flowItems = Array.from(requestedFeatureList.children).filter((item) => !item.matches('.requested-feature-chip-add'));
        const firstRowTop = flowItems.length ? Math.min(...flowItems.map((item) => item.offsetTop)) : 0;
        const hasMore = flowItems.some((item) => item.offsetTop > firstRowTop + 4);
        shell.classList.toggle('has-more', hasMore);
        toggle.hidden = !hasMore;
        setClientFeatureEditorExpanded(hasMore && wasExpanded, false);
        return hasMore;
    }

    function queueClientFeatureEditorAvailability() {
        window.requestAnimationFrame(() => {
            updateClientFeatureEditorAvailability();
            window.requestAnimationFrame(updateClientFeatureEditorAvailability);
        });
    }

    function toggleClientFeatureEditor() {
        const shell = addClientForm.querySelector('[data-client-feature-shell]');
        if (shell?.querySelector('[data-client-feature-toggle]')?.hidden) {
            return;
        }
        setClientFeatureEditorExpanded(!shell?.classList.contains('is-expanded'), true);
    }

    function createRequestedFeatureChip(value) {
        const chip = document.createElement('span');
        const label = document.createElement('span');
        const removeButton = document.createElement('button');
        const normalizedValue = String(value || '').trim().replace(/\s+/g, ' ');

        chip.className = 'requested-feature-chip';
        chip.dataset.requestedFeatureValue = normalizedValue;
        chip.dataset.templateRecommended = isRecommendedFeature(normalizedValue) ? '1' : '0';
        chip.setAttribute('role', 'listitem');
        label.textContent = normalizedValue;

        removeButton.type = 'button';
        removeButton.className = 'requested-feature-chip-remove';
        removeButton.dataset.removeRequestedFeature = '';
        removeButton.setAttribute('aria-label', `Remove ${normalizedValue}`);
        removeButton.innerHTML = '<i class="fas fa-times" aria-hidden="true"></i>';

        chip.append(label, removeButton);
        return chip;
    }

    function createRequestedFeatureInput() {
        const input = document.createElement('input');
        input.type = 'text';
        input.className = 'requested-feature-chip-input';
        input.dataset.requestedFeatureInput = '';
        input.placeholder = 'Type a feature';
        input.setAttribute('aria-label', 'New requested feature');
        return input;
    }

    function splitRequestedFeatureInput(value) {
        const rawValue = String(value || '').trim();
        if (rawValue === '') {
            return [];
        }

        const delimited = rawValue
            .split(/[\r\n,;|]+/)
            .map((feature) => feature.trim().replace(/\s+/g, ' '))
            .filter(Boolean);
        if (delimited.length > 1) {
            return delimited;
        }

        const words = rawValue.replace(/\s+/g, ' ').split(' ').filter(Boolean);
        const firstCompactIndex = words.findIndex((word, index) => index > 0 && /^[a-z]+[\w-]*\d+$/i.test(word));
        if (firstCompactIndex > 0) {
            return [
                words.slice(0, firstCompactIndex).join(' '),
                ...words.slice(firstCompactIndex)
            ].filter(Boolean);
        }

        return [rawValue.replace(/\s+/g, ' ')];
    }

    function getRequestedFeatureValues() {
        if (!requestedFeatureList) {
            return [];
        }

        return Array.from(requestedFeatureList.querySelectorAll('[data-requested-feature-value]'))
            .map((chip) => String(chip.dataset.requestedFeatureValue || '').trim())
            .filter((value) => value !== '');
    }

    function syncRequestedFeaturesField() {
        if (requestedFeaturesField) {
            requestedFeaturesField.value = getRequestedFeatureValues().join("\n");
        }
    }

    function addRequestedFeatureValue(value, focusInput = true) {
        const input = requestedFeatureList?.querySelector('[data-requested-feature-input]');
        const parts = splitRequestedFeatureInput(value);
        if (parts.length > 1) {
            let addedAny = false;
            parts.forEach((part) => {
                addedAny = addRequestedFeatureValue(part, false) || addedAny;
            });
            input && (input.value = '');
            if (focusInput) {
                setClientFeatureEditorExpanded(true, true);
            }
            return addedAny;
        }

        const normalizedValue = parts[0] || '';
        if (!requestedFeatureList || !input || !normalizedValue) {
            return false;
        }

        const duplicate = Array.from(requestedFeatureList.querySelectorAll('[data-requested-feature-value]'))
            .find((chip) => normalizeTemplateType(chip.dataset.requestedFeatureValue) === normalizeTemplateType(normalizedValue));
        input.value = '';
        input.setCustomValidity('');

        if (duplicate) {
            duplicate.classList.add('is-duplicate');
            duplicate.scrollIntoView({ block: 'nearest', inline: 'nearest' });
            window.setTimeout(() => duplicate.classList.remove('is-duplicate'), 650);
            if (focusInput) {
                setClientFeatureEditorExpanded(true, true);
            }
            return false;
        }

        requestedFeatureList.insertBefore(createRequestedFeatureChip(normalizedValue), input);
        syncRequestedFeaturesField();
        requestedFeatureList.scrollTop = requestedFeatureList.scrollHeight;
        updateClientFeatureEditorAvailability();
        if (focusInput) {
            setClientFeatureEditorExpanded(true, true);
        }
        return true;
    }

    function commitRequestedFeatureInput(focusInput = true) {
        const input = requestedFeatureList?.querySelector('[data-requested-feature-input]');
        if (!input || !String(input.value || '').trim()) {
            if (focusInput) {
                setClientFeatureEditorExpanded(true, true);
            }
            return false;
        }
        return addRequestedFeatureValue(input.value, focusInput);
    }

    window.addClientRequestedFeatureValue = addRequestedFeatureValue;

    function addDaysToDateInput(dateValue, daysToAdd) {
        const baseDate = dateValue ? new Date(dateValue + 'T00:00:00') : new Date();
        if (Number.isNaN(baseDate.getTime())) {
            return '';
        }
        baseDate.setDate(baseDate.getDate() + Math.max(0, Number(daysToAdd) || 0));
        const year = baseDate.getFullYear();
        const month = String(baseDate.getMonth() + 1).padStart(2, '0');
        const day = String(baseDate.getDate()).padStart(2, '0');
        return `${year}-${month}-${day}`;
    }

    function syncClientTimelineWithPriority(force = false) {
        const timelineEnd = addClientForm.elements.timeline_end;
        const template = findProjectTemplate(getFormValue('project_type'));
        if (!timelineEnd || !template) return;
        if (!force && timelineEnd.value && timelineEnd.dataset.templateDurationAuto !== '1') return;

        const weeks = getPriorityAdjustedTemplateWeeks(template, addClientForm.elements.priority?.value || 'medium');
        if (!weeks) return;
        const startValue = getFormValue('timeline_start') || addDaysToDateInput('', 0);
        timelineEnd.value = addDaysToDateInput(startValue, weeks * 7);
        timelineEnd.dataset.templateDurationAuto = '1';
        timelineEnd.dataset.templateDurationWeeks = String(weeks);
    }

    function applyProjectTemplate(template, options = {}) {
        if (!template) {
            return;
        }

        const force = Boolean(options.force);
        const showSuccess = Boolean(options.showSuccess);
        appliedProjectTemplateType = normalizeTemplateType(template.project_type);
        recommendedFeatureTitles = new Set((template.feature_items || []).map((feature) => normalizeTemplateType(feature)));

        if (addClientForm.elements.project_type) {
            addClientForm.elements.project_type.value = template.project_type || '';
        }
        if (addClientForm.elements.project_description && (force || !getFormValue('project_description'))) {
            addClientForm.elements.project_description.value = template.project_description || `${template.project_type} based on the ${template.methodology || 'recommended'} project template.`;
        }
        if (Array.isArray(template.feature_items) && template.feature_items.length > 0 && (force || !getRequestedFeatureValues().length)) {
            renderRequestedFeatureRows(template.feature_items.join("\n"));
        }
        if (addClientForm.elements.sales_budget && Number(template.budget_min || 0) > 0 && (force || !getFormValue('sales_budget') || autoBudgetFromTemplate)) {
            autoBudgetBase = Number(template.budget_min || 0);
            autoBudgetFromTemplate = true;
            syncAutoBudgetWithPriority();
        }
        if (addClientForm.elements.timeline_start && (force || !getFormValue('timeline_start'))) {
            addClientForm.elements.timeline_start.value = addDaysToDateInput('', 0);
        }
        if (addClientForm.elements.timeline_end && getPriorityAdjustedTemplateWeeks(template, addClientForm.elements.priority?.value || 'medium') > 0 && (force || !getFormValue('timeline_end'))) {
            const startValue = getFormValue('timeline_start') || addDaysToDateInput('', 0);
            const durationWeeks = getPriorityAdjustedTemplateWeeks(template, addClientForm.elements.priority?.value || 'medium');
            addClientForm.elements.timeline_end.value = addDaysToDateInput(startValue, durationWeeks * 7);
            addClientForm.elements.timeline_end.dataset.templateDurationAuto = '1';
            addClientForm.elements.timeline_end.dataset.templateDurationWeeks = String(durationWeeks);
        }
        if (addClientForm.elements.additional_requirements && (force || !getFormValue('additional_requirements'))) {
            addClientForm.elements.additional_requirements.value = [
                template.functional_requirements ? 'Functional requirements: ' + template.functional_requirements : '',
                template.non_functional_requirements ? 'Non-functional requirements: ' + template.non_functional_requirements : '',
                template.integrations ? 'Suggested integrations: ' + template.integrations : '',
                template.third_party_services ? 'Third-party services/APIs: ' + template.third_party_services : '',
                template.payment_terms ? 'Suggested payment terms: ' + template.payment_terms : ''
            ].filter(Boolean).join("\n");
            resizeAdditionalRequirementsField();
        }
        if (addClientForm.elements.sales_notes && (force || !getFormValue('sales_notes'))) {
            addClientForm.elements.sales_notes.value = [
                template.sales_notes || '',
                template.suggested_milestones ? 'Suggested milestones: ' + template.suggested_milestones : '',
                template.methodology ? 'Recommended methodology: ' + template.methodology : '',
                template.tech_stack ? 'Architect recommendation preview: ' + template.tech_stack : '',
                template.team_composition ? 'Suggested team: ' + template.team_composition : '',
                template.maintenance_support ? 'Maintenance/support: ' + template.maintenance_support : ''
            ].filter(Boolean).join("\n");
            queueResizeSalesNotesField();
        }
        if (showSuccess) {
            const priorityMarkup = getPriorityMarkup();
            const baseBudget = Number(template.budget_min || 0);
            const adjustedBudget = baseBudget > 0 ? baseBudget * (1 + priorityMarkup.rate) : 0;
            templateApplyMessage = baseBudget > 0
                ? `${template.project_type} template applied. Budget set to ${formatPeso(adjustedBudget)} from ${formatPeso(baseBudget)} base plus ${priorityMarkup.label.toLowerCase()} priority.`
                : `${template.project_type} template applied.`;
        }

        formTouched = true;
        hideUnsavedClosePanel();
        syncRequestedFeaturesField();
        updateGeneratedComplexity();
        updateTemplateRecommendation();
        updateDraftStatus(false);
    }

    function splitDecisionInsightItems(value) {
        const rawItems = Array.isArray(value)
            ? value
            : String(value || '').split(/[\n,;]+/);
        return rawItems
            .map((item) => String(item || '').trim())
            .filter((item) => item !== '');
    }

    function renderDecisionInsightChips(value, fallback, limit = 5) {
        const items = splitDecisionInsightItems(value);
        if (items.length === 0) {
            return `<span class="dss-muted">${escapeHtml(fallback)}</span>`;
        }

        const visibleItems = items.slice(0, limit);
        const hiddenCount = Math.max(0, items.length - visibleItems.length);
        return `
            <div class="dss-chip-row">
                ${visibleItems.map((item) => `<span class="dss-chip">${escapeHtml(item)}</span>`).join('')}
                ${hiddenCount > 0 ? `<span class="dss-chip is-more">+${hiddenCount} more</span>` : ''}
            </div>
        `;
    }

    function renderDecisionMetric(label, value) {
        return `
            <div class="dss-metric">
                <span>${escapeHtml(label)}</span>
                <strong title="${escapeHtml(value)}">${escapeHtml(value)}</strong>
            </div>
        `;
    }

    function getProjectTemplatePreviewData(template) {
        const budgetMin = Number(template.budget_min || 0);
        const budgetMax = Number(template.budget_max || 0);
        const featureSource = Array.isArray(template.feature_items) && template.feature_items.length > 0
            ? template.feature_items
            : template.core_features;
        const architectureSource = [
            template.tech_stack ? `Stack: ${template.tech_stack}` : '',
            template.team_composition ? `Team: ${template.team_composition}` : ''
        ].filter(Boolean).join('; ');

        return {
            budgetRange: budgetMin > 0 || budgetMax > 0
                ? `${formatPeso(budgetMin)} - ${formatPeso(budgetMax)}`
                : 'Budget TBD',
            featureSource,
            architectureSource,
            paymentTerms: template.payment_terms || 'Payment terms can be finalized by Sales.'
        };
    }

    function renderProjectTemplateReview(template) {
        const preview = getProjectTemplatePreviewData(template);
        const steps = [
            ['Project brief', template.project_description || `${template.project_type} project baseline.`, 'brief'],
            ['Requested features', Array.isArray(template.feature_items) ? template.feature_items.join(', ') : template.core_features, 'features'],
            ['Timeline', template.duration_label || 'Timeline TBD', 'timeline'],
            ['Budget baseline', preview.budgetRange, 'budget'],
            ['Requirements', template.functional_requirements || template.non_functional_requirements || 'No saved requirements.', 'requirements'],
            ['Sales notes', template.sales_notes || preview.paymentTerms, 'notes']
        ];

        return `
            <div class="dss-template-review-dialog" role="dialog" aria-modal="true" aria-labelledby="projectTemplateReviewTitle">
                <div class="dss-review-header">
                    <div class="dss-review-title">
                        <span><i class="fas fa-magic"></i> Decision Support Preview</span>
                        <strong id="projectTemplateReviewTitle">${escapeHtml(template.project_type)}</strong>
                    </div>
                    <button type="button" class="dss-review-close" data-close-template-review aria-label="Close template review">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <div class="dss-review-body">
                    <div class="dss-review-summary">
                        <span><strong>Preview only.</strong> This saved baseline will not change the form until you apply it.</span>
                        <span><i class="fas fa-shield-alt me-1"></i> Safe to review</span>
                    </div>
                    <div class="dss-section" data-dss-guided-step>
                        <div class="dss-section-title"><i class="fas fa-route"></i> Step 1 of ${steps.length}: ${escapeHtml(steps[0][0])}</div>
                        <p class="dss-muted mb-3">${escapeHtml(steps[0][1] || 'No saved value.')}</p>
                        <div class="dss-review-actions">
                            <button type="button" class="btn btn-light btn-sm" data-dss-step-decision="skip">Skip</button>
                            <button type="button" class="btn btn-primary btn-sm" data-dss-step-decision="accept">Accept this suggestion</button>
                        </div>
                    </div>
                </div>
                <div class="dss-review-footer">
                    <span>Nothing is inserted automatically. Accept or skip each recommendation one at a time.</span>
                    <div class="dss-review-actions">
                        <button type="button" class="btn btn-light btn-sm" data-cancel-template-apply>Keep Current</button>
                    </div>
                </div>
            </div>
        `;
    }

    let templateReviewReturnFocus = null;

    function openProjectTemplateReview(template, trigger = null) {
        if (!templateReviewOverlay || !template) {
            return;
        }

        templateReviewReturnFocus = trigger || document.activeElement;
        templateReviewOverlay.classList.remove('is-applying');
        templateReviewOverlay.dataset.templateType = template.project_type || '';
        templateReviewOverlay.dataset.guidedStep = '0';
        templateReviewOverlay.innerHTML = renderProjectTemplateReview(template);
        templateReviewOverlay.hidden = false;
        templateReviewOverlay.setAttribute('aria-hidden', 'false');
        templateReviewOverlay.querySelector('[data-confirm-template-apply]')?.focus();
    }

    function applyGuidedTemplateStep(template, key) {
        const force = true;
        if (key === 'brief' && addClientForm.elements.project_description) addClientForm.elements.project_description.value = template.project_description || `${template.project_type} project baseline.`;
        if (key === 'features' && Array.isArray(template.feature_items)) {
            recommendedFeatureTitles = new Set(template.feature_items.map((item) => normalizeTemplateType(item)));
            renderRequestedFeatureRows(template.feature_items.join("\n"));
        }
        if (key === 'timeline') {
            if (addClientForm.elements.timeline_start && !getFormValue('timeline_start')) addClientForm.elements.timeline_start.value = addDaysToDateInput('', 0);
            syncClientTimelineWithPriority(force);
        }
        if (key === 'budget' && addClientForm.elements.sales_budget) {
            autoBudgetBase = Number(template.budget_min || 0); autoBudgetFromTemplate = true; syncAutoBudgetWithPriority();
        }
        if (key === 'requirements' && addClientForm.elements.additional_requirements) {
            addClientForm.elements.additional_requirements.value = [template.functional_requirements, template.non_functional_requirements, template.integrations].filter(Boolean).join("\n"); resizeAdditionalRequirementsField();
        }
        if (key === 'notes' && addClientForm.elements.sales_notes) {
            addClientForm.elements.sales_notes.value = [template.sales_notes, template.payment_terms, template.suggested_milestones].filter(Boolean).join("\n"); queueResizeSalesNotesField();
        }
        appliedProjectTemplateType = normalizeTemplateType(template.project_type);
        acceptedTemplateSections.add(key);
        formTouched = true; syncRequestedFeaturesField(); updateGeneratedComplexity(); updateDraftStatus(false);
    }

    function clearProjectTypeProgress() {
        ['project_description', 'meeting_notes', 'mom', 'additional_requirements', 'sales_notes', 'sales_budget', 'timeline_start', 'timeline_start_time', 'timeline_end', 'timeline_end_time'].forEach((name) => {
            if (addClientForm.elements[name]) {
                addClientForm.elements[name].value = name === 'mom' ? SALES_MOM_TEMPLATE : '';
            }
        });
        renderRequestedFeatureRows('');
        if (addClientForm.elements.timeline_end) {
            delete addClientForm.elements.timeline_end.dataset.templateDurationAuto;
            delete addClientForm.elements.timeline_end.dataset.templateDurationWeeks;
        }
        acceptedTemplateSections = new Set();
        recommendedFeatureTitles = new Set();
        appliedProjectTemplateType = '';
        autoBudgetBase = null;
        autoBudgetFromTemplate = false;
        syncRequestedFeaturesField();
        resizeAdditionalRequirementsField();
        queueResizeSalesNotesField();
    }
    window.clearSalesClientProjectTypeProgress = clearProjectTypeProgress;

    function hasProjectTypeProgress() {
        const trackedFields = ['project_description', 'meeting_notes', 'mom', 'additional_requirements', 'sales_notes', 'sales_budget', 'timeline_start', 'timeline_start_time', 'timeline_end', 'timeline_end_time'];
        return trackedFields.some((name) => String(addClientForm.elements[name]?.value || '').trim() !== '')
            || getRequestedFeatureValues().length > 0;
    }

    function advanceGuidedTemplateReview(template, accepted) {
        const steps = [
            ['Project brief', template.project_description || `${template.project_type} project baseline.`, 'brief'],
            ['Requested features', (template.feature_items || []).join(', ') || template.core_features, 'features'],
            ['Timeline', template.duration_label || 'Timeline TBD', 'timeline'],
            ['Budget baseline', getProjectTemplatePreviewData(template).budgetRange, 'budget'],
            ['Requirements', template.functional_requirements || template.non_functional_requirements || 'No saved requirements.', 'requirements'],
            ['Sales notes', template.sales_notes || template.payment_terms || 'No saved notes.', 'notes']
        ];
        let index = Number(templateReviewOverlay.dataset.guidedStep || 0);
        if (accepted) applyGuidedTemplateStep(template, steps[index][2]);
        index += 1;
        if (index >= steps.length) {
            templateApplyMessage = `${template.project_type} DSS review completed step by step.`;
            closeProjectTemplateReview(false); updateTemplateRecommendation(); return;
        }
        templateReviewOverlay.dataset.guidedStep = String(index);
        const panel = templateReviewOverlay.querySelector('[data-dss-guided-step]');
        panel.innerHTML = `<div class="dss-section-title"><i class="fas fa-route"></i> Step ${index + 1} of ${steps.length}: ${escapeHtml(steps[index][0])}</div><p class="dss-muted mb-3">${escapeHtml(steps[index][1] || 'No saved value.')}</p><div class="dss-review-actions"><button type="button" class="btn btn-light btn-sm" data-dss-step-decision="skip">Skip</button><button type="button" class="btn btn-primary btn-sm" data-dss-step-decision="accept">Accept this suggestion</button></div>`;
    }

    function closeProjectTemplateReview(returnFocus = true) {
        if (!templateReviewOverlay || templateReviewOverlay.hidden) {
            return;
        }

        templateReviewOverlay.hidden = true;
        templateReviewOverlay.setAttribute('aria-hidden', 'true');
        templateReviewOverlay.classList.remove('is-applying');
        templateReviewOverlay.innerHTML = '';
        templateReviewOverlay.dataset.templateType = '';

        if (returnFocus && templateReviewReturnFocus instanceof HTMLElement) {
            templateReviewReturnFocus.focus();
        }
        templateReviewReturnFocus = null;
    }

    function closeClientTemplateInsight(returnFocus = false) {
        const trigger = addClientForm.querySelector('[data-client-template-insight-trigger]');
        const panel = addClientForm.querySelector('[data-client-template-recommendation]');
        if (!trigger || !panel || panel.hidden) {
            return;
        }

        panel.hidden = true;
        trigger.classList.remove('is-open');
        trigger.setAttribute('aria-expanded', 'false');
        if (returnFocus) {
            trigger.focus();
        }
    }

    function toggleClientTemplateInsight() {
        const trigger = addClientForm.querySelector('[data-client-template-insight-trigger]');
        const panel = addClientForm.querySelector('[data-client-template-recommendation]');
        if (!trigger || !panel) {
            return;
        }

        const shouldOpen = panel.hidden;
        panel.hidden = !shouldOpen;
        trigger.classList.toggle('is-open', shouldOpen);
        trigger.setAttribute('aria-expanded', shouldOpen ? 'true' : 'false');
    }

    function closePriorityInsight(returnFocus = false) {
        const trigger = addClientForm.querySelector('[data-priority-insight-trigger]');
        const popover = addClientForm.querySelector('[data-priority-insight-popover]');
        if (!trigger || !popover) {
            return;
        }

        popover.hidden = true;
        trigger.classList.remove('is-open');
        trigger.setAttribute('aria-expanded', 'false');

        if (returnFocus) {
            trigger.focus();
        }
    }

    function togglePriorityInsight() {
        const trigger = addClientForm.querySelector('[data-priority-insight-trigger]');
        const popover = addClientForm.querySelector('[data-priority-insight-popover]');
        if (!trigger || !popover) {
            return;
        }

        const shouldOpen = popover.hidden;
        popover.hidden = !shouldOpen;
        trigger.classList.toggle('is-open', shouldOpen);
        trigger.setAttribute('aria-expanded', shouldOpen ? 'true' : 'false');
    }

    function updatePriorityInsight(priorityMarkup, hasBudget, previewAmount, adjustedBudget) {
        const trigger = addClientForm.querySelector('[data-priority-insight-trigger]');
        const popover = addClientForm.querySelector('[data-priority-insight-popover]');
        if (!trigger || !popover) {
            return;
        }

        const amountText = hasBudget ? formatPeso(previewAmount) : 'Budget not entered yet';
        const totalText = hasBudget ? `Adjusted intake budget: ${formatPeso(adjustedBudget)}.` : 'Enter budget later to calculate the exact amount.';
        const label = `${priorityMarkup.label} priority adds +${priorityMarkup.percent}%`;

        trigger.removeAttribute('title');
        trigger.setAttribute('aria-label', `View priority impact: ${label}`);
        popover.innerHTML = `
            <strong>${escapeHtml(label)}</strong>
            <span>${escapeHtml(priorityMarkup.reason)}</span>
            <span class="priority-impact-amount">${escapeHtml(amountText)}</span>
            <span>${escapeHtml(totalText)}</span>
        `;
    }

    function updateTemplateRecommendation() {
        const trigger = addClientForm.querySelector('[data-template-recommendation]');
        const panel = addClientForm.querySelector('[data-client-template-recommendation]');
        if (!trigger || !panel) {
            return;
        }

        trigger.dataset.templateType = '';
        trigger.classList.remove('is-applied');
        trigger.innerHTML = '<i class="fas fa-lightbulb" aria-hidden="true"></i>';

        const projectType = getFormValue('project_type');
        const template = findProjectTemplate(projectType);
        if (!projectType) {
            templateApplyMessage = '';
            panel.innerHTML = '<strong>Project template search</strong><span>Choose a project type to apply saved template details.</span>';
            trigger.setAttribute('aria-label', 'Show project template search information');
            return;
        }
        if (!template) {
            templateApplyMessage = '';
            panel.innerHTML = `<strong>New project type</strong><span>No saved template matches "${escapeHtml(projectType)}". Fill the project details manually.</span>`;
            trigger.setAttribute('aria-label', 'Show project template information');
            return;
        }

        const templateType = normalizeTemplateType(template.project_type);
        const isApplied = appliedProjectTemplateType === templateType;
        const triggerLabel = isApplied
            ? `${template.project_type} template applied. Click to review details.`
            : `${template.project_type} recommendation available. Click to review before applying.`;
        trigger.dataset.templateType = template.project_type || '';
        trigger.classList.toggle('is-applied', Boolean(isApplied));
        trigger.removeAttribute('title');
        trigger.setAttribute('aria-label', triggerLabel);
        panel.innerHTML = `
            <strong>Recommended template: ${escapeHtml(template.project_type)}</strong>
            <span>${escapeHtml(template.duration_label || 'Timeline TBD')} | ${escapeHtml(formatPeso(template.budget_min || 0))} - ${escapeHtml(formatPeso(template.budget_max || 0))}</span>
            <span>Features: ${escapeHtml(template.core_features || 'Saved template features are ready.')}</span>
            <button type="button" class="btn btn-outline-primary btn-sm" data-review-project-template data-template-type="${escapeHtml(template.project_type)}">Review template details</button>
        `;
    }

    function getPriorityMarkup(priorityValue = getFormValue('priority') || 'medium') {
        const normalized = String(priorityValue || 'medium').toLowerCase();
        if (normalized === 'low') {
            return {
                label: 'Low',
                percent: 0,
                rate: 0,
                reason: 'Standard development process with no urgency premium.'
            };
        }
        if (normalized === 'high') {
            return {
                label: 'High',
                percent: 25,
                rate: 0.25,
                reason: 'Urgent or critical features require extra effort, overtime, and more experienced developers.'
            };
        }
        if (normalized === 'critical') {
            return {
                label: 'Critical',
                percent: 30,
                rate: 0.30,
                reason: 'Critical delivery pressure uses the top priority premium for overtime, senior oversight, and rapid turnaround.'
            };
        }
        return {
            label: 'Medium',
            percent: 10,
            rate: 0.10,
            reason: 'Requires more planning, testing, and attention than the standard development process.'
        };
    }

    function syncAutoBudgetWithPriority() {
        const budgetField = addClientForm.elements.sales_budget;
        if (!budgetField) {
            return;
        }

        if (!Number.isFinite(Number(autoBudgetBase)) || Number(autoBudgetBase) <= 0) {
            const currentBudget = Number(String(budgetField.value || '').replace(/,/g, ''));
            autoBudgetBase = Number.isFinite(currentBudget) && currentBudget > 0 ? currentBudget : null;
        }
        if (!Number.isFinite(Number(autoBudgetBase)) || Number(autoBudgetBase) <= 0) return;

        const priorityMarkup = getPriorityMarkup();
        const adjustedBudget = Number(autoBudgetBase) * (1 + priorityMarkup.rate);
        budgetField.value = adjustedBudget.toFixed(2);
    }

    function getComplexityAssessment() {
        const startValue = addClientForm.elements.timeline_start?.value || '';
        const endValue = addClientForm.elements.timeline_end?.value || '';
        const budgetValue = String(addClientForm.elements.sales_budget?.value || '').replace(/,/g, '').trim();
        const priorityValue = String(addClientForm.elements.priority?.value || 'medium').toLowerCase();
        const featureCount = getRequestedFeatureValues().length;
        const budget = Number(budgetValue);
        let days = 0;
        let workingDays = 0;

        if (startValue && endValue) {
            const startDate = new Date(startValue + 'T00:00:00');
            const endDate = new Date(endValue + 'T00:00:00');
            if (!Number.isNaN(startDate.getTime()) && !Number.isNaN(endDate.getTime()) && endDate >= startDate) {
                days = Math.floor((endDate - startDate) / 86400000) + 1;
                for (const date = new Date(startDate); date <= endDate; date.setDate(date.getDate() + 1)) {
                    if (date.getDay() !== 0 && date.getDay() !== 6) workingDays++;
                }
            }
        }

        const budgetBand = Number.isFinite(budget) && budget >= 500000
            ? (budget <= 1500000 ? 'medium' : 'high')
            : 'low';
        const timelineBand = days > 0 && days <= 30
            ? 'short'
            : (days > 0 && days <= 90 ? 'moderate' : 'long');
        const score = (
            ({ low: 0, medium: 1, high: 2 }[budgetBand] ?? 1)
            + ({ long: 0, moderate: 1, short: 2 }[timelineBand] ?? 1)
            + ({ low: 0, medium: 1, high: 2, critical: 2 }[priorityValue] ?? 1)
            + (featureCount >= 13 ? 2 : (featureCount >= 8 ? 1 : 0))
        );
        const scheduleIsCompressed = featureCount > 0 && workingDays > 0 && workingDays < (featureCount * 3);
        const level = featureCount >= 13 || scheduleIsCompressed || score >= 6 ? 'High' : (score >= 3 ? 'Medium' : 'Low');
        const budgetLabel = ({ low: 'Low budget', medium: 'Medium budget', high: 'High budget' }[budgetBand] || 'Medium budget');
        const timelineLabel = ({ long: 'Long timeline', moderate: 'Moderate timeline', short: 'Short timeline' }[timelineBand] || 'Moderate timeline');
        const priorityLabel = getPriorityMarkup(priorityValue).label + ' priority';
        const scheduleReason = featureCount > 0 && workingDays > 0
            ? ` The schedule provides ${workingDays} working day${workingDays === 1 ? '' : 's'} against an estimated ${featureCount * 3}–${featureCount * 5} working days for the selected features.`
            : '';
        const reason = `${level} complexity based on ${featureCount} requested features, ${budgetLabel.toLowerCase()}, ${timelineLabel.toLowerCase()}, and ${priorityLabel.toLowerCase()} for a score of ${score}/8.${scheduleReason} ${
            level === 'High'
                ? 'Tighter deadline pressure, higher budget scope, or urgent priority increases delivery coordination and effort.'
                : (level === 'Medium'
                    ? 'Some planning pressure exists, but the intake is not in the highest risk range.'
                    : 'Budget, timeline, and priority are in the lighter range, so standard planning should be enough.')
        }`;

        return { level, reason, budgetBand, timelineBand, score, days };
    }

    function calculateGeneratedComplexity() {
        return getComplexityAssessment().level;
    }

    function updateGeneratedComplexity() {
        const assessment = getComplexityAssessment();
        const priorityMarkup = getPriorityMarkup();
        const budgetRaw = String(addClientForm.elements.sales_budget?.value || '').replace(/,/g, '').trim();
        const budget = Number(budgetRaw);
        const hasBudget = budgetRaw !== '' && Number.isFinite(budget);
        const baseBudget = Number.isFinite(Number(autoBudgetBase)) && Number(autoBudgetBase) > 0
            ? Number(autoBudgetBase)
            : budget;
        const previewAmount = hasBudget ? baseBudget * priorityMarkup.rate : 0;
        const adjustedBudget = hasBudget ? baseBudget + previewAmount : 0;
        updatePriorityInsight(priorityMarkup, hasBudget, previewAmount, adjustedBudget);
        if (generatedComplexityField) {
            generatedComplexityField.value = assessment.level;
        }

        const complexityReason = addClientForm.querySelector('[data-complexity-reason]');
        if (complexityReason) {
            complexityReason.innerHTML = `<strong>${escapeHtml(assessment.level)} complexity</strong><span>${escapeHtml(assessment.reason)}</span>`;
        }
        syncComplexityPopover(complexityReason, addClientForm.querySelector('[data-complexity-popover]'));

        const priorityMarkupPreview = addClientForm.querySelector('[data-priority-markup-preview]');
        if (priorityMarkupPreview) {
            priorityMarkupPreview.innerHTML = `<strong>${escapeHtml(priorityMarkup.label)} priority adds +${priorityMarkup.percent}%</strong><span>${escapeHtml(priorityMarkup.reason)}</span>`;
        }

        const priorityPricePreview = addClientForm.querySelector('[data-priority-price-preview]');
        if (priorityPricePreview) {
            const amountText = hasBudget
                ? formatPeso(previewAmount)
                : 'Enter a budget to calculate the amount';
            const totalText = hasBudget ? ` Adjusted intake budget: ${formatPeso(adjustedBudget)}.` : '';
            priorityPricePreview.innerHTML = `<strong>Priority price preview: ${escapeHtml(amountText)}</strong><span>${escapeHtml(priorityMarkup.label)} priority adds ${priorityMarkup.percent}% to the base budget during intake.${escapeHtml(totalText)} Final pricing is recalculated later from the Expert project cost estimate.</span>`;
        }

        const budgetHelper = addClientForm.querySelector('[data-budget-helper]');
        if (budgetHelper) {
            if (autoBudgetFromTemplate && hasBudget) {
                budgetHelper.textContent = `Auto-adjusted from template base ${formatPeso(baseBudget)} plus ${priorityMarkup.label.toLowerCase()} priority markup ${formatPeso(previewAmount)}. You can still type a custom budget.`;
            } else {
                budgetHelper.textContent = 'Required as the decision insight budget baseline.';
            }
        }
    }

    function renderRequestedFeatureRows(rawValue = '') {
        if (!requestedFeatureList) {
            return;
        }

        const seen = new Set();
        const features = splitRequestedFeatureInput(rawValue)
            .filter((feature) => {
                const normalized = normalizeTemplateType(feature);
                if (!normalized || seen.has(normalized)) {
                    return false;
                }
                seen.add(normalized);
                return true;
            });

        requestedFeatureList.replaceChildren();
        features.forEach((feature) => requestedFeatureList.appendChild(createRequestedFeatureChip(feature)));
        requestedFeatureList.append(createRequestedFeatureInput());
        syncRequestedFeaturesField();
        setClientFeatureEditorExpanded(false, false);
        queueClientFeatureEditorAvailability();
    }

    function isMeaningfulDraftValue(key, value) {
        const systemFields = new Set(['add_client', 'client_draft_id', 'attachments[]']);
        if (key.startsWith('_') || systemFields.has(key)) {
            return false;
        }

        const normalizedValue = String(value || '').trim();
        if (normalizedValue === '') {
            return false;
        }

        // The MOM textarea is prefilled with headings. Those headings alone are
        // placeholders, not user-entered draft content.
        if (key === 'mom') {
            const momContent = normalizedValue
                .replace(/^(Topic|Date|Company Name|Location|Attendees)\s*:\s*/gim, '')
                .trim();
            if (momContent === '') {
                return false;
            }
        }

        const defaultDraftValues = {
            priority: 'medium'
        };

        return !(Object.prototype.hasOwnProperty.call(defaultDraftValues, key)
            && normalizedValue === defaultDraftValues[key]);
    }

    function hasDraftValues(data = getDraftData()) {
        return Object.entries(data).some(([key, value]) => isMeaningfulDraftValue(key, value));
    }

    function createDraftId() {
        return 'draft-' + Date.now() + '-' + Math.random().toString(36).slice(2, 10);
    }

    function normalizeDraftEntry(entry) {
        if (!entry || typeof entry !== 'object') {
            return null;
        }

        try {
            const id = String(entry.id || '').trim() || createDraftId();
            const savedAt = String(entry.savedAt || entry._savedAt || '').trim() || new Date().toISOString();
            const data = entry.data && typeof entry.data === 'object'
                ? entry.data
                : Object.fromEntries(Object.entries(entry).filter(([key]) => !key.startsWith('_') && key !== 'id' && key !== 'savedAt' && key !== 'data'));
            delete data['attachments[]'];
            delete data.attachments;

            return hasDraftValues(data) ? { id, savedAt, data } : null;
        } catch (error) {
            return null;
        }
    }

    function getStoredDrafts() {
        let drafts = [];

        const rawDrafts = localStorage.getItem(addClientDraftsKey);
        if (rawDrafts) {
            try {
                const parsed = JSON.parse(rawDrafts);
                if (Array.isArray(parsed)) {
                    drafts = parsed.map(normalizeDraftEntry).filter(Boolean);
                }
            } catch (error) {
                drafts = [];
            }
        }

        if (drafts.length === 0) {
            legacyDraftKeys.forEach((legacyKey) => {
                const rawLegacyDraft = localStorage.getItem(legacyKey);
                if (!rawLegacyDraft) {
                    return;
                }

                try {
                    const parsedLegacy = JSON.parse(rawLegacyDraft);
                    const legacyEntry = normalizeDraftEntry(parsedLegacy);
                    if (legacyEntry) {
                        drafts.push(legacyEntry);
                    }
                } catch (error) {
                    // Ignore legacy corruption and move on.
                }
            });
        }

        drafts.sort((a, b) => new Date(b.savedAt).getTime() - new Date(a.savedAt).getTime());
        return drafts;
    }

    function saveStoredDrafts(drafts) {
        localStorage.setItem(addClientDraftsKey, JSON.stringify(drafts));
        legacyDraftKeys.forEach((legacyKey) => localStorage.removeItem(legacyKey));
    }

    function removeStoredDraft(draftId) {
        const normalizedDraftId = String(draftId || '').trim();
        if (normalizedDraftId === '') {
            return;
        }

        saveStoredDrafts(getStoredDrafts().filter((draft) => draft.id !== normalizedDraftId));
    }

    function hasStoredDraft() {
        return getStoredDrafts().length > 0;
    }

    function formatDraftDate(value) {
        if (!value) {
            return 'Saved recently';
        }

        const savedDate = new Date(value);
        if (Number.isNaN(savedDate.getTime())) {
            return 'Saved recently';
        }

        return 'Saved ' + savedDate.toLocaleString([], {
            month: 'short',
            day: 'numeric',
            hour: 'numeric',
            minute: '2-digit'
        });
    }

    function getDraftPaginationPages(currentPage, totalPages) {
        if (totalPages <= 5) {
            return Array.from({ length: totalPages }, (_, index) => index + 1);
        }

        const startPage = Math.max(1, Math.min(currentPage - 2, totalPages - 4));
        return Array.from({ length: 5 }, (_, index) => startPage + index);
    }

    function renderDraftList(drafts = getStoredDrafts(), requestedPage = draftCurrentPage) {
        if (!draftList) {
            return;
        }

        if (draftTabBadge) {
            draftTabBadge.textContent = String(drafts.length);
            draftTabBadge.classList.toggle('is-visible', drafts.length > 0);
        }
        if (draftEmpty) {
            draftEmpty.hidden = drafts.length > 0;
        }

        if (drafts.length === 0) {
            draftCurrentPage = 1;
            draftList.innerHTML = '';
            return;
        }

        const totalPages = Math.max(1, Math.ceil(drafts.length / draftPageSize));
        draftCurrentPage = Math.min(totalPages, Math.max(1, Number(requestedPage) || 1));
        const pageOffset = (draftCurrentPage - 1) * draftPageSize;
        const pageDrafts = drafts.slice(pageOffset, pageOffset + draftPageSize);
        const pageStart = pageOffset + 1;
        const pageEnd = Math.min(pageOffset + pageDrafts.length, drafts.length);
        const paginationPages = getDraftPaginationPages(draftCurrentPage, totalPages);

        const rows = pageDrafts.map((draft) => {
            const companyName = String(draft.data?.name || '').trim();
            const contactPerson = String(draft.data?.contact_person || '').trim();
            const projectName = String(draft.data?.project_name || '').trim();
            const savedAt = formatDraftDate(draft.savedAt).replace(/^Saved\s+/i, '');

            return `
                <div class="client-draft-card" data-draft-id="${escapeHtml(draft.id)}" role="row">
                    <div class="client-draft-card__identity" role="cell">
                        <span class="client-draft-card__icon" aria-hidden="true">
                            <i class="fas fa-file-alt"></i>
                        </span>
                        <div class="client-draft-card__title">${companyName ? escapeHtml(companyName) : 'Untitled draft'}</div>
                    </div>
                    <div class="client-draft-card__cell" data-label="Contact" role="cell">
                        ${contactPerson ? escapeHtml(contactPerson) : '<span class="client-draft-card__empty">Not provided</span>'}
                    </div>
                    <div class="client-draft-card__cell" data-label="Project" role="cell">
                        ${projectName ? escapeHtml(projectName) : '<span class="client-draft-card__empty">Not added yet</span>'}
                    </div>
                    <div class="client-draft-card__subtitle" data-label="Saved" role="cell">${escapeHtml(savedAt)}</div>
                    <div class="client-draft-card__actions" role="cell">
                        <button type="button" class="btn btn-outline-primary btn-sm" data-draft-action="continue">Continue</button>
                        <button type="button" class="btn btn-outline-danger btn-sm" data-draft-action="delete">Delete</button>
                    </div>
                </div>
            `;
        }).join('');

        const previousControl = draftCurrentPage > 1
            ? `<button type="button" class="client-pagination__step" data-draft-page="${draftCurrentPage - 1}">Previous</button>`
            : '<span class="client-pagination__step is-disabled" aria-disabled="true">Previous</span>';
        const nextControl = draftCurrentPage < totalPages
            ? `<button type="button" class="client-pagination__step" data-draft-page="${draftCurrentPage + 1}">Next</button>`
            : '<span class="client-pagination__step is-disabled" aria-disabled="true">Next</span>';
        const pageControls = paginationPages.map((pageNumber) => (
            pageNumber === draftCurrentPage
                ? `<span class="client-pagination__page is-current" aria-current="page">${pageNumber}</span>`
                : `<button type="button" class="client-pagination__page" data-draft-page="${pageNumber}">${pageNumber}</button>`
        )).join('');

        draftList.innerHTML = `
            <div class="client-draft-table__head" role="row">
                <span role="columnheader">Draft</span>
                <span role="columnheader">Contact</span>
                <span role="columnheader">Project</span>
                <span role="columnheader">Saved</span>
                <span role="columnheader">Actions</span>
            </div>
            ${rows}
            <div class="client-pagination client-draft-pagination" aria-label="Draft pagination">
                <div class="client-pagination__meta">
                    <span class="client-pagination__summary">${pageStart}&ndash;${pageEnd} of ${drafts.length}</span>
                    <label class="visually-hidden" for="clientDraftPageSize">Drafts per page</label>
                    <select class="client-pagination__size" id="clientDraftPageSize" data-draft-page-size aria-label="Drafts per page">
                        ${draftPageSizeOptions.map((pageSize) => (
                            `<option value="${pageSize}"${pageSize === draftPageSize ? ' selected' : ''}>${pageSize} items</option>`
                        )).join('')}
                    </select>
                </div>
                <nav class="client-pagination__controls" aria-label="Draft pages">
                    ${previousControl}
                    <span class="client-pagination__pages">${pageControls}</span>
                    ${nextControl}
                </nav>
            </div>
        `;
    }

    function showClientTab(tabName) {
        clientTabButtons.forEach((button) => {
            const isActive = button.dataset.clientTab === tabName;
            button.classList.toggle('is-active', isActive);
            button.setAttribute('aria-selected', isActive ? 'true' : 'false');
        });

        clientTabPanels.forEach((panel) => {
            panel.hidden = panel.dataset.clientPanel !== tabName;
        });
    }

    function escapeHtml(value) {
        const div = document.createElement('div');
        div.textContent = value;
        return div.innerHTML;
    }

    function hideUnsavedClosePanel() {
        if (unsavedClosePanel) {
            unsavedClosePanel.classList.remove('is-visible');
        }
        if (addClientFooter) {
            addClientFooter.classList.remove('has-close-panel');
        }
    }

    function showUnsavedClosePanel() {
        if (unsavedClosePanel) {
            unsavedClosePanel.classList.add('is-visible');
        }
        if (addClientFooter) {
            addClientFooter.classList.add('has-close-panel');
        }
    }

    function updateSaveDraftButton() {
        if (saveDraftButton) {
            saveDraftButton.disabled = !hasDraftValues();
        }
    }

    function showDraftFeedback(message, type = 'success') {
        if (typeof window.appToast === 'function') {
            window.appToast('Client Draft', message, type);
        }
    }

    function updateDraftStatus(hasDraft = hasDraftValues()) {
        if (draftStatus) {
            draftStatus.textContent = hasDraft ? 'Draft saved on this browser' : (formTouched ? 'Unsaved changes' : 'Draft not started');
        }
        updateGeneratedComplexity();
        updateTemplateRecommendation();
        updateSaveDraftButton();
        renderDraftList();
    }

    function saveAddClientDraft(showFeedback = false) {
        const draftData = getDraftData();
        if (!hasDraftValues(draftData)) {
            if (activeDraftId) {
                const remainingDrafts = getStoredDrafts().filter((draft) => draft.id !== activeDraftId);
                saveStoredDrafts(remainingDrafts);
                activeDraftId = null;
            }
            updateDraftStatus(false);
            if (showFeedback) {
                showDraftFeedback('There is no client intake data to save yet.', 'warning');
            }
            return;
        }

        const drafts = getStoredDrafts();
        const now = new Date().toISOString();
        const nextDraft = {
            id: activeDraftId || createDraftId(),
            savedAt: now,
            data: { ...draftData }
        };

        const existingIndex = drafts.findIndex((draft) => draft.id === nextDraft.id);
        if (existingIndex >= 0) {
            drafts[existingIndex] = nextDraft;
        } else {
            drafts.unshift(nextDraft);
        }

        saveStoredDrafts(drafts);
        activeDraftId = nextDraft.id;
        if (draftIdField) {
            draftIdField.value = activeDraftId;
        }
        activeDraftLoaded = true;
        updateDraftStatus(true);
        if (showFeedback) {
            showDraftFeedback('Draft saved on this browser.');
        }
    }

    function loadAddClientDraft(draftId = null) {
        const draftData = getStoredDrafts().find((draft) => draft.id === draftId) || null;
        if (!draftData) {
            updateDraftStatus(false);
            return;
        }

        addClientForm.reset();
        renderRequestedFeatureRows(draftData.data?.requested_features || '');
        Object.entries(draftData.data || {}).forEach(([name, value]) => {
            if (name === 'requested_features') {
                return;
            }
            const field = addClientForm.elements[name];
            if (field) {
                if (field.type === 'file') {
                    return;
                }
                field.value = typeof value === 'string' ? value : '';
            }
        });
        ensureMomTemplate(addClientForm);
        resizeAdditionalRequirementsField();
        resizeSalesNotesField();
        updateAttachmentSummary();
        formTouched = false;
        activeDraftLoaded = true;
        activeDraftId = draftData.id;
        autoBudgetBase = null;
        autoBudgetFromTemplate = false;
        if (draftIdField) {
            draftIdField.value = activeDraftId;
        }
        hideUnsavedClosePanel();
        appliedProjectTemplateType = normalizeTemplateType(getFormValue('project_type'));
        recommendedFeatureTitles = new Set();
        templateApplyMessage = '';
        updateDraftStatus(true);
        showClientStep(0);
    }

    function clearAddClientDraft() {
        saveStoredDrafts([]);
        addClientForm.reset();
        renderRequestedFeatureRows('');
        formTouched = false;
        activeDraftLoaded = false;
        activeDraftId = null;
        if (draftIdField) {
            draftIdField.value = '';
        }
        appliedProjectTemplateType = '';
        recommendedFeatureTitles = new Set();
        templateApplyMessage = '';
        autoBudgetBase = null;
        autoBudgetFromTemplate = false;
        if (addClientForm.elements.additional_requirements) {
            addClientForm.elements.additional_requirements.style.height = '';
        }
        if (addClientForm.elements.sales_notes) {
            addClientForm.elements.sales_notes.style.height = '';
        }
        updateAttachmentSummary();
        hideUnsavedClosePanel();
        updateDraftStatus(false);
        showClientStep(0);
    }

    function resetCurrentClientInput() {
        addClientForm.reset();
        renderRequestedFeatureRows('');
        formTouched = false;
        activeDraftLoaded = false;
        activeDraftId = null;
        isSubmittingClient = false;
        if (draftIdField) {
            draftIdField.value = '';
        }
        appliedProjectTemplateType = '';
        recommendedFeatureTitles = new Set();
        templateApplyMessage = '';
        autoBudgetBase = null;
        autoBudgetFromTemplate = false;
        if (addClientForm.elements.additional_requirements) {
            addClientForm.elements.additional_requirements.style.height = '';
        }
        if (addClientForm.elements.sales_notes) {
            addClientForm.elements.sales_notes.style.height = '';
        }
        updateAttachmentSummary();
        hideUnsavedClosePanel();
        updateDraftStatus(false);
        showClientStep(0);
    }

    function getFormValue(name) {
        const field = addClientForm.elements[name];
        return field ? String(field.value || '').trim() : '';
    }

    function formatDateInput(value) {
        if (!value) {
            return '';
        }

        const parts = value.split('-').map(Number);
        if (parts.length !== 3 || parts.some((part) => Number.isNaN(part))) {
            return value;
        }

        const parsed = new Date(parts[0], parts[1] - 1, parts[2]);
        return parsed.toLocaleDateString([], {
            month: 'short',
            day: 'numeric',
            year: 'numeric'
        });
    }

    function formatTimeInput(value) {
        if (!value) {
            return '';
        }

        const parts = value.split(':').map(Number);
        if (parts.length < 2 || parts.some((part) => Number.isNaN(part))) {
            return value;
        }

        const parsed = new Date();
        parsed.setHours(parts[0], parts[1], 0, 0);
        return parsed.toLocaleTimeString([], {
            hour: 'numeric',
            minute: '2-digit'
        });
    }

    function formatDateTime(dateValue, timeValue) {
        const formattedDate = formatDateInput(dateValue);
        const formattedTime = formatTimeInput(timeValue);

        if (formattedDate && formattedTime) {
            return `${formattedDate}, ${formattedTime}`;
        }

        return formattedDate || formattedTime;
    }

    function formatBudget(value) {
        const normalized = String(value || '').replace(/,/g, '').trim();
        if (!normalized) {
            return '';
        }

        const amount = Number(normalized);
        if (!Number.isFinite(amount)) {
            return value;
        }

        return `₱${amount.toLocaleString([], {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        })}`;
    }

    function buildTimelineSummary() {
        const start = formatDateTime(getFormValue('timeline_start'), getFormValue('timeline_start_time'));
        const endDate = getFormValue('timeline_end');
        const end = formatDateTime(endDate, getFormValue('timeline_end_time'));

        if (start && end) {
            return `${start} to ${end}`;
        }

        if (start) {
            return `Starts ${start}`;
        }

        if (end) {
            return `Due ${end}`;
        }

        return '';
    }

    function renderAddClientSummary() {
        syncRequestedFeaturesField();
        updateGeneratedComplexity();
        const requestedFeatures = getRequestedFeatureValues();
        const attachmentInput = addClientForm.querySelector('input[name="attachments[]"]');
        const attachmentNames = Array.from(attachmentInput?.files || [])
            .map((file) => file.name)
            .filter(Boolean);
        const priorityMarkup = getPriorityMarkup();
        const complexityAssessment = getComplexityAssessment();
        const budgetRaw = String(getFormValue('sales_budget') || '').replace(/,/g, '').trim();
        const budget = Number(budgetRaw);
        const hasBudget = budgetRaw !== '' && Number.isFinite(budget);
        const summaryBaseBudget = Number.isFinite(Number(autoBudgetBase)) && Number(autoBudgetBase) > 0
            ? Number(autoBudgetBase)
            : budget;
        const priorityMarkupAmount = hasBudget ? summaryBaseBudget * priorityMarkup.rate : 0;
        const priorityMarkupDetail = hasBudget
            ? `${priorityMarkup.label} priority adds ${priorityMarkup.percent}% (${formatPeso(priorityMarkupAmount)}) based on a ${formatPeso(summaryBaseBudget)} base budget. ${priorityMarkup.reason}`
            : `${priorityMarkup.label} priority adds ${priorityMarkup.percent}%. ${priorityMarkup.reason}`;
        const summaryData = {
            name: getFormValue('name'),
            contact_person: getFormValue('contact_person'),
            email: getFormValue('email'),
            phone: getFormValue('phone'),
            address: getFormValue('address'),
            project_name: getFormValue('project_name'),
            project_type: getFormValue('project_type'),
            timeline: buildTimelineSummary(),
            sales_budget: formatBudget(getFormValue('sales_budget')),
            priority: getFormValue('priority') || 'medium',
            generated_complexity: complexityAssessment.level,
            priority_markup_detail: priorityMarkupDetail,
            complexity_reason: complexityAssessment.reason,
            attachments: attachmentNames.join("\n"),
            requested_feature_count: `${requestedFeatures.length} feature${requestedFeatures.length === 1 ? '' : 's'}`,
            project_description: getFormValue('project_description'),
            additional_requirements: getFormValue('additional_requirements'),
            meeting_notes: getFormValue('meeting_notes'),
            mom: getFormValue('mom'),
            sales_notes: getFormValue('sales_notes')
        };

        summaryFields.forEach((field) => {
            const key = field.dataset.summaryField || '';
            const fallback = key === 'timeline'
                ? 'Not set'
                : (key === 'attachments' ? 'No attachments' : 'Not provided');
            const value = summaryData[key] || '';
            field.textContent = value || fallback;
            field.classList.toggle('is-empty', value === '');

            const chip = field.closest('[data-summary-chip]');
            if (chip) {
                chip.dataset.value = String(value || '').toLowerCase();
            }
        });

        if (summaryFeatureList) {
            summaryFeatureList.replaceChildren();
            if (requestedFeatures.length === 0) {
                const emptyItem = document.createElement('li');
                emptyItem.className = 'is-empty';
                emptyItem.textContent = 'Not provided';
                summaryFeatureList.appendChild(emptyItem);
            } else {
                requestedFeatures.forEach((feature) => {
                    const item = document.createElement('li');
                    item.textContent = feature;
                    summaryFeatureList.appendChild(item);
                });
            }
        }
    }

    function prepareRequestedFeatureValidation() {
        commitRequestedFeatureInput(false);
        const featureInputs = Array.from(requestedFeatureList?.querySelectorAll('[data-requested-feature-input]') || []);
        featureInputs.forEach((input) => input.setCustomValidity(''));

        if (getRequestedFeatureValues().length > 0) {
            return true;
        }

        const firstInput = featureInputs[0];
        if (firstInput) {
            firstInput.setCustomValidity('Add at least one requested feature before sending the project to Architect.');
        }
        return false;
    }

    function prepareTimelineValidation() {
        const startDateField = addClientForm.elements.timeline_start;
        const startTimeField = addClientForm.elements.timeline_start_time;
        const endDateField = addClientForm.elements.timeline_end;
        const endTimeField = addClientForm.elements.timeline_end_time;
        if (!startDateField || !startTimeField || !endDateField || !endTimeField) {
            return true;
        }

        endDateField.setCustomValidity('');
        endTimeField.setCustomValidity('');
        endDateField.min = startDateField.value || '';
        if (startDateField.value && endDateField.value && endDateField.value < startDateField.value) {
            endDateField.setCustomValidity('Project deadline cannot be earlier than the timeline start date.');
            return false;
        }
        if (!startDateField.value || !startTimeField.value || !endDateField.value || !endTimeField.value) {
            return true;
        }

        const startAt = new Date(`${startDateField.value}T${startTimeField.value}:00`);
        const endAt = new Date(`${endDateField.value}T${endTimeField.value}:00`);
        if (Number.isNaN(startAt.getTime()) || Number.isNaN(endAt.getTime()) || endAt <= startAt) {
            endTimeField.setCustomValidity('Project deadline must be later than the timeline start date and time.');
            return false;
        }

        return true;
    }

    function validateClientStep(stepIndex, shouldReport = true) {
        const panel = stepPanels[stepIndex];
        if (!panel) {
            return true;
        }

        if (stepIndex === 1) {
            prepareRequestedFeatureValidation();
        }
        if (stepIndex === 2) {
            prepareTimelineValidation();
        }

        const fields = Array.from(panel.querySelectorAll('input, select, textarea'));
        for (const field of fields) {
            if (!field.checkValidity()) {
                if (shouldReport) {
                    field.reportValidity();
                }
                return false;
            }
        }

        return true;
    }

    function showClientStep(stepIndex) {
        currentStep = Math.max(0, Math.min(stepIndex, stepPanels.length - 1));
        if (currentStep === stepPanels.length - 1) {
            renderAddClientSummary();
        }

        stepPanels.forEach((panel, index) => {
            panel.hidden = index !== currentStep;
        });

        stepButtons.forEach((button, index) => {
            button.classList.toggle('is-active', index === currentStep);
            button.classList.toggle('is-complete', index < currentStep);
            button.setAttribute('aria-current', index === currentStep ? 'step' : 'false');
        });

        if (previousButton) {
            previousButton.hidden = currentStep === 0;
        }
        if (nextButton) {
            nextButton.hidden = currentStep === stepPanels.length - 1;
        }
        if (submitButton) {
            submitButton.hidden = currentStep !== stepPanels.length - 1;
        }
        if (architectHandoffNote) {
            architectHandoffNote.hidden = currentStep === stepPanels.length - 1;
        }
        if (currentStep === 1) {
            window.requestAnimationFrame(resizeAdditionalRequirementsField);
            queueClientFeatureEditorAvailability();
        }
        if (currentStep === 2) {
            queueResizeSalesNotesField();
        }
    }

    function requestAddClientClose() {
        window.clearTimeout(addClientForm._draftSaveTimer);

        // The intake is auto-saved, so Cancel should always close the wizard.
        // Flush the latest edit first to avoid losing input made immediately
        // before the button was clicked.
        if (hasDraftValues()) {
            saveAddClientDraft(false);
        }

        resetCurrentClientInput();
        addClientModal.hide();
    }

    addClientForm.addEventListener('input', (event) => {
        if (event.target?.name === 'project_type') {
            closeProjectTemplateReview(false);
            templateApplyMessage = '';
            updateTemplateRecommendation();
        }
        if (event.target?.name === 'sales_budget') {
            const enteredBudget = Number(String(event.target.value || '').replace(/,/g, ''));
            autoBudgetBase = Number.isFinite(enteredBudget) && enteredBudget > 0 ? enteredBudget : null;
            autoBudgetFromTemplate = false;
        }
        if (event.target?.name === 'additional_requirements') {
            resizeAdditionalRequirementsField();
        }
        if (event.target?.name === 'sales_notes') {
            resizeSalesNotesField();
        }
        if (event.target?.name === 'timeline_end') {
            delete event.target.dataset.templateDurationAuto;
            delete event.target.dataset.templateDurationWeeks;
        }
        if (event.target?.name === 'timeline_start' && addClientForm.elements.timeline_end?.dataset.templateDurationAuto === '1') {
            syncClientTimelineWithPriority(true);
        }
        formTouched = true;
        hideUnsavedClosePanel();
        updateDraftStatus(false);
        window.clearTimeout(addClientForm._draftSaveTimer);
        addClientForm._draftSaveTimer = window.setTimeout(() => saveAddClientDraft(false), 250);
    });

    addClientForm.addEventListener('focusin', (event) => {
        if (event.target?.name === 'project_type' && !event.target.dataset.previousProjectType) {
            event.target.dataset.previousProjectType = event.target.value;
        }
    });

    addClientForm.addEventListener('change', async (event) => {
        if (event.target?.name === 'project_type') {
            const nextType = normalizeTemplateType(event.target.value);
            const previousType = String(event.target.dataset.previousProjectType || '').trim();
            const previousNormalized = normalizeTemplateType(previousType) || appliedProjectTemplateType;
            if (previousNormalized && nextType !== previousNormalized && hasProjectTypeProgress()) {
                const shouldChange = await showSalesAssistantProjectTypeWarning(addClientModalElement, previousType, event.target.value);
                if (!shouldChange) {
                    event.target.value = previousType;
                    updateTemplateRecommendation();
                    return;
                }
                clearProjectTypeProgress();
                window.setTimeout(() => announceSalesAssistant('Project type changed', `${event.target.value} is ready. I found a new template recommendation for you to review.`), 220);
            }
            event.target.dataset.previousProjectType = event.target.value;
            updateTemplateRecommendation();
        }
    });

    addClientForm.addEventListener('change', (event) => {
        if (event.target?.matches('[data-attachment-input]')) {
            updateAttachmentSummary();
        }
        if (event.target?.name === 'priority') {
            syncAutoBudgetWithPriority();
            syncClientTimelineWithPriority(false);
        }
        if (event.target?.name === 'sales_budget') {
            syncAutoBudgetWithPriority();
        }
        formTouched = true;
        hideUnsavedClosePanel();
        updateDraftStatus(false);
        window.clearTimeout(addClientForm._draftSaveTimer);
        addClientForm._draftSaveTimer = window.setTimeout(() => saveAddClientDraft(false), 250);
    });

    addClientForm.addEventListener('click', (event) => {
        const featureToggleButton = event.target.closest('[data-client-feature-toggle]');
        if (featureToggleButton) {
            toggleClientFeatureEditor();
            return;
        }

        if (!event.target.closest('[data-client-feature-shell]')) {
            setClientFeatureEditorExpanded(false, false);
        }

        const templateInsightButton = event.target.closest('[data-client-template-insight-trigger]');
        if (templateInsightButton) {
            closePriorityInsight(false);
            closeComplexityInsight(addClientForm, false);
            closeTemplatePickers();
            toggleClientTemplateInsight();
            return;
        }

        if (!event.target.closest('.template-recommendation-shell')) {
            closeClientTemplateInsight(false);
        }

        const complexityInsightButton = event.target.closest('[data-complexity-insight-trigger]');
        if (complexityInsightButton) {
            closePriorityInsight(false);
            closeClientTemplateInsight(false);
            toggleComplexityInsight(complexityInsightButton, addClientForm);
            return;
        }

        if (!event.target.closest('.complexity-field-shell')) {
            closeComplexityInsight(addClientForm, false);
        }

        const priorityInsightButton = event.target.closest('[data-priority-insight-trigger]');
        if (priorityInsightButton) {
            closeComplexityInsight(addClientForm, false);
            closeClientTemplateInsight(false);
            togglePriorityInsight();
            return;
        }

        if (!event.target.closest('.priority-field-shell')) {
            closePriorityInsight(false);
        }

        const reviewButton = event.target.closest('[data-review-project-template]');
        if (reviewButton) {
            const template = findProjectTemplate(reviewButton.dataset.templateType || getFormValue('project_type'));
            if (template) {
                closeClientTemplateInsight(false);
                openProjectTemplateReview(template, reviewButton);
            }
            return;
        }

        const closeReviewButton = event.target.closest('[data-close-template-review], [data-cancel-template-apply]');
        if (closeReviewButton || event.target === templateReviewOverlay) {
            if (templateReviewOverlay?.classList.contains('is-applying')) {
                return;
            }
            closeProjectTemplateReview(true);
            return;
        }

        const decisionButton = event.target.closest('[data-dss-step-decision]');
        if (decisionButton) {
            const template = findProjectTemplate(templateReviewOverlay?.dataset.templateType || getFormValue('project_type'));
            if (template) advanceGuidedTemplateReview(template, decisionButton.dataset.dssStepDecision === 'accept');
            return;
        }

        const confirmButton = event.target.closest('[data-confirm-template-apply]');
        if (!confirmButton) {
            return;
        }

        const template = findProjectTemplate(templateReviewOverlay?.dataset.templateType || getFormValue('project_type'));
        if (template) {
            templateReviewOverlay?.classList.add('is-applying');
            confirmButton.disabled = true;
            confirmButton.innerHTML = '<i class="fas fa-check me-1"></i> Applying';
            templateReviewOverlay?.querySelector('[data-cancel-template-apply]')?.setAttribute('disabled', 'disabled');
            templateReviewOverlay?.querySelector('[data-close-template-review]')?.setAttribute('disabled', 'disabled');
            const hasSystemLoading = typeof window.appShowLoading === 'function' && typeof window.appHideLoading === 'function';
            if (hasSystemLoading) {
                window.appShowLoading('Applying template', 'Please wait while the saved project baseline is applied.');
            }

            window.setTimeout(() => {
                try {
                    closeProjectTemplateReview(false);
                    applyProjectTemplate(template, { force: true, showSuccess: true });
                } finally {
                    if (hasSystemLoading) {
                        window.appHideLoading();
                    }
                }
            }, hasSystemLoading ? 650 : 250);
        }
    });

    addClientForm.addEventListener('keydown', (event) => {
        const featureInput = event.target.closest('[data-requested-feature-input]');
        if (featureInput && event.key === 'Enter') {
            event.preventDefault();
            if (commitRequestedFeatureInput(true)) {
                formTouched = true;
                hideUnsavedClosePanel();
                updateDraftStatus(false);
            }
            return;
        }

        if (featureInput && event.key === 'Backspace' && !featureInput.value) {
            const chips = requestedFeatureList?.querySelectorAll('[data-requested-feature-value]') || [];
            const lastChip = chips[chips.length - 1];
            const removeButton = lastChip?.querySelector('[data-remove-requested-feature]');
            if (removeButton) {
                event.preventDefault();
                removeButton.click();
            }
            return;
        }

        if (event.key === 'Escape' && templateReviewOverlay && !templateReviewOverlay.hidden) {
            event.preventDefault();
            closeProjectTemplateReview(true);
            return;
        }

        if (event.key === 'Escape' && !addClientForm.querySelector('[data-client-template-recommendation]')?.hidden) {
            event.preventDefault();
            closeClientTemplateInsight(true);
            return;
        }

        if (event.key === 'Escape') {
            const featureShell = addClientForm.querySelector('[data-client-feature-shell]');
            if (featureShell?.classList.contains('is-expanded')) {
                event.preventDefault();
                setClientFeatureEditorExpanded(false, true);
                return;
            }
            closePriorityInsight(true);
            closeComplexityInsight(addClientForm, true);
        }
    });

    addClientForm.addEventListener('focusin', (event) => {
        if (event.target.matches('[data-requested-feature-input]')) {
            setClientFeatureEditorExpanded(true, false);
        }
    });

    if (requestedFeatureList) {
        requestedFeatureList.addEventListener('input', (event) => {
            if (event.target.matches('[data-requested-feature-input]')) {
                event.target.setCustomValidity('');
            }
        });

        requestedFeatureList.addEventListener('paste', (event) => {
            const input = event.target.closest('[data-requested-feature-input]');
            if (!input) {
                return;
            }

            const pastedText = event.clipboardData?.getData('text') || '';
            const parts = splitRequestedFeatureInput(pastedText);
            if (parts.length <= 1) {
                return;
            }

            event.preventDefault();
            let addedAny = false;
            parts.forEach((part) => {
                addedAny = addRequestedFeatureValue(part, false) || addedAny;
            });
            input.value = '';
            if (addedAny) {
                formTouched = true;
                hideUnsavedClosePanel();
                updateDraftStatus(false);
                setClientFeatureEditorExpanded(true, true);
            }
        });

        requestedFeatureList.addEventListener('click', async (event) => {
            const removeButton = event.target.closest('[data-remove-requested-feature]');
            if (!removeButton) {
                return;
            }

            const chip = removeButton.closest('[data-requested-feature-value]');
            if (!chip) {
                return;
            }
            const featureName = String(chip.dataset.requestedFeatureValue || '').trim();
            const isRecommended = featureName !== '' && isRecommendedFeature(featureName);
            if (featureName !== '') {
                const shouldRemove = isRecommended
                    ? await showSalesAssistantFeatureRemoval(featureName)
                    : (typeof window.appConfirm === 'function'
                        ? await window.appConfirm({
                            title: 'Remove Feature',
                            titleIcon: 'fas fa-layer-group',
                            subject: `Remove "${featureName}"?`,
                            message: 'This feature will be removed from the client intake draft.',
                            icon: 'fas fa-trash-can fa-lg',
                            confirmIcon: 'fas fa-trash',
                            confirmText: 'Remove Feature',
                            variant: 'danger'
                        })
                        : false);
                if (!shouldRemove) {
                    return;
                }
            }

            if (isRecommended) {
                recommendedFeatureTitles.delete(normalizeTemplateType(featureName));
            }

            chip.remove();

            formTouched = true;
            hideUnsavedClosePanel();
            syncRequestedFeaturesField();
            updateClientFeatureEditorAvailability();
            requestedFeatureList.querySelector('[data-requested-feature-input]')?.focus();
            updateDraftStatus(false);
        });
    }

    ['timeline_start', 'timeline_start_time', 'timeline_end', 'timeline_end_time'].forEach((fieldName) => {
        addClientForm.elements[fieldName]?.addEventListener('input', () => {
            addClientForm.elements.timeline_end?.setCustomValidity('');
            addClientForm.elements.timeline_end_time?.setCustomValidity('');
            if (addClientForm.elements.timeline_end) {
                addClientForm.elements.timeline_end.min = addClientForm.elements.timeline_start?.value || '';
            }
        });
    });

    closeButtons.forEach((button) => {
        button.addEventListener('click', requestAddClientClose);
    });

    if (keepEditingButton) {
        keepEditingButton.addEventListener('click', hideUnsavedClosePanel);
    }

    if (discardUnsavedButton) {
        discardUnsavedButton.addEventListener('click', () => {
            resetCurrentClientInput();
            addClientModal.hide();
        });
    }

    if (nextButton) {
        nextButton.addEventListener('click', () => {
            if (validateClientStep(currentStep, true)) {
                showClientStep(currentStep + 1);
            }
        });
    }

    if (previousButton) {
        previousButton.addEventListener('click', () => {
            showClientStep(currentStep - 1);
        });
    }

    stepButtons.forEach((button) => {
        button.addEventListener('click', () => {
            const targetStep = Number(button.dataset.stepTarget);
            if (targetStep <= currentStep) {
                showClientStep(targetStep);
            }
        });
    });

    addClientForm.addEventListener('submit', (event) => {
        if (isSubmittingClient) {
            event.preventDefault();
            event.stopPropagation();
            return;
        }

        syncRequestedFeaturesField();
        for (let stepIndex = 0; stepIndex < stepPanels.length; stepIndex++) {
            if (!validateClientStep(stepIndex, false)) {
                event.preventDefault();
                event.stopPropagation();
                showClientStep(stepIndex);
                validateClientStep(stepIndex, true);
                return;
            }
        }

        if (currentStep !== stepPanels.length - 1) {
            event.preventDefault();
            event.stopPropagation();
            showClientStep(stepPanels.length - 1);
            return;
        }

        if (hasDraftValues()) {
            saveAddClientDraft();
        }
        if (draftIdField) {
            draftIdField.value = activeDraftId || '';
        }
        isSubmittingClient = true;
        formTouched = false;
    });

    function shouldProtectAddClientProgress() {
        return !isSubmittingClient
            && addClientModalElement.classList.contains('show')
            && (formTouched || activeDraftLoaded || Boolean(activeDraftId))
            && hasDraftValues();
    }

    function preserveAddClientProgress() {
        if (shouldProtectAddClientProgress()) {
            saveAddClientDraft(false);
        }
    }

    window.addEventListener('beforeunload', (event) => {
        if (!shouldProtectAddClientProgress()) {
            return;
        }

        preserveAddClientProgress();
        event.preventDefault();
        event.returnValue = '';
    });

    window.addEventListener('pagehide', preserveAddClientProgress);

    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'hidden' && addClientModalElement.classList.contains('show')) {
            preserveAddClientProgress();
        }
    });

    if (draftList) {
        draftList.addEventListener('click', (event) => {
            const pageControl = event.target.closest('[data-draft-page]');
            if (pageControl) {
                renderDraftList(getStoredDrafts(), Number(pageControl.dataset.draftPage));
                return;
            }

            const button = event.target.closest('[data-draft-action]');
            const card = event.target.closest('[data-draft-id]');
            if (!button || !card) {
                return;
            }

            const draftId = card.dataset.draftId;
            const action = button.dataset.draftAction;
            if (!draftId || !action) {
                return;
            }

            if (action === 'continue') {
                loadAddClientDraft(draftId);
                addClientModal.show();
                return;
            }

            if (action === 'delete') {
                const draft = getStoredDrafts().find((item) => item.id === draftId);
                const draftLabel = draft?.data?.name || draft?.data?.project_name || 'Untitled draft';
                openDeleteDraftModal(draftId, draftLabel);
            }
        });

        draftList.addEventListener('change', (event) => {
            const pageSizeControl = event.target.closest('[data-draft-page-size]');
            if (!pageSizeControl) {
                return;
            }

            const nextPageSize = Number(pageSizeControl.value);
            if (!draftPageSizeOptions.includes(nextPageSize)) {
                return;
            }

            draftPageSize = nextPageSize;
            draftCurrentPage = 1;
            localStorage.setItem(draftPageSizeKey, String(draftPageSize));
            renderDraftList(getStoredDrafts(), draftCurrentPage);
        });
    }

    if (deleteDraftConfirmBtn) {
        deleteDraftConfirmBtn.addEventListener('click', function () {
            if (!pendingDeleteDraftId) {
                return;
            }

            const draftIdToDelete = pendingDeleteDraftId;
            const deletingActiveDraft = activeDraftId === draftIdToDelete;
            deleteDraftConfirmBtn.disabled = true;

            try {
                removeStoredDraft(draftIdToDelete);
            } catch (error) {
                console.error('Unable to delete saved client draft:', error);
                window.appToast?.(
                    'Unable to delete draft',
                    'Browser storage could not be updated. Check that storage access is allowed and try again.',
                    'error'
                );
                deleteDraftConfirmBtn.disabled = false;
                return;
            }

            pendingDeleteDraftId = null;
            bootstrap.Modal.getOrCreateInstance(deleteDraftModalElement).hide();

            try {
                if (deletingActiveDraft) {
                    resetCurrentClientInput();
                } else {
                    renderDraftList(getStoredDrafts(), draftCurrentPage);
                    updateDraftStatus(hasStoredDraft());
                }
            } catch (uiError) {
                console.warn('Draft was deleted, but the draft list could not be fully refreshed:', uiError);
                renderDraftList(getStoredDrafts(), draftCurrentPage);
            }

            deleteDraftConfirmBtn.disabled = false;
            window.appToast?.('Draft deleted', 'The saved draft was removed from this browser.', 'success');
        });
    }

    clientTabButtons.forEach((button) => {
        button.addEventListener('click', () => {
            showClientTab(button.dataset.clientTab || 'clients');
        });
    });

    addClientModalElement.addEventListener('show.bs.modal', () => {
        syncAddClientPageBounds();
        if (!activeDraftLoaded) {
            resetCurrentClientInput();
        }
    });

    window.addEventListener('resize', () => {
        if (addClientModalElement.classList.contains('show')) {
            syncAddClientPageBounds();
            if (currentStep === 1) {
                queueClientFeatureEditorAvailability();
                window.requestAnimationFrame(resizeAdditionalRequirementsField);
            }
        }
    });

    addClientModalElement.addEventListener('hidden.bs.modal', () => {
        showClientStep(0);
        closeProjectTemplateReview(false);
        closeClientTemplateInsight(false);
        closePriorityInsight(false);
        setClientFeatureEditorExpanded(false, false);
        hideUnsavedClosePanel();
        activeDraftLoaded = false;
        activeDraftId = null;
        updateDraftStatus(hasStoredDraft());
    });

    showClientStep(0);
    showClientTab('clients');
    // Persist the normalized list once on startup so legacy/empty phantom
    // drafts are removed from storage, not merely hidden from the table.
    saveStoredDrafts(getStoredDrafts());
    removeStoredDraft(savedClientDraftId);
    renderDraftList();
    updateDraftStatus(hasStoredDraft());
}

const clientSearchInput = document.getElementById('searchInput');
const clientStatusFilter = document.getElementById('statusFilter');
const clientPageSizeSelect = document.getElementById('clientPageSize');
let clientFilterTimer = null;

clientSearchInput?.addEventListener('input', function() {
    window.clearTimeout(clientFilterTimer);
    clientFilterTimer = window.setTimeout(applyFilters, 500);
});

clientSearchInput?.addEventListener('keydown', function(event) {
    if (event.key === 'Enter') {
        event.preventDefault();
        window.clearTimeout(clientFilterTimer);
        applyFilters();
    }
});

clientStatusFilter?.addEventListener('change', function() {
    window.clearTimeout(clientFilterTimer);
    applyFilters();
});

clientPageSizeSelect?.addEventListener('change', function() {
    const params = new URLSearchParams(window.location.search);
    params.set('per_page', this.value);
    params.delete('page');
    window.location.href = 'clients.php?' + params.toString();
});

document.addEventListener('DOMContentLoaded', function() {
    const params = new URLSearchParams(window.location.search);
    const clientId = params.get('view') || params.get('id');
    if (clientId) {
        viewClient(clientId);
    }
});
</script>

<?php include '../../includes/footer.php'; ?>
