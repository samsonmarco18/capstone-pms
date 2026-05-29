<?php
require_once '../../includes/auth.php';
requireRole('sul');

$conn = getConnection();
$user = getCurrentUser();

$projectId = isset($_GET['project_id']) ? intval($_GET['project_id']) : 0;
$taskId = isset($_GET['task_id']) ? intval($_GET['task_id']) : 0;

// Ensure sales approval columns exist
$hasSalesApproval = $conn->query("SHOW COLUMNS FROM tasks LIKE 'sales_approved'")->num_rows > 0;
if (!$hasSalesApproval) {
    try {
        $conn->query("ALTER TABLE tasks ADD COLUMN sales_approved TINYINT(1) DEFAULT 0");
        $conn->query("ALTER TABLE tasks ADD COLUMN sales_approved_by INT NULL");
        $conn->query("ALTER TABLE tasks ADD COLUMN sales_approved_at DATETIME NULL");
        $hasSalesApproval = $conn->query("SHOW COLUMNS FROM tasks LIKE 'sales_approved'")->num_rows > 0;
    } catch (Throwable $e) {
        // Continue without sales approval columns
    }
}

$hasSalesChoice = $conn->query("SHOW COLUMNS FROM tasks LIKE 'sales_estimate_choice'")->num_rows > 0;
if (!$hasSalesChoice) {
    try {
        $conn->query("ALTER TABLE tasks ADD COLUMN sales_estimate_choice ENUM('min','likely','max') DEFAULT NULL");
        $conn->query("ALTER TABLE tasks ADD COLUMN sales_estimate_days INT DEFAULT NULL");
        $hasSalesChoice = $conn->query("SHOW COLUMNS FROM tasks LIKE 'sales_estimate_choice'")->num_rows > 0;
    } catch (Throwable $e) {
        // Continue without sales choice columns
    }
}

$hasProjectPhases = $conn->query("SHOW COLUMNS FROM projects LIKE 'project_phases'")->num_rows > 0;

