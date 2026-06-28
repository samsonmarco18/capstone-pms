<?php
require_once '../../includes/auth.php';
require_once '../../includes/project-progress.php';
require_once '../../includes/workflow.php';
requireRole('sales');

$conn = getConnection();
$user = getCurrentUser();
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
        $projectDescription = trim((string) ($_POST['project_description'] ?? ''));
        $meetingNotes = trim((string) ($_POST['meeting_notes'] ?? ''));
        $mom = trim((string) ($_POST['mom'] ?? ''));
        $requestedFeatures = trim((string) ($_POST['requested_features'] ?? ''));
        $additionalRequirements = trim((string) ($_POST['additional_requirements'] ?? ''));
        $salesBudgetInput = trim((string) ($_POST['sales_budget'] ?? ''));
        $salesBudget = parseSalesBudgetInput($salesBudgetInput);
        $timelineStart = $_POST['timeline_start'] ?: null;
        $timelineEnd = $_POST['timeline_end'] ?: ($deadline ?: null);
        $timelineStartTimeInput = trim((string) ($_POST['timeline_start_time'] ?? ''));
        $timelineEndTimeInput = trim((string) ($_POST['timeline_end_time'] ?? ($_POST['deadline_time'] ?? '')));
        $timelineStartTime = parseSalesTimeInput($timelineStartTimeInput);
        $timelineEndTime = parseSalesTimeInput($timelineEndTimeInput);

        $requirementsSummary = $requestedFeatures;
        if ($additionalRequirements !== '') {
            $requirementsSummary .= ($requirementsSummary !== '' ? "\n\n" : '') . 'Additional Requirements:' . "\n" . $additionalRequirements;
        }
        if (!$hasSalesBudget && $salesBudgetInput !== '') {
            $requirementsSummary .= ($requirementsSummary !== '' ? "\n\n" : '') . 'Budget:' . "\n" . $salesBudgetInput;
        }

        if ($name === '') {
            $_SESSION['error'] = 'Client name is required.';
        } elseif ($salesBudgetInput !== '' && $salesBudget === null) {
            $_SESSION['error'] = 'Budget must be a valid number.';
        } elseif ($timelineStartTimeInput !== '' && $timelineStartTime === null) {
            $_SESSION['error'] = 'Timeline start time must be valid.';
        } elseif ($timelineEndTimeInput !== '' && $timelineEndTime === null) {
            $_SESSION['error'] = 'Project deadline time must be valid.';
        } else {
            $conn->begin_transaction();

            try {
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

                $clientFolderStmt = $conn->prepare(
                    "SELECT id FROM folders WHERE client_id = ? AND parent_id IS NULL AND folder_type = 'client' LIMIT 1"
                );
                if ($clientFolderStmt === false) {
                    throw new RuntimeException('Unable to prepare client folder lookup.');
                }
                $clientFolderStmt->bind_param('i', $clientId);
                if (!$clientFolderStmt->execute()) {
                    throw new RuntimeException('Unable to load client folder.');
                }
                $clientFolderResult = $clientFolderStmt->get_result()->fetch_assoc();
                $clientFolderId = $clientFolderResult['id'] ?? null;
                $clientFolderStmt->close();

                if (!$clientFolderId) {
                    $createClientFolderStmt = $conn->prepare(
                        "INSERT INTO folders (name, folder_type, client_id, parent_id, role, created_by)
                         VALUES (?, 'client', ?, NULL, NULL, ?)"
                    );
                    if ($createClientFolderStmt === false) {
                        throw new RuntimeException('Unable to prepare client folder creation.');
                    }
                    $createClientFolderStmt->bind_param('sii', $name, $clientId, $user['id']);
                    if (!$createClientFolderStmt->execute()) {
                        throw new RuntimeException('Unable to create client folder.');
                    }
                    $clientFolderId = $createClientFolderStmt->insert_id;
                    $createClientFolderStmt->close();
                }

                $projectId = null;
                if ($projectName !== '') {
                    if ($hasSalesBudget) {
                        $timeColumns = $hasTimelineStartTime && $hasTimelineEndTime
                            ? ', timeline_start_time, timeline_end_time'
                            : '';
                        $timePlaceholders = $hasTimelineStartTime && $hasTimelineEndTime
                            ? ', ?, ?'
                            : '';
                        $projectStmt = $conn->prepare("
                            INSERT INTO projects (
                                client_id, name, description, status, timeline_start, timeline_end,
                                meeting_notes, mom, requested_features, sales_budget{$timeColumns}, created_by, architect_id
                            ) VALUES (?, ?, ?, 'pending', ?, ?, ?, ?, ?, " . (DB_DRIVER === 'pgsql' ? "CAST(NULLIF(?, '') AS NUMERIC)" : "NULLIF(?, '')") . "{$timePlaceholders}, ?, NULL)
                        ");
                        if ($projectStmt === false) {
                            throw new RuntimeException('Unable to prepare project insert.' . salesClientsDbError($conn));
                        }
                        $projectBudget = $salesBudget === null ? '' : number_format($salesBudget, 2, '.', '');
                        if ($hasTimelineStartTime && $hasTimelineEndTime) {
                            $projectStmt->bind_param(
                                'issssssssssi',
                                $clientId,
                                $projectName,
                                $projectDescription,
                                $timelineStart,
                                $timelineEnd,
                                $meetingNotes,
                                $mom,
                                $requirementsSummary,
                                $projectBudget,
                                $timelineStartTime,
                                $timelineEndTime,
                                $user['id']
                            );
                        } else {
                            $projectStmt->bind_param(
                                'issssssssi',
                                $clientId,
                                $projectName,
                                $projectDescription,
                                $timelineStart,
                                $timelineEnd,
                                $meetingNotes,
                                $mom,
                                $requirementsSummary,
                                $projectBudget,
                                $user['id']
                            );
                        }
                    } else {
                        $timeColumns = $hasTimelineStartTime && $hasTimelineEndTime
                            ? ', timeline_start_time, timeline_end_time'
                            : '';
                        $timePlaceholders = $hasTimelineStartTime && $hasTimelineEndTime
                            ? ', ?, ?'
                            : '';
                        $projectStmt = $conn->prepare("
                            INSERT INTO projects (
                                client_id, name, description, status, timeline_start, timeline_end,
                                meeting_notes, mom, requested_features{$timeColumns}, created_by, architect_id
                            ) VALUES (?, ?, ?, 'pending', ?, ?, ?, ?, ?{$timePlaceholders}, ?, NULL)
                        ");
                        if ($projectStmt === false) {
                            throw new RuntimeException('Unable to prepare project insert.' . salesClientsDbError($conn));
                        }
                        if ($hasTimelineStartTime && $hasTimelineEndTime) {
                            $projectStmt->bind_param(
                                'isssssssssi',
                                $clientId,
                                $projectName,
                                $projectDescription,
                                $timelineStart,
                                $timelineEnd,
                                $meetingNotes,
                                $mom,
                                $requirementsSummary,
                                $timelineStartTime,
                                $timelineEndTime,
                                $user['id']
                            );
                        } else {
                            $projectStmt->bind_param(
                                'isssssssi',
                                $clientId,
                                $projectName,
                                $projectDescription,
                                $timelineStart,
                                $timelineEnd,
                                $meetingNotes,
                                $mom,
                                $requirementsSummary,
                                $user['id']
                            );
                        }
                    }
                        if (!$projectStmt->execute()) {
                            throw new RuntimeException('Unable to create project requirements.' . salesClientsDbError($conn));
                        }
                        $projectId = $projectStmt->insert_id;
                        $projectStmt->close();

                    // Create shared project folder under client folder
                    $sharedFolderName = "Shared - " . $projectName;
                    $sharedFolderStmt = $conn->prepare(
                        "INSERT INTO folders (name, folder_type, client_id, project_id, parent_id, role, created_by) 
                         VALUES (?, 'project', ?, ?, ?, NULL, ?)"
                    );
                    if ($sharedFolderStmt === false) {
                        throw new RuntimeException('Unable to prepare shared folder creation.' . salesClientsDbError($conn));
                    }
                    $sharedFolderStmt->bind_param('siiii', $sharedFolderName, $clientId, $projectId, $clientFolderId, $user['id']);
                    if (!$sharedFolderStmt->execute()) {
                        throw new RuntimeException('Unable to create shared project folder.' . salesClientsDbError($conn));
                    }
                    $sharedFolderId = $sharedFolderStmt->insert_id;
                    $sharedFolderStmt->close();

                }

                $conn->commit();
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
                }
                logActivity($user['id'], 'add_client', 'clients', $clientId, null, $_POST);
                if ($projectId) {
                    logActivity($user['id'], 'create_project_requirements', 'projects', $projectId, null, $_POST);
                    $_SESSION['success'] = "Client added, shared project folder created, and project details are available to all roles.";
                } else {
                    $_SESSION['success'] = "Client added and shared client folder created. No project created because project name was empty.";
                }
            } catch (Throwable $e) {
                $conn->rollback();
                error_log('Sales client save error: ' . $e->getMessage());
                $_SESSION['error'] = 'Unable to save client and requirements. ' . $e->getMessage();
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
        $deadline = $_POST['deadline'];
        
        $oldResult = salesClientsQuery($conn, "SELECT * FROM clients WHERE id = $id");
        $oldData = $oldResult ? $oldResult->fetch_assoc() : null;
        
        $stmt = $conn->prepare("UPDATE clients SET name=?, contact_person=?, email=?, phone=?, address=?, status=?, deadline=? WHERE id=?");
        $stmt->bind_param("sssssssi", $name, $contact_person, $email, $phone, $address, $status, $deadline, $id);
        
        if ($stmt->execute()) {
            logActivity($user['id'], 'update_client', 'clients', $id, $oldData, $_POST);
            $_SESSION['success'] = "Client updated successfully";
        }
    } elseif (isset($_POST['delete_client'])) {
        $id = $_POST['id'];
        $oldResult = salesClientsQuery($conn, "SELECT * FROM clients WHERE id = $id");
        $oldData = $oldResult ? $oldResult->fetch_assoc() : null;
        
        $stmt = $conn->prepare("DELETE FROM clients WHERE id=?");
        $stmt->bind_param("i", $id);
        
        if ($stmt->execute()) {
            logActivity($user['id'], 'delete_client', 'clients', $id, $oldData, null);
            $_SESSION['success'] = "Client deleted successfully";
        }
    }
    
    header('Location: clients.php');
    exit();
}

// Get all clients
$search = isset($_GET['search']) ? $_GET['search'] : '';
$statusFilter = isset($_GET['status']) ? $_GET['status'] : '';

$featureCondition = projectProgressFeatureCondition('t');
$mergedCondition = projectProgressMergedCondition('t');
$query = "
    SELECT
        c.*,
        COALESCE(cp.total_features, 0) AS total_features,
        COALESCE(cp.merged_features, 0) AS merged_features
    FROM clients c
    LEFT JOIN (
        SELECT
            p.client_id,
            COUNT(t.id) AS total_features,
            SUM(CASE WHEN {$mergedCondition} THEN 1 ELSE 0 END) AS merged_features
        FROM projects p
        LEFT JOIN tasks t ON t.project_id = p.id AND {$featureCondition}
        GROUP BY p.client_id
    ) cp ON cp.client_id = c.id
    WHERE 1=1
";
if ($search) {
    $query .= " AND (c.name LIKE '%$search%' OR c.email LIKE '%$search%' OR c.contact_person LIKE '%$search%')";
}
if ($statusFilter) {
    $query .= " AND c.status = '$statusFilter'";
}
$query .= " ORDER BY c.created_at DESC";

$clients = salesClientsQuery($conn, $query);

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

/* Stats Row */
.stats-row {
    display: flex;
    gap: 1rem;
    margin-bottom: 2rem;
    flex-wrap: wrap;
}

.stat-card-modern {
    background: white;
    border-radius: 20px;
    padding: 1.25rem 1.5rem;
    flex: 1;
    min-width: 180px;
    box-shadow: var(--shadow-sm);
    border: 1px solid var(--gray-200);
    transition: all 0.2s ease;
    position: relative;
}

.stat-card-modern:hover {
    transform: translateY(-2px);
    box-shadow: var(--shadow-md);
}

.stat-card-modern .stat-icon {
    position: absolute;
    right: 1rem;
    top: 1rem;
    opacity: 0.65;
    font-size: 1.6rem;
    color: var(--theme-primary);
}

.stat-card-modern .stat-label {
    font-size: 0.75rem;
    text-transform: uppercase;
    font-weight: 600;
    color: var(--gray-600);
    letter-spacing: 0.5px;
    margin-bottom: 0.5rem;
}

.stat-card-modern .stat-number {
    font-size: 2rem;
    font-weight: 700;
    color: var(--theme-primary);
    line-height: 1;
}

/* Main Card */
.main-card {
    background: white;
    border-radius: 24px;
    border: 1px solid var(--gray-200);
    overflow: hidden;
    box-shadow: var(--shadow-sm);
}

.card-header-modern {
    padding: 1.5rem 2rem;
    background: #ffffff;
    border-bottom: 1px solid var(--gray-200);
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
    padding: 1.5rem 2rem;
    background: var(--gray-50);
    border-bottom: 1px solid var(--gray-200);
}

.client-tabs {
    display: flex;
    gap: 0.5rem;
    padding: 1rem 2rem 0;
    background: #ffffff;
    border-bottom: 1px solid var(--gray-200);
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
    border: none;
    background: transparent;
    color: var(--gray-600);
    padding: 0.75rem 0.9rem;
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
    padding: 1.5rem 2rem 2rem;
    background: var(--gray-50);
}

.client-draft-list {
    display: grid;
    gap: 1rem;
    margin-bottom: 1rem;
}

.client-draft-card {
    background: #ffffff;
    border: 1px solid var(--gray-200);
    border-radius: 18px;
    padding: 1.25rem 1.5rem;
    box-shadow: var(--shadow-sm);
    display: flex;
    justify-content: space-between;
    gap: 1rem;
    align-items: flex-start;
}

.client-draft-card__meta {
    flex: 1;
    min-width: 0;
}

.client-draft-card__title {
    font-size: 1rem;
    font-weight: 700;
    color: var(--gray-900);
    margin-bottom: 0.3rem;
    word-break: break-word;
}

.client-draft-card__subtitle {
    font-size: 0.82rem;
    color: var(--gray-600);
    margin-bottom: 0.7rem;
}

.client-draft-card__summary {
    display: flex;
    flex-wrap: wrap;
    gap: 0.4rem;
}

.client-draft-card__summary span {
    display: inline-flex;
    align-items: center;
    padding: 0.25rem 0.55rem;
    border-radius: 999px;
    background: var(--gray-100);
    color: var(--gray-700);
    font-size: 0.74rem;
    font-weight: 600;
}

.client-draft-card__actions {
    display: flex;
    gap: 0.5rem;
    flex-wrap: wrap;
    justify-content: flex-end;
}

.client-draft-card__actions .btn {
    border-radius: 999px;
    font-weight: 600;
}

.filter-group {
    display: flex;
    gap: 1rem;
    flex-wrap: wrap;
    align-items: flex-end;
}

.filter-input {
    flex: 1;
    min-width: 200px;
}

.filter-input label {
    font-size: 0.7rem;
    text-transform: uppercase;
    font-weight: 600;
    color: var(--gray-600);
    letter-spacing: 0.5px;
    margin-bottom: 0.25rem;
    display: block;
}

.filter-input input,
.filter-input select {
    width: 100%;
    padding: 0.6rem 1rem;
    border: 1px solid var(--gray-300);
    border-radius: 12px;
    font-size: 0.85rem;
    transition: all 0.2s;
}

.filter-input input:focus,
.filter-input select:focus {
    outline: none;
    border-color: var(--theme-primary);
    box-shadow: 0 0 0 3px rgba(31, 99, 146, 0.1);
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

.client-table tr:hover {
    background: var(--gray-50);
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

/* Progress Bar */
.progress-wrapper {
    min-width: 100px;
}

.progress {
    height: 6px;
    background: var(--gray-200);
    border-radius: 10px;
    overflow: hidden;
}

.progress-bar {
    background: linear-gradient(90deg, var(--theme-primary) 0%, var(--theme-primary-light) 100%);
    border-radius: 10px;
}

.progress-text {
    font-size: 0.7rem;
    font-weight: 500;
    color: var(--theme-primary);
    margin-top: 0.25rem;
    display: block;
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

.modal-modern.add-client-modal .add-client-layout {
    display: grid;
    gap: 1rem;
}

.modal-modern.add-client-modal .add-client-stepper {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 0.75rem;
    margin-bottom: 1rem;
}

.modal-modern.add-client-modal .stepper-item {
    display: flex;
    align-items: center;
    gap: 0.65rem;
    min-width: 0;
    padding: 0.75rem;
    border: 1px solid var(--gray-200);
    border-radius: 12px;
    background: #ffffff;
    color: var(--gray-600);
    text-align: left;
    transition: all 0.18s ease;
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

.modal-modern.add-client-modal .field-group:last-child {
    margin-bottom: 0;
}

.modal-modern.add-client-modal .mini-note {
    margin-top: 0.5rem;
    color: var(--gray-500);
    font-size: 0.78rem;
    line-height: 1.4;
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

.modal-modern.add-client-modal .add-client-footer {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
}

.modal-modern.add-client-modal .draft-status {
    color: var(--gray-500);
    font-size: 0.8rem;
    font-weight: 500;
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
    gap: 0.75rem;
}

@media (max-width: 768px) {
    .modal-modern.add-client-modal .add-client-stepper {
        grid-template-columns: 1fr;
    }

    .modal-modern.add-client-modal .add-client-footer {
        flex-direction: column-reverse;
        align-items: stretch;
    }

    .modal-modern.add-client-modal .add-client-actions {
        display: grid;
        grid-template-columns: 1fr 1fr;
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

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .page-header-modern,
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

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .filter-section,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .client-drafts-panel,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .client-table thead,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .client-table tr:hover,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .modal-modern.add-client-modal .stepper-item,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .modal-modern.add-client-modal .field-group,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .modal-modern.add-client-modal .info-card,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .client-draft-card__summary span,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .client-draft-summary span,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .btn-icon.view,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .btn-icon.edit,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .client-detail-panel,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .client-project-item {
    background: #0a1b2c;
    border-color: rgba(31, 99, 146, 0.24);
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
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #viewClientContent,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #clientProjects {
    color: #d7e4f2;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .client-detail-panel strong,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .client-project-item strong {
    color: #d7e4f2;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .card-header-modern .text-muted,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .stat-card-modern .stat-label,
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
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .modal-modern.add-client-modal .draft-status,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .filter-input label {
    color: #9db6cf !important;
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
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .client-draft-card,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .client-draft-empty,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .client-detail-panel,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .client-project-item {
    border-color: rgba(31, 99, 146, 0.24);
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

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .client-tab-btn.is-active,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .progress-text,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .client-draft-summary span {
    color: var(--theme-primary) !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .progress {
    background: rgba(157, 182, 207, 0.18);
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

/* Responsive */
@media (max-width: 768px) {
    .page-header-modern {
        padding: 1.5rem;
    }
    
    .stats-row {
        flex-direction: column;
    }
    
    .stat-card-modern {
        min-width: auto;
    }
    
    .card-header-modern {
        flex-direction: column;
        align-items: stretch;
    }
    
    .filter-group {
        flex-direction: column;
    }

    .client-tabs {
        padding: 0.85rem 1rem 0;
        overflow-x: auto;
    }

    .client-drafts-panel {
        padding: 1rem;
    }
    
    .filter-input {
        width: 100%;
    }
    
    .btn-apply {
        width: 100%;
    }
    
    .client-table th,
    .client-table td {
        padding: 0.75rem 1rem;
    }
    
    .action-buttons {
        flex-direction: column;
        gap: 0.25rem;
    }
}
</style>

<div class="container-fluid px-4 py-4">
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
            <div class="mt-3 mt-md-0">
                <button class="btn-add-modern" data-bs-toggle="modal" data-bs-target="#addClientModal">
                    <i class="fas fa-plus me-2"></i> Add New Client
                </button>
            </div>
        </div>
    </div>

    <!-- Statistics Cards -->
    <div class="stats-row">
        <div class="stat-card-modern">
            <i class="fas fa-users stat-icon"></i>
            <div class="stat-label">Total Clients</div>
            <div class="stat-number"><?php echo $totalClients; ?></div>
        </div>
        <div class="stat-card-modern">
            <i class="fas fa-user-check stat-icon"></i>
            <div class="stat-label">Active Clients</div>
            <div class="stat-number"><?php echo $activeClients; ?></div>
        </div>
        <div class="stat-card-modern">
            <i class="fas fa-hourglass-half stat-icon"></i>
            <div class="stat-label">Pending</div>
            <div class="stat-number"><?php echo $pendingClients; ?></div>
        </div>
    </div>

    <!-- Main Content Card -->
    <div class="main-card">
        <div class="card-header-modern">
            <h5>
                <i class="fas fa-list me-2"></i>
                Client Directory
            </h5>
            <div>
                <span class="text-muted small">Showing <?php echo $clients ? $clients->num_rows : 0; ?> clients</span>
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
                    <label>Search</label>
                    <input type="text" id="searchInput" placeholder="Name, email, or contact person..." value="<?php echo htmlspecialchars($search); ?>">
                </div>
                <div class="filter-input">
                    <label>Status</label>
                    <select id="statusFilter">
                        <option value="">All Status</option>
                        <option value="active" <?php echo $statusFilter == 'active' ? 'selected' : ''; ?>>Active</option>
                        <option value="inactive" <?php echo $statusFilter == 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                        <option value="pending" <?php echo $statusFilter == 'pending' ? 'selected' : ''; ?>>Pending</option>
                    </select>
                </div>
                <div>
                    <button class="btn-apply" onclick="applyFilters()">Apply Filters</button>
                </div>
            </div>
        </div>
        
        <!-- Clients Table -->
        <div class="table-container">
            <?php if($clients && $clients->num_rows > 0): ?>
            <table class="client-table">
                <thead>
                    <tr>
                        <th>Client</th>
                        <th>Contact Person</th>
                        <th>Status</th>
                        <th>Progress</th>
                        <th>Deadline</th>
                        <th>Actions</th>
                    </thead>
                <tbody>
    <?php while($client = $clients->fetch_assoc()): ?>
    <?php 
    $clientDeadline = (string) ($client['deadline'] ?? '');
    $clientDeadlineTimestamp = $clientDeadline !== '' ? strtotime($clientDeadline) : false;
    $daysLeft = $clientDeadlineTimestamp !== false ? ceil(($clientDeadlineTimestamp - time()) / (60 * 60 * 24)) : null;
    $deadlineClass = $daysLeft === null ? 'normal' : ($daysLeft <= 7 ? 'urgent' : ($daysLeft <= 30 ? 'warning' : 'normal'));
    $featureProgress = projectProgressPercent((int) ($client['total_features'] ?? 0), (int) ($client['merged_features'] ?? 0));
    ?>
    <tr>
        <td>
            <div class="client-name"><?php echo htmlspecialchars($client['name']); ?></div>
            <div class="client-email"><?php echo htmlspecialchars($client['email']); ?></div>
        </td>
        <td><?php echo htmlspecialchars($client['contact_person'] ?: '—'); ?></td>
        <td>
            <span class="badge-status <?php echo $client['status']; ?>">
                <?php echo ucfirst($client['status']); ?>
            </span>
        </td>
        <td>
            <div class="progress-wrapper">
                <div class="progress">
                    <div class="progress-bar" style="width: <?php echo $featureProgress; ?>%"></div>
                </div>
                <span class="progress-text"><?php echo $featureProgress; ?>% Complete</span>
            </div>
        </td>
        <td>
            <?php if($client['deadline']): ?>
            <div class="deadline-info">
                <div class="deadline-date"><?php echo $clientDeadlineTimestamp !== false ? date('M d, Y', $clientDeadlineTimestamp) : 'No deadline'; ?></div>
                <span class="deadline-days <?php echo $deadlineClass; ?>">
                    <?php echo $daysLeft; ?> days left
                </span>
            </div>
            <?php else: ?>
            <span class="text-muted">Not set</span>
            <?php endif; ?>
        </td>
        <td>
            <div class="action-buttons">
                <button class="btn-icon view" onclick="viewClient(<?php echo $client['id']; ?>)">
                    <i class="fas fa-eye"></i>
                </button>
                <button class="btn-icon edit" onclick="editClient(<?php echo htmlspecialchars(json_encode($client)); ?>)">
                    <i class="fas fa-edit"></i>
                </button>
                <button class="btn-icon delete" onclick="openDeleteClientModal(<?php echo (int) $client['id']; ?>, <?php echo htmlspecialchars(json_encode($client['name'])); ?>)">
                    <i class="fas fa-trash"></i>
                </button>
            </div>
        </td>
    </tr>
    <?php endwhile; ?>
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
        </div>
    </div>
</div>

<!-- Add Client Modal (same content as before) -->
<div class="modal fade modal-modern add-client-modal" id="addClientModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-user-plus me-2"></i> Add New Client
                </h5>
                <button type="button" class="btn-close add-client-close" aria-label="Close"></button>
            </div>
            <form method="POST" id="addClientForm" novalidate data-app-loading data-loading-title="Saving client" data-loading-text="Please wait while the client, project details, folders, and notifications are saved.">
                <div class="modal-body">
                    <div class="add-client-stepper" aria-label="Add client progress">
                        <button type="button" class="stepper-item is-active" data-step-target="0" aria-current="step" disabled>
                            <span>1</span>
                            <strong>Client Profile</strong>
                        </button>
                        <button type="button" class="stepper-item" data-step-target="1" disabled>
                            <span>2</span>
                            <strong>Project Intake</strong>
                        </button>
                        <button type="button" class="stepper-item" data-step-target="2" disabled>
                            <span>3</span>
                            <strong>Timeline & Budget</strong>
                        </button>
                    </div>

                    <div class="add-client-layout">
                        <div class="field-group add-client-step" data-client-step="0">
                            <div class="section-title"><i class="fas fa-id-card"></i> Client Profile</div>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label">Company Name</label>
                                    <input type="text" name="name" class="form-control" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Contact Person</label>
                                    <input type="text" name="contact_person" class="form-control" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Email</label>
                                    <input type="email" name="email" class="form-control" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Phone</label>
                                    <input type="text" name="phone" class="form-control" required>
                                </div>
                                <div class="col-12">
                                    <label class="form-label">Address</label>
                                    <textarea name="address" class="form-control" rows="2"></textarea>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Client Deadline</label>
                                    <input type="date" name="deadline" class="form-control">
                                </div>
                            </div>
                        </div>

                        <div class="field-group add-client-step" data-client-step="1" hidden>
                            <div class="section-title"><i class="fas fa-clipboard-list"></i> Project Intake</div>
                            <div class="row g-3">
                                <div class="col-12">
                                    <label class="form-label">Project Name</label>
                                    <input type="text" name="project_name" class="form-control" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Project Description</label>
                                    <textarea name="project_description" class="form-control" rows="3" placeholder="Short overview of the requested system or solution"></textarea>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Requested System Features</label>
                                    <textarea name="requested_features" class="form-control" rows="3" placeholder="List requested features, one per line"></textarea>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Meeting Notes</label>
                                    <textarea name="meeting_notes" class="form-control" rows="3" placeholder="Important notes from discovery meetings"></textarea>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Minutes of the Meeting (MOM)</label>
                                    <textarea name="mom" class="form-control" rows="3" placeholder="Formal MOM summary for the architect"></textarea>
                                </div>
                                <div class="col-12">
                                    <label class="form-label">Additional Requirements</label>
                                    <textarea name="additional_requirements" class="form-control" rows="3" placeholder="Constraints, integrations, priorities, compliance needs, or extra requests"></textarea>
                                </div>
                            </div>
                        </div>

                        <div class="field-group add-client-step" data-client-step="2" hidden>
                            <div class="section-title"><i class="fas fa-calendar-check"></i> Timeline & Budget</div>
                            <div class="row g-3">
                                <div class="col-md-3">
                                    <label class="form-label">Timeline Start</label>
                                    <input type="date" name="timeline_start" class="form-control">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Start Time</label>
                                    <input type="time" name="timeline_start_time" class="form-control">
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
                                    <label class="form-label">Budget</label>
                                    <input type="number" name="sales_budget" class="form-control" min="0" step="0.01" inputmode="decimal" placeholder="e.g. 1500000.00">
                                    <div class="mini-note">Used as the decision insight budget baseline when provided.</div>
                                </div>
                                <div class="col-md-6">
                                    <div class="info-card">
                                        <span>Outcome</span>
                                        <strong>Creates the client and sends the project to Architect review</strong>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer add-client-footer">
                    <span class="draft-status" data-draft-status>Draft not started</span>
                    <div class="add-client-actions">
                        <button type="button" class="btn btn-outline-primary" data-save-draft disabled>Save Draft</button>
                        <button type="button" class="btn btn-outline-secondary" data-client-prev hidden>Previous</button>
                        <button type="button" class="btn-add-modern" data-client-next>Next</button>
                        <button type="submit" name="add_client" class="btn-add-modern" data-client-submit hidden>Add Client</button>
                    </div>
                    <div class="unsaved-close-panel" data-unsaved-close-panel>
                        <div class="unsaved-close-panel__text">
                            <strong>Save this client intake before closing?</strong>
                            <span>You have entered details that are not saved yet.</span>
                        </div>
                        <div class="unsaved-close-panel__actions">
                            <button type="button" class="btn btn-light" data-keep-editing>Keep Editing</button>
                            <button type="button" class="btn btn-outline-danger" data-discard-unsaved>Discard</button>
                            <button type="button" class="btn-add-modern" data-save-close-draft>
                                <i class="fas fa-save me-1"></i> Save Draft
                            </button>
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
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
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
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Status</label>
                            <select name="status" id="edit_status" class="form-select">
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                                <option value="pending">Pending</option>
                            </select>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Progress (%)</label>
                            <input type="number" name="progress" id="edit_progress" class="form-control" min="0" max="100">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Project Deadline</label>
                            <input type="date" name="deadline" id="edit_deadline" class="form-control">
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
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-building me-2"></i> Client Details
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
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
                        <div class="small text-muted">This will remove the client and all related projects, tasks, and files. This action cannot be undone.</div>
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
                <button type="button" class="btn-delete-confirm" id="deleteDraftConfirmBtn">
                    <i class="fas fa-trash-alt me-1"></i> Yes, delete it
                </button>
            </div>
        </div>
    </div>
</div>

<script>
function applyFilters() {
    const search = document.getElementById('searchInput').value;
    const status = document.getElementById('statusFilter').value;
    
    let url = 'clients.php?';
    if (search) url += 'search=' + encodeURIComponent(search) + '&';
    if (status) url += 'status=' + encodeURIComponent(status);
    
    window.location.href = url;
}

function editClient(client) {
    document.getElementById('edit_id').value = client.id;
    document.getElementById('edit_name').value = client.name;
    document.getElementById('edit_contact').value = client.contact_person;
    document.getElementById('edit_email').value = client.email;
    document.getElementById('edit_phone').value = client.phone;
    document.getElementById('edit_address').value = client.address;
    document.getElementById('edit_status').value = client.status;
    document.getElementById('edit_progress').value = client.progress;
    document.getElementById('edit_deadline').value = client.deadline;
    
    new bootstrap.Modal(document.getElementById('editClientModal')).show();
}

function viewClient(id) {
    $.ajax({
        url: '../../api/get-client-details.php',
        method: 'GET',
        data: { id: id },
        success: function(response) {
            const client = typeof response === 'string' ? JSON.parse(response) : response;
            if (client.error) {
                alert(client.error);
                return;
            }
            let html = `
                <div class="row g-4">
                    <div class="col-md-6">
                        <div class="client-detail-panel">
                            <h6 class="mb-3" style="color: var(--theme-primary);"><i class="fas fa-info-circle me-2"></i>Contact Information</h6>
                            <p><strong>Company Name:</strong> ${client.name}</p>
                            <p><strong>Contact Person:</strong> ${client.contact_person}</p>
                            <p><strong>Email:</strong> ${client.email}</p>
                            <p><strong>Phone:</strong> ${client.phone}</p>
                            <p><strong>Address:</strong> ${client.address || '—'}</p>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="client-detail-panel">
                            <h6 class="mb-3" style="color: var(--theme-primary);"><i class="fas fa-chart-line me-2"></i>Project Status</h6>
                            <p><strong>Status:</strong> <span class="badge-status ${client.status}">${client.status}</span></p>
                            <p><strong>Progress:</strong> ${client.progress}%</p>
                            <div class="progress mb-2">
                                <div class="progress-bar" style="width: ${client.progress}%"></div>
                            </div>
                            <p><strong>Deadline:</strong> ${client.deadline ? new Date(client.deadline).toLocaleDateString() : 'Not set'}</p>
                        </div>
                    </div>
                </div>
                <hr>
                <h6 class="mb-3" style="color: var(--theme-primary);"><i class="fas fa-project-diagram me-2"></i>Associated Projects</h6>
                <div id="clientProjects" class="text-center py-3">
                    <div class="spinner-border text-primary spinner-border-sm" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <p class="mt-2 text-muted small">Loading projects...</p>
                </div>
            `;
            $('#viewClientContent').html(html);
            
            $.ajax({
                url: '../../api/get-client-projects.php',
                method: 'GET',
                data: { client_id: id },
                success: function(projectsResponse) {
                    const projects = typeof projectsResponse === 'string' ? JSON.parse(projectsResponse) : projectsResponse;
                    if(projects.length > 0) {
                        let projectsHtml = '<div class="list-group">';
                        projects.forEach(project => {
                            projectsHtml += `
                                <div class="client-project-item d-flex justify-content-between align-items-center">
                                    <div>
                                        <strong>${project.name}</strong><br>
                                        <small class="text-muted">Created: ${new Date(project.created_at).toLocaleDateString()}</small>
                                    </div>
                                    <span class="badge-status ${project.status === 'completed' ? 'active' : 'pending'}">${project.status}</span>
                                </div>
                            `;
                        });
                        projectsHtml += '</div>';
                        $('#clientProjects').html(projectsHtml);
                    } else {
                        $('#clientProjects').html('<p class="text-muted text-center">No projects yet</p>');
                    }
                }
            });
            
            new bootstrap.Modal(document.getElementById('viewClientModal')).show();
        },
        error: function(xhr) {
            let message = 'Unable to load client details';
            try {
                message = JSON.parse(xhr.responseText).error || message;
            } catch (error) {}
            alert(message);
        }
    });
}

let pendingDeleteClientId = null;
const deleteClientModalElement = document.getElementById('deleteClientModal');
const deleteClientConfirmBtn = document.getElementById('deleteClientConfirmBtn');
const deleteClientName = document.getElementById('deleteClientName');
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

if (deleteDraftConfirmBtn) {
    deleteDraftConfirmBtn.addEventListener('click', function () {
        if (!pendingDeleteDraftId) {
            return;
        }

        const remainingDrafts = getStoredDrafts().filter((draft) => draft.id !== pendingDeleteDraftId);
        saveStoredDrafts(remainingDrafts);

        if (activeDraftId === pendingDeleteDraftId) {
            resetCurrentClientInput();
        }

        pendingDeleteDraftId = null;
        updateDraftStatus(hasStoredDraft());
    });
}

const addClientModalElement = document.getElementById('addClientModal');
const addClientForm = document.getElementById('addClientForm');

if (addClientModalElement && addClientForm) {
    const addClientDraftsKey = 'erniPms.sales.addClientDrafts.v1';
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
    const closeButtons = Array.from(addClientModalElement.querySelectorAll('.add-client-close'));
    const draftStatus = addClientModalElement.querySelector('[data-draft-status]');
    const addClientFooter = addClientModalElement.querySelector('.add-client-footer');
    const unsavedClosePanel = addClientModalElement.querySelector('[data-unsaved-close-panel]');
    const keepEditingButton = addClientModalElement.querySelector('[data-keep-editing]');
    const discardUnsavedButton = addClientModalElement.querySelector('[data-discard-unsaved]');
    const saveCloseDraftButton = addClientModalElement.querySelector('[data-save-close-draft]');
    let currentStep = 0;
    let formTouched = false;
    let activeDraftLoaded = false;
    let activeDraftId = null;

    function getDraftData() {
        return Object.fromEntries(new FormData(addClientForm).entries());
    }

    function hasDraftValues(data = getDraftData()) {
        return Object.entries(data).some(([key, value]) => !key.startsWith('_') && String(value).trim() !== '');
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

    function renderDraftList(drafts = getStoredDrafts()) {
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
            draftList.innerHTML = '';
            return;
        }

        draftList.innerHTML = drafts.map((draft) => {
            const companyName = String(draft.data?.name || '').trim();
            const contactPerson = String(draft.data?.contact_person || '').trim();
            const projectName = String(draft.data?.project_name || '').trim();
            const summaryBits = [
                companyName ? 'Company: ' + escapeHtml(companyName) : '',
                contactPerson ? 'Contact: ' + escapeHtml(contactPerson) : '',
                projectName ? 'Project: ' + escapeHtml(projectName) : ''
            ].filter(Boolean);

            return `
                <div class="client-draft-card" data-draft-id="${escapeHtml(draft.id)}">
                    <div class="client-draft-card__meta">
                        <div class="client-draft-card__title">${companyName ? escapeHtml(companyName) : 'Untitled draft'}</div>
                        <div class="client-draft-card__subtitle">${escapeHtml(formatDraftDate(draft.savedAt))}</div>
                        <div class="client-draft-card__summary">
                            ${summaryBits.map((item) => `<span>${item}</span>`).join('')}
                        </div>
                    </div>
                    <div class="client-draft-card__actions">
                        <button type="button" class="btn btn-outline-primary btn-sm" data-draft-action="continue">Continue</button>
                        <button type="button" class="btn btn-outline-danger btn-sm" data-draft-action="delete">Delete</button>
                    </div>
                </div>
            `;
        }).join('');
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

    function updateDraftStatus(hasDraft = hasDraftValues()) {
        if (draftStatus) {
            draftStatus.textContent = hasDraft ? 'Draft saved on this browser' : (formTouched ? 'Unsaved changes' : 'Draft not started');
        }
        updateSaveDraftButton();
        renderDraftList();
    }

    function saveAddClientDraft() {
        const draftData = getDraftData();
        if (!hasDraftValues(draftData)) {
            if (activeDraftId) {
                const remainingDrafts = getStoredDrafts().filter((draft) => draft.id !== activeDraftId);
                saveStoredDrafts(remainingDrafts);
                activeDraftId = null;
            }
            updateDraftStatus(false);
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
        activeDraftLoaded = true;
        updateDraftStatus(true);
    }

    function loadAddClientDraft(draftId = null) {
        const draftData = getStoredDrafts().find((draft) => draft.id === draftId) || null;
        if (!draftData) {
            updateDraftStatus(false);
            return;
        }

        addClientForm.reset();
        Object.entries(draftData.data || {}).forEach(([name, value]) => {
            const field = addClientForm.elements[name];
            if (field) {
                field.value = value;
            }
        });
        formTouched = false;
        activeDraftLoaded = true;
        activeDraftId = draftData.id;
        hideUnsavedClosePanel();
        updateDraftStatus(true);
    }

    function clearAddClientDraft() {
        saveStoredDrafts([]);
        addClientForm.reset();
        formTouched = false;
        activeDraftLoaded = false;
        activeDraftId = null;
        hideUnsavedClosePanel();
        updateDraftStatus(false);
        showClientStep(0);
    }

    function resetCurrentClientInput() {
        addClientForm.reset();
        formTouched = false;
        activeDraftLoaded = false;
        activeDraftId = null;
        hideUnsavedClosePanel();
        updateDraftStatus(false);
        showClientStep(0);
    }

    function validateClientStep(stepIndex, shouldReport = true) {
        const panel = stepPanels[stepIndex];
        if (!panel) {
            return true;
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
    }

    function requestAddClientClose() {
        if (!hasDraftValues()) {
            resetCurrentClientInput();
            addClientModal.hide();
            return;
        }

        showUnsavedClosePanel();
    }

    addClientForm.addEventListener('input', () => {
        formTouched = true;
        hideUnsavedClosePanel();
        updateDraftStatus(false);
    });

    addClientForm.addEventListener('change', () => {
        formTouched = true;
        hideUnsavedClosePanel();
        updateDraftStatus(false);
    });

    closeButtons.forEach((button) => {
        button.addEventListener('click', requestAddClientClose);
    });

    if (saveDraftButton) {
        saveDraftButton.addEventListener('click', () => {
            if (!hasDraftValues()) {
                if (activeDraftId) {
                    const remainingDrafts = getStoredDrafts().filter((draft) => draft.id !== activeDraftId);
                    saveStoredDrafts(remainingDrafts);
                    activeDraftId = null;
                }
                formTouched = false;
                updateDraftStatus(false);
                return;
            }

            saveAddClientDraft();
            formTouched = false;
            hideUnsavedClosePanel();
        });
    }

    if (keepEditingButton) {
        keepEditingButton.addEventListener('click', hideUnsavedClosePanel);
    }

    if (discardUnsavedButton) {
        discardUnsavedButton.addEventListener('click', () => {
            resetCurrentClientInput();
            addClientModal.hide();
        });
    }

    if (saveCloseDraftButton) {
        saveCloseDraftButton.addEventListener('click', () => {
            saveAddClientDraft();
            resetCurrentClientInput();
            addClientModal.hide();
            showClientTab('drafts');
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
        for (let stepIndex = 0; stepIndex < stepPanels.length; stepIndex++) {
            if (!validateClientStep(stepIndex, false)) {
                event.preventDefault();
                showClientStep(stepIndex);
                validateClientStep(stepIndex, true);
                return;
            }
        }

        if (activeDraftId) {
            const remainingDrafts = getStoredDrafts().filter((draft) => draft.id !== activeDraftId);
            saveStoredDrafts(remainingDrafts);
        }
        formTouched = false;
    });

    if (draftList) {
        draftList.addEventListener('click', (event) => {
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
    }

    clientTabButtons.forEach((button) => {
        button.addEventListener('click', () => {
            showClientTab(button.dataset.clientTab || 'clients');
        });
    });

    addClientModalElement.addEventListener('show.bs.modal', () => {
        if (!activeDraftLoaded) {
            resetCurrentClientInput();
        }
    });

    addClientModalElement.addEventListener('hidden.bs.modal', () => {
        showClientStep(0);
        hideUnsavedClosePanel();
        activeDraftLoaded = false;
        activeDraftId = null;
        updateDraftStatus(hasStoredDraft());
    });

    showClientStep(0);
    showClientTab('clients');
    renderDraftList();
    updateDraftStatus(hasStoredDraft());
}

// Auto-submit on enter in search
document.getElementById('searchInput').addEventListener('keypress', function(e) {
    if (e.key === 'Enter') {
        applyFilters();
    }
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
