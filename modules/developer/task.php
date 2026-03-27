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
    
    $completedAt = $newStatus === 'completed' ? date('Y-m-d H:i:s') : null;
    $stmt = $conn->prepare("UPDATE tasks SET status = ?, completed_at = ? WHERE id = ?");
    $stmt->bind_param("ssi", $newStatus, $completedAt, $taskId);
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
    --shadow-md: 0 4px 6px -1px rgba(0,0,0,0.05), 0 2px 4px -1px rgba(0,0,0,0.03);
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

/* Kanban Columns */
.kanban-column {
    background: var(--gray-50);
    border-radius: 20px;
    min-height: 500px;
    border: 1px solid var(--gray-200);
}

.kanban-header {
    padding: 1rem 1.25rem;
    background: white;
    border-bottom: 1px solid var(--gray-200);
    border-radius: 20px 20px 0 0;
}

.kanban-header h5 {
    font-size: 0.9rem;
    font-weight: 600;
    margin: 0;
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.kanban-body {
    padding: 1rem;
    min-height: 450px;
}

/* Kanban Cards */
.kanban-card {
    background: white;
    border-radius: 12px;
    padding: 0.75rem;
    margin-bottom: 0.75rem;
    cursor: grab;
    transition: all 0.2s;
    border: 1px solid var(--gray-200);
    position: relative;
}

.kanban-card:hover {
    transform: translateY(-2px);
    box-shadow: var(--shadow-md);
    border-color: var(--theme-primary);
}

.kanban-card:active {
    cursor: grabbing;
}

.task-priority-dot {
    position: absolute;
    top: 0.75rem;
    right: 0.75rem;
    width: 8px;
    height: 8px;
    border-radius: 50%;
}

.priority-high { background-color: #dc2626; }
.priority-medium { background-color: #f59e0b; }
.priority-low { background-color: #10b981; }

/* Buttons */
.btn-action {
    background: white;
    border: 1px solid var(--gray-200);
    padding: 0.2rem 0.5rem;
    border-radius: 6px;
    font-size: 0.65rem;
    transition: all 0.2s;
    cursor: pointer;
}

.btn-action:hover {
    background: var(--theme-primary);
    border-color: var(--theme-primary);
    color: white;
}

.btn-action-view {
    background: linear-gradient(135deg, var(--theme-primary) 0%, var(--theme-dark) 100%);
    color: white;
    border: none;
    padding: 0.2rem 0.5rem;
    border-radius: 6px;
    font-size: 0.65rem;
    transition: all 0.2s;
    cursor: pointer;
}

.btn-action-view:hover {
    transform: translateY(-1px);
    box-shadow: 0 2px 6px rgba(31, 99, 146, 0.3);
    color: white;
}

/* Empty State */
.empty-state-mini {
    text-align: center;
    padding: 2rem 1rem;
}

.empty-state-mini i {
    font-size: 1.5rem;
    color: var(--gray-300);
    margin-bottom: 0.5rem;
}

.empty-state-mini p {
    color: var(--gray-500);
    font-size: 0.75rem;
    margin: 0;
}

/* Completed Card */
.completed-card {
    background: var(--gray-50);
    border-radius: 10px;
    padding: 0.5rem 0.75rem;
    margin-bottom: 0.5rem;
}

.completed-card small {
    font-size: 0.7rem;
}

/* Responsive */
@media (max-width: 768px) {
    .page-header-modern {
        padding: 1.5rem;
    }
    
    .page-header-modern h2 {
        font-size: 1.5rem;
    }
    
    .kanban-column {
        margin-bottom: 1rem;
    }
}
</style>

<div class="container-fluid px-4 py-4">
    <!-- Page Header -->
    <div class="page-header-modern">
        <div>
            <h2>
                <i class="fas fa-tasks me-2"></i> 
                My Tasks
            </h2>
            <p>Drag and drop tasks to update status, or use the buttons below</p>
        </div>
    </div>

    <div class="row g-4">
        <!-- To Do Column -->
        <div class="col-md-3">
            <div class="kanban-column">
                <div class="kanban-header">
                    <h5>
                        <span><i class="fas fa-clock text-secondary me-2"></i>To Do</span>
                        <span class="badge bg-secondary"><?php echo $todoTasks->num_rows; ?></span>
                    </h5>
                </div>
                <div class="kanban-body" id="todo-column">
                    <?php while($task = $todoTasks->fetch_assoc()): ?>
                    <div class="kanban-card" data-task-id="<?php echo $task['id']; ?>" draggable="true">
                        <div class="task-priority-dot priority-<?php echo $task['priority']; ?>"></div>
                        <h6 class="mb-1 fw-semibold"><?php echo htmlspecialchars($task['title']); ?></h6>
                        <p class="small text-muted mb-2"><?php echo htmlspecialchars(substr($task['description'], 0, 80)); ?></p>
                        <div class="d-flex justify-content-between align-items-center">
                            <small class="text-muted"><i class="far fa-calendar-alt me-1"></i>Due: <?php echo date('M d', strtotime($task['deadline'])); ?></small>
                            <div>
                                <button class="btn-action me-1" onclick="startTask(<?php echo $task['id']; ?>)">
                                    <i class="fas fa-play"></i> Start
                                </button>
                                <button class="btn-action-view" onclick="viewTask(<?php echo $task['id']; ?>)">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                    <?php endwhile; ?>
                    <?php if($todoTasks->num_rows == 0): ?>
                    <div class="empty-state-mini">
                        <i class="fas fa-check-circle"></i>
                        <p>No tasks in To Do</p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- In Progress Column -->
        <div class="col-md-3">
            <div class="kanban-column">
                <div class="kanban-header">
                    <h5>
                        <span><i class="fas fa-spinner text-primary me-2"></i>In Progress</span>
                        <span class="badge bg-primary"><?php echo $inProgressTasks->num_rows; ?></span>
                    </h5>
                </div>
                <div class="kanban-body" id="progress-column">
                    <?php while($task = $inProgressTasks->fetch_assoc()): ?>
                    <div class="kanban-card" data-task-id="<?php echo $task['id']; ?>" draggable="true">
                        <div class="task-priority-dot priority-<?php echo $task['priority']; ?>"></div>
                        <h6 class="mb-1 fw-semibold"><?php echo htmlspecialchars($task['title']); ?></h6>
                        <p class="small text-muted mb-2"><?php echo htmlspecialchars(substr($task['description'], 0, 80)); ?></p>
                        <div class="d-flex justify-content-between align-items-center">
                            <small class="text-muted"><i class="far fa-calendar-alt me-1"></i>Due: <?php echo date('M d', strtotime($task['deadline'])); ?></small>
                            <div>
                                <button class="btn-action me-1" onclick="completeTask(<?php echo $task['id']; ?>)">
                                    <i class="fas fa-check"></i> Complete
                                </button>
                                <button class="btn-action me-1" onclick="reviewTask(<?php echo $task['id']; ?>)">
                                    <i class="fas fa-undo"></i> Back
                                </button>
                                <button class="btn-action-view" onclick="viewTask(<?php echo $task['id']; ?>)">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                    <?php endwhile; ?>
                    <?php if($inProgressTasks->num_rows == 0): ?>
                    <div class="empty-state-mini">
                        <i class="fas fa-play-circle"></i>
                        <p>No tasks in progress</p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- Review Column -->
        <div class="col-md-3">
            <div class="kanban-column">
                <div class="kanban-header">
                    <h5>
                        <span><i class="fas fa-check-circle text-warning me-2"></i>Review</span>
                        <span class="badge bg-warning"><?php echo $reviewTasks->num_rows; ?></span>
                    </h5>
                </div>
                <div class="kanban-body" id="review-column">
                    <?php while($task = $reviewTasks->fetch_assoc()): ?>
                    <div class="kanban-card" data-task-id="<?php echo $task['id']; ?>" draggable="true">
                        <div class="task-priority-dot priority-<?php echo $task['priority']; ?>"></div>
                        <h6 class="mb-1 fw-semibold"><?php echo htmlspecialchars($task['title']); ?></h6>
                        <p class="small text-muted mb-2"><?php echo htmlspecialchars(substr($task['description'], 0, 80)); ?></p>
                        <div class="d-flex justify-content-between align-items-center">
                            <small class="text-muted"><i class="far fa-calendar-alt me-1"></i>Due: <?php echo date('M d', strtotime($task['deadline'])); ?></small>
                            <button class="btn-action-view" onclick="viewTask(<?php echo $task['id']; ?>)">
                                <i class="fas fa-eye"></i> View
                            </button>
                        </div>
                    </div>
                    <?php endwhile; ?>
                    <?php if($reviewTasks->num_rows == 0): ?>
                    <div class="empty-state-mini">
                        <i class="fas fa-check-double"></i>
                        <p>No tasks in review</p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- Completed Column -->
        <div class="col-md-3">
            <div class="kanban-column">
                <div class="kanban-header">
                    <h5>
                        <span><i class="fas fa-check-double text-success me-2"></i>Completed</span>
                        <span class="badge bg-success"><?php echo $completedTasks->num_rows; ?></span>
                    </h5>
                </div>
                <div class="kanban-body">
                    <?php while($task = $completedTasks->fetch_assoc()): ?>
                    <div class="completed-card">
                        <div class="d-flex justify-content-between">
                            <small><s><?php echo htmlspecialchars($task['title']); ?></s></small>
                            <small class="text-muted"><?php echo date('M d', strtotime($task['completed_at'])); ?></small>
                        </div>
                    </div>
                    <?php endwhile; ?>
                    <?php if($completedTasks->num_rows == 0): ?>
                    <div class="empty-state-mini">
                        <i class="fas fa-trophy"></i>
                        <p>No completed tasks yet</p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Task Detail Modal -->
<div class="modal fade modal-modern" id="taskModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-info-circle me-2"></i>Task Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="taskDetails">
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
                <div class="row g-4">
                    <div class="col-md-8">
                        <h5 class="mb-3">${escapeHtml(task.title)}</h5>
                        <div class="mb-3">
                            <strong>Description:</strong>
                            <p class="mt-2">${escapeHtml(task.description)}</p>
                        </div>
                        <div class="mb-2">
                            <strong>Priority:</strong> 
                            <span class="badge-priority-${task.priority}">${task.priority}</span>
                        </div>
                        <div class="mb-2">
                            <strong>Deadline:</strong> ${new Date(task.deadline).toLocaleDateString()}
                        </div>
                        <div class="mb-2">
                            <strong>Estimated Days:</strong> 
                            Min: ${task.estimated_days_min}, Likely: ${task.estimated_days_likely}, Max: ${task.estimated_days_max}
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="p-3 bg-light rounded-3">
                            <div class="mb-3">
                                <label class="form-label">Add Comment</label>
                                <textarea id="taskComment" class="form-control" rows="3"></textarea>
                            </div>
                            <button class="btn-primary-modern w-100" onclick="addComment(${taskId})">
                                <i class="fas fa-paper-plane"></i> Add Comment
                            </button>
                        </div>
                        <div class="mt-3">
                            <h6 class="mb-2">Comments</h6>
                            <div id="commentsList" class="small"></div>
                        </div>
                    </div>
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
                        <strong>${escapeHtml(comment.user_name)}</strong> 
                        <small class="text-muted">${new Date(comment.created_at).toLocaleString()}</small>
                        <p class="mb-0 small">${escapeHtml(comment.comment)}</p>
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
        data: { task_id: taskId, comment: comment },
        success: function() {
            $('#taskComment').val('');
            loadComments(taskId);
            alert('✓ Comment added successfully');
        }
    });
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Drag and drop functionality
let draggedItem = null;

document.querySelectorAll('.kanban-card').forEach(card => {
    card.addEventListener('dragstart', handleDragStart);
    card.addEventListener('dragend', handleDragEnd);
});

document.querySelectorAll('.kanban-body').forEach(column => {
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
    const columnTitle = targetColumn.querySelector('.kanban-header h5 span:first-child').textContent.trim();
    
    let newStatus = '';
    if(columnTitle === 'To Do') newStatus = 'todo';
    else if(columnTitle === 'In Progress') newStatus = 'in_progress';
    else if(columnTitle === 'Review') newStatus = 'review';
    else if(columnTitle === 'Completed') newStatus = 'completed';
    
    if(newStatus) {
        updateTaskStatus(taskId, newStatus);
    }
}
</script>

<?php include '../../includes/footer.php'; ?>