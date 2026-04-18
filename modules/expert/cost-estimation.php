<?php
require_once '../../includes/auth.php';
requireRole('expert');

$conn = getConnection();
$user = getCurrentUser();

$_SESSION['info'] = "Cost estimation is now managed in Feature Estimation.";
header('Location: feature-estimation.php');
exit();

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

/* Project Sidebar Card */
.project-card {
    background: white;
    border-radius: 20px;
    border: 1px solid var(--gray-200);
    overflow: hidden;
    box-shadow: var(--shadow-sm);
    position: sticky;
    top: 20px;
}

.project-card .card-header {
    padding: 1.25rem 1.5rem;
    background: white;
    border-bottom: 1px solid var(--gray-200);
}

.project-card .card-header h5 {
    font-size: 1rem;
    font-weight: 600;
    color: var(--gray-900);
    margin: 0;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.project-card .card-header h5 i {
    color: var(--theme-primary);
}

.project-list {
    padding: 0.5rem;
}

.project-item {
    display: block;
    padding: 0.75rem 1rem;
    margin: 0.25rem 0;
    border-radius: 12px;
    color: var(--gray-700);
    text-decoration: none;
    transition: all 0.2s;
    font-size: 0.85rem;
}

.project-item:hover {
    background: var(--gray-50);
    color: var(--theme-primary);
}

.project-item.active {
    background: linear-gradient(135deg, rgba(31, 99, 146, 0.1) 0%, rgba(31, 99, 146, 0.05) 100%);
    color: var(--theme-primary);
    font-weight: 500;
    border-left: 3px solid var(--theme-primary);
}

/* Main Card */
.main-card {
    background: white;
    border-radius: 20px;
    border: 1px solid var(--gray-200);
    overflow: hidden;
    box-shadow: var(--shadow-sm);
}

.main-card .card-header {
    padding: 1.25rem 1.5rem;
    background: white;
    border-bottom: 1px solid var(--gray-200);
}

.main-card .card-header h5 {
    font-size: 1rem;
    font-weight: 600;
    color: var(--gray-900);
    margin: 0;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.main-card .card-header h5 i {
    color: var(--theme-primary);
}

.main-card .card-body {
    padding: 1.5rem;
}

/* Component Item */
.component-item {
    background: var(--gray-50);
    border: 1px solid var(--gray-200);
    border-radius: 16px;
    padding: 1rem;
    margin-bottom: 1rem;
    transition: all 0.2s;
}

.component-item:hover {
    border-color: var(--theme-primary);
    box-shadow: var(--shadow-sm);
}

/* Form Elements */
.form-label {
    font-size: 0.7rem;
    text-transform: uppercase;
    font-weight: 600;
    color: var(--gray-500);
    letter-spacing: 0.5px;
    margin-bottom: 0.5rem;
    display: block;
}

.form-control-custom, .form-select-custom {
    width: 100%;
    padding: 0.6rem 0.8rem;
    border: 1px solid var(--gray-300);
    border-radius: 10px;
    font-size: 0.85rem;
    transition: all 0.2s;
    background: white;
}

.form-control-custom:focus, .form-select-custom:focus {
    outline: none;
    border-color: var(--theme-primary);
    box-shadow: 0 0 0 3px rgba(31, 99, 146, 0.1);
}

/* Buttons */
.btn-add {
    background: white;
    border: 1px solid var(--theme-primary);
    color: var(--theme-primary);
    padding: 0.5rem 1rem;
    border-radius: 40px;
    font-size: 0.8rem;
    font-weight: 500;
    transition: all 0.2s;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
}

.btn-add:hover {
    background: var(--theme-primary);
    color: white;
    transform: translateY(-1px);
}

.btn-save {
    background: linear-gradient(135deg, var(--theme-primary) 0%, var(--theme-dark) 100%);
    color: white;
    border: none;
    padding: 0.6rem 1.5rem;
    border-radius: 40px;
    font-weight: 500;
    transition: all 0.2s;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
}

.btn-save:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(31, 99, 146, 0.3);
    color: white;
}

.btn-remove {
    background: #fee2e2;
    border: none;
    color: #dc2626;
    padding: 0.4rem 0.8rem;
    border-radius: 8px;
    font-size: 0.7rem;
    font-weight: 500;
    transition: all 0.2s;
    width: 100%;
}

.btn-remove:hover {
    background: #dc2626;
    color: white;
}

/* Cost Summary */
.cost-summary {
    background: var(--gray-50);
    border-radius: 16px;
    padding: 1.25rem;
    margin-top: 1.5rem;
}

.cost-summary h5 {
    font-size: 0.9rem;
    font-weight: 600;
    color: var(--gray-800);
    margin-bottom: 0.5rem;
}

.total-cost {
    font-size: 2.5rem;
    font-weight: 700;
    color: var(--theme-primary);
    line-height: 1.2;
}

/* Empty State */
.empty-state {
    text-align: center;
    padding: 3rem 2rem;
    background: white;
    border-radius: 20px;
    border: 1px solid var(--gray-200);
}

.empty-state i {
    font-size: 3rem;
    color: var(--gray-300);
    margin-bottom: 1rem;
}

.empty-state h5 {
    font-size: 1rem;
    font-weight: 600;
    color: var(--gray-800);
    margin-bottom: 0.5rem;
}

.empty-state p {
    color: var(--gray-500);
    font-size: 0.85rem;
    margin-bottom: 0;
}

/* Reference Card */
.reference-card {
    background: white;
    border-radius: 20px;
    border: 1px solid var(--gray-200);
    overflow: hidden;
    box-shadow: var(--shadow-sm);
    margin-top: 1.5rem;
}

.reference-card .card-header {
    padding: 1rem 1.5rem;
    background: var(--gray-50);
    border-bottom: 1px solid var(--gray-200);
}

.reference-card .card-header h5 {
    font-size: 0.9rem;
    font-weight: 600;
    color: var(--gray-800);
    margin: 0;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.reference-card .card-header h5 i {
    color: var(--theme-primary);
}

.reference-card .card-body {
    padding: 1.25rem;
}

.ratio-iframe {
    position: relative;
    padding-bottom: 56.25%;
    height: 0;
    overflow: hidden;
    border-radius: 12px;
    margin-top: 1rem;
}

.ratio-iframe iframe {
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    border: none;
    border-radius: 12px;
}

/* Responsive */
@media (max-width: 768px) {
    .page-header-modern {
        padding: 1.5rem;
    }
    
    .page-header-modern h2 {
        font-size: 1.5rem;
    }
    
    .component-item .row > div {
        margin-bottom: 0.75rem;
    }
    
    .btn-add, .btn-save {
        width: 100%;
        justify-content: center;
    }
}
</style>

<div class="container-fluid px-4 py-4">
    <!-- Page Header -->
    <div class="page-header-modern">
        <div>
            <h2>
                <i class="fas fa-chart-line me-2"></i> 
                Infrastructure Cost Estimation
            </h2>
            <p>Based on Azure Pricing Calculator</p>
        </div>
    </div>

    <div class="row g-4">
        <!-- Project Sidebar -->
        <div class="col-lg-3">
            <div class="project-card">
                <div class="card-header">
                    <h5>
                        <i class="fas fa-building"></i>
                        Select Project
                    </h5>
                </div>
                <div class="project-list">
                    <?php while($project = $projects->fetch_assoc()): ?>
                    <a href="?project_id=<?php echo $project['id']; ?>" 
                       class="project-item <?php echo $selectedProject == $project['id'] ? 'active' : ''; ?>">
                        <i class="fas fa-folder me-2"></i>
                        <?php echo htmlspecialchars($project['name']); ?>
                    </a>
                    <?php endwhile; ?>
                </div>
            </div>
        </div>
        
        <!-- Main Content -->
        <div class="col-lg-9">
            <?php if($selectedProject): ?>
            <div class="main-card">
                <div class="card-header">
                    <h5>
                        <i class="fas fa-chart-line"></i>
                        Azure Cost Breakdown
                    </h5>
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
                            <div class="component-item">
                                <div class="row g-3 align-items-end">
                                    <div class="col-md-3">
                                        <label class="form-label">Component</label>
                                        <input type="text" name="components[<?php echo $index; ?>][name]" 
                                               class="form-control-custom" value="<?php echo $comp['name']; ?>" required>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Azure Service</label>
                                        <select name="components[<?php echo $index; ?>][service]" class="form-select-custom" required>
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
                                               class="form-control-custom cost-input" step="0.01" 
                                               value="<?php echo $existing[$comp['name']]['monthly_cost'] ?? $comp['cost']; ?>" 
                                               onchange="updateTotal()" required>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">&nbsp;</label>
                                        <button type="button" class="btn-remove w-100" onclick="removeComponent(this)">
                                            <i class="fas fa-trash"></i> Remove
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <div class="mt-3 d-flex justify-content-between flex-wrap gap-2">
                            <button type="button" class="btn-add" onclick="addComponent()">
                                <i class="fas fa-plus"></i> Add Component
                            </button>
                            <button type="submit" class="btn-save">
                                <i class="fas fa-save"></i> Save Estimation
                            </button>
                        </div>
                        
                        <div class="cost-summary">
                            <h5>Total Estimated Monthly Cost</h5>
                            <div class="total-cost" id="totalCost">$0.00</div>
                            <small class="text-muted">* Estimated costs based on Azure pricing calculator</small>
                        </div>
                    </form>
                </div>
            </div>
            
            <div class="reference-card">
                <div class="card-header">
                    <h5>
                        <i class="fas fa-external-link-alt"></i>
                        Azure Pricing Calculator Reference
                    </h5>
                </div>
                <div class="card-body">
                    <p>For accurate pricing, visit the official 
                        <a href="https://azure.microsoft.com/en-us/pricing/calculator/" target="_blank" style="color: var(--theme-primary);">
                            Azure Pricing Calculator <i class="fas fa-external-link-alt fa-xs"></i>
                        </a>
                    </p>
                    <div class="ratio-iframe">
                        <iframe src="https://azure.microsoft.com/en-us/pricing/calculator/" title="Azure Pricing Calculator" allowfullscreen></iframe>
                    </div>
                </div>
            </div>
            
            <script>
            let componentIndex = <?php echo count($components); ?>;
            
            function addComponent() {
                const container = document.getElementById('componentsContainer');
                const newComponent = `
                    <div class="component-item">
                        <div class="row g-3 align-items-end">
                            <div class="col-md-3">
                                <label class="form-label">Component</label>
                                <input type="text" name="components[${componentIndex}][name]" class="form-control-custom" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Azure Service</label>
                                <select name="components[${componentIndex}][service]" class="form-select-custom" required>
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
                                <input type="number" name="components[${componentIndex}][cost]" class="form-control-custom cost-input" step="0.01" onchange="updateTotal()" required>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">&nbsp;</label>
                                <button type="button" class="btn-remove w-100" onclick="removeComponent(this)">
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
            <div class="empty-state">
                <i class="fas fa-folder-open"></i>
                <h5>No Project Selected</h5>
                <p>Please select a project from the sidebar to estimate costs.</p>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
