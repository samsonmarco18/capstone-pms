<?php
require_once '../../includes/auth.php';
requireRole('admin');

$conn = getConnection();
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
        
        $stmt = $conn->prepare("INSERT INTO users (username, email, password, full_name, department, role) VALUES (?, ?, ?, ?, ?, ?)");
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
        
        // Get old data for audit
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
        
        // Get old data for audit
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

include '../../includes/header.php';
include '../../includes/sidebar.php';
include '../../includes/navbar.php';
?>

<div class="row mb-4">
    <div class="col-12">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5>User Management</h5>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createUserModal">
                    <i class="fas fa-plus"></i> Add New User
                </button>
            </div>
            <div class="card-body">
                <!-- Search and Filter -->
                <div class="row mb-3">
                    <div class="col-md-4">
                        <input type="text" id="searchInput" class="form-control" placeholder="Search users..." value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                    <div class="col-md-3">
                        <select id="roleFilter" class="form-select">
                            <option value="">All Roles</option>
                            <option value="admin" <?php echo $roleFilter == 'admin' ? 'selected' : ''; ?>>Admin</option>
                            <option value="sales" <?php echo $roleFilter == 'sales' ? 'selected' : ''; ?>>Sales</option>
                            <option value="architect" <?php echo $roleFilter == 'architect' ? 'selected' : ''; ?>>Architect</option>
                            <option value="sul" <?php echo $roleFilter == 'sul' ? 'selected' : ''; ?>>SUL</option>
                            <option value="expert" <?php echo $roleFilter == 'expert' ? 'selected' : ''; ?>>Expert/Senior</option>
                            <option value="developer" <?php echo $roleFilter == 'developer' ? 'selected' : ''; ?>>Developer</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <select id="statusFilter" class="form-select">
                            <option value="">All Status</option>
                            <option value="active" <?php echo $statusFilter == 'active' ? 'selected' : ''; ?>>Active</option>
                            <option value="inactive" <?php echo $statusFilter == 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                            <option value="suspended" <?php echo $statusFilter == 'suspended' ? 'selected' : ''; ?>>Suspended</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <button class="btn btn-primary w-100" onclick="applyFilters()">Apply Filters</button>
                    </div>
                </div>
                
                <!-- Users Table -->
                <div class="table-responsive">
                    <table class="table table-hover">
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
                                    <strong><?php echo htmlspecialchars($row['full_name']); ?></strong><br>
                                    <small class="text-muted"><?php echo htmlspecialchars($row['email']); ?></small>
                                </td>
                                <td><?php echo htmlspecialchars($row['department']); ?></td>
                                <td>
                                    <span class="badge bg-<?php 
                                        echo $row['role'] == 'admin' ? 'danger' : 
                                            ($row['role'] == 'sales' ? 'info' : 
                                            ($row['role'] == 'architect' ? 'primary' : 
                                            ($row['role'] == 'sul' ? 'warning' : 
                                            ($row['role'] == 'expert' ? 'success' : 'secondary')))); 
                                    ?>">
                                        <?php echo strtoupper($row['role']); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge bg-<?php echo $row['status'] == 'active' ? 'success' : ($row['status'] == 'inactive' ? 'warning' : 'danger'); ?>">
                                        <?php echo ucfirst($row['status']); ?>
                                    </span>
                                </td>
                                <td><?php echo date('M d, Y', strtotime($row['created_at'])); ?></td>
                                <td>
                                    <button class="btn btn-sm btn-info" onclick="viewUser(<?php echo $row['id']; ?>)">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                    <button class="btn btn-sm btn-primary" onclick="editUser(<?php echo $row['id']; ?>)">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <button class="btn btn-sm btn-danger" onclick="deleteUser(<?php echo $row['id']; ?>, '<?php echo htmlspecialchars($row['full_name']); ?>')">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Create User Modal -->
<div class="modal fade" id="createUserModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Create New User</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="create">
                    <div class="mb-3">
                        <label class="form-label">Username</label>
                        <input type="text" name="username" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Password</label>
                        <input type="password" name="password" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Full Name</label>
                        <input type="text" name="full_name" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Department</label>
                        <input type="text" name="department" class="form-control">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Role</label>
                        <select name="role" class="form-select" required>
                            <option value="sales">Sales</option>
                            <option value="architect">Architect</option>
                            <option value="sul">Service Unit Leader</option>
                            <option value="expert">Expert/Senior</option>
                            <option value="developer">Developer</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Create User</button>
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