<?php
require_once '../../includes/auth.php';
require_once '../../includes/workflow.php';
requireRole('admin');

$conn = getConnection();
workflowEnsureSchema($conn);
$user = getCurrentUser();

// Handle user creation
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] == 'create') {
        $username = $_POST['username'];
        $email = $_POST['email'];
        $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
        $full_name = $_POST['full_name'];
        $department = $_POST['department'];
        $role = $_POST['role'];
        $hasPasswordChangedAt = ensureUserPasswordSecurityColumns();
        
        $sql = $hasPasswordChangedAt
            ? "INSERT INTO users (username, email, password, full_name, department, role, password_changed_at) VALUES (?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)"
            : "INSERT INTO users (username, email, password, full_name, department, role) VALUES (?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ssssss", $username, $email, $password, $full_name, $department, $role);
        
        if ($stmt->execute()) {
            $userId = $stmt->insert_id;
            logActivity($user['id'], 'create_user', 'users', $userId, null, $_POST);
            $_SESSION['success'] = "User created successfully";
        } else {
            $_SESSION['error'] = "Error creating user: " . $conn->error;
        }
    } elseif ($_POST['action'] == 'update') {
        $id = $_POST['id'];
        $full_name = $_POST['full_name'];
        $department = $_POST['department'];
        $role = $_POST['role'];
        $status = $_POST['status'];
        
        $oldData = $conn->query("SELECT * FROM users WHERE id = $id")->fetch_assoc();
        
        $stmt = $conn->prepare("UPDATE users SET full_name=?, department=?, role=?, status=? WHERE id=?");
        $stmt->bind_param("ssssi", $full_name, $department, $role, $status, $id);
        
        if ($stmt->execute()) {
            logActivity($user['id'], 'update_user', 'users', $id, $oldData, $_POST);
            $_SESSION['success'] = "User updated successfully";
        } else {
            $_SESSION['error'] = "Error updating user";
        }
    } elseif ($_POST['action'] == 'delete') {
        $id = $_POST['id'];
        
        $oldData = $conn->query("SELECT * FROM users WHERE id = $id")->fetch_assoc();
        
        $stmt = $conn->prepare("DELETE FROM users WHERE id=?");
        $stmt->bind_param("i", $id);
        
        if ($stmt->execute()) {
            logActivity($user['id'], 'delete_user', 'users', $id, $oldData, null);
            $_SESSION['success'] = "User deleted successfully";
        } else {
            $_SESSION['error'] = "Error deleting user";
        }
    }
    
    header('Location: user-management.php');
    exit();
}

// Get all users
$search = isset($_GET['search']) ? $_GET['search'] : '';
$roleFilter = isset($_GET['role']) ? $_GET['role'] : '';
$statusFilter = isset($_GET['status']) ? $_GET['status'] : '';

$query = "SELECT * FROM users WHERE 1=1";
if ($search) {
    $query .= " AND (username LIKE '%$search%' OR email LIKE '%$search%' OR full_name LIKE '%$search%')";
}
if ($roleFilter) {
    $query .= " AND role = '$roleFilter'";
}
if ($statusFilter) {
    $query .= " AND status = '$statusFilter'";
}
$query .= " ORDER BY created_at DESC";

$users = $conn->query($query);

// Get statistics
$totalUsers = $conn->query("SELECT COUNT(*) as count FROM users")->fetch_assoc()['count'];
$activeUsers = $conn->query("SELECT COUNT(*) as count FROM users WHERE status = 'active'")->fetch_assoc()['count'];
$adminUsers = $conn->query("SELECT COUNT(*) as count FROM users WHERE role = 'admin'")->fetch_assoc()['count'];

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
    --shadow-lg: 0 10px 15px -3px rgba(0,0,0,0.05), 0 4px 6px -2px rgba(0,0,0,0.02);
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

.card-header-modern .text-muted {
    color: var(--gray-600) !important;
}

