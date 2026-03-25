<?php
require_once '../../includes/auth.php';
requireRole('architect');

$conn = getConnection();
$user = getCurrentUser();
$projectId = isset($_GET['id']) ? $_GET['id'] : 0;

$project = $conn->query("SELECT * FROM projects WHERE id = $projectId")->fetch_assoc();
$technologies = $conn->query("SELECT * FROM technologies WHERE status = 'active'");

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Clear existing recommendations
    $conn->query("DELETE FROM tech_recommendations WHERE project_id = $projectId");
    
    // Save new recommendations
    if(isset($_POST['technologies'])) {
        foreach($_POST['technologies'] as $tech) {
            $techId = $tech['id'];
            $serviceType = $tech['service_type'];
            $cost = $tech['cost'];
            $notes = $tech['notes'];
            
            $stmt = $conn->prepare("INSERT INTO tech_recommendations (project_id, tech_id, service_type, estimated_cost, notes) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param("iisds", $projectId, $techId, $serviceType, $cost, $notes);
            $stmt->execute();
        }
    }
    
    logActivity($user['id'], 'tech_recommendation', 'projects', $projectId, null, $_POST);
    
    header('Location: developer-allocation.php?id=' . $projectId);
    exit();
}

include '../../includes/header.php';
include '../../includes/sidebar.php';
include '../../includes/navbar.php';
?>

<div class="row">
    <div class="col-12 mb-4">
        <h2>Technology Stack & Resources Recommendation</h2>
        <p class="text-muted">Project: <?php echo htmlspecialchars($project['name']); ?></p>
    </div>
</div>

<div class="row">
    <div class="col-md-8">
        <form method="POST" id="techForm">
            <div class="card mb-3">
                <div class="card-header">
                    <h5>Select Technologies/Services</h5>
                </div>
                <div class="card-body">
                    <div id="technologiesContainer">
                        <?php while($tech = $technologies->fetch_assoc()): ?>
                        <div class="tech-item mb-3 p-3 border rounded">
                            <div class="row">
                                <div class="col-md-5">
                                    <div class="form-check">
                                        <input class="form-check-input tech-checkbox" type="checkbox" 
                                               name="technologies[<?php echo $tech['id']; ?>][id]" 
                                               value="<?php echo $tech['id']; ?>" 
                                               id="tech_<?php echo $tech['id']; ?>">
                                        <label class="form-check-label fw-bold" for="tech_<?php echo $tech['id']; ?>">
                                            <?php echo htmlspecialchars($tech['name']); ?>
                                        </label>
                                        <br>
                                        <small class="text-muted">Category: <?php echo $tech['category']; ?></small>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label small">Service Type</label>
                                    <select name="technologies[<?php echo $tech['id']; ?>][service_type]" class="form-select form-select-sm" disabled>
                                        <option value="Development">Development</option>
                                        <option value="Testing">Testing</option>
                                        <option value="Production">Production</option>
                                        <option value="Backup">Backup</option>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label small">Monthly Cost ($)</label>
                                    <input type="number" name="technologies[<?php echo $tech['id']; ?>][cost]" 
                                           class="form-control form-control-sm" step="0.01" 
                                           value="<?php echo $tech['price']; ?>" disabled>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label small">Notes</label>
                                    <input type="text" name="technologies[<?php echo $tech['id']; ?>][notes]" 
                                           class="form-control form-control-sm" placeholder="Optional" disabled>
                                </div>
                            </div>
                        </div>
                        <?php endwhile; ?>
                    </div>
                </div>
            </div>
            
            <div class="card">
                <div class="card-header">
                    <h5>Infrastructure Requirements</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Cloud Platform</label>
                            <select name="cloud_platform" class="form-select">
                                <option value="Azure">Microsoft Azure</option>
                                <option value="AWS">Amazon Web Services</option>
                                <option value="GCP">Google Cloud Platform</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Database Server</label>
                            <select name="database_server" class="form-select">
                                <option value="Azure SQL">Azure SQL Database</option>
                                <option value="MySQL">MySQL</option>
                                <option value="PostgreSQL">PostgreSQL</option>
                                <option value="MongoDB">MongoDB</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Storage Requirements</label>
                            <input type="text" name="storage" class="form-control" placeholder="e.g., 100GB SSD">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Additional Services</label>
                            <input type="text" name="additional_services" class="form-control" placeholder="CDN, Load Balancer, etc.">
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="mt-3">
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-arrow-right"></i> Proceed to Developer Allocation
                </button>
            </div>
        </form>
    </div>
    
    <div class="col-md-4">
        <div class="card">
            <div class="card-header">
                <h5>Cost Summary</h5>
            </div>
            <div class="card-body">
                <div id="costSummary">
                    <p class="text-muted">Select technologies to see cost summary</p>
                </div>
                <hr>
                <div class="mt-3">
                    <strong>Total Monthly Cost:</strong>
                    <h3 class="text-primary" id="totalCost">$0.00</h3>
                </div>
            </div>
        </div>
        
        <div class="card mt-3">
            <div class="card-header">
                <h5>Recommendation Tips</h5>
            </div>
            <div class="card-body">
                <ul class="list-unstyled">
                    <li><i class="fas fa-lightbulb text-warning me-2"></i> Choose technologies that align with project requirements</li>
                    <li><i class="fas fa-lightbulb text-warning me-2"></i> Consider scalability for future growth</li>
                    <li><i class="fas fa-lightbulb text-warning me-2"></i> Balance cost vs performance</li>
                    <li><i class="fas fa-lightbulb text-warning me-2"></i> Ensure compatibility with existing systems</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    // Enable/disable fields based on checkbox
    $('.tech-checkbox').change(function() {
        const container = $(this).closest('.tech-item');
        const inputs = container.find('select, input[type="number"], input[type="text"]');
        
        if($(this).is(':checked')) {
            inputs.prop('disabled', false);
        } else {
            inputs.prop('disabled', true);
        }
        
        updateCostSummary();
    });
    
    function updateCostSummary() {
        let total = 0;
        let summaryHtml = '<table class="table table-sm">';
        
        $('.tech-checkbox:checked').each(function() {
            const container = $(this).closest('.tech-item');
            const name = container.find('.form-check-label').text().trim();
            const cost = parseFloat(container.find('input[type="number"]').val()) || 0;
            
            total += cost;
            summaryHtml += `
                <tr>
                    <td>${name}</td>
                    <td class="text-end">$${cost.toFixed(2)}</td>
                </tr>
            `;
        });
        
        summaryHtml += `
            <tr class="table-primary">
                <td><strong>Total</strong></td>
                <td class="text-end"><strong>$${total.toFixed(2)}</strong></td>
            </tr>
        </table>`;
        
        if($('.tech-checkbox:checked').length > 0) {
            $('#costSummary').html(summaryHtml);
        } else {
            $('#costSummary').html('<p class="text-muted">Select technologies to see cost summary</p>');
        }
        
        $('#totalCost').text('$' + total.toFixed(2));
    }
});
</script>

<?php include '../../includes/footer.php'; ?>