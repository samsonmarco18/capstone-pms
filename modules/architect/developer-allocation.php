<?php
require_once '../../includes/auth.php';
requireRole('architect');

$conn = getConnection();
$user = getCurrentUser();
$projectId = isset($_GET['id']) ? $_GET['id'] : 0;

$project = $conn->query("SELECT * FROM projects WHERE id = $projectId")->fetch_assoc();

// Get existing allocations
$existingAllocations = [];
$allocResult = $conn->query("SELECT * FROM developer_allocations WHERE project_id = $projectId");
while($row = $allocResult->fetch_assoc()) {
    $existingAllocations[$row['role']] = $row;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Clear existing allocations
    $conn->query("DELETE FROM developer_allocations WHERE project_id = $projectId");
    
    // Save new allocations
    if(isset($_POST['allocations'])) {
        foreach($_POST['allocations'] as $role => $data) {
            $requiredCount = $data['required'];
            $complexity = $data['complexity'];
            
            $stmt = $conn->prepare("INSERT INTO developer_allocations (project_id, role, required_count, complexity_level) VALUES (?, ?, ?, ?)");
            $stmt->bind_param("isis", $projectId, $role, $requiredCount, $complexity);
            $stmt->execute();
        }
    }
    
    logActivity($user['id'], 'developer_allocation', 'projects', $projectId, null, $_POST);
    
    header('Location: upload-diagram.php?id=' . $projectId);
    exit();
}

include '../../includes/header.php';
include '../../includes/sidebar.php';
include '../../includes/navbar.php';
?>

<div class="row">
    <div class="col-12 mb-4">
        <h2>Developer Allocation</h2>
        <p class="text-muted">Project: <?php echo htmlspecialchars($project['name']); ?></p>
    </div>
</div>

<div class="row">
    <div class="col-md-8">
        <div class="card">
            <div class="card-header">
                <h5>Team Requirements</h5>
            </div>
            <div class="card-body">
                <form method="POST" id="allocationForm">
                    <div class="mb-3">
                        <label class="form-label">Project Complexity Level</label>
                        <select class="form-select" id="complexityLevel" onchange="updateAllocation()">
                            <option value="low">Low - Simple application with basic features</option>
                            <option value="medium">Medium - Standard web/mobile application</option>
                            <option value="high">High - Complex system with advanced features</option>
                            <option value="critical">Critical - Enterprise-grade solution</option>
                        </select>
                    </div>
                    
                    <div id="allocationTable">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Role</th>
                                    <th>Required Count</th>
                                    <th>Available</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $roles = [
                                    'Frontend Developer' => ['min' => 1, 'max' => 5],
                                    'Backend Developer' => ['min' => 1, 'max' => 5],
                                    'Full Stack Developer' => ['min' => 0, 'max' => 3],
                                    'QA Engineer' => ['min' => 1, 'max' => 2],
                                    'UI/UX Designer' => ['min' => 1, 'max' => 2],
                                    'DevOps Engineer' => ['min' => 0, 'max' => 2],
                                    'Database Administrator' => ['min' => 0, 'max' => 1]
                                ];
                                
                                // Get available developers count
                                $availableDevs = [];
                                $devQuery = $conn->query("SELECT role, COUNT(*) as count FROM users WHERE role = 'developer' AND status = 'active' GROUP BY role");
                                while($dev = $devQuery->fetch_assoc()) {
                                    $availableDevs[$dev['role']] = $dev['count'];
                                }
                                
                                foreach($roles as $role => $limits):
                                    $existing = $existingAllocations[$role]['required_count'] ?? 0;
                                    $available = $availableDevs[$role] ?? 0;
                                    $isShortage = $existing > $available;
                                ?>
                                <tr>
                                    <td><?php echo $role; ?></td>
                                    <td>
                                        <input type="number" name="allocations[<?php echo $role; ?>][required]" 
                                               class="form-control form-control-sm required-count" 
                                               value="<?php echo $existing; ?>" 
                                               min="<?php echo $limits['min']; ?>" 
                                               max="<?php echo $limits['max']; ?>"
                                               onchange="checkAvailability(this, '<?php echo $role; ?>')">
                                        <input type="hidden" name="allocations[<?php echo $role; ?>][complexity]" value="medium">
                                    </td>
                                    <td>
                                        <span class="badge bg-info"><?php echo $available; ?> available</span>
                                    </td>
                                    <td class="status-cell">
                                        <?php if($existing > 0): ?>
                                            <span class="badge bg-<?php echo $isShortage ? 'danger' : 'success'; ?>">
                                                <?php echo $isShortage ? 'Shortage!' : 'Sufficient'; ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <div id="alertMessages" class="mt-3"></div>
                    
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-arrow-right"></i> Proceed to Upload Diagrams
                    </button>
                </form>
            </div>
        </div>
    </div>
    
    <div class="col-md-4">
        <div class="card">
            <div class="card-header">
                <h5>Resource Summary</h5>
            </div>
            <div class="card-body">
                <div id="resourceSummary">
                    <p class="text-muted">Select complexity level to see recommendations</p>
                </div>
            </div>
        </div>
        
        <div class="card mt-3">
            <div class="card-header">
                <h5>Allocation Guidelines</h5>
            </div>
            <div class="card-body">
                <ul class="list-unstyled">
                    <li><i class="fas fa-info-circle text-info me-2"></i> Low complexity: 2-3 developers</li>
                    <li><i class="fas fa-info-circle text-info me-2"></i> Medium complexity: 4-5 developers</li>
                    <li><i class="fas fa-info-circle text-info me-2"></i> High complexity: 6-8 developers</li>
                    <li><i class="fas fa-info-circle text-info me-2"></i> Critical: 8+ developers</li>
                    <li><i class="fas fa-exclamation-triangle text-warning me-2"></i> Red alerts indicate resource shortage</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<script>
