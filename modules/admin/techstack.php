<?php
require_once '../../includes/auth.php';
requireRole('admin');

$conn = getConnection();
$user = getCurrentUser();

// Handle technology operations
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['add_technology'])) {
        $name = $_POST['name'];
        $category = $_POST['category'];
        $price = $_POST['price'];
        $expiry_date = $_POST['expiry_date'];
        
        // Determine status based on expiry date
        $today = date('Y-m-d');
        if ($expiry_date < $today) {
            $status = 'expired';
        } elseif ($expiry_date <= date('Y-m-d', strtotime('+30 days'))) {
            $status = 'expiring';
        } else {
            $status = 'active';
        }
        
        $stmt = $conn->prepare("INSERT INTO technologies (name, category, price, expiry_date, status, created_by) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("ssdssi", $name, $category, $price, $expiry_date, $status, $user['id']);
        
        if ($stmt->execute()) {
            logActivity($user['id'], 'add_technology', 'technologies', $stmt->insert_id, null, $_POST);
            $_SESSION['success'] = "Technology added successfully";
        }
    } elseif (isset($_POST['update_technology'])) {
        $id = $_POST['id'];
        $name = $_POST['name'];
        $category = $_POST['category'];
        $price = $_POST['price'];
        $expiry_date = $_POST['expiry_date'];
        
        // Get old data
        $oldData = $conn->query("SELECT * FROM technologies WHERE id = $id")->fetch_assoc();
        
        // Determine status
        $today = date('Y-m-d');
        if ($expiry_date < $today) {
            $status = 'expired';
        } elseif ($expiry_date <= date('Y-m-d', strtotime('+30 days'))) {
            $status = 'expiring';
        } else {
            $status = 'active';
        }
        
        $stmt = $conn->prepare("UPDATE technologies SET name=?, category=?, price=?, expiry_date=?, status=? WHERE id=?");
        $stmt->bind_param("ssdssi", $name, $category, $price, $expiry_date, $status, $id);
        
        if ($stmt->execute()) {
            logActivity($user['id'], 'update_technology', 'technologies', $id, $oldData, $_POST);
            $_SESSION['success'] = "Technology updated successfully";
        }
    } elseif (isset($_POST['delete_technology'])) {
        $id = $_POST['id'];
        $oldData = $conn->query("SELECT * FROM technologies WHERE id = $id")->fetch_assoc();
        
        $stmt = $conn->prepare("DELETE FROM technologies WHERE id=?");
        $stmt->bind_param("i", $id);
        
        if ($stmt->execute()) {
            logActivity($user['id'], 'delete_technology', 'technologies', $id, $oldData, null);
            $_SESSION['success'] = "Technology deleted successfully";
        }
    }
    
    header('Location: techstack.php');
    exit();
}

// Get all technologies
$technologies = $conn->query("SELECT * FROM technologies ORDER BY expiry_date ASC");

include '../../includes/header.php';
include '../../includes/sidebar.php';
include '../../includes/navbar.php';
?>

<style>
.pricing-card {
    border: 1px solid #dee2e6;
    border-radius: 8px;
    padding: 20px;
    margin-bottom: 20px;
    transition: transform 0.2s;
}
.pricing-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 5px 15px rgba(0,0,0,0.1);
}
.status-badge {
    position: absolute;
    top: 10px;
    right: 10px;
}
.price {
    font-size: 24px;
    font-weight: bold;
    color: #0d6efd;
}
.category-icon {
    font-size: 40px;
    color: #0d6efd;
}
</style>

