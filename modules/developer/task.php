<?php
require_once '../../includes/auth.php';
requireRole('developer');

$conn = getConnection();
$user = getCurrentUser();

$taskBaseQuery = "
    SELECT t.*,
           p.name AS project_name,
           latest_git.branch_name AS git_branch_name,
           latest_git.commit_hash AS git_commit_hash,
           latest_git.status AS git_status,
           latest_git.git_action AS git_action,
           latest_git.pull_request_url AS git_pull_request_url
    FROM tasks t
    LEFT JOIN projects p ON p.id = t.project_id
    LEFT JOIN task_git_updates latest_git ON latest_git.id = (
        SELECT tgu.id
        FROM task_git_updates tgu
        WHERE tgu.task_id = t.id
        ORDER BY tgu.created_at DESC, tgu.id DESC
        LIMIT 1
    )
";

// Get tasks assigned to this developer
$todoTasks = $conn->query($taskBaseQuery . " WHERE t.assigned_to = {$user['id']} AND t.status = 'todo' ORDER BY t.deadline ASC");
$inProgressTasks = $conn->query($taskBaseQuery . " WHERE t.assigned_to = {$user['id']} AND t.status = 'in_progress' ORDER BY t.deadline ASC");
$reviewTasks = $conn->query($taskBaseQuery . " WHERE t.assigned_to = {$user['id']} AND t.status = 'review' ORDER BY t.deadline ASC");
$completedTasks = $conn->query($taskBaseQuery . " WHERE t.assigned_to = {$user['id']} AND t.status = 'completed' ORDER BY t.completed_at DESC LIMIT 10");

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

.git-snippet {
    display: flex;
    align-items: center;
    gap: 0.4rem;
    font-size: 0.72rem;
    color: var(--theme-primary);
    margin-bottom: 0.65rem;
}

.git-update-item {
    border: 1px solid var(--gray-200);
    border-radius: 12px;
    padding: 0.75rem;
    margin-bottom: 0.75rem;
    background: white;
}

.git-update-item:last-child {
    margin-bottom: 0;
}

