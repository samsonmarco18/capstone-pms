<?php
require_once '../../includes/auth.php';
requireRole('developer');

$conn = getConnection();
$user = getCurrentUser();

// Get tasks assigned to this developer
$todoTasks = $conn->query("SELECT * FROM tasks WHERE assigned_to = {$user['id']} AND status = 'todo' ORDER BY deadline ASC");
$inProgressTasks = $conn->query("SELECT * FROM tasks WHERE assigned_to = {$user['id']} AND status = 'in_progress' ORDER BY deadline ASC");
$reviewTasks = $conn->query("SELECT * FROM tasks WHERE assigned_to = {$user['id']} AND status = 'review' ORDER BY deadline ASC");
$completedTasks = $conn->query("SELECT * FROM tasks WHERE assigned_to = {$user['id']} AND status = 'completed' ORDER BY completed_at DESC LIMIT 10");

// Update task status
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    $taskId = $_POST['task_id'];
    $newStatus = $_POST['new_status'];
    
    $updateData = ['status' => $newStatus];
    if($newStatus == 'completed') {
        $updateData['completed_at'] = date('Y-m-d H:i:s');
    }
    
    $stmt = $conn->prepare("UPDATE tasks SET status = ?, completed_at = NOW() WHERE id = ?");
    $stmt->bind_param("si", $newStatus, $taskId);
    $stmt->execute();
    
    logActivity($user['id'], 'update_task_status', 'tasks', $taskId, null, ['status' => $newStatus]);
    $_SESSION['success'] = "Task status updated";
    
    header('Location: task.php');
    exit();
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
    cursor: move;
    transition: all 0.3s;
}

.kanban-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(0,0,0,0.1);
}

.task-priority {
    position: absolute;
    top: 10px;
    right: 10px;
    width: 10px;
    height: 10px;
    border-radius: 50%;
}

