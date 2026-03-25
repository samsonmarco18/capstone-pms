<?php
require_once '../../includes/auth.php';
requireRole('expert');

$conn = getConnection();
$user = getCurrentUser();

// Get projects assigned to this expert
$projects = $conn->query("
    SELECT DISTINCT p.* 
    FROM projects p
    JOIN tasks t ON p.id = t.project_id
    WHERE t.assigned_to = {$user['id']}
    ORDER BY p.created_at DESC
");

$selectedProject = isset($_GET['project_id']) ? $_GET['project_id'] : 0;

if($selectedProject) {
    // Get existing cost estimations
    $estimations = $conn->query("SELECT * FROM cost_estimations WHERE project_id = $selectedProject");
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $projectId = $_POST['project_id'];
    
    // Clear existing estimations
    $conn->query("DELETE FROM cost_estimations WHERE project_id = $projectId");
    
    // Save new estimations
    if(isset($_POST['components'])) {
        foreach($_POST['components'] as $component) {
            $stmt = $conn->prepare("INSERT INTO cost_estimations (project_id, component, azure_service, monthly_cost) VALUES (?, ?, ?, ?)");
            $stmt->bind_param("issd", $projectId, $component['name'], $component['service'], $component['cost']);
            $stmt->execute();
        }
    }
    
    logActivity($user['id'], 'cost_estimation', 'projects', $projectId, null, $_POST);
    $_SESSION['success'] = "Cost estimation saved successfully";
    
    header('Location: cost-estimation.php?project_id=' . $projectId);
    exit();
}

include '../../includes/header.php';
include '../../includes/sidebar.php';
include '../../includes/navbar.php';
?>

<div class="row">
    <div class="col-12 mb-4">
        <h2>Infrastructure Cost Estimation</h2>
        <p class="text-muted">Based on Azure Pricing Calculator</p>
    </div>
</div>

<div class="row">
    <div class="col-md-3 mb-4">
        <div class="card">
            <div class="card-header">
                <h5>Select Project</h5>
            </div>
            <div class="card-body">
                <div class="list-group">
                    <?php while($project = $projects->fetch_assoc()): ?>
                    <a href="?project_id=<?php echo $project['id']; ?>" 
                       class="list-group-item list-group-item-action <?php echo $selectedProject == $project['id'] ? 'active' : ''; ?>">
                        <?php echo htmlspecialchars($project['name']); ?>
                    </a>
                    <?php endwhile; ?>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-md-9">
        <?php if($selectedProject): ?>
        <div class="card">
            <div class="card-header">
                <h5>Azure Cost Breakdown</h5>
            </div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="project_id" value="<?php echo $selectedProject; ?>">
                    
                    <div id="componentsContainer">
                        <?php 
                        $components = [
                            ['name' => 'Web App', 'service' => 'Azure App Service', 'cost' => 100],
                            ['name' => 'API', 'service' => 'Azure API Management', 'cost' => 50],
                            ['name' => 'Database', 'service' => 'Azure SQL Database', 'cost' => 150],
                            ['name' => 'Storage', 'service' => 'Azure Blob Storage', 'cost' => 30],
                            ['name' => 'CDN', 'service' => 'Azure CDN', 'cost' => 20],
                            ['name' => 'Monitoring', 'service' => 'Azure Monitor', 'cost' => 15]
                        ];
                        
                        $existing = [];
                        while($est = $estimations->fetch_assoc()) {
                            $existing[$est['component']] = $est;
                        }
                        
                        foreach($components as $index => $comp): 
                        ?>
                        <div class="component-item mb-3 p-3 border rounded">
                            <div class="row">
                                <div class="col-md-3">
                                    <label class="form-label">Component</label>
                                    <input type="text" name="components[<?php echo $index; ?>][name]" 
                                           class="form-control" value="<?php echo $comp['name']; ?>" required>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Azure Service</label>
                                    <select name="components[<?php echo $index; ?>][service]" class="form-select" required>
                                        <option value="Azure App Service" <?php echo ($existing[$comp['name']]['azure_service'] ?? '') == 'Azure App Service' ? 'selected' : ''; ?>>Azure App Service</option>
                                        <option value="Azure Functions" <?php echo ($existing[$comp['name']]['azure_service'] ?? '') == 'Azure Functions' ? 'selected' : ''; ?>>Azure Functions</option>
                                        <option value="Azure SQL Database" <?php echo ($existing[$comp['name']]['azure_service'] ?? '') == 'Azure SQL Database' ? 'selected' : ''; ?>>Azure SQL Database</option>
                                        <option value="Azure Cosmos DB" <?php echo ($existing[$comp['name']]['azure_service'] ?? '') == 'Azure Cosmos DB' ? 'selected' : ''; ?>>Azure Cosmos DB</option>
                                        <option value="Azure Blob Storage" <?php echo ($existing[$comp['name']]['azure_service'] ?? '') == 'Azure Blob Storage' ? 'selected' : ''; ?>>Azure Blob Storage</option>
                                        <option value="Azure CDN" <?php echo ($existing[$comp['name']]['azure_service'] ?? '') == 'Azure CDN' ? 'selected' : ''; ?>>Azure CDN</option>
                                        <option value="Azure Monitor" <?php echo ($existing[$comp['name']]['azure_service'] ?? '') == 'Azure Monitor' ? 'selected' : ''; ?>>Azure Monitor</option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Monthly Cost ($)</label>
                                    <input type="number" name="components[<?php echo $index; ?>][cost]" 
                                           class="form-control cost-input" step="0.01" 
                                           value="<?php echo $existing[$comp['name']]['monthly_cost'] ?? $comp['cost']; ?>" 
                                           onchange="updateTotal()" required>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">&nbsp;</label>
                                    <button type="button" class="btn btn-danger btn-sm w-100" onclick="removeComponent(this)">
                                        <i class="fas fa-trash"></i> Remove
                                    </button>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <div class="mt-3">
                        <button type="button" class="btn btn-secondary" onclick="addComponent()">
                            <i class="fas fa-plus"></i> Add Component
                        </button>
                        <button type="submit" class="btn btn-primary float-end">
                            <i class="fas fa-save"></i> Save Estimation
                        </button>
                    </div>
                    
                    <div class="mt-4 p-3 bg-light rounded">
                        <h5>Total Estimated Monthly Cost:</h5>
                        <h2 class="text-primary" id="totalCost">$0.00</h2>
                        <small class="text-muted">* Estimated costs based on Azure pricing calculator</small>
                    </div>
                </form>
            </div>
        </div>
        
        <div class="card mt-3">
            <div class="card-header">
                <h5>Azure Pricing Calculator Reference</h5>
            </div>
            <div class="card-body">
                <p>For accurate pricing, visit the official <a href="https://azure.microsoft.com/en-us/pricing/calculator/" target="_blank">Azure Pricing Calculator</a></p>
                <div class="ratio ratio-16x9">
                    <iframe src="https://azure.microsoft.com/en-us/pricing/calculator/" title="Azure Pricing Calculator" allowfullscreen></iframe>
                </div>
            </div>
        </div>
        
        <script>
        let componentIndex = <?php echo count($components); ?>;
        
        function addComponent() {
            const container = document.getElementById('componentsContainer');
            const newComponent = `
                <div class="component-item mb-3 p-3 border rounded">
                    <div class="row">
                        <div class="col-md-3">
                            <label class="form-label">Component</label>
                            <input type="text" name="components[${componentIndex}][name]" class="form-control" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Azure Service</label>
                            <select name="components[${componentIndex}][service]" class="form-select" required>
                                <option value="Azure App Service">Azure App Service</option>
                                <option value="Azure Functions">Azure Functions</option>
                                <option value="Azure SQL Database">Azure SQL Database</option>
                                <option value="Azure Cosmos DB">Azure Cosmos DB</option>
                                <option value="Azure Blob Storage">Azure Blob Storage</option>
                                <option value="Azure CDN">Azure CDN</option>
                                <option value="Azure Monitor">Azure Monitor</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Monthly Cost ($)</label>
                            <input type="number" name="components[${componentIndex}][cost]" class="form-control cost-input" step="0.01" onchange="updateTotal()" required>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">&nbsp;</label>
                            <button type="button" class="btn btn-danger btn-sm w-100" onclick="removeComponent(this)">
                                <i class="fas fa-trash"></i> Remove
                            </button>
                        </div>
                    </div>
                </div>
            `;
            container.insertAdjacentHTML('beforeend', newComponent);
            componentIndex++;
            updateTotal();
        }
        
        function removeComponent(button) {
            button.closest('.component-item').remove();
            updateTotal();
        }
        
        function updateTotal() {
            let total = 0;
            document.querySelectorAll('.cost-input').forEach(input => {
                total += parseFloat(input.value) || 0;
            });
            document.getElementById('totalCost').textContent = '$' + total.toFixed(2);
        }
        
        updateTotal();
        </script>
        
        <?php else: ?>
        <div class="alert alert-info">
            <i class="fas fa-info-circle"></i> Please select a project to estimate costs.
        </div>
        <?php endif; ?>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>