function updateAllocation() {
    const complexity = document.getElementById('complexityLevel').value;
    const recommendations = {
        low: {
            'Frontend Developer': 1,
            'Backend Developer': 1,
            'Full Stack Developer': 0,
            'QA Engineer': 1,
            'UI/UX Designer': 1,
            'DevOps Engineer': 0,
            'Database Administrator': 0
        },
        medium: {
            'Frontend Developer': 2,
            'Backend Developer': 2,
            'Full Stack Developer': 1,
            'QA Engineer': 1,
            'UI/UX Designer': 1,
            'DevOps Engineer': 1,
            'Database Administrator': 1
        },
        high: {
            'Frontend Developer': 3,
            'Backend Developer': 3,
            'Full Stack Developer': 2,
            'QA Engineer': 2,
            'UI/UX Designer': 2,
            'DevOps Engineer': 2,
            'Database Administrator': 1
        },
        critical: {
            'Frontend Developer': 4,
            'Backend Developer': 4,
            'Full Stack Developer': 3,
            'QA Engineer': 3,
            'UI/UX Designer': 2,
            'DevOps Engineer': 3,
            'Database Administrator': 2
        }
    };
    
    const rec = recommendations[complexity];
    if (rec) {
        for (const [role, count] of Object.entries(rec)) {
            const input = document.querySelector(`input[name="allocations[${role}][required]"]`);
            if (input && !input.value) {
                input.value = count;
            }
        }
    }
    
    // Update complexity level in hidden inputs
    document.querySelectorAll('input[name$="[complexity]"]').forEach(input => {
        input.value = complexity;
    });
    
    checkAllAvailability();
}

function checkAvailability(input, role) {
    const required = parseInt(input.value) || 0;
    const available = parseInt(input.closest('tr').querySelector('.badge.bg-info').textContent) || 0;
    const statusCell = input.closest('tr').querySelector('.status-cell');
    
    if (required > 0) {
        if (required > available) {
            statusCell.innerHTML = '<span class="badge bg-danger">Shortage! Need ' + (required - available) + ' more</span>';
        } else {
            statusCell.innerHTML = '<span class="badge bg-success">Sufficient</span>';
        }
    } else {
        statusCell.innerHTML = '';
    }
    
    checkAllAvailability();
}

function checkAllAvailability() {
    let shortages = [];
    let totalRequired = 0;
    let totalAvailable = 0;
    
    document.querySelectorAll('.required-count').forEach(input => {
        const required = parseInt(input.value) || 0;
        const row = input.closest('tr');
        const availableText = row.querySelector('.badge.bg-info').textContent;
        const available = parseInt(availableText) || 0;
        const role = row.cells[0].textContent;
        
        totalRequired += required;
        totalAvailable += available;
        
        if (required > available) {
            shortages.push(`${role}: Need ${required - available} more (${available} available)`);
        }
    });
    
    const alertDiv = document.getElementById('alertMessages');
    const summaryDiv = document.getElementById('resourceSummary');
    
    if (shortages.length > 0) {
        alertDiv.innerHTML = `
            <div class="alert alert-warning">
                <i class="fas fa-exclamation-triangle"></i>
                <strong>Resource Shortages Detected:</strong>
                <ul class="mb-0 mt-2">
                    ${shortages.map(s => `<li>${s}</li>`).join('')}
                </ul>
                <small class="d-block mt-2">Consider adjusting allocation or requesting additional resources.</small>
            </div>
        `;
    } else {
        alertDiv.innerHTML = '';
    }
    
    // Update summary
    const utilization = totalRequired > 0 ? ((totalRequired / totalAvailable) * 100).toFixed(1) : 0;
    summaryDiv.innerHTML = `
        <div class="mb-3">
            <strong>Total Required:</strong> ${totalRequired} developers
        </div>
        <div class="mb-3">
            <strong>Total Available:</strong> ${totalAvailable} developers
        </div>
        <div class="mb-3">
            <strong>Utilization:</strong> ${utilization}%
            <div class="progress mt-1">
                <div class="progress-bar ${utilization > 100 ? 'bg-danger' : 'bg-success'}" 
                     style="width: ${Math.min(utilization, 100)}%"></div>
            </div>
        </div>
        ${totalRequired > totalAvailable ? 
            '<div class="alert alert-danger mb-0">⚠️ Resource shortage detected! Total required exceeds available resources.</div>' : 
            '<div class="alert alert-success mb-0">✓ Resource allocation looks good!</div>'
        }
    `;
}

// Initialize
updateAllocation();
</script>

<?php include '../../includes/footer.php'; ?>