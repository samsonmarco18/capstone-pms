<?php
require_once '../../includes/auth.php';
requireRole('sales');

$conn = getConnection();
$user = getCurrentUser();

// Handle client operations
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['add_client'])) {
        $name = $_POST['name'];
        $contact_person = $_POST['contact_person'];
        $email = $_POST['email'];
        $phone = $_POST['phone'];
        $address = $_POST['address'];
        $deadline = $_POST['deadline'];
        
        $stmt = $conn->prepare("INSERT INTO clients (name, contact_person, email, phone, address, deadline, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("ssssssi", $name, $contact_person, $email, $phone, $address, $deadline, $user['id']);
        
        if ($stmt->execute()) {
            $clientId = $stmt->insert_id;
            logActivity($user['id'], 'add_client', 'clients', $clientId, null, $_POST);
            $_SESSION['success'] = "Client added successfully";
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
        
        $oldData = $conn->query("SELECT * FROM clients WHERE id = $id")->fetch_assoc();
        
        $stmt = $conn->prepare("UPDATE clients SET name=?, contact_person=?, email=?, phone=?, address=?, status=?, deadline=? WHERE id=?");
        $stmt->bind_param("sssssssi", $name, $contact_person, $email, $phone, $address, $status, $deadline, $id);
        
        if ($stmt->execute()) {
            logActivity($user['id'], 'update_client', 'clients', $id, $oldData, $_POST);
            $_SESSION['success'] = "Client updated successfully";
        }
    } elseif (isset($_POST['delete_client'])) {
        $id = $_POST['id'];
        $oldData = $conn->query("SELECT * FROM clients WHERE id = $id")->fetch_assoc();
        
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

$query = "SELECT * FROM clients WHERE 1=1";
if ($search) {
    $query .= " AND (name LIKE '%$search%' OR email LIKE '%$search%' OR contact_person LIKE '%$search%')";
}
if ($statusFilter) {
    $query .= " AND status = '$statusFilter'";
}
$query .= " ORDER BY created_at DESC";

$clients = $conn->query($query);

include '../../includes/header.php';
include '../../includes/sidebar.php';
include '../../includes/navbar.php';
?>

<div class="row mb-4">
    <div class="col-12">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5>Client Management</h5>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addClientModal">
                    <i class="fas fa-plus"></i> Add New Client
                </button>
            </div>
            <div class="card-body">
                <!-- Search and Filter -->
                <div class="row mb-3">
                    <div class="col-md-6">
                        <input type="text" id="searchInput" class="form-control" placeholder="Search clients..." value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                    <div class="col-md-4">
                        <select id="statusFilter" class="form-select">
                            <option value="">All Status</option>
                            <option value="active" <?php echo $statusFilter == 'active' ? 'selected' : ''; ?>>Active</option>
                            <option value="inactive" <?php echo $statusFilter == 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                            <option value="pending" <?php echo $statusFilter == 'pending' ? 'selected' : ''; ?>>Pending</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <button class="btn btn-primary w-100" onclick="applyFilters()">Apply Filters</button>
                    </div>
                </div>
                
                <!-- Clients Table -->
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Client Name</th>
                                <th>Contact Person</th>
                                <th>Email</th>
                                <th>Status</th>
                                <th>Progress</th>
                                <th>Deadline</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while($client = $clients->fetch_assoc()): ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($client['name']); ?></strong>
                                </td>
                                <td><?php echo htmlspecialchars($client['contact_person']); ?></td>
                                <td><?php echo htmlspecialchars($client['email']); ?></td>
                                <td>
                                    <span class="badge bg-<?php 
                                        echo $client['status'] == 'active' ? 'success' : 
                                            ($client['status'] == 'inactive' ? 'secondary' : 'warning');
                                    ?>">
                                        <?php echo ucfirst($client['status']); ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="progress" style="height: 20px;">
                                        <div class="progress-bar bg-info" style="width: <?php echo $client['progress']; ?>%">
                                            <?php echo $client['progress']; ?>%
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <?php 
                                    if($client['deadline']) {
                                        $daysLeft = ceil((strtotime($client['deadline']) - time()) / (60 * 60 * 24));
                                        $badgeClass = $daysLeft <= 7 ? 'danger' : ($daysLeft <= 30 ? 'warning' : 'info');
                                        echo date('M d, Y', strtotime($client['deadline']));
                                        echo "<br><small class='text-{$badgeClass}'>{$daysLeft} days left</small>";
                                    } else {
                                        echo 'Not set';
                                    }
                                    ?>
                                </td>
                                <td>
                                    <button class="btn btn-sm btn-info" onclick="viewClient(<?php echo $client['id']; ?>)">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                    <button class="btn btn-sm btn-primary" onclick="editClient(<?php echo htmlspecialchars(json_encode($client)); ?>)">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <button class="btn btn-sm btn-danger" onclick="deleteClient(<?php echo $client['id']; ?>, '<?php echo htmlspecialchars($client['name']); ?>')">
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

<!-- Add Client Modal -->
<div class="modal fade" id="addClientModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Add New Client</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Company Name</label>
                            <input type="text" name="name" class="form-control" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Contact Person</label>
                            <input type="text" name="contact_person" class="form-control" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Email</label>
                            <input type="email" name="email" class="form-control" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Phone</label>
                            <input type="text" name="phone" class="form-control" required>
                        </div>
                        <div class="col-12 mb-3">
                            <label class="form-label">Address</label>
                            <textarea name="address" class="form-control" rows="2"></textarea>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Project Deadline</label>
                            <input type="date" name="deadline" class="form-control">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="add_client" class="btn btn-primary">Add Client</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Client Modal -->
<div class="modal fade" id="editClientModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit Client</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
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
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="update_client" class="btn btn-primary">Update Client</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- View Client Modal -->
<div class="modal fade" id="viewClientModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Client Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="viewClientContent">
                <!-- Content loaded via AJAX -->
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
            const client = JSON.parse(response);
            let html = `
                <div class="row">
                    <div class="col-md-6">
                        <p><strong>Company Name:</strong> ${client.name}</p>
                        <p><strong>Contact Person:</strong> ${client.contact_person}</p>
                        <p><strong>Email:</strong> ${client.email}</p>
                        <p><strong>Phone:</strong> ${client.phone}</p>
                    </div>
                    <div class="col-md-6">
                        <p><strong>Status:</strong> <span class="badge bg-${client.status == 'active' ? 'success' : 'secondary'}">${client.status}</span></p>
                        <p><strong>Progress:</strong> ${client.progress}%</p>
                        <p><strong>Deadline:</strong> ${client.deadline ? new Date(client.deadline).toLocaleDateString() : 'Not set'}</p>
                        <p><strong>Address:</strong> ${client.address}</p>
                    </div>
                </div>
                <hr>
                <h6>Associated Projects</h6>
                <div id="clientProjects"></div>
            `;
            $('#viewClientContent').html(html);
            
            // Load projects for this client
            $.ajax({
                url: '../../api/get-client-projects.php',
                method: 'GET',
                data: { client_id: id },
                success: function(projectsResponse) {
                    const projects = JSON.parse(projectsResponse);
                    let projectsHtml = '<div class="list-group">';
                    projects.forEach(project => {
                        projectsHtml += `
                            <div class="list-group-item">
                                <div class="d-flex justify-content-between">
                                    <strong>${project.name}</strong>
                                    <span class="badge bg-${project.status == 'completed' ? 'success' : 'warning'}">${project.status}</span>
                                </div>
                                <small>Created: ${new Date(project.created_at).toLocaleDateString()}</small>
                            </div>
                        `;
                    });
                    projectsHtml += '</div>';
                    $('#clientProjects').html(projectsHtml || '<p class="text-muted">No projects yet</p>');
                }
            });
            
            new bootstrap.Modal(document.getElementById('viewClientModal')).show();
        }
    });
}

function deleteClient(id, name) {
    if (confirm(`WARNING: This will delete client "${name}" and ALL associated projects, tasks, and files. This action cannot be undone.\n\nAre you sure you want to proceed?`)) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="delete_client" value="1">
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

<?php include '../../includes/footer.php'; ?>