// Default to first available project if none selected
if (!$projectId) {
    $firstProject = $conn->query("
        SELECT p.id 
        FROM projects p 
        WHERE p.status != 'completed'
        ORDER BY p.created_at DESC 
        LIMIT 1
    ")->fetch_assoc();
    $projectId = (int) ($firstProject['id'] ?? 0);
}

// Get project details
$project = null;
if($projectId) {
    $project = $conn->query("
        SELECT p.*, c.name as client_name, c.email as client_email 
        FROM projects p
        JOIN clients c ON p.client_id = c.id
        WHERE p.id = $projectId
    ")->fetch_assoc();
}

$ganttProjectData = null;
$canShowGantt = false;
if ($projectId && $project) {
    if ($hasSalesApproval) {
        $choiceFilter = $hasSalesChoice ? " AND sales_estimate_days IS NOT NULL" : "";
        $approvedCount = $conn->query("SELECT COUNT(*) as count FROM tasks WHERE project_id = $projectId AND sales_approved = 1{$choiceFilter}")->fetch_assoc()['count'] ?? 0;
        $canShowGantt = ((int) $approvedCount) > 0;
    } else {
        $canShowGantt = true;
    }

    if ($canShowGantt) {
        $ganttTasks = [];
        if ($hasProjectPhases && !empty($project['project_phases'])) {
            $phases = json_decode($project['project_phases'], true);
            if (is_array($phases)) {
                foreach ($phases as $idx => $phase) {
                    if (empty($phase['start_date']) || empty($phase['end_date'])) {
                        continue;
                    }
                    $ganttTasks[] = [
                        'id' => 'phase-' . $projectId . '-' . $idx,
                        'name' => $phase['name'] ?? 'Phase',
                        'start' => $phase['start_date'],
                        'end' => $phase['end_date'],
                        'progress' => 0,
                        'custom_class' => 'gantt-phase',
                        'is_phase' => true
                    ];
                }
            }
        }

        $taskRows = $conn->query("SELECT id, title, started_at, deadline, estimated_days_min, estimated_days_likely, estimated_days_max, sales_estimate_choice, sales_estimate_days FROM tasks WHERE project_id = $projectId");
        while ($task = $taskRows->fetch_assoc()) {
            $startRaw = $task['started_at'] ?? null;
            $endRaw = $task['deadline'] ?? null;
            $start = $startRaw ? date('Y-m-d', strtotime($startRaw)) : null;
            $end = $endRaw ? date('Y-m-d', strtotime($endRaw)) : null;

            if (empty($start)) {
                $start = !empty($project['timeline_start'])
                    ? date('Y-m-d', strtotime($project['timeline_start']))
                    : date('Y-m-d');
            }

            if (empty($end)) {
                $duration = (int) ($task['sales_estimate_days'] ?? 0);
                if ($duration <= 0) {
                    $duration = (int) ($task['estimated_days_likely'] ?? 0);
                }
                if ($duration <= 0) {
                    $duration = (int) ($task['estimated_days_min'] ?? 0);
                }
                if ($duration <= 0) {
                    $duration = (int) ($task['estimated_days_max'] ?? 0);
                }
                if ($duration <= 0) {
                    $duration = 1;
                }
                $endDate = new DateTime($start);
                $endDate->modify('+' . max(1, $duration) . ' days');
                $end = $endDate->format('Y-m-d');
            }
            $ganttTasks[] = [
                'id' => 'task-' . (int) $task['id'],
                'name' => $task['title'],
                'start' => $start,
                'end' => $end,
                'progress' => 0,
                'custom_class' => 'gantt-task',
                'min_days' => (int) ($task['estimated_days_min'] ?? 0),
                'likely_days' => (int) ($task['estimated_days_likely'] ?? 0),
                'max_days' => (int) ($task['estimated_days_max'] ?? 0),
                'sales_estimate_choice' => $task['sales_estimate_choice'] ?? null,
                'sales_estimate_days' => (int) ($task['sales_estimate_days'] ?? 0),
                'is_phase' => false
            ];
        }

        $ganttProjectData = [
            'project_name' => $project['name'],
            'tasks' => $ganttTasks
        ];
    }
}

// Get all unassigned tasks (need expert assignment)
$unassignedTasks = $conn->query("
    SELECT t.*, p.name as project_name, p.client_id,
           (SELECT COUNT(*) FROM feature_estimations fe WHERE fe.task_id = t.id AND fe.status = 'completed') as has_estimation
    FROM tasks t
    JOIN projects p ON t.project_id = p.id
    WHERE (t.assigned_to IS NULL OR t.assigned_to = 0) AND t.status = 'todo'
    ORDER BY 
        CASE t.priority 
            WHEN 'critical' THEN 1 
            WHEN 'high' THEN 2 
            WHEN 'medium' THEN 3 
            WHEN 'low' THEN 4 
        END,
        t.deadline ASC
");

// Get tasks pending expert estimation (assigned to expert but not yet estimated)
$pendingEstimationTasks = $conn->query("
    SELECT t.*, u.full_name as expert_name, p.name as project_name,
           (SELECT COUNT(*) FROM feature_estimations fe WHERE fe.task_id = t.id AND fe.status = 'completed') as has_estimation
    FROM tasks t
    JOIN users u ON t.assigned_to = u.id
    JOIN projects p ON t.project_id = p.id
    WHERE t.status = 'todo' 
      AND t.assigned_to IS NOT NULL 
      AND t.assigned_to != 0
      AND u.role = 'expert'
      AND (SELECT COUNT(*) FROM feature_estimations fe WHERE fe.task_id = t.id AND fe.status = 'completed') = 0
    ORDER BY t.deadline ASC
");

// Get tasks ready for review (expert has submitted estimation)
$salesApprovalFilter = $hasSalesApproval ? " AND t.sales_approved = 1" : "";
if ($hasSalesChoice) {
    $salesApprovalFilter .= " AND t.sales_estimate_days IS NOT NULL";
}
$reviewTasks = $conn->query("
    SELECT t.*, u.full_name as expert_name, p.name as project_name,
           fe.min_days, fe.likely_days, fe.max_days, fe.expectation_description,
           (SELECT COUNT(*) FROM feature_estimations fe2 WHERE fe2.task_id = t.id AND fe2.status = 'completed') as has_estimation
    FROM tasks t
    JOIN users u ON t.assigned_to = u.id
    JOIN projects p ON t.project_id = p.id
    LEFT JOIN feature_estimations fe ON t.id = fe.task_id AND fe.status = 'completed'
    WHERE t.status = 'review'{$salesApprovalFilter}
    ORDER BY t.deadline ASC
");

// Get tasks ready for developer assignment (approved, waiting for developer)
$readyForDevTasks = $conn->query("
    SELECT t.*, u.full_name as expert_name, p.name as project_name,
           fe.min_days, fe.likely_days, fe.max_days
    FROM tasks t
    JOIN users u ON t.assigned_to = u.id
    JOIN projects p ON t.project_id = p.id
    JOIN feature_estimations fe ON t.id = fe.task_id AND fe.status = 'completed'
    WHERE t.status = 'todo' 
      AND t.assigned_to IS NOT NULL 
      AND t.assigned_to != 0
      AND u.role = 'expert'
      AND (SELECT COUNT(*) FROM feature_estimations fe2 WHERE fe2.task_id = t.id AND fe2.status = 'completed') > 0" . ($hasSalesApproval ? " AND t.sales_approved = 1" : "") . ($hasSalesChoice ? " AND t.sales_estimate_days IS NOT NULL" : "") . "
    ORDER BY t.deadline ASC
");

// Get tasks assigned to developers (in progress)
$inProgressTasks = $conn->query("
    SELECT t.*, u.full_name as developer_name, p.name as project_name
    FROM tasks t
    JOIN users u ON t.assigned_to = u.id
    JOIN projects p ON t.project_id = p.id
    WHERE t.status = 'in_progress'
    ORDER BY t.deadline ASC
");

// Get completed tasks
$completedTasks = $conn->query("
    SELECT t.*, u.full_name as assigned_to_name, p.name as project_name
    FROM tasks t
    LEFT JOIN users u ON t.assigned_to = u.id
    JOIN projects p ON t.project_id = p.id
    WHERE t.status = 'completed'
    ORDER BY t.completed_at DESC
    LIMIT 10
");

// Get available experts
$experts = $conn->query("
    SELECT u.id, u.full_name, 
           COUNT(t.id) as assigned_tasks
    FROM users u
    LEFT JOIN tasks t ON u.id = t.assigned_to AND t.status != 'completed'
    WHERE u.role = 'expert' AND u.status = 'active'
    GROUP BY u.id
    ORDER BY assigned_tasks ASC
");

// Projects with unassigned feature tasks
$projectsForAllocation = $conn->query("
    SELECT p.id, p.name, COUNT(t.id) as unassigned_count
    FROM projects p
    JOIN tasks t ON t.project_id = p.id
    WHERE t.task_type = 'feature'
      AND (t.assigned_to IS NULL OR t.assigned_to = 0)
      AND t.status = 'todo'
    GROUP BY p.id, p.name
    ORDER BY p.created_at DESC
");

// Get available developers
$developers = $conn->query("
    SELECT u.id, u.full_name, 
           COUNT(t.id) as assigned_tasks
    FROM users u
    LEFT JOIN tasks t ON u.id = t.assigned_to AND t.status != 'completed'
    WHERE u.role = 'developer' AND u.status = 'active'
    GROUP BY u.id
    ORDER BY assigned_tasks ASC
");

// Get projects for dropdown
$projects = $conn->query("
    SELECT p.id, p.name, c.name as client_name
    FROM projects p
    JOIN clients c ON p.client_id = c.id
    WHERE p.status != 'completed'
    ORDER BY p.created_at DESC
");

// Handle task operations
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    
    // Assign task to expert
    if (isset($_POST['assign_to_expert'])) {
        $taskId = $_POST['task_id'];
        $expertId = $_POST['expert_id'];
        
        $stmt = $conn->prepare("UPDATE tasks SET assigned_to = ?, status = 'todo' WHERE id = ?");
        $stmt->bind_param("ii", $expertId, $taskId);
        
        if ($stmt->execute()) {
            $taskDetails = $conn->query("SELECT title FROM tasks WHERE id = $taskId")->fetch_assoc();
            
            createNotification(
                (int) $expertId,
                "New Task Assignment: " . $taskDetails['title'],
                "You have been assigned a new task for estimation.",
                'task',
                'tasks',
                (int) $taskId,
                BASE_URL . 'modules/expert/feature-estimation.php'
            );
            
            $_SESSION['success'] = "Task assigned to Expert successfully";
            header("Location: task-allocation.php");
            exit();
        } else {
            $_SESSION['error'] = "Failed to assign task";
        }
    }

    // Assign project to expert (bulk features)
    if (isset($_POST['assign_project_to_expert'])) {
        $projectId = (int) ($_POST['project_id'] ?? 0);
        $expertId = (int) ($_POST['expert_id'] ?? 0);
        if ($projectId > 0 && $expertId > 0) {
            $stmt = $conn->prepare("
                UPDATE tasks 
                SET assigned_to = ?, status = 'todo' 
                WHERE project_id = ? 
                  AND task_type = 'feature'
                  AND (assigned_to IS NULL OR assigned_to = 0)
                  AND status = 'todo'
            ");
            $stmt->bind_param("ii", $expertId, $projectId);

            if ($stmt->execute()) {
                $projectDetails = $conn->query("SELECT name FROM projects WHERE id = $projectId")->fetch_assoc();
                createNotification(
                    (int) $expertId,
                    "Project Assigned for Estimation: " . ($projectDetails['name'] ?? 'Project'),
                    "You have been assigned features to estimate for this project.",
                    'project',
                    'projects',
                    (int) $projectId,
                    BASE_URL . 'modules/expert/feature-estimation.php'
                );
                $_SESSION['success'] = "Project assigned to Expert successfully";
            } else {
                $_SESSION['error'] = "Failed to assign project to Expert";
            }
            header("Location: task-allocation.php");
            exit();
        }
    }
    
    // Assign task to developer (after estimation approved)
    if (isset($_POST['assign_to_developer'])) {
        $taskId = $_POST['task_id'];
        $developerId = $_POST['developer_id'];
        
        $stmt = $conn->prepare("UPDATE tasks SET assigned_to = ?, status = 'todo' WHERE id = ?");
        $stmt->bind_param("ii", $developerId, $taskId);
        
        if ($stmt->execute()) {
            $taskDetails = $conn->query("SELECT title, deadline FROM tasks WHERE id = $taskId")->fetch_assoc();
            
            createNotification(
                (int) $developerId,
                "New Task Assignment: " . $taskDetails['title'],
                "You have been assigned a new task. Deadline: " . date('M d, Y', strtotime($taskDetails['deadline'])),
                'task',
                'tasks',
                (int) $taskId,
                BASE_URL . 'modules/developer/task.php?id=' . $taskId
            );
            
            $_SESSION['success'] = "Task assigned to Developer successfully";
            header("Location: task-allocation.php");
            exit();
        } else {
            $_SESSION['error'] = "Failed to assign task";
        }
    }
    
    // Approve estimation and move to ready for dev
    if (isset($_POST['approve_estimation'])) {
        $taskId = $_POST['task_id'];
        
        $stmt = $conn->prepare("UPDATE tasks SET status = 'todo' WHERE id = ?");
        $stmt->bind_param("i", $taskId);
        
        if ($stmt->execute()) {
            $_SESSION['success'] = "Estimation approved! Task is now ready for Developer assignment.";
            header("Location: task-allocation.php");
            exit();
        } else {
            $_SESSION['error'] = "Failed to approve estimation";
        }
    }
    
    // Reject estimation
    if (isset($_POST['reject_estimation'])) {
        $taskId = $_POST['task_id'];
        $rejectionReason = trim($_POST['rejection_reason']);
        
        // Get task details for notification
        $task = $conn->query("SELECT assigned_to, title FROM tasks WHERE id = $taskId")->fetch_assoc();
        
        $stmt = $conn->prepare("UPDATE tasks SET status = 'todo' WHERE id = ?");
        $stmt->bind_param("i", $taskId);
        
        if ($stmt->execute()) {
            if (!empty($rejectionReason)) {
                createNotification(
                    (int) $task['assigned_to'],
                    "Estimation Revision Requested: " . $task['title'],
                    "Please revise your estimation. Reason: " . $rejectionReason,
                    'task',
                    'tasks',
                    (int) $taskId,
                    BASE_URL . 'modules/expert/feature-estimation.php'
                );
            }
            $_SESSION['success'] = "Estimation rejected. Task sent back to Expert.";
            header("Location: task-allocation.php");
            exit();
        } else {
            $_SESSION['error'] = "Failed to reject estimation";
        }
    }
}

include '../../includes/header.php';
include '../../includes/sidebar.php';
include '../../includes/navbar.php';
?>

<style>
:root {
    --theme-primary: #1f6392;
    --theme-dark: #184f73;
    --theme-light: #3a7ca3;
    --gray-50: #f8f9fa;
    --gray-100: #f1f3f5;
    --gray-200: #e9ecef;
    --gray-300: #dee2e6;
    --gray-400: #ced4da;
    --gray-500: #adb5bd;
    --gray-600: #6c757d;
    --gray-700: #495057;
    --gray-800: #343a40;
    --gray-900: #212529;
    --shadow-sm: 0 1px 3px rgba(0,0,0,0.04), 0 1px 2px rgba(0,0,0,0.03);
    --shadow-md: 0 4px 6px -1px rgba(0,0,0,0.05), 0 2px 4px -1px rgba(0,0,0,0.02);
    --shadow-lg: 0 10px 15px -3px rgba(0,0,0,0.05), 0 4px 6px -2px rgba(0,0,0,0.02);
}

/* Page Header */
.page-header-modern {
    background: linear-gradient(135deg, var(--theme-primary) 0%, var(--theme-dark) 100%);
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

/* Section Cards */
.section-card {
    background: white;
    border-radius: 20px;
    border: 1px solid var(--gray-200);
    overflow: hidden;
    box-shadow: var(--shadow-sm);
    margin-bottom: 1.5rem;
}

.section-header {
    padding: 1rem 1.5rem;
    background: var(--gray-50);
    border-bottom: 1px solid var(--gray-200);
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 0.5rem;
}

.section-header h5 {
    font-size: 0.9rem;
    font-weight: 600;
    color: var(--gray-800);
    margin: 0;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.section-header h5 i {
    color: var(--theme-primary);
}

.badge-count {
    background: var(--gray-200);
    color: var(--gray-600);
    padding: 0.2rem 0.5rem;
    border-radius: 20px;
    font-size: 0.7rem;
    font-weight: 600;
}

/* Task Cards */
.task-card {
    background: white;
    border-radius: 12px;
    padding: 1rem;
    margin-bottom: 0.75rem;
    border: 1px solid var(--gray-200);
    transition: all 0.2s;
}

.task-card:hover {
    transform: translateY(-2px);
    box-shadow: var(--shadow-md);
    border-color: var(--theme-primary);
}

.task-priority {
    display: inline-block;
    padding: 0.2rem 0.6rem;
    border-radius: 20px;
    font-size: 0.65rem;
    font-weight: 600;
}

.priority-critical { background: #fee2e2; color: #dc2626; }
.priority-high { background: #fff3e0; color: #f59e0b; }
.priority-medium { background: #dbeafe; color: #2563eb; }
.priority-low { background: #d1fae5; color: #059669; }

.task-title {
    font-size: 0.9rem;
    font-weight: 600;
    color: var(--gray-800);
    margin-bottom: 0.25rem;
}

.task-meta {
    font-size: 0.7rem;
    color: var(--gray-500);
    display: flex;
    align-items: center;
    gap: 1rem;
    flex-wrap: wrap;
    margin-top: 0.5rem;
}

/* Estimation Badge */
.estimation-badge {
    background: #f0fdf4;
    color: #059669;
    padding: 0.25rem 0.6rem;
    border-radius: 20px;
    font-size: 0.65rem;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
}

/* Buttons */
.btn-primary-modern {
    background: linear-gradient(135deg, var(--theme-primary) 0%, var(--theme-dark) 100%);
    color: white;
    border: none;
    padding: 0.4rem 0.8rem;
    border-radius: 8px;
    font-size: 0.75rem;
    transition: all 0.2s;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
}

.btn-primary-modern:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(31, 99, 146, 0.3);
    color: white;
}

.btn-success-modern {
    background: linear-gradient(135deg, #10b981 0%, #059669 100%);
    color: white;
    border: none;
    padding: 0.4rem 0.8rem;
    border-radius: 8px;
    font-size: 0.75rem;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
}

.btn-success-modern:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
}

.btn-warning-modern {
    background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
    color: white;
    border: none;
    padding: 0.4rem 0.8rem;
    border-radius: 8px;
    font-size: 0.75rem;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
}

.btn-warning-modern:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(245, 158, 11, 0.3);
}

.btn-outline-modern {
    background: transparent;
    border: 1px solid var(--gray-300);
    color: var(--gray-700);
    padding: 0.4rem 0.8rem;
    border-radius: 8px;
    font-size: 0.75rem;
    cursor: pointer;
    transition: all 0.2s;
}

.btn-outline-modern:hover {
    background: var(--gray-50);
    border-color: var(--theme-primary);
}

.gantt-phase .bar {
    fill: #1f6392;
}

/* Empty State */
.empty-state {
    text-align: center;
    padding: 2rem 1.5rem;
}

.empty-state i {
    font-size: 2rem;
    color: var(--gray-300);
    margin-bottom: 0.5rem;
}

.empty-state p {
    color: var(--gray-500);
    font-size: 0.8rem;
    margin: 0;
}

/* Modal */
.modal-modern .modal-content {
    border-radius: 20px;
    border: none;
    overflow: hidden;
}

.modal-modern .modal-header {
    background: linear-gradient(135deg, var(--theme-primary) 0%, var(--theme-dark) 100%);
    color: white;
    padding: 1rem 1.5rem;
    border: none;
}

.modal-modern .modal-header .modal-title,
.modal-modern .modal-header .modal-title i {
    color: #ffffff !important;
}

.modal-modern .modal-header .btn-close {
    filter: brightness(0) invert(1);
}

.modal-modern .modal-body {
    padding: 1.5rem;
}

.modal-modern .modal-footer {
    padding: 1rem 1.5rem 1.5rem;
    border-top: 1px solid var(--gray-200);
}

.form-select-custom {
    width: 100%;
    padding: 0.5rem 0.75rem;
    border: 1px solid var(--gray-300);
    border-radius: 10px;
    font-size: 0.85rem;
}

.form-control-custom {
    width: 100%;
    padding: 0.5rem 0.75rem;
    border: 1px solid var(--gray-300);
    border-radius: 10px;
    font-size: 0.85rem;
}

/* Tab Navigation */
#allocationTabs {
    border-bottom: 2px solid var(--gray-200);
}

#allocationTabs .nav-link {
    border: none;
    padding: 0.75rem 1.5rem;
    color: var(--gray-600);
    font-weight: 500;
    position: relative;
    transition: all 0.2s;
}

#allocationTabs .nav-link:hover {
    color: var(--theme-primary);
    background: transparent;
}

#allocationTabs .nav-link.active {
    color: var(--theme-primary);
    background: transparent;
    border: none;
}

#allocationTabs .nav-link.active::after {
    content: '';
    position: absolute;
    bottom: -2px;
    left: 0;
    right: 0;
    height: 3px;
    background: var(--theme-primary);
    border-radius: 3px 3px 0 0;
}

#allocationTabs .nav-link i {
    margin-right: 0.25rem;
}

/* Responsive */
@media (max-width: 768px) {
    .page-header-modern {
        padding: 1.5rem;
    }
    
    .section-header {
        flex-direction: column;
        align-items: stretch;
    }
    
    .task-meta {
        gap: 0.5rem;
    }
    
    #allocationTabs .nav-link {
        padding: 0.5rem 1rem;
        font-size: 0.9rem;
    }
}
</style>

<div class="container-fluid px-4 py-4">
    <!-- Page Header -->
    <div class="page-header-modern">
        <div>
            <h2>
                <i class="fas fa-tasks me-2"></i> 
                Task Allocation
            </h2>
            <p>Manage tasks from creation to completion</p>
        </div>
    </div>

    <!-- Tab Navigation -->
    <ul class="nav nav-tabs mb-4" id="allocationTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active" id="expert-tab" data-bs-toggle="tab" data-bs-target="#expert-panel" type="button" role="tab">
                <i class="fas fa-user-tie me-2"></i>Expert
                <span class="badge bg-warning ms-2"><?php echo $unassignedTasks->num_rows + $pendingEstimationTasks->num_rows + $reviewTasks->num_rows; ?></span>
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="developer-tab" data-bs-toggle="tab" data-bs-target="#developer-panel" type="button" role="tab">
                <i class="fas fa-code me-2"></i>Developer
                <span class="badge bg-primary ms-2"><?php echo $readyForDevTasks->num_rows + $inProgressTasks->num_rows; ?></span>
            </button>
        </li>
    </ul>

    <!-- Tab Content -->
    <div class="tab-content" id="allocationTabsContent">
        
        <!-- Expert Tab Panel -->
        <div class="tab-pane fade show active" id="expert-panel" role="tabpanel">
            
            <!-- Project Allocation to Expert -->
            <div class="section-card">
                <div class="section-header">
                    <h5>
                        <i class="fas fa-user-check"></i>
                        Assign Project to Expert
                    </h5>
                </div>
                <div class="p-3">
                    <?php if($projectsForAllocation->num_rows > 0): ?>
                        <form method="POST" class="row g-3 align-items-end">
                            <div class="col-md-6">
                                <label class="form-label">Project</label>
                                <select name="project_id" class="form-select-custom" required>
                                    <option value="">Select project</option>
                                    <?php while($proj = $projectsForAllocation->fetch_assoc()): ?>
                                        <option value="<?php echo $proj['id']; ?>">
                                            <?php echo htmlspecialchars($proj['name']); ?> (<?php echo $proj['unassigned_count']; ?> features)
                                        </option>
                                    <?php endwhile; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Expert</label>
                                <select name="expert_id" class="form-select-custom" required>
                                    <option value="">Select expert</option>
                                    <?php $experts->data_seek(0); while($expert = $experts->fetch_assoc()): ?>
                                        <option value="<?php echo $expert['id']; ?>">
                                            <?php echo htmlspecialchars($expert['full_name']); ?> (<?php echo $expert['assigned_tasks']; ?> tasks)
                                        </option>
                                    <?php endwhile; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <button type="submit" name="assign_project_to_expert" class="btn-primary-modern w-100">
                                    <i class="fas fa-share"></i> Assign
                                </button>
                            </div>
                        </form>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-check-circle"></i>
                            <p>No projects awaiting expert allocation</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($projectId && $project): ?>
            <div class="section-card">
                <div class="section-header">
                    <h5>
                        <i class="fas fa-stream"></i>
                        Project Gantt Chart
                    </h5>
                </div>
                <div class="p-3">
                    <?php if (!$canShowGantt): ?>
                        <div class="empty-state">
                            <i class="fas fa-lock"></i>
                            <p>Gantt chart is available after Sales approval.</p>
                        </div>
                    <?php elseif (!$ganttProjectData || empty($ganttProjectData['tasks'])): ?>
                        <div class="empty-state">
                            <i class="fas fa-stream"></i>
                            <p>No Gantt data available for this project.</p>
                        </div>
                    <?php else: ?>
                        <div id="sulGanttChart"></div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Section 1: Unassigned Tasks - Need Expert Assignment -->
            <div class="section-card">
                <div class="section-header">
                    <h5>
                        <i class="fas fa-user-plus"></i>
                        Unassigned Tasks
                        <span class="badge-count"><?php echo $unassignedTasks->num_rows; ?> tasks</span>
                    </h5>
                    
                </div>
                <div class="p-3">
                    <?php if($unassignedTasks->num_rows > 0): ?>
                        <?php while($task = $unassignedTasks->fetch_assoc()): 
                            $daysLeft = ceil((strtotime($task['deadline']) - time()) / (60 * 60 * 24));
                        ?>
                        <div class="task-card">
                            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                                <div>
                                    <div class="d-flex align-items-center gap-2 mb-1">
                                        <span class="task-priority priority-<?php echo $task['priority']; ?>"><?php echo ucfirst($task['priority']); ?></span>
                                        <span class="task-title"><?php echo htmlspecialchars($task['title']); ?></span>
                                    </div>
                                    <div class="task-meta">
                                        <span><i class="fas fa-folder"></i> <?php echo htmlspecialchars($task['project_name']); ?></span>
                                        <span><i class="far fa-calendar-alt"></i> Due: <?php echo date('M d, Y', strtotime($task['deadline'])); ?></span>
                                        <span class="text-<?php echo $daysLeft <= 2 ? 'danger' : 'muted'; ?>"><?php echo $daysLeft; ?> days left</span>
                                    </div>
                                </div>
                                <button class="btn-primary-modern" onclick="openAssignExpertModal(<?php echo $task['id']; ?>, '<?php echo htmlspecialchars($task['title']); ?>')">
                                    <i class="fas fa-user-plus"></i> Assign to Expert
                                </button>
                            </div>
                        </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-check-circle"></i>
                            <p>No unassigned tasks</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Section 2: Pending Expert Estimation -->
    <div class="section-card">
        <div class="section-header">
            <h5>
                <i class="fas fa-calculator"></i>
                Pending Expert Estimation
                <span class="badge-count"><?php echo $pendingEstimationTasks->num_rows; ?> tasks</span>
            </h5>
        </div>
                <div class="p-3">
                    <?php if($pendingEstimationTasks->num_rows > 0): ?>
                        <?php while($task = $pendingEstimationTasks->fetch_assoc()): 
                            $daysLeft = ceil((strtotime($task['deadline']) - time()) / (60 * 60 * 24));
                        ?>
                        <div class="task-card">
                            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                                <div>
                                    <div class="d-flex align-items-center gap-2 mb-1">
                                        <span class="task-priority priority-<?php echo $task['priority']; ?>"><?php echo ucfirst($task['priority']); ?></span>
                                        <span class="task-title"><?php echo htmlspecialchars($task['title']); ?></span>
                                    </div>
                                    <div class="task-meta">
                                        <span><i class="fas fa-folder"></i> <?php echo htmlspecialchars($task['project_name']); ?></span>
                                        <span><i class="fas fa-user"></i> Expert: <?php echo htmlspecialchars($task['expert_name']); ?></span>
                                        <span><i class="far fa-calendar-alt"></i> Due: <?php echo date('M d, Y', strtotime($task['deadline'])); ?></span>
                                        <span class="estimation-badge"><i class="fas fa-hourglass-half"></i> Waiting for estimation</span>
                                    </div>
                                </div>
                                <div class="text-muted small">
                                    <i class="fas fa-clock"></i> Pending Expert
                                </div>
                            </div>
                        </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-check-circle"></i>
                            <p>No tasks pending expert estimation</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Section 3: Ready for Review -->
            <div class="section-card">
                <div class="section-header">
                    <h5>
                        <i class="fas fa-clipboard-list"></i>
                        Ready for Review
                        <span class="badge-count"><?php echo $reviewTasks->num_rows; ?> tasks</span>
                    </h5>
                </div>
                <div class="p-3">
                    <?php if($reviewTasks->num_rows > 0): ?>
                        <?php while($task = $reviewTasks->fetch_assoc()): 
                            $daysLeft = ceil((strtotime($task['deadline']) - time()) / (60 * 60 * 24));
                            $pertEstimate = round(($task['min_days'] + 4*$task['likely_days'] + $task['max_days'])/6, 1);
                        ?>
                        <div class="task-card">
                            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                                <div class="flex-grow-1">
                                    <div class="d-flex align-items-center gap-2 mb-1">
                                        <span class="task-priority priority-<?php echo $task['priority']; ?>"><?php echo ucfirst($task['priority']); ?></span>
                                        <span class="task-title"><?php echo htmlspecialchars($task['title']); ?></span>
                                        </div>
                                <div class="task-meta">
                                    <span><i class="fas fa-folder"></i> <?php echo htmlspecialchars($task['project_name']); ?></span>
                                    <span><i class="fas fa-user"></i> Expert: <?php echo htmlspecialchars($task['expert_name']); ?></span>
                                    <span><i class="far fa-calendar-alt"></i> Due: <?php echo date('M d, Y', strtotime($task['deadline'])); ?></span>
                                    <span class="estimation-badge"><i class="fas fa-chart-line"></i> PERT: <?php echo $pertEstimate; ?> days</span>
                                </div>
                                <div class="mt-2 p-2 bg-light rounded-2">
                                    <small class="text-muted"><i class="fas fa-info-circle"></i> Estimation: Min: <?php echo $task['min_days']; ?> | Likely: <?php echo $task['likely_days']; ?> | Max: <?php echo $task['max_days']; ?> days</small>
                                </div>
                            </div>
                            <div class="d-flex gap-2">
                                <button class="btn-success-modern" onclick="approveEstimation(<?php echo $task['id']; ?>)">
                                    <i class="fas fa-check"></i> Approve
                                </button>
                                <button class="btn-warning-modern" onclick="openRejectModal(<?php echo $task['id']; ?>, '<?php echo htmlspecialchars($task['title']); ?>')">
                                    <i class="fas fa-times"></i> Reject
                                </button>
                            </div>
                        </div>
                    </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-check-circle"></i>
                        <p>No tasks ready for review</p>
                    </div>
                <?php endif; ?>
                </div>
            </div>
            </div>
            <!-- End Expert Tab Panel -->
            
            <!-- Developer Tab Panel -->
            <div class="tab-pane fade" id="developer-panel" role="tabpanel">
            
            <!-- Section 4: Ready for Developer Assignment -->
            <div class="section-card">
                <div class="section-header">
                    <h5>
                        <i class="fas fa-code"></i>
                        Ready for Developer Assignment
                        <span class="badge-count"><?php echo $readyForDevTasks->num_rows; ?> tasks</span>
                    </h5>
                </div>
                <div class="p-3">
                <?php if($readyForDevTasks->num_rows > 0): ?>
                    <?php while($task = $readyForDevTasks->fetch_assoc()): 
                        $daysLeft = ceil((strtotime($task['deadline']) - time()) / (60 * 60 * 24));
                        $pertEstimate = round(($task['min_days'] + 4*$task['likely_days'] + $task['max_days'])/6, 1);
                    ?>
                    <div class="task-card">
                        <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                            <div>
                                <div class="d-flex align-items-center gap-2 mb-1">
                                    <span class="task-priority priority-<?php echo $task['priority']; ?>"><?php echo ucfirst($task['priority']); ?></span>
                                    <span class="task-title"><?php echo htmlspecialchars($task['title']); ?></span>
                                </div>
                                <div class="task-meta">
                                    <span><i class="fas fa-folder"></i> <?php echo htmlspecialchars($task['project_name']); ?></span>
                                    <span><i class="fas fa-user-check"></i> Estimated by: <?php echo htmlspecialchars($task['expert_name']); ?></span>
                                    <span><i class="far fa-calendar-alt"></i> Due: <?php echo date('M d, Y', strtotime($task['deadline'])); ?></span>
                                    <span class="estimation-badge"><i class="fas fa-chart-line"></i> PERT: <?php echo $pertEstimate; ?> days</span>
                                    <?php if (!empty($task['sales_estimate_choice']) && !empty($task['sales_estimate_days'])): ?>
                                        <span class="estimation-badge"><i class="fas fa-flag-checkered"></i> Sales picked <?php echo ucfirst($task['sales_estimate_choice']); ?>: <?php echo (int) $task['sales_estimate_days']; ?> days</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <button class="btn-primary-modern" onclick="openAssignDeveloperModal(<?php echo $task['id']; ?>, '<?php echo htmlspecialchars($task['title']); ?>')">
                                <i class="fas fa-user-plus"></i> Assign to Developer
                            </button>
                        </div>
                    </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-check-circle"></i>
                        <p>No tasks ready for developer assignment</p>
                    </div>
                <?php endif; ?>
                </div>
            </div>

            <!-- Section 5: In Progress -->
            <div class="section-card">
                <div class="section-header">
                    <h5>
                        <i class="fas fa-spinner fa-pulse"></i>
                        In Progress
                        <span class="badge-count"><?php echo $inProgressTasks->num_rows; ?> tasks</span>
                    </h5>
                </div>
                <div class="p-3">
                <?php if($inProgressTasks->num_rows > 0): ?>
                    <?php while($task = $inProgressTasks->fetch_assoc()): 
                        $daysLeft = ceil((strtotime($task['deadline']) - time()) / (60 * 60 * 24));
                    ?>
                    <div class="task-card">
                        <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                            <div>
                                <div class="d-flex align-items-center gap-2 mb-1">
                                    <span class="task-priority priority-<?php echo $task['priority']; ?>"><?php echo ucfirst($task['priority']); ?></span>
                                    <span class="task-title"><?php echo htmlspecialchars($task['title']); ?></span>
                                </div>
                                <div class="task-meta">
                                    <span><i class="fas fa-folder"></i> <?php echo htmlspecialchars($task['project_name']); ?></span>
                                    <span><i class="fas fa-user"></i> Developer: <?php echo htmlspecialchars($task['developer_name']); ?></span>
                                    <span><i class="far fa-calendar-alt"></i> Due: <?php echo date('M d, Y', strtotime($task['deadline'])); ?></span>
                                    <span class="text-<?php echo $daysLeft <= 2 ? 'danger' : 'muted'; ?>"><?php echo $daysLeft; ?> days left</span>
                                </div>
                            </div>
                            <button class="btn-outline-modern" onclick="viewTaskDetails(<?php echo $task['id']; ?>)">
                                <i class="fas fa-eye"></i> View
                            </button>
                        </div>
                    </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-play-circle"></i>
                        <p>No tasks in progress</p>
                    </div>
                <?php endif; ?>
                </div>
            </div>

            <!-- Section 6: Recently Completed -->
            <div class="section-card">
                <div class="section-header">
                    <h5>
                        <i class="fas fa-check-double"></i>
                        Recently Completed
                        <span class="badge-count"><?php echo $completedTasks->num_rows; ?> tasks</span>
                    </h5>
                </div>
                <div class="p-3">
                <?php if($completedTasks->num_rows > 0): ?>
                    <?php while($task = $completedTasks->fetch_assoc()): ?>
                    <div class="task-card bg-light">
                        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                            <div>
                                <div class="d-flex align-items-center gap-2">
                                    <s class="task-title"><?php echo htmlspecialchars($task['title']); ?></s>
                                </div>
                                <div class="task-meta">
                                    <span><i class="fas fa-folder"></i> <?php echo htmlspecialchars($task['project_name']); ?></span>
                                    <span><i class="fas fa-user"></i> <?php echo htmlspecialchars($task['assigned_to_name']); ?></span>
                                    <span><i class="fas fa-check-circle text-success"></i> Completed: <?php echo date('M d, Y', strtotime($task['completed_at'])); ?></span>
                                </div>
                            </div>
                            <button class="btn-outline-modern" onclick="viewTaskDetails(<?php echo $task['id']; ?>)">
                                <i class="fas fa-eye"></i> View
                            </button>
                        </div>
                    </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-trophy"></i>
                        <p>No completed tasks yet</p>
                    </div>
                <?php endif; ?>
                </div>
            </div>
            </div>
            <!-- End Developer Tab Panel -->
            
        </div>
        <!-- End Tab Content -->
    </div>

<!-- Create Task Modal -->
<div class="modal fade modal-modern" id="createTaskModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-plus me-2"></i>Create New Task</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Project *</label>
                        <select name="project_id" class="form-select-custom" required>
                            <option value="">Select Project</option>
                            <?php 
                            $projects->data_seek(0);
                            while($proj = $projects->fetch_assoc()): 
                            ?>
                            <option value="<?php echo $proj['id']; ?>">
                                <?php echo htmlspecialchars($proj['name']); ?>
                            </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Task Title *</label>
                        <input type="text" name="title" class="form-control-custom" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Description *</label>
                        <textarea name="description" class="form-control-custom" rows="3" required></textarea>
                    </div>
                    <div class="row g-2">
                        <div class="col-md-6">
                            <label class="form-label">Priority *</label>
                            <select name="priority" class="form-select-custom" required>
                                <option value="low">Low</option>
                                <option value="medium">Medium</option>
                                <option value="high">High</option>
                                <option value="critical">Critical</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Deadline *</label>
                            <input type="date" name="deadline" class="form-control-custom" required>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-outline-modern" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="create_task" class="btn-primary-modern">Create Task</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Assign to Expert Modal -->
<div class="modal fade modal-modern" id="assignExpertModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-user-plus me-2"></i>Assign to Expert</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="task_id" id="expertTaskId">
                    <p><strong>Task:</strong> <span id="expertTaskTitle"></span></p>
                    <div class="mb-3">
                        <label class="form-label">Select Expert</label>
                        <select name="expert_id" class="form-select-custom" required>
                            <option value="">Choose an expert...</option>
                            <?php $experts->data_seek(0); while($expert = $experts->fetch_assoc()): ?>
                            <option value="<?php echo $expert['id']; ?>">
                                <?php echo htmlspecialchars($expert['full_name']); ?> 
                                (<?php echo $expert['assigned_tasks']; ?> active tasks)
                            </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="alert alert-info bg-light border-0">
                        <small><i class="fas fa-info-circle"></i> The expert will provide a three-point estimation (min/likely/max days) for this task.</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-outline-modern" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="assign_to_expert" class="btn-primary-modern">Assign to Expert</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Assign to Developer Modal -->
<div class="modal fade modal-modern" id="assignDeveloperModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-user-plus me-2"></i>Assign to Developer</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="task_id" id="devTaskId">
                    <p><strong>Task:</strong> <span id="devTaskTitle"></span></p>
                    <div class="mb-3">
                        <label class="form-label">Select Developer</label>
                        <select name="developer_id" class="form-select-custom" required>
                            <option value="">Choose a developer...</option>
                            <?php while($developer = $developers->fetch_assoc()): ?>
                            <option value="<?php echo $developer['id']; ?>">
                                <?php echo htmlspecialchars($developer['full_name']); ?> 
                                (<?php echo $developer['assigned_tasks']; ?> active tasks)
                            </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-outline-modern" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="assign_to_developer" class="btn-primary-modern">Assign to Developer</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Reject Estimation Modal -->
<div class="modal fade modal-modern" id="rejectModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-times-circle me-2"></i>Reject Estimation</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="task_id" id="rejectTaskId">
                    <p><strong>Task:</strong> <span id="rejectTaskTitle"></span></p>
                    <div class="mb-3">
                        <label class="form-label">Reason for Rejection</label>
                        <textarea name="rejection_reason" class="form-control-custom" rows="3" required placeholder="Please provide feedback for the expert..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-outline-modern" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="reject_estimation" class="btn-warning-modern">Reject Estimation</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Task Details Modal -->
<div class="modal fade modal-modern" id="taskDetailsModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-info-circle me-2"></i>Task Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="taskDetailsContent">
                <div class="text-center py-4">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function openAssignExpertModal(taskId, taskTitle) {
    document.getElementById('expertTaskId').value = taskId;
    document.getElementById('expertTaskTitle').textContent = taskTitle;
    new bootstrap.Modal(document.getElementById('assignExpertModal')).show();
}

function openAssignDeveloperModal(taskId, taskTitle) {
    document.getElementById('devTaskId').value = taskId;
    document.getElementById('devTaskTitle').textContent = taskTitle;
    new bootstrap.Modal(document.getElementById('assignDeveloperModal')).show();
}

function approveEstimation(taskId) {
    if(confirm('Approve this estimation? The task will be ready for developer assignment.')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `<input type="hidden" name="approve_estimation" value="1"><input type="hidden" name="task_id" value="${taskId}">`;
        document.body.appendChild(form);
        form.submit();
    }
}

function openRejectModal(taskId, taskTitle) {
    document.getElementById('rejectTaskId').value = taskId;
    document.getElementById('rejectTaskTitle').textContent = taskTitle;
    new bootstrap.Modal(document.getElementById('rejectModal')).show();
}

function viewTaskDetails(taskId) {
    $.ajax({
        url: '../../api/get-task-details.php',
        method: 'GET',
        data: { id: taskId },
        success: function(response) {
            const task = JSON.parse(response);
            let html = `
                <div class="row g-4">
                    <div class="col-md-8">
                        <h5 class="mb-3">${escapeHtml(task.title)}</h5>
                        <div class="mb-3">
                            <strong>Description:</strong>
                            <p class="mt-2">${escapeHtml(task.description)}</p>
                        </div>
                        <div class="mb-2">
                            <strong>Project:</strong> ${escapeHtml(task.project_name)}
                        </div>
                        <div class="mb-2">
                            <strong>Priority:</strong> 
                            <span class="task-priority priority-${task.priority}">${task.priority}</span>
                        </div>
                        <div class="mb-2">
                            <strong>Deadline:</strong> ${new Date(task.deadline).toLocaleDateString()}
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="p-3 bg-light rounded-3">
                            <p><strong>Assigned To:</strong> ${task.assigned_to_name || 'Unassigned'}</p>
                            ${task.estimated_days_min ? `
                                <p><strong>Estimation:</strong><br>
                                Min: ${task.estimated_days_min} days<br>
                                Likely: ${task.estimated_days_likely} days<br>
                                Max: ${task.estimated_days_max} days</p>
                            ` : ''}
                        </div>
                    </div>
                </div>
            `;
            $('#taskDetailsContent').html(html);
            new bootstrap.Modal(document.getElementById('taskDetailsModal')).show();
        }
    });
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

const sulGanttProject = <?php echo json_encode($ganttProjectData, JSON_UNESCAPED_UNICODE); ?>;

function diffDays(startDate, endDate) {
    return Math.max(1, Math.round((endDate - startDate) / 86400000));
}

function normalizeSulGanttRows(tasks) {
    const rows = (tasks || []).map(task => {
        const startDate = new Date(`${task.start}T00:00:00`);
        const endDate = new Date(`${task.end}T00:00:00`);
        if (Number.isNaN(startDate.getTime()) || Number.isNaN(endDate.getTime())) {
            return null;
        }

        const dateDuration = diffDays(startDate, endDate);
        const isPhase = Boolean(task.is_phase);
        const minDays = Math.max(0, parseInt(task.min_days || '0', 10));
        const likelyDays = Math.max(minDays, parseInt(task.likely_days || '0', 10));
        const maxDays = Math.max(likelyDays, parseInt(task.max_days || '0', 10));
        const committedDays = Math.max(0, parseInt(task.sales_estimate_days || '0', 10));
        const totalDays = isPhase ? dateDuration : Math.max(maxDays, committedDays, dateDuration, 1);

        return {
            name: task.name,
            startDate,
            isPhase,
            phaseDuration: isPhase ? totalDays : 0,
            minSegment: isPhase ? 0 : Math.min(minDays, totalDays),
            likelySegment: isPhase ? 0 : Math.max(Math.min(likelyDays, totalDays) - Math.min(minDays, totalDays), 0),
            maxSegment: isPhase ? 0 : Math.max(totalDays - Math.max(Math.min(likelyDays, totalDays), Math.min(minDays, totalDays)), 0),
            tooltip: {
                minDays,
                likelyDays,
                maxDays,
                committedDays,
                committedChoice: task.sales_estimate_choice || ''
            }
        };
    }).filter(Boolean);

    if (!rows.length) {
        return rows;
    }

    const earliestStart = new Date(Math.min(...rows.map(task => task.startDate.getTime())));
    rows.forEach(task => {
        task.offset = Math.max(0, diffDays(earliestStart, task.startDate) - 1);
    });

    return rows;
}

function renderSulThreePointGantt(target, tasks) {
    if (!target) {
        return;
    }

    if (typeof Chart === 'undefined') {
        target.innerHTML = '<div class="text-danger small">Chart.js failed to load. Please check your internet connection.</div>';
        return;
    }

    const rows = normalizeSulGanttRows(tasks);
    if (!rows.length) {
        target.innerHTML = '<div class="text-muted small">No timeline data yet.</div>';
        return;
    }

    target.innerHTML = '';
    const canvas = document.createElement('canvas');
    canvas.height = Math.max(240, rows.length * 44);
    target.appendChild(canvas);
    const ctx = canvas.getContext('2d');

    if (window.sulGanttChartInstance) {
        window.sulGanttChartInstance.destroy();
    }

    window.sulGanttChartInstance = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: rows.map(task => task.name),
            datasets: [{
                label: 'Offset',
                data: rows.map(task => task.offset),
                backgroundColor: 'rgba(0,0,0,0)',
                borderWidth: 0,
                stack: 'gantt'
            }, {
                label: 'Project Phase',
                data: rows.map(task => task.phaseDuration),
                backgroundColor: '#94a3b8',
                stack: 'gantt'
            }, {
                label: 'Minimum',
                data: rows.map(task => task.minSegment),
                backgroundColor: '#60a5fa',
                stack: 'gantt'
            }, {
                label: 'Most Likely',
                data: rows.map(task => task.likelySegment),
                backgroundColor: '#34d399',
                stack: 'gantt'
            }, {
                label: 'Maximum',
                data: rows.map(task => task.maxSegment),
                backgroundColor: '#f97316',
                stack: 'gantt'
            }]
        },
        options: {
            indexAxis: 'y',
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    labels: {
                        filter: (legendItem) => legendItem.text !== 'Offset'
                    }
                },
                tooltip: {
                    callbacks: {
                        label: (context) => {
                            if (context.dataset.label === 'Offset') {
                                return null;
                            }
                            const value = Number(context.raw || 0);
                            if (value <= 0) {
                                return null;
                            }
                            return `${context.dataset.label}: ${value} day${value === 1 ? '' : 's'}`;
                        },
                        footer: (items) => {
                            if (!items.length) {
                                return '';
                            }
                            const row = rows[items[0].dataIndex];
                            if (!row || row.isPhase) {
                                return '';
                            }
                            const details = [
                                `Min: ${row.tooltip.minDays} day${row.tooltip.minDays === 1 ? '' : 's'}`,
                                `Likely: ${row.tooltip.likelyDays} day${row.tooltip.likelyDays === 1 ? '' : 's'}`,
                                `Max: ${row.tooltip.maxDays} day${row.tooltip.maxDays === 1 ? '' : 's'}`
                            ];
                            if (row.tooltip.committedChoice && row.tooltip.committedDays > 0) {
                                details.push(`Committed: ${row.tooltip.committedChoice} (${row.tooltip.committedDays} days)`);
                            }
                            return details;
                        }
                    }
                }
            },
            scales: {
                x: {
                    beginAtZero: true,
                    stacked: true,
                    title: { display: true, text: 'Days From Earliest Start' }
                },
                y: {
                    stacked: true
                }
            }
        }
    });
}

if (sulGanttProject && Array.isArray(sulGanttProject.tasks) && sulGanttProject.tasks.length > 0) {
    const container = document.getElementById('sulGanttChart');
    if (container) {
        renderSulThreePointGantt(container, sulGanttProject.tasks);
    }
}
</script>

<?php include '../../includes/footer.php'; ?>