<div class="row mb-4">
    <div class="col-12">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5>Technology Stack Management</h5>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addTechnologyModal">
                    <i class="fas fa-plus"></i> Add Technology/Service
                </button>
            </div>
            <div class="card-body">
                <div class="row">
                    <?php while($tech = $technologies->fetch_assoc()): ?>
                    <div class="col-md-4">
                        <div class="pricing-card position-relative">
                            <div class="status-badge">
                                <span class="badge bg-<?php 
                                    echo $tech['status'] == 'active' ? 'success' : 
                                        ($tech['status'] == 'expiring' ? 'warning' : 'danger'); 
                                ?>">
                                    <?php echo ucfirst($tech['status']); ?>
                                </span>
                            </div>
                            <div class="text-center mb-3">
                                <i class="fas fa-cloud category-icon"></i>
                                <h5 class="mt-2"><?php echo htmlspecialchars($tech['name']); ?></h5>
                                <p class="text-muted"><?php echo htmlspecialchars($tech['category']); ?></p>
                            </div>
                            <div class="text-center">
                                <div class="price">$<?php echo number_format($tech['price'], 2); ?></div>
                                <small class="text-muted">per month</small>
                            </div>
                            <div class="mt-3">
                                <small class="text-muted">
                                    <i class="fas fa-calendar-alt"></i> 
                                    Expires: <?php echo date('M d, Y', strtotime($tech['expiry_date'])); ?>
                                </small>
                            </div>
                            <div class="mt-3">
                                <button class="btn btn-sm btn-primary" onclick="editTechnology(<?php echo htmlspecialchars(json_encode($tech)); ?>)">
                                    <i class="fas fa-edit"></i> Edit
                                </button>
                                <button class="btn btn-sm btn-danger" onclick="deleteTechnology(<?php echo $tech['id']; ?>, '<?php echo htmlspecialchars($tech['name']); ?>')">
                                    <i class="fas fa-trash"></i> Delete
                                </button>
                                <?php if($tech['status'] == 'expiring'): ?>
                                <button class="btn btn-sm btn-success" onclick="renewTechnology(<?php echo $tech['id']; ?>)">
                                    <i class="fas fa-sync-alt"></i> Renew
                                </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endwhile; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Add Technology Modal -->
<div class="modal fade" id="addTechnologyModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Add New Technology/Service</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Technology/Service Name</label>
                        <input type="text" name="name" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Category</label>
                        <select name="category" class="form-select" required>
                            <option value="Cloud Platform">Cloud Platform</option>
                            <option value="Database">Database</option>
                            <option value="Storage">Storage</option>
                            <option value="Networking">Networking</option>
                            <option value="AI/ML">AI/ML</option>
                            <option value="IoT">IoT</option>
                            <option value="DevOps">DevOps</option>
                            <option value="Security">Security</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Monthly Price ($)</label>
                        <input type="number" name="price" class="form-control" step="0.01" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Expiry Date</label>
                        <input type="date" name="expiry_date" class="form-control" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="add_technology" class="btn btn-primary">Add Technology</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Technology Modal -->
<div class="modal fade" id="editTechnologyModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit Technology/Service</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="id" id="edit_id">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Technology/Service Name</label>
                        <input type="text" name="name" id="edit_name" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Category</label>
                        <select name="category" id="edit_category" class="form-select" required>
                            <option value="Cloud Platform">Cloud Platform</option>
                            <option value="Database">Database</option>
                            <option value="Storage">Storage</option>
                            <option value="Networking">Networking</option>
                            <option value="AI/ML">AI/ML</option>
                            <option value="IoT">IoT</option>
                            <option value="DevOps">DevOps</option>
                            <option value="Security">Security</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Monthly Price ($)</label>
                        <input type="number" name="price" id="edit_price" class="form-control" step="0.01" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Expiry Date</label>
                        <input type="date" name="expiry_date" id="edit_expiry_date" class="form-control" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="update_technology" class="btn btn-primary">Update Technology</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function editTechnology(tech) {
    document.getElementById('edit_id').value = tech.id;
    document.getElementById('edit_name').value = tech.name;
    document.getElementById('edit_category').value = tech.category;
    document.getElementById('edit_price').value = tech.price;
    document.getElementById('edit_expiry_date').value = tech.expiry_date;
    
    new bootstrap.Modal(document.getElementById('editTechnologyModal')).show();
}

function deleteTechnology(id, name) {
    if (confirm('Are you sure you want to delete ' + name + '? This action cannot be undone.')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="delete_technology" value="1">
            <input type="hidden" name="id" value="${id}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

function renewTechnology(id) {
    if (confirm('Renew this technology/service? This will extend the expiry date by 1 year.')) {
        // AJAX call to renew technology
        $.ajax({
            url: '../../api/renew-technology.php',
            method: 'POST',
            data: { id: id },
            success: function(response) {
                location.reload();
            }
        });
    }
}
</script>

<?php
include '../../includes/footer.php';
?>
