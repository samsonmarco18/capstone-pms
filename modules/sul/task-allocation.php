<?php
require_once '../../includes/auth.php';
requireRole('sul');

$conn = getConnection();
$user = getCurrentUser();

$projectId = isset($_GET['project_id']) ? intval($_GET['project_id']) : 0;
$taskId = isset($_GET['task_id']) ? intval($_GET['task_id']) : 0;

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

// Get all unassigned tasks
$unassignedTasks = $conn->query("
    SELECT t.*, p.name as project_name, p.client_id
    FROM tasks t
    JOIN projects p ON t.project_id = p.id
    WHERE (t.assigned_to IS NULL OR t.assigned_to = 0) AND t.status != 'completed'
    ORDER BY 
        CASE t.priority 
            WHEN 'critical' THEN 1 
            WHEN 'high' THEN 2 
            WHEN 'medium' THEN 3 
            WHEN 'low' THEN 4 
        END,
        t.deadline ASC
");

// Get all tasks (for kanban view)
$allTasks = $conn->query("
    SELECT t.*, u.full_name as assigned_to_name, p.name as project_name
    FROM tasks t
    LEFT JOIN users u ON t.assigned_to = u.id
    JOIN projects p ON t.project_id = p.id
    ORDER BY 
        CASE t.status
            WHEN 'todo' THEN 1
            WHEN 'in_progress' THEN 2
            WHEN 'review' THEN 3
            WHEN 'completed' THEN 4
        END,
        t.deadline ASC
");

// Get available team members (experts and developers)
$teamMembers = $conn->query("
    SELECT u.*, 
           COUNT(t.id) as assigned_tasks,
           SUM(CASE WHEN t.status != 'completed' THEN 1 ELSE 0 END) as active_tasks
    FROM users u
    LEFT JOIN tasks t ON u.id = t.assigned_to
    WHERE u.role IN ('expert', 'developer') AND u.status = 'active'
    GROUP BY u.id
    ORDER BY active_tasks ASC, assigned_tasks ASC
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
    
    // Create new task
    if (isset($_POST['create_task'])) {
        $projectId = $_POST['project_id'];
        $title = trim($_POST['title']);
        $description = trim($_POST['description']);
        $priority = $_POST['priority'];
        $deadline = $_POST['deadline'];
        $estimated_min = floatval($_POST['estimated_min']);
        $estimated_likely = floatval($_POST['estimated_likely']);
        $estimated_max = floatval($_POST['estimated_max']);
        
        $stmt = $conn->prepare("
            INSERT INTO tasks (project_id, title, description, priority, deadline, 
                              estimated_days_min, estimated_days_likely, estimated_days_max, 
                              status, assigned_by) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'todo', ?)
        ");
        $stmt->bind_param("issssdddi", $projectId, $title, $description, $priority, $deadline, 
                         $estimated_min, $estimated_likely, $estimated_max, $user['id']);
        
        if ($stmt->execute()) {
            $newTaskId = $stmt->insert_id;
            logActivity($user['id'], 'create_task', 'tasks', $newTaskId, null, $_POST);
            
            // Create notification for all team members
            $title = "New Task Created: $title";
            $message = "A new task has been created and needs to be assigned.";
            $link = "/modules/sul/task-allocation.php?task_id=$newTaskId";
            $notificationUsers = $conn->query("SELECT id FROM users WHERE role IN ('sul', 'expert', 'developer') AND status = 'active'");
            while ($notificationUser = $notificationUsers->fetch_assoc()) {
                createNotification(
                    (int) $notificationUser['id'],
                    $title,
                    $message,
                    'task',
                    'tasks',
                    (int) $newTaskId,
                    BASE_URL . ltrim($link, '/')
                );
            }
            
            $_SESSION['success'] = "Task created successfully! You can now assign it to a team member.";
            header("Location: task-allocation.php?project_id=$projectId");
            exit();
        } else {
            $_SESSION['error'] = "Failed to create task: " . $conn->error;
        }
    }
    
    // Assign task to user
    if (isset($_POST['assign_task'])) {
        $taskId = $_POST['task_id'];
        $userId = $_POST['user_id'];
        $sendEmail = isset($_POST['send_email']) ? 1 : 0;
        
        // Get task details before update
        $oldTask = $conn->query("SELECT * FROM tasks WHERE id = $taskId")->fetch_assoc();
        
        $stmt = $conn->prepare("UPDATE tasks SET assigned_to = ?, status = 'todo' WHERE id = ?");
        $stmt->bind_param("ii", $userId, $taskId);
        
        if ($stmt->execute()) {
            logActivity($user['id'], 'assign_task', 'tasks', $taskId, $oldTask, ['assigned_to' => $userId]);
            
            // Get user details for notification
            $assignedUser = $conn->query("SELECT full_name, email FROM users WHERE id = $userId")->fetch_assoc();
            $taskDetails = $conn->query("SELECT title, deadline FROM tasks WHERE id = $taskId")->fetch_assoc();
            
            // Create notification for assigned user
            $title = "New Task Assigned: " . $taskDetails['title'];
            $message = "You have been assigned a new task. Deadline: " . date('M d, Y', strtotime($taskDetails['deadline']));
            createNotification(
                (int) $userId,
                $title,
                $message,
                'task',
                'tasks',
                (int) $taskId,
                BASE_URL . 'modules/developer/task.php?id=' . $taskId
            );
            
            // Send email if enabled
            if($sendEmail) {
                $to = $assignedUser['email'];
                $subject = "New Task Assignment: " . $taskDetails['title'];
                $body = "Hello " . $assignedUser['full_name'] . ",\n\n";
                $body .= "You have been assigned a new task: " . $taskDetails['title'] . "\n";
                $body .= "Deadline: " . date('M d, Y', strtotime($taskDetails['deadline'])) . "\n\n";
                $body .= "Please log in to the system to view the task details.\n\n";
                $body .= "Best regards,\nERNI PMS Team";
                
                mail($to, $subject, $body, "From: notifications@erni.com");
            }
            
            $_SESSION['success'] = "Task assigned successfully to " . $assignedUser['full_name'];
            header("Location: task-allocation.php");
            exit();
        } else {
            $_SESSION['error'] = "Failed to assign task";
        }
    }
    
    // Update task status
    if (isset($_POST['update_status'])) {
        $taskId = $_POST['task_id'];
        $newStatus = $_POST['new_status'];
        
        $oldTask = $conn->query("SELECT * FROM tasks WHERE id = $taskId")->fetch_assoc();
        
        $stmt = $conn->prepare("UPDATE tasks SET status = ? WHERE id = ?");
        $stmt->bind_param("si", $newStatus, $taskId);
        
        if ($stmt->execute()) {
            logActivity($user['id'], 'update_task_status', 'tasks', $taskId, $oldTask, ['status' => $newStatus]);
            $_SESSION['success'] = "Task status updated successfully";
            header("Location: task-allocation.php");
            exit();
        }
    }
    
    // Delete task
    if (isset($_POST['delete_task'])) {
        $taskId = $_POST['task_id'];
        $oldTask = $conn->query("SELECT * FROM tasks WHERE id = $taskId")->fetch_assoc();
        
        $stmt = $conn->prepare("DELETE FROM tasks WHERE id = ?");
        $stmt->bind_param("i", $taskId);
        
        if ($stmt->execute()) {
            logActivity($user['id'], 'delete_task', 'tasks', $taskId, $oldTask, null);
            $_SESSION['success'] = "Task deleted successfully";
            header("Location: task-allocation.php");
            exit();
        }
    }
    
    // Bulk assign tasks
    if (isset($_POST['bulk_assign'])) {
        $userIds = $_POST['user_ids'] ?? [];
        $taskIds = $_POST['task_ids'] ?? [];
        
        if(empty($taskIds)) {
            $_SESSION['error'] = "No tasks selected for assignment";
        } elseif(empty($userIds)) {
            $_SESSION['error'] = "No users selected for assignment";
        } else {
            $assignedCount = 0;
            foreach($taskIds as $taskId) {
                foreach($userIds as $userId) {
                    $stmt = $conn->prepare("UPDATE tasks SET assigned_to = ? WHERE id = ? AND (assigned_to IS NULL OR assigned_to = 0)");
                    $stmt->bind_param("ii", $userId, $taskId);
                    if($stmt->execute() && $stmt->affected_rows > 0) {
                        $assignedCount++;
                        break;
                    }
                }
            }
            $_SESSION['success'] = "$assignedCount tasks assigned successfully";
            header("Location: task-allocation.php");
            exit();
        }
    }
}

// Get task details if editing
$taskToEdit = null;
if($taskId) {
    $taskToEdit = $conn->query("
        SELECT t.*, p.name as project_name 
        FROM tasks t
        JOIN projects p ON t.project_id = p.id
        WHERE t.id = $taskId
    ")->fetch_assoc();
}

include '../../includes/header.php';
include '../../includes/sidebar.php';
include '../../includes/navbar.php';
?>

<style>
.kanban-column {
    background: #f8f9fa;
    border-radius: 10px;
    min-height: 500px;
}

.kanban-card {
    cursor: pointer;
    transition: all 0.3s;
    margin-bottom: 10px;
}

.kanban-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
}

.task-priority-badge {
    position: absolute;
    top: 10px;
    right: 10px;
}

.priority-critical { background-color: #dc3545; }
.priority-high { background-color: #fd7e14; }
.priority-medium { background-color: #ffc107; }
.priority-low { background-color: #28a745; }

.avatar {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    background-color: #0d6efd;
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: bold;
}

.workload-indicator {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    display: inline-block;
    margin-right: 5px;
}

.workload-high { background-color: #dc3545; }
.workload-medium { background-color: #ffc107; }
.workload-low { background-color: #28a745; }
</style>

<div class="row">
    <div class="col-12 mb-4">
        <h2>Task Allocation</h2>
        <p class="text-muted">Create, assign, and manage tasks for your team members</p>
    </div>
</div>

<!-- Tabs -->
<ul class="nav nav-tabs mb-4" id="taskTabs" role="tablist">
    <li class="nav-item" role="presentation">
        <button class="nav-link active" id="kanban-tab" data-bs-toggle="tab" data-bs-target="#kanban" type="button" role="tab">
            <i class="fas fa-columns"></i> Kanban Board
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link" id="unassigned-tab" data-bs-toggle="tab" data-bs-target="#unassigned" type="button" role="tab">
            <i class="fas fa-clock"></i> Unassigned Tasks 
            <span class="badge bg-warning"><?php echo $unassignedTasks->num_rows; ?></span>
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link" id="create-tab" data-bs-toggle="tab" data-bs-target="#create" type="button" role="tab">
            <i class="fas fa-plus"></i> Create Task
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link" id="bulk-tab" data-bs-toggle="tab" data-bs-target="#bulk" type="button" role="tab">
            <i class="fas fa-layer-group"></i> Bulk Assign
        </button>
    </li>
</ul>

<div class="tab-content">
    <!-- Kanban Board Tab -->
    <div class="tab-pane fade show active" id="kanban" role="tabpanel">
        <div class="row">
            <div class="col-md-3 mb-4">
                <div class="kanban-column">
                    <div class="p-3 bg-light border-bottom rounded-top">
                        <h5 class="mb-0">
                            <i class="fas fa-clock text-secondary"></i> To Do
                            <span class="badge bg-secondary float-end"><?php 
                                $todoCount = $conn->query("SELECT COUNT(*) as count FROM tasks WHERE status = 'todo'")->fetch_assoc()['count'];
                                echo $todoCount;
                            ?></span>
                        </h5>
                    </div>
                    <div class="p-3" id="todo-column" style="min-height: 400px;">
                        <?php 
                        $todoTasks = $conn->query("
                            SELECT t.*, u.full_name as assigned_to_name, p.name as project_name
                            FROM tasks t
                            LEFT JOIN users u ON t.assigned_to = u.id
                            JOIN projects p ON t.project_id = p.id
                            WHERE t.status = 'todo'
                            ORDER BY 
                                CASE t.priority 
                                    WHEN 'critical' THEN 1 
                                    WHEN 'high' THEN 2 
                                    WHEN 'medium' THEN 3 
                                    WHEN 'low' THEN 4 
                                END,
                                t.deadline ASC
                        ");
                        while($task = $todoTasks->fetch_assoc()): 
                            $daysLeft = ceil((strtotime($task['deadline']) - time()) / (60 * 60 * 24));
                        ?>
                        <div class="card kanban-card" onclick="viewTaskDetails(<?php echo $task['id']; ?>)">
                            <div class="card-body">
                                <div class="task-priority-badge">
                                    <span class="badge priority-<?php echo $task['priority']; ?>">
                                        <?php echo ucfirst($task['priority']); ?>
                                    </span>
                                </div>
                                <h6 class="card-title"><?php echo htmlspecialchars($task['title']); ?></h6>
                                <p class="card-text small text-muted">
                                    <?php echo htmlspecialchars(substr($task['description'], 0, 80)); ?>
                                </p>
                                <div class="d-flex justify-content-between align-items-center mt-2">
                                    <small class="text-muted">
                                        <i class="fas fa-folder"></i> <?php echo htmlspecialchars($task['project_name']); ?>
                                    </small>
                                    <?php if($task['assigned_to']): ?>
                                    <small>
                                        <i class="fas fa-user"></i> <?php echo htmlspecialchars($task['assigned_to_name']); ?>
                                    </small>
                                    <?php else: ?>
                                    <span class="badge bg-warning">Unassigned</span>
                                    <?php endif; ?>
                                </div>
                                <div class="mt-2">
                                    <small class="text-<?php echo $daysLeft <= 2 ? 'danger' : ($daysLeft <= 5 ? 'warning' : 'muted'); ?>">
                                        <i class="fas fa-calendar"></i> Due: <?php echo date('M d', strtotime($task['deadline'])); ?>
                                        (<?php echo $daysLeft; ?> days left)
                                    </small>
                                </div>
                            </div>
                        </div>
                        <?php endwhile; ?>
                    </div>
                </div>
            </div>
            
            <div class="col-md-3 mb-4">
                <div class="kanban-column">
                    <div class="p-3 bg-light border-bottom rounded-top">
                        <h5 class="mb-0">
                            <i class="fas fa-spinner text-primary"></i> In Progress
                            <span class="badge bg-primary float-end"><?php 
                                $inProgressCount = $conn->query("SELECT COUNT(*) as count FROM tasks WHERE status = 'in_progress'")->fetch_assoc()['count'];
                                echo $inProgressCount;
                            ?></span>
                        </h5>
                    </div>
                    <div class="p-3" id="progress-column" style="min-height: 400px;">
                        <?php 
                        $inProgressTasks = $conn->query("
                            SELECT t.*, u.full_name as assigned_to_name, p.name as project_name
                            FROM tasks t
                            LEFT JOIN users u ON t.assigned_to = u.id
                            JOIN projects p ON t.project_id = p.id
                            WHERE t.status = 'in_progress'
                            ORDER BY t.deadline ASC
                        ");
                        while($task = $inProgressTasks->fetch_assoc()): 
                            $daysLeft = ceil((strtotime($task['deadline']) - time()) / (60 * 60 * 24));
                        ?>
                        <div class="card kanban-card" onclick="viewTaskDetails(<?php echo $task['id']; ?>)">
                            <div class="card-body">
                                <div class="task-priority-badge">
                                    <span class="badge priority-<?php echo $task['priority']; ?>">
                                        <?php echo ucfirst($task['priority']); ?>
                                    </span>
                                </div>
                                <h6 class="card-title"><?php echo htmlspecialchars($task['title']); ?></h6>
                                <p class="card-text small text-muted">
                                    <?php echo htmlspecialchars(substr($task['description'], 0, 80)); ?>
                                </p>
                                <div class="d-flex justify-content-between align-items-center mt-2">
                                    <small class="text-muted">
                                        <i class="fas fa-user"></i> <?php echo htmlspecialchars($task['assigned_to_name']); ?>
                                    </small>
                                    <small class="text-<?php echo $daysLeft <= 2 ? 'danger' : 'muted'; ?>">
                                        <?php echo $daysLeft; ?> days left
                                    </small>
                                </div>
                            </div>
                        </div>
                        <?php endwhile; ?>
                    </div>
                </div>
            </div>
            
            <div class="col-md-3 mb-4">
                <div class="kanban-column">
                    <div class="p-3 bg-light border-bottom rounded-top">
                        <h5 class="mb-0">
                            <i class="fas fa-check-circle text-warning"></i> Review
                            <span class="badge bg-warning float-end"><?php 
                                $reviewCount = $conn->query("SELECT COUNT(*) as count FROM tasks WHERE status = 'review'")->fetch_assoc()['count'];
                                echo $reviewCount;
                            ?></span>
                        </h5>
                    </div>
                    <div class="p-3" id="review-column" style="min-height: 400px;">
                        <?php 
                        $reviewTasks = $conn->query("
                            SELECT t.*, u.full_name as assigned_to_name, p.name as project_name
                            FROM tasks t
                            LEFT JOIN users u ON t.assigned_to = u.id
                            JOIN projects p ON t.project_id = p.id
                            WHERE t.status = 'review'
                            ORDER BY t.deadline ASC
                        ");
                        while($task = $reviewTasks->fetch_assoc()): 
                        ?>
                        <div class="card kanban-card" onclick="viewTaskDetails(<?php echo $task['id']; ?>)">
                            <div class="card-body">
                                <div class="task-priority-badge">
                                    <span class="badge priority-<?php echo $task['priority']; ?>">
                                        <?php echo ucfirst($task['priority']); ?>
                                    </span>
                                </div>
                                <h6 class="card-title"><?php echo htmlspecialchars($task['title']); ?></h6>
                                <p class="card-text small text-muted">
                                    <?php echo htmlspecialchars(substr($task['description'], 0, 80)); ?>
                                </p>
                                <div class="d-flex justify-content-between align-items-center mt-2">
                                    <small class="text-muted">
                                        <i class="fas fa-user"></i> <?php echo htmlspecialchars($task['assigned_to_name']); ?>
                                    </small>
                                    <button class="btn btn-sm btn-success" onclick="event.stopPropagation(); approveTask(<?php echo $task['id']; ?>)">
                                        <i class="fas fa-check"></i> Approve
                                    </button>
                                </div>
                            </div>
                        </div>
                        <?php endwhile; ?>
                    </div>
                </div>
            </div>
            
            <div class="col-md-3 mb-4">
                <div class="kanban-column">
                    <div class="p-3 bg-light border-bottom rounded-top">
                        <h5 class="mb-0">
                            <i class="fas fa-check-double text-success"></i> Completed
                            <span class="badge bg-success float-end"><?php 
                                $completedCount = $conn->query("SELECT COUNT(*) as count FROM tasks WHERE status = 'completed'")->fetch_assoc()['count'];
                                echo $completedCount;
                            ?></span>
                        </h5>
                    </div>
                    <div class="p-3" id="completed-column" style="min-height: 400px;">
                        <?php 
                        $completedTasks = $conn->query("
                            SELECT t.*, u.full_name as assigned_to_name, p.name as project_name
                            FROM tasks t
                            LEFT JOIN users u ON t.assigned_to = u.id
                            JOIN projects p ON t.project_id = p.id
                            WHERE t.status = 'completed'
                            ORDER BY t.completed_at DESC
                            LIMIT 10
                        ");
                        while($task = $completedTasks->fetch_assoc()): 
                        ?>
                        <div class="card bg-light" onclick="viewTaskDetails(<?php echo $task['id']; ?>)">
                            <div class="card-body">
                                <h6 class="card-title text-muted">
                                    <s><?php echo htmlspecialchars($task['title']); ?></s>
                                </h6>
                                <small class="text-muted">
                                    <i class="fas fa-user"></i> <?php echo htmlspecialchars($task['assigned_to_name']); ?>
                                </small>
                                <br>
                                <small class="text-muted">
                                    Completed: <?php echo date('M d', strtotime($task['completed_at'])); ?>
                                </small>
                            </div>
                        </div>
                        <?php endwhile; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Unassigned Tasks Tab -->
    <div class="tab-pane fade" id="unassigned" role="tabpanel">
        <div class="card">
            <div class="card-header">
                <h5>Unassigned Tasks</h5>
                <p class="text-muted mb-0">Assign these tasks to available team members</p>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th><input type="checkbox" id="selectAllUnassigned"></th>
                                <th>Task</th>
                                <th>Project</th>
                                <th>Priority</th>
                                <th>Deadline</th>
                                <th>Est. Days</th>
                                <th>Actions</th>
                            </thead>
                        <tbody>
                            <?php if($unassignedTasks->num_rows > 0): ?>
                                <?php while($task = $unassignedTasks->fetch_assoc()): 
                                    $daysLeft = ceil((strtotime($task['deadline']) - time()) / (60 * 60 * 24));
                                ?>
                                <tr>
                                    <td><input type="checkbox" class="task-checkbox" value="<?php echo $task['id']; ?>"></td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($task['title']); ?></strong>
                                        <br>
                                        <small class="text-muted"><?php echo htmlspecialchars(substr($task['description'], 0, 60)); ?></small>
                                    </td>
                                    <td><?php echo htmlspecialchars($task['project_name']); ?></td>
                                    <td>
                                        <span class="badge priority-<?php echo $task['priority']; ?>">
                                            <?php echo ucfirst($task['priority']); ?>
                                        </span>
                                    </td>
                                    <td class="<?php echo $daysLeft <= 2 ? 'text-danger' : ''; ?>">
                                        <?php echo date('M d, Y', strtotime($task['deadline'])); ?>
                                        <br>
                                        <small><?php echo $daysLeft; ?> days left</small>
                                    </td>
                                    <td><?php echo $task['estimated_days_likely']; ?> days</td>
                                    <td>
                                        <button class="btn btn-sm btn-primary" onclick="quickAssign(<?php echo $task['id']; ?>, '<?php echo htmlspecialchars($task['title']); ?>')">
                                            <i class="fas fa-user-plus"></i> Assign
                                        </button>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="7" class="text-center text-muted">No unassigned tasks</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                
                <div class="mt-3" id="bulkAssignSection" style="display: none;">
                    <hr>
                    <h6>Bulk Assign Selected Tasks</h6>
                    <div class="row">
                        <div class="col-md-4">
                            <select id="bulkUserId" class="form-select">
                                <option value="">Select team member</option>
                                <?php 
                                $teamMembers->data_seek(0);
                                while($member = $teamMembers->fetch_assoc()): 
                                ?>
                                <option value="<?php echo $member['id']; ?>">
                                    <?php echo htmlspecialchars($member['full_name']); ?> 
                                    (<?php echo $member['active_tasks']; ?> active tasks)
                                </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <button class="btn btn-success" onclick="bulkAssign()">
                                <i class="fas fa-tasks"></i> Assign Selected
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Create Task Tab -->
    <div class="tab-pane fade" id="create" role="tabpanel">
        <div class="row">
            <div class="col-md-8">
                <div class="card">
                    <div class="card-header">
                        <h5>Create New Task</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Project *</label>
                                    <select name="project_id" class="form-select" required>
                                        <option value="">Select Project</option>
                                        <?php 
                                        $projects->data_seek(0);
                                        while($proj = $projects->fetch_assoc()): 
                                        ?>
                                        <option value="<?php echo $proj['id']; ?>" <?php echo $projectId == $proj['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($proj['name']); ?> (<?php echo htmlspecialchars($proj['client_name']); ?>)
                                        </option>
                                        <?php endwhile; ?>
                                    </select>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Priority *</label>
                                    <select name="priority" class="form-select" required>
                                        <option value="low">Low</option>
                                        <option value="medium">Medium</option>
                                        <option value="high">High</option>
                                        <option value="critical">Critical</option>
                                    </select>
                                </div>
                                <div class="col-12 mb-3">
                                    <label class="form-label">Task Title *</label>
                                    <input type="text" name="title" class="form-control" required>
                                </div>
                                <div class="col-12 mb-3">
                                    <label class="form-label">Description *</label>
                                    <textarea name="description" class="form-control" rows="4" required></textarea>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label">Min Days (Best Case)</label>
                                    <input type="number" name="estimated_min" class="form-control" step="0.5" value="1" required>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label">Most Likely Days</label>
                                    <input type="number" name="estimated_likely" class="form-control" step="0.5" value="2" required>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label">Max Days (Worst Case)</label>
                                    <input type="number" name="estimated_max" class="form-control" step="0.5" value="4" required>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Deadline *</label>
                                    <input type="date" name="deadline" class="form-control" required>
                                </div>
                                <div class="col-12">
                                    <button type="submit" name="create_task" class="btn btn-primary">
                                        <i class="fas fa-save"></i> Create Task
                                    </button>
                                    <button type="reset" class="btn btn-secondary">Clear</button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            
            <div class="col-md-4">
                <div class="card">
                    <div class="card-header">
                        <h5>Task Creation Tips</h5>
                    </div>
                    <div class="card-body">
                        <ul class="list-unstyled">
                            <li class="mb-2">
                                <i class="fas fa-check-circle text-success me-2"></i>
                                Be specific with task titles
                            </li>
                            <li class="mb-2">
                                <i class="fas fa-check-circle text-success me-2"></i>
                                Include clear acceptance criteria in description
                            </li>
                            <li class="mb-2">
                                <i class="fas fa-check-circle text-success me-2"></i>
                                Use three-point estimation for better accuracy
                            </li>
                            <li class="mb-2">
                                <i class="fas fa-check-circle text-success me-2"></i>
                                Set realistic deadlines
                            </li>
                            <li class="mb-2">
                                <i class="fas fa-check-circle text-success me-2"></i>
                                Critical tasks should be assigned immediately
                            </li>
                        </ul>
                    </div>
                </div>
                
                <div class="card mt-3">
                    <div class="card-header">
                        <h5>Three-Point Estimation</h5>
                    </div>
                    <div class="card-body">
                        <p class="small">Formula: (Min + 4×Likely + Max) / 6</p>
                        <div class="alert alert-info">
                            <strong>Example:</strong><br>
                            Min: 2, Likely: 4, Max: 7<br>
                            Expected = (2 + 4×4 + 7) / 6 = 4.17 days
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Bulk Assign Tab -->
    <div class="tab-pane fade" id="bulk" role="tabpanel">
        <div class="card">
            <div class="card-header">
                <h5>Bulk Task Assignment</h5>
                <p class="text-muted">Assign multiple tasks to team members in one go</p>
            </div>
            <div class="card-body">
                <form method="POST">
                    <div class="row">
                        <div class="col-md-6">
                            <h6>Select Tasks</h6>
                            <div class="border rounded p-3" style="max-height: 400px; overflow-y: auto;">
                                <?php 
                                $allUnassigned = $conn->query("
                                    SELECT t.*, p.name as project_name
                                    FROM tasks t
                                    JOIN projects p ON t.project_id = p.id
                                    WHERE (t.assigned_to IS NULL OR t.assigned_to = 0)
                                    ORDER BY 
                                        CASE t.priority 
                                            WHEN 'critical' THEN 1 
                                            WHEN 'high' THEN 2 
                                            WHEN 'medium' THEN 3 
                                            WHEN 'low' THEN 4 
                                        END
                                ");
                                while($task = $allUnassigned->fetch_assoc()): 
                                ?>
                                <div class="form-check mb-2">
                                    <input class="form-check-input bulk-task-checkbox" type="checkbox" 
                                           name="task_ids[]" value="<?php echo $task['id']; ?>" id="task_<?php echo $task['id']; ?>">
                                    <label class="form-check-label" for="task_<?php echo $task['id']; ?>">
                                        <strong><?php echo htmlspecialchars($task['title']); ?></strong>
                                        <br>
                                        <small class="text-muted">
                                            <?php echo htmlspecialchars($task['project_name']); ?> | 
                                            Priority: <?php echo ucfirst($task['priority']); ?> | 
                                            Due: <?php echo date('M d', strtotime($task['deadline'])); ?>
                                        </small>
                                    </label>
                                </div>
                                <?php endwhile; ?>
                                <?php if($allUnassigned->num_rows == 0): ?>
                                    <p class="text-muted">No unassigned tasks available</p>
                                <?php endif; ?>
                            </div>
                            <div class="mt-2">
                                <button type="button" class="btn btn-sm btn-link" onclick="selectAllTasks()">Select All</button>
                                <button type="button" class="btn btn-sm btn-link" onclick="deselectAllTasks()">Deselect All</button>
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <h6>Select Team Members</h6>
                            <div class="border rounded p-3" style="max-height: 400px; overflow-y: auto;">
                                <?php 
                                $teamMembers->data_seek(0);
                                while($member = $teamMembers->fetch_assoc()): 
                                    $workloadClass = $member['active_tasks'] >= 5 ? 'high' : ($member['active_tasks'] >= 3 ? 'medium' : 'low');
                                ?>
                                <div class="form-check mb-2">
                                    <input class="form-check-input bulk-user-checkbox" type="checkbox" 
                                           name="user_ids[]" value="<?php echo $member['id']; ?>" id="user_<?php echo $member['id']; ?>">
                                    <label class="form-check-label" for="user_<?php echo $member['id']; ?>">
                                        <strong><?php echo htmlspecialchars($member['full_name']); ?></strong>
                                        <br>
                                        <small class="text-muted">
                                            <?php echo strtoupper($member['role']); ?> | 
                                            <span class="workload-indicator workload-<?php echo $workloadClass; ?>"></span>
                                            <?php echo $member['active_tasks']; ?> active tasks
                                        </small>
                                    </label>
                                </div>
                                <?php endwhile; ?>
                            </div>
                            <div class="mt-2">
                                <button type="button" class="btn btn-sm btn-link" onclick="selectAllUsers()">Select All</button>
                                <button type="button" class="btn btn-sm btn-link" onclick="deselectAllUsers()">Deselect All</button>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mt-4">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="send_email" id="bulkSendEmail" checked>
                            <label class="form-check-label" for="bulkSendEmail">
                                Send email notifications to assigned team members
                            </label>
                        </div>
                        <button type="submit" name="bulk_assign" class="btn btn-primary mt-3">
                            <i class="fas fa-tasks"></i> Assign Selected Tasks
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Quick Assign Modal -->
<div class="modal fade" id="quickAssignModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Quick Assign Task</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="task_id" id="quickTaskId">
                    <p><strong>Task:</strong> <span id="quickTaskTitle"></span></p>
                    <div class="mb-3">
                        <label class="form-label">Assign to:</label>
                        <select name="user_id" class="form-select" required>
                            <option value="">Select team member</option>
                            <?php 
                            $teamMembers->data_seek(0);
                            while($member = $teamMembers->fetch_assoc()): 
                            ?>
                            <option value="<?php echo $member['id']; ?>">
                                <?php echo htmlspecialchars($member['full_name']); ?> 
                                (<?php echo $member['active_tasks']; ?> active tasks)
                            </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="send_email" id="quickSendEmail" checked>
                        <label class="form-check-label" for="quickSendEmail">
                            Send email notification
                        </label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="assign_task" class="btn btn-primary">Assign Task</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Task Details Modal -->
<div class="modal fade" id="taskDetailsModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Task Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="taskDetailsContent">
                <!-- Content loaded via AJAX -->
            </div>
        </div>
    </div>
</div>

<script>
let selectedTaskIds = [];

// Task selection for bulk assign
document.getElementById('selectAllUnassigned')?.addEventListener('change', function(e) {
    document.querySelectorAll('.task-checkbox').forEach(cb => {
        cb.checked = e.target.checked;
    });
    updateBulkAssignSection();
});

document.querySelectorAll('.task-checkbox').forEach(cb => {
    cb.addEventListener('change', updateBulkAssignSection);
});

function updateBulkAssignSection() {
    const checkedCount = document.querySelectorAll('.task-checkbox:checked').length;
    const section = document.getElementById('bulkAssignSection');
    if(section) {
        section.style.display = checkedCount > 0 ? 'block' : 'none';
    }
}

function bulkAssign() {
    const userId = document.getElementById('bulkUserId').value;
    const taskIds = Array.from(document.querySelectorAll('.task-checkbox:checked')).map(cb => cb.value);
    
    if(!userId) {
        showToast('Error', 'Please select a team member', 'danger');
        return;
    }
    
    if(taskIds.length === 0) {
        showToast('Error', 'No tasks selected', 'danger');
        return;
    }
    
    if(confirm(`Assign ${taskIds.length} task(s) to this team member?`)) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="bulk_assign" value="1">
            <input type="hidden" name="user_ids[]" value="${userId}">
            ${taskIds.map(id => `<input type="hidden" name="task_ids[]" value="${id}">`).join('')}
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

function quickAssign(taskId, taskTitle) {
    document.getElementById('quickTaskId').value = taskId;
    document.getElementById('quickTaskTitle').textContent = taskTitle;
    new bootstrap.Modal(document.getElementById('quickAssignModal')).show();
}

function approveTask(taskId) {
    if(confirm('Mark this task as completed?')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="update_status" value="1">
            <input type="hidden" name="task_id" value="${taskId}">
            <input type="hidden" name="new_status" value="completed">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

function viewTaskDetails(taskId) {
    $.ajax({
        url: '../../api/get-task-details.php',
        method: 'GET',
        data: { id: taskId },
        success: function(response) {
            const task = JSON.parse(response);
            let html = `
                <div class="row">
                    <div class="col-md-8">
                        <h4>${escapeHtml(task.title)}</h4>
                        <p><strong>Description:</strong><br>${escapeHtml(task.description)}</p>
                        <p><strong>Project:</strong> ${escapeHtml(task.project_name)}</p>
                        <p><strong>Status:</strong> <span class="badge bg-${task.status == 'completed' ? 'success' : (task.status == 'in_progress' ? 'info' : 'secondary')}">${task.status}</span></p>
                        <p><strong>Priority:</strong> <span class="badge priority-${task.priority}">${task.priority}</span></p>
                    </div>
                    <div class="col-md-4">
                        <div class="card bg-light">
                            <div class="card-body">
                                <p><strong>Assigned To:</strong> ${task.assigned_to_name || 'Unassigned'}</p>
                                <p><strong>Deadline:</strong> ${new Date(task.deadline).toLocaleDateString()}</p>
                                <p><strong>Estimation:</strong><br>
                                    Min: ${task.estimated_days_min} days<br>
                                    Likely: ${task.estimated_days_likely} days<br>
                                    Max: ${task.estimated_days_max} days
                                </p>
                                ${!task.assigned_to ? `
                                    <button class="btn btn-primary w-100" onclick="quickAssign(${task.id}, '${escapeHtml(task.title)}')">
                                        <i class="fas fa-user-plus"></i> Assign Task
                                    </button>
                                ` : ''}
                                ${task.status != 'completed' ? `
                                    <button class="btn btn-danger w-100 mt-2" onclick="deleteTask(${task.id})">
                                        <i class="fas fa-trash"></i> Delete Task
                                    </button>
                                ` : ''}
                            </div>
                        </div>
                    </div>
                </div>
            `;
            $('#taskDetailsContent').html(html);
            new bootstrap.Modal(document.getElementById('taskDetailsModal')).show();
        }
    });
}

function deleteTask(taskId) {
    if(confirm('Are you sure you want to delete this task? This action cannot be undone.')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="delete_task" value="1">
            <input type="hidden" name="task_id" value="${taskId}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

function selectAllTasks() {
    document.querySelectorAll('.bulk-task-checkbox').forEach(cb => cb.checked = true);
}

function deselectAllTasks() {
    document.querySelectorAll('.bulk-task-checkbox').forEach(cb => cb.checked = false);
}

function selectAllUsers() {
    document.querySelectorAll('.bulk-user-checkbox').forEach(cb => cb.checked = true);
}

function deselectAllUsers() {
    document.querySelectorAll('.bulk-user-checkbox').forEach(cb => cb.checked = false);
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function showToast(title, message, type) {
    const toastHTML = `
        <div class="position-fixed bottom-0 end-0 p-3" style="z-index: 11">
            <div class="toast align-items-center text-white bg-${type} border-0" role="alert" data-bs-autohide="true" data-bs-delay="3000">
                <div class="d-flex">
                    <div class="toast-body">
                        <strong>${title}</strong><br>
                        ${message}
                    </div>
                    <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
                </div>
            </div>
        </div>
    `;
    $('body').append(toastHTML);
    const toast = new bootstrap.Toast($('.toast').last());
    toast.show();
    $('.toast').last().on('hidden.bs.toast', function() { $(this).parent().remove(); });
}
</script>

<?php include '../../includes/footer.php'; ?>