.git-update-item code {
    font-size: 0.75rem;
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
                        <?php if (!empty($task['git_branch_name'])): ?>
                        <div class="git-snippet">
                            <i class="fas fa-code-branch"></i>
                            <span><?php echo htmlspecialchars($task['git_branch_name']); ?></span>
                            <?php if (!empty($task['git_commit_hash'])): ?>
                            <code><?php echo htmlspecialchars(substr($task['git_commit_hash'], 0, 7)); ?></code>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
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
                        <?php if (!empty($task['git_branch_name'])): ?>
                        <div class="git-snippet">
                            <i class="fas fa-code-branch"></i>
                            <span><?php echo htmlspecialchars($task['git_branch_name']); ?></span>
                            <?php if (!empty($task['git_commit_hash'])): ?>
                            <code><?php echo htmlspecialchars(substr($task['git_commit_hash'], 0, 7)); ?></code>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
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
                        <?php if (!empty($task['git_branch_name'])): ?>
                        <div class="git-snippet">
                            <i class="fas fa-code-branch"></i>
                            <span><?php echo htmlspecialchars($task['git_branch_name']); ?></span>
                            <?php if (!empty($task['git_commit_hash'])): ?>
                            <code><?php echo htmlspecialchars(substr($task['git_commit_hash'], 0, 7)); ?></code>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
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
            const task = typeof response === 'string' ? JSON.parse(response) : response;
            const latestGit = task.latest_git_update;
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
                        <div class="mt-4 p-3 bg-light rounded-3 border">
                            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                                <div>
                                    <strong><i class="fas fa-code-branch me-2 text-primary"></i>Latest Git Activity</strong>
                                    <div class="small text-muted mt-1">${task.git_updates_count} update(s) logged for this task</div>
                                </div>
                                ${latestGit ? `<span class="badge bg-light text-primary border">${formatGitAction(latestGit.git_action)}</span>` : ''}
                            </div>
                            ${latestGit ? `
                                <div class="row g-3 mt-1">
                                    <div class="col-sm-6">
                                        <div class="small text-muted">Branch</div>
                                        <div class="fw-semibold">${escapeHtml(latestGit.branch_name)}</div>
                                    </div>
                                    <div class="col-sm-6">
                                        <div class="small text-muted">Status</div>
                                        <div>${formatGitStatusBadge(latestGit.status)}</div>
                                    </div>
                                    <div class="col-sm-6">
                                        <div class="small text-muted">Commit</div>
                                        <div>${latestGit.commit_hash ? `<code>${escapeHtml(shortCommit(latestGit.commit_hash))}</code>` : '<span class="text-muted">No commit hash</span>'}</div>
                                    </div>
                                    <div class="col-sm-6">
                                        <div class="small text-muted">Updated By</div>
                                        <div>${escapeHtml(latestGit.user_name || 'Unknown')}</div>
                                    </div>
                                    <div class="col-12">
                                        <div class="small text-muted">Commit Message</div>
                                        <div>${latestGit.commit_message ? escapeHtml(latestGit.commit_message) : '<span class="text-muted">No commit message</span>'}</div>
                                    </div>
                                    <div class="col-12">
                                        <div class="small text-muted">Links</div>
                                        <div class="d-flex gap-2 flex-wrap">
                                            ${latestGit.repository_url ? `<a href="${escapeAttribute(latestGit.repository_url)}" target="_blank" rel="noopener" class="btn btn-sm btn-outline-primary"><i class="fas fa-link me-1"></i>Repository</a>` : ''}
                                            ${latestGit.pull_request_url ? `<a href="${escapeAttribute(latestGit.pull_request_url)}" target="_blank" rel="noopener" class="btn btn-sm btn-outline-primary"><i class="fas fa-code-pull-request me-1"></i>Pull Request</a>` : ''}
                                            ${!latestGit.repository_url && !latestGit.pull_request_url ? '<span class="text-muted">No links attached</span>' : ''}
                                        </div>
                                    </div>
                                    <div class="col-12">
                                        <div class="small text-muted">Notes</div>
                                        <div>${latestGit.notes ? escapeHtml(latestGit.notes) : '<span class="text-muted">No notes provided</span>'}</div>
                                    </div>
                                </div>
                            ` : `<p class="text-muted mb-0 mt-3">No Git activity has been recorded for this task yet.</p>`}
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="p-3 bg-light rounded-3 border mb-3">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <label class="form-label mb-0 fw-semibold">Log Git Update</label>
                                <small class="text-muted">Branch, commit, PR</small>
                            </div>
                            <div class="mb-2">
                                <select id="gitAction" class="form-select form-select-sm">
                                    <option value="commit_pushed">Commit Pushed</option>
                                    <option value="branch_created">Branch Created</option>
                                    <option value="pull_request_opened">Pull Request Opened</option>
                                    <option value="merged">Merged</option>
                                    <option value="deployed">Deployed</option>
                                    <option value="note">General Note</option>
                                </select>
                            </div>
                            <div class="mb-2">
                                <input id="gitBranchName" class="form-control form-control-sm" placeholder="Branch name (required)">
                            </div>
                            <div class="mb-2">
                                <input id="gitCommitHash" class="form-control form-control-sm" placeholder="Commit hash">
                            </div>
                            <div class="mb-2">
                                <input id="gitCommitMessage" class="form-control form-control-sm" placeholder="Commit message">
                            </div>
                            <div class="mb-2">
                                <input id="gitPullRequestUrl" class="form-control form-control-sm" placeholder="Pull request URL">
                            </div>
                            <div class="mb-2">
                                <input id="gitRepositoryUrl" class="form-control form-control-sm" placeholder="Repository URL">
                            </div>
                            <div class="mb-2">
                                <select id="gitStatus" class="form-select form-select-sm">
                                    <option value="active">Active</option>
                                    <option value="in_review">In Review</option>
                                    <option value="merged">Merged</option>
                                    <option value="closed">Closed</option>
                                </select>
                            </div>
                            <div class="mb-3">
                                <textarea id="gitNotes" class="form-control form-control-sm" rows="3" placeholder="Notes for this Git update"></textarea>
                            </div>
                            <button class="btn-primary-modern w-100" onclick="addGitUpdate(${taskId})">
                                <i class="fas fa-code-branch"></i> Save Git Update
                            </button>
                        </div>
                        <div class="mt-3">
                            <h6 class="mb-2">Git History</h6>
                            <div id="gitUpdatesList" class="small"></div>
                        </div>
                        <div class="p-3 bg-light rounded-3 border mt-3">
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
            loadGitUpdates(taskId);
            new bootstrap.Modal(document.getElementById('taskModal')).show();
        },
        error: function(xhr) {
            const message = xhr.responseJSON?.error || 'Unable to load task details';
            $('#taskDetails').html(`<div class="alert alert-danger mb-0">${escapeHtml(message)}</div>`);
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
            const comments = typeof response === 'string' ? JSON.parse(response) : response;
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

function loadGitUpdates(taskId) {
    $.ajax({
        url: '../../api/get-task-git-updates.php',
        method: 'GET',
        data: { task_id: taskId },
        success: function(response) {
            const updates = typeof response === 'string' ? JSON.parse(response) : response;
            let html = '';
            updates.forEach(update => {
                html += `
                    <div class="git-update-item">
                        <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                            <div>
                                <strong>${escapeHtml(update.branch_name)}</strong>
                                <div class="small text-muted">${escapeHtml(update.user_name || 'Unknown')} | ${formatDateTime(update.created_at)}</div>
                            </div>
                            ${formatGitStatusBadge(update.status)}
                        </div>
                        <div class="small mb-2"><span class="badge bg-light text-dark border">${formatGitAction(update.git_action)}</span></div>
                        <div class="small mb-2">${update.commit_hash ? `<code>${escapeHtml(shortCommit(update.commit_hash))}</code>` : '<span class="text-muted">No commit hash</span>'}</div>
                        ${update.commit_message ? `<div class="small mb-2">${escapeHtml(update.commit_message)}</div>` : ''}
                        ${update.notes ? `<div class="small mb-2 text-muted">${escapeHtml(update.notes)}</div>` : ''}
                        <div class="d-flex gap-2 flex-wrap">
                            ${update.repository_url ? `<a href="${escapeAttribute(update.repository_url)}" target="_blank" rel="noopener" class="btn btn-sm btn-outline-primary">Repository</a>` : ''}
                            ${update.pull_request_url ? `<a href="${escapeAttribute(update.pull_request_url)}" target="_blank" rel="noopener" class="btn btn-sm btn-outline-primary">Pull Request</a>` : ''}
                        </div>
                    </div>
                `;
            });
            $('#gitUpdatesList').html(html || '<p class="text-muted">No Git updates yet</p>');
        },
        error: function(xhr) {
            const message = xhr.responseJSON?.error || 'Unable to load Git history';
            $('#gitUpdatesList').html(`<p class="text-danger mb-0">${escapeHtml(message)}</p>`);
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
            alert('Comment added successfully');
        }
    });
}

function addGitUpdate(taskId) {
    const branchName = $('#gitBranchName').val().trim();

    if(!branchName) {
        alert('Branch name is required for Git updates.');
        return;
    }

    $.ajax({
        url: '../../api/add-task-git-update.php',
        method: 'POST',
        dataType: 'json',
        data: {
            task_id: taskId,
            git_action: $('#gitAction').val(),
            branch_name: branchName,
            commit_hash: $('#gitCommitHash').val().trim(),
            commit_message: $('#gitCommitMessage').val().trim(),
            pull_request_url: $('#gitPullRequestUrl').val().trim(),
            repository_url: $('#gitRepositoryUrl').val().trim(),
            status: $('#gitStatus').val(),
            notes: $('#gitNotes').val().trim()
        },
        success: function() {
            $('#gitCommitHash, #gitCommitMessage, #gitPullRequestUrl, #gitRepositoryUrl, #gitNotes').val('');
            loadGitUpdates(taskId);
            viewTask(taskId);
        },
        error: function(xhr) {
            alert(xhr.responseJSON?.error || 'Failed to save Git update');
        }
    });
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text ?? '';
    return div.innerHTML;
}

function escapeAttribute(text) {
    return escapeHtml(text).replace(/"/g, '&quot;');
}

function shortCommit(hash) {
    return (hash || '').slice(0, 7);
}

function formatDateTime(value) {
    return value ? new Date(value).toLocaleString() : 'Unknown date';
}

function formatGitAction(action) {
    const labels = {
        branch_created: 'Branch Created',
        commit_pushed: 'Commit Pushed',
        pull_request_opened: 'Pull Request Opened',
        merged: 'Merged',
        deployed: 'Deployed',
        note: 'General Note'
    };
    return labels[action] || 'Git Update';
}

function formatGitStatusBadge(status) {
    const labels = {
        active: 'Active',
        in_review: 'In Review',
        merged: 'Merged',
        closed: 'Closed'
    };
    const colors = {
        active: 'success',
        in_review: 'warning text-dark',
        merged: 'primary',
        closed: 'secondary'
    };
    const label = labels[status] || 'Active';
    const color = colors[status] || 'secondary';
    return `<span class="badge bg-${color}">${label}</span>`;
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

const selectedTaskId = new URLSearchParams(window.location.search).get('id');
if(selectedTaskId) {
    viewTask(selectedTaskId);
}
</script>

<?php include '../../includes/footer.php'; ?>