.priority-high { background-color: #dc3545; }
.priority-medium { background-color: #ffc107; }
.priority-low { background-color: #28a745; }
</style>

<div class="row mb-4">
    <div class="col-12">
        <h2>My Tasks</h2>
        <p class="text-muted">Drag and drop tasks to update status, or use the buttons below</p>
    </div>
</div>

<div class="row">
    <div class="col-md-3 mb-4">
        <div class="kanban-column">
            <div class="p-3 bg-light border-bottom">
                <h5 class="mb-0">To Do</h5>
                <small class="text-muted"><?php echo $todoTasks->num_rows; ?> tasks</small>
            </div>
            <div class="p-3" id="todo-column">
                <?php while($task = $todoTasks->fetch_assoc()): ?>
                <div class="kanban-card card mb-3" data-task-id="<?php echo $task['id']; ?>">
                    <div class="card-body">
                        <div class="task-priority priority-<?php echo $task['priority']; ?>"></div>
                        <h6 class="card-title"><?php echo htmlspecialchars($task['title']); ?></h6>
                        <p class="card-text small"><?php echo htmlspecialchars(substr($task['description'], 0, 100)); ?></p>
                        <div class="d-flex justify-content-between align-items-center">
                            <small class="text-muted">
                                <i class="fas fa-calendar"></i> 
                                Due: <?php echo date('M d', strtotime($task['deadline'])); ?>
                            </small>
                            <div class="btn-group">
                                <button class="btn btn-sm btn-primary" onclick="startTask(<?php echo $task['id']; ?>)">
                                    <i class="fas fa-play"></i> Start
                                </button>
                                <button class="btn btn-sm btn-info" onclick="viewTask(<?php echo $task['id']; ?>)">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endwhile; ?>
            </div>
        </div>
    </div>
    
    <div class="col-md-3 mb-4">
        <div class="kanban-column">
            <div class="p-3 bg-light border-bottom">
                <h5 class="mb-0">In Progress</h5>
                <small class="text-muted"><?php echo $inProgressTasks->num_rows; ?> tasks</small>
            </div>
            <div class="p-3" id="progress-column">
                <?php while($task = $inProgressTasks->fetch_assoc()): ?>
                <div class="kanban-card card mb-3" data-task-id="<?php echo $task['id']; ?>">
                    <div class="card-body">
                        <div class="task-priority priority-<?php echo $task['priority']; ?>"></div>
                        <h6 class="card-title"><?php echo htmlspecialchars($task['title']); ?></h6>
                        <p class="card-text small"><?php echo htmlspecialchars(substr($task['description'], 0, 100)); ?></p>
                        <div class="d-flex justify-content-between align-items-center">
                            <small class="text-muted">
                                <i class="fas fa-calendar"></i> 
                                Due: <?php echo date('M d', strtotime($task['deadline'])); ?>
                            </small>
                            <div class="btn-group">
                                <button class="btn btn-sm btn-success" onclick="completeTask(<?php echo $task['id']; ?>)">
                                    <i class="fas fa-check"></i> Complete
                                </button>
                                <button class="btn btn-sm btn-warning" onclick="reviewTask(<?php echo $task['id']; ?>)">
                                    <i class="fas fa-undo"></i> Back
                                </button>
                                <button class="btn btn-sm btn-info" onclick="viewTask(<?php echo $task['id']; ?>)">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endwhile; ?>
            </div>
        </div>
    </div>
    
    <div class="col-md-3 mb-4">
        <div class="kanban-column">
            <div class="p-3 bg-light border-bottom">
                <h5 class="mb-0">Review</h5>
                <small class="text-muted"><?php echo $reviewTasks->num_rows; ?> tasks</small>
            </div>
            <div class="p-3" id="review-column">
                <?php while($task = $reviewTasks->fetch_assoc()): ?>
                <div class="kanban-card card mb-3" data-task-id="<?php echo $task['id']; ?>">
                    <div class="card-body">
                        <div class="task-priority priority-<?php echo $task['priority']; ?>"></div>
                        <h6 class="card-title"><?php echo htmlspecialchars($task['title']); ?></h6>
                        <p class="card-text small"><?php echo htmlspecialchars(substr($task['description'], 0, 100)); ?></p>
                        <div class="d-flex justify-content-between align-items-center">
                            <small class="text-muted">
                                <i class="fas fa-calendar"></i> 
                                Due: <?php echo date('M d', strtotime($task['deadline'])); ?>
                            </small>
                            <button class="btn btn-sm btn-info" onclick="viewTask(<?php echo $task['id']; ?>)">
                                <i class="fas fa-eye"></i>
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
            <div class="p-3 bg-light border-bottom">
                <h5 class="mb-0">Completed</h5>
                <small class="text-muted"><?php echo $completedTasks->num_rows; ?> recent tasks</small>
            </div>
            <div class="p-3">
                <?php while($task = $completedTasks->fetch_assoc()): ?>
                <div class="card mb-2 bg-light">
                    <div class="card-body p-2">
                        <small><s><?php echo htmlspecialchars($task['title']); ?></s></small><br>
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

<!-- Task Detail Modal -->
<div class="modal fade" id="taskModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Task Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="taskDetails">
                <!-- Task details will be loaded here -->
            </div>
        </div>
    </div>
</div>

<script>
function startTask(taskId) {
    updateTaskStatus(taskId, 'in_progress');
}

function completeTask(taskId) {
    if(confirm('Mark this task as completed?')) {
        updateTaskStatus(taskId, 'completed');
    }
}

function reviewTask(taskId) {
    updateTaskStatus(taskId, 'todo');
}

function updateTaskStatus(taskId, status) {
    const form = document.createElement('form');
    form.method = 'POST';
    form.innerHTML = `
        <input type="hidden" name="action" value="update_status">
        <input type="hidden" name="task_id" value="${taskId}">
        <input type="hidden" name="new_status" value="${status}">
    `;
    document.body.appendChild(form);
    form.submit();
}

function viewTask(taskId) {
    $.ajax({
        url: '../../api/get-task-details.php',
        method: 'GET',
        data: { id: taskId },
        success: function(response) {
            const task = JSON.parse(response);
            let html = `
                <h6>${task.title}</h6>
                <p><strong>Description:</strong><br>${task.description}</p>
                <p><strong>Priority:</strong> <span class="badge bg-${task.priority == 'high' ? 'danger' : (task.priority == 'medium' ? 'warning' : 'success')}">${task.priority}</span></p>
                <p><strong>Deadline:</strong> ${new Date(task.deadline).toLocaleDateString()}</p>
                <p><strong>Estimated Days:</strong> Min: ${task.estimated_days_min}, Likely: ${task.estimated_days_likely}, Max: ${task.estimated_days_max}</p>
                <div class="mt-3">
                    <label class="form-label">Add Comment</label>
                    <textarea id="taskComment" class="form-control" rows="2"></textarea>
                    <button class="btn btn-sm btn-primary mt-2" onclick="addComment(${taskId})">Add Comment</button>
                </div>
                <div id="commentsSection" class="mt-3">
                    <h6>Comments</h6>
                    <div id="commentsList"></div>
                </div>
            `;
            $('#taskDetails').html(html);
            loadComments(taskId);
            new bootstrap.Modal(document.getElementById('taskModal')).show();
        }
    });
}

function loadComments(taskId) {
    $.ajax({
        url: '../../api/get-task-comments.php',
        method: 'GET',
        data: { task_id: taskId },
        success: function(response) {
            const comments = JSON.parse(response);
            let html = '';
            comments.forEach(comment => {
                html += `
                    <div class="border-bottom mb-2 pb-2">
                        <strong>${comment.user_name}</strong> <small class="text-muted">${new Date(comment.created_at).toLocaleString()}</small>
                        <p class="mb-0">${comment.comment}</p>
                    </div>
                `;
            });
            $('#commentsList').html(html || '<p class="text-muted">No comments yet</p>');
        }
    });
}

function addComment(taskId) {
    const comment = $('#taskComment').val();
    if(!comment.trim()) return;
    
    $.ajax({
        url: '../../api/add-task-comment.php',
        method: 'POST',
        data: {
            task_id: taskId,
            comment: comment
        },
        success: function() {
            $('#taskComment').val('');
            loadComments(taskId);
            showToast('Success', 'Comment added successfully');
        }
    });
}

// Drag and drop functionality
let draggedItem = null;

document.querySelectorAll('.kanban-card').forEach(card => {
    card.addEventListener('dragstart', handleDragStart);
    card.addEventListener('dragend', handleDragEnd);
});

document.querySelectorAll('.kanban-column .p-3').forEach(column => {
    column.addEventListener('dragover', handleDragOver);
    column.addEventListener('drop', handleDrop);
});

function handleDragStart(e) {
    draggedItem = this;
    e.dataTransfer.setData('text/plain', this.dataset.taskId);
}

function handleDragEnd(e) {
    draggedItem = null;
}

function handleDragOver(e) {
    e.preventDefault();
}

function handleDrop(e) {
    e.preventDefault();
    const taskId = e.dataTransfer.getData('text/plain');
    const targetColumn = this.closest('.kanban-column');
    const columnId = targetColumn.querySelector('h5').textContent.trim();
    
    let newStatus = '';
    if(columnId === 'To Do') newStatus = 'todo';
    else if(columnId === 'In Progress') newStatus = 'in_progress';
    else if(columnId === 'Review') newStatus = 'review';
    else if(columnId === 'Completed') newStatus = 'completed';
    
    if(newStatus) {
        updateTaskStatus(taskId, newStatus);
    }
}
</script>

<?php include '../../includes/footer.php'; ?>