/* Filter Section */
.filter-section {
    padding: 1.5rem 2rem;
    background: var(--gray-50);
    border-bottom: 1px solid var(--gray-200);
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

.user-table {
    width: 100%;
    border-collapse: collapse;
}

.user-table thead {
    background: var(--gray-50);
}

.user-table th {
    padding: 1rem 1.5rem;
    text-align: left;
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: var(--gray-600);
    border-bottom: 1px solid var(--gray-200);
}

.user-table td {
    padding: 1rem 1.5rem;
    vertical-align: middle;
    border-bottom: 1px solid var(--gray-200);
}

.user-table tr:hover {
    background: var(--gray-50);
}

.user-name {
    font-weight: 600;
    color: var(--gray-900);
    margin-bottom: 0.25rem;
}

.user-email {
    font-size: 0.75rem;
    color: var(--gray-600);
}

/* Badges */
.badge-role {
    display: inline-block;
    padding: 0.25rem 0.75rem;
    border-radius: 20px;
    font-size: 0.7rem;
    font-weight: 600;
    text-transform: uppercase;
}

.badge-role.admin {
    background: #fee2e2;
    color: #dc2626;
}

.badge-role.sales {
    background: #dbeafe;
    color: #2563eb;
}

.badge-role.architect {
    background: #e0e7ff;
    color: #4f46e5;
}

.badge-role.sul {
    background: #fef3c7;
    color: #d97706;
}

.badge-role.qa {
    background: #cffafe;
    color: #0e7490;
}

.badge-role.expert {
    background: #d1fae5;
    color: #059669;
}

.badge-role.developer {
    background: #e9ecef;
    color: #495057;
}

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

.badge-status.suspended {
    background: #fee2e2;
    color: #dc2626;
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

/* Primary Button (Solid) */
.btn-primary-modern {
    background: linear-gradient(135deg, var(--theme-primary) 0%, var(--theme-primary-dark) 100%);
    color: white;
    border: none;
    padding: 0.6rem 1.4rem;
    border-radius: 12px;
    font-weight: 600;
    font-size: 0.85rem;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    transition: all 0.2s ease;
    cursor: pointer;
}

.btn-primary-modern:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(31, 99, 146, 0.3);
    color: white;
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
.modal-modern .form-select {
    border-radius: 12px;
    border: 1px solid var(--gray-300);
    padding: 0.6rem 1rem;
}

.modal-modern .form-control:focus,
.modal-modern .form-select:focus {
    border-color: var(--theme-primary);
    box-shadow: 0 0 0 3px rgba(31, 99, 146, 0.1);
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
    
    .filter-input {
        width: 100%;
    }
    
    .user-table th,
    .user-table td {
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
                    <i class="fas fa-users me-2"></i> 
                    User Management
                </h2>
                <p>Manage user accounts, roles, and permissions across your organization</p>
            </div>
            <div class="mt-3 mt-md-0">
                <button class="btn-add-modern" data-bs-toggle="modal" data-bs-target="#createUserModal">
                    <i class="fas fa-plus me-2"></i> Add New User
                </button>
            </div>
        </div>
    </div>

    <!-- Statistics Cards -->
    <div class="stats-row">
        <div class="stat-card-modern">
            <i class="fas fa-users stat-icon"></i>
            <div class="stat-label">Total Users</div>
            <div class="stat-number"><?php echo $totalUsers; ?></div>
        </div>
        <div class="stat-card-modern">
            <i class="fas fa-user-check stat-icon"></i>
            <div class="stat-label">Active Users</div>
            <div class="stat-number"><?php echo $activeUsers; ?></div>
        </div>
        <div class="stat-card-modern">
            <i class="fas fa-user-shield stat-icon"></i>
            <div class="stat-label">Administrators</div>
            <div class="stat-number"><?php echo $adminUsers; ?></div>
        </div>
    </div>

    <!-- Main Content Card -->
    <div class="main-card">
        <div class="card-header-modern">
            <h5>
                <i class="fas fa-list me-2" style="color: var(--theme-primary);"></i>
                User Directory
            </h5>
            <div>
                <span class="text-muted small">Showing <?php echo $users->num_rows; ?> users</span>
            </div>
        </div>
        
        <!-- Filter Section -->
        <div class="filter-section">
            <div class="filter-group">
                <div class="filter-input">
                    <label>Search</label>
                    <input type="text" id="searchInput" placeholder="Name, email, or username..." value="<?php echo htmlspecialchars($search); ?>">
                </div>
                <div class="filter-input">
                    <label>Role</label>
                    <select id="roleFilter">
                        <option value="">All Roles</option>
                        <option value="admin" <?php echo $roleFilter == 'admin' ? 'selected' : ''; ?>>Admin</option>
                        <option value="sales" <?php echo $roleFilter == 'sales' ? 'selected' : ''; ?>>Sales</option>
                        <option value="architect" <?php echo $roleFilter == 'architect' ? 'selected' : ''; ?>>Architect</option>
                        <option value="sul" <?php echo $roleFilter == 'sul' ? 'selected' : ''; ?>>SUL</option>
                        <option value="qa" <?php echo $roleFilter == 'qa' ? 'selected' : ''; ?>>QA</option>
                        <option value="expert" <?php echo $roleFilter == 'expert' ? 'selected' : ''; ?>>Expert/Senior</option>
                        <option value="developer" <?php echo $roleFilter == 'developer' ? 'selected' : ''; ?>>Developer</option>
                    </select>
                </div>
                <div class="filter-input">
                    <label>Status</label>
                    <select id="statusFilter">
                        <option value="">All Status</option>
                        <option value="active" <?php echo $statusFilter == 'active' ? 'selected' : ''; ?>>Active</option>
                        <option value="inactive" <?php echo $statusFilter == 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                        <option value="suspended" <?php echo $statusFilter == 'suspended' ? 'selected' : ''; ?>>Suspended</option>
                    </select>
                </div>
                <div>
                    <button class="btn-apply" onclick="applyFilters()">Apply Filters</button>
                </div>
            </div>
        </div>
        
        <!-- Users Table -->
        <div class="table-container">
            <?php if($users->num_rows > 0): ?>
            <table class="user-table">
                <thead>
                    <tr>
                        <th>User</th>
                        <th>Department</th>
                        <th>Role</th>
                        <th>Status</th>
                        <th>Created</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while($row = $users->fetch_assoc()): ?>
                    <tr>
                        <td>
                            <div class="user-name"><?php echo htmlspecialchars($row['full_name']); ?></div>
                            <div class="user-email"><?php echo htmlspecialchars($row['email']); ?></div>
                        </td>
                        <td><?php echo htmlspecialchars($row['department'] ?: '—'); ?></td>
                        <td>
                            <span class="badge-role <?php echo $row['role']; ?>">
                                <?php echo strtoupper($row['role']); ?>
                            </span>
                        </td>
                        <td>
                            <span class="badge-status <?php echo $row['status']; ?>">
                                <?php echo ucfirst($row['status']); ?>
                            </span>
                        </td>
                        <td><?php echo date('M d, Y', strtotime($row['created_at'])); ?></td>
                        <td>
                            <div class="action-buttons">
                                <button class="btn-icon view" onclick="viewUser(<?php echo $row['id']; ?>)">
                                    <i class="fas fa-eye"></i>
                                </button>
                                <button class="btn-icon edit" onclick="editUser(<?php echo $row['id']; ?>)">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <button class="btn-icon delete" onclick="deleteUser(<?php echo $row['id']; ?>, '<?php echo htmlspecialchars($row['full_name']); ?>')">
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
                <i class="fas fa-user-friends"></i>
                <h4>No Users Found</h4>
                <p>Try adjusting your filters or add a new user</p>
                <button class="btn-add-modern mt-2" data-bs-toggle="modal" data-bs-target="#createUserModal">
                    <i class="fas fa-plus me-2"></i> Add Your First User
                </button>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Create User Modal -->
<div class="modal fade modal-modern" id="createUserModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-user-plus me-2"></i> Create New User
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="create">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Username</label>
                            <input type="text" name="username" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Email</label>
                            <input type="email" name="email" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Password</label>
                            <input type="password" name="password" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Full Name</label>
                            <input type="text" name="full_name" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Department</label>
                            <select name="department" class="form-select">
                                <option value="">Select Department</option>
                                <?php foreach (getDepartmentOptions() as $department): ?>
                                    <option value="<?php echo htmlspecialchars($department); ?>">
                                        <?php echo htmlspecialchars($department); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Role</label>
                            <select name="role" class="form-select" required>
                                <option value="sales">Sales</option>
                                <option value="architect">Architect</option>
                                <option value="sul">Service Unit Leader</option>
                                <option value="qa">QA</option>
                                <option value="expert">Expert/Senior</option>
                                <option value="developer">Developer</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn-primary-modern">Create User</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function applyFilters() {
    const search = document.getElementById('searchInput').value;
    const role = document.getElementById('roleFilter').value;
    const status = document.getElementById('statusFilter').value;
    
    let url = 'user-management.php?';
    if (search) url += 'search=' + encodeURIComponent(search) + '&';
    if (role) url += 'role=' + encodeURIComponent(role) + '&';
    if (status) url += 'status=' + encodeURIComponent(status);
    
    window.location.href = url;
}

function viewUser(id) {
    window.location.href = 'view-user.php?id=' + id;
}

function editUser(id) {
    window.location.href = 'edit-user.php?id=' + id;
}

function deleteUser(id, name) {
    if (confirm('Are you sure you want to delete user: ' + name + '? This action cannot be undone.')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="${id}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

// Auto-submit on enter in search
document.getElementById('searchInput').addEventListener('keypress', function(e) {
    if (e.key === 'Enter') {
        applyFilters();
    }
});
</script>

<?php
include '../../includes/footer.php';
?>
