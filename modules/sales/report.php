<?php
require_once '../../includes/auth.php';
requireRole('sales');

$conn = getConnection();
$user = getCurrentUser();

// Get clients with projects
$clients = $conn->query("
    SELECT DISTINCT c.* 
    FROM clients c
    JOIN projects p ON c.id = p.client_id
    ORDER BY c.name
");

$selectedClient = isset($_GET['client_id']) ? $_GET['client_id'] : 0;
$reportData = null;
$technologies = null;
$developerCost = null;
$resources = null;
$otherFees = [];

if ($selectedClient) {
    // Get project for this client
    $project = $conn->query("
        SELECT * FROM projects 
        WHERE client_id = $selectedClient 
        ORDER BY created_at DESC 
        LIMIT 1
    ")->fetch_assoc();
    
    if ($project) {
        // Get technology costs from architect recommendations
        $technologies = $conn->query("
            SELECT tr.*, t.name as tech_name, t.category 
            FROM tech_recommendations tr
            JOIN technologies t ON tr.tech_id = t.id
            WHERE tr.project_id = {$project['id']}
        ");
        
        // Get developer allocation costs
        $allocations = $conn->query("
            SELECT * FROM developer_allocations 
            WHERE project_id = {$project['id']}
        ");
        
        // Calculate developer cost (estimated $500 per day per developer)
        $developerCost = 0;
        while($alloc = $allocations->fetch_assoc()) {
            $developerCost += $alloc['required_count'] * 500 * 30; // Monthly cost
        }
        
        // Get resource costs from tech recommendations
        $resourceCost = 0;
        $technologies->data_seek(0);
        while($tech = $technologies->fetch_assoc()) {
            $resourceCost += $tech['estimated_cost'];
        }
        
        $reportData = [
            'project' => $project,
            'technology_cost' => $resourceCost,
            'developer_cost' => $developerCost,
            'resource_cost' => $resourceCost
        ];
    }
}

// Save report
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_report'])) {
    $projectId = $_POST['project_id'];
    $clientId = $_POST['client_id'];
    $technologyCost = $_POST['technology_cost'];
    $developerCost = $_POST['developer_cost'];
    $resourceCost = $_POST['resource_cost'];
    $otherFees = isset($_POST['other_fees']) ? json_encode($_POST['other_fees']) : '[]';
    $totalCost = $technologyCost + $developerCost + $resourceCost;
    
    // Add other fees to total
    if(isset($_POST['other_fees_list'])) {
        foreach($_POST['other_fees_list'] as $fee) {
            $totalCost += $fee['cost'];
        }
    }
    
    $stmt = $conn->prepare("
        INSERT INTO sales_reports (project_id, client_id, technology_cost, developer_cost, resource_cost, other_fees, total_cost, created_by) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->bind_param("iidddsdi", $projectId, $clientId, $technologyCost, $developerCost, $resourceCost, $otherFees, $totalCost, $user['id']);
    
    if ($stmt->execute()) {
        $reportId = $stmt->insert_id;
        logActivity($user['id'], 'create_report', 'sales_reports', $reportId, null, $_POST);
        $_SESSION['success'] = "Report saved successfully";
        header('Location: receipt.php?report_id=' . $reportId);
        exit();
    }
}

include '../../includes/header.php';
include '../../includes/sidebar.php';
include '../../includes/navbar.php';
?>

<div class="row">
    <div class="col-12 mb-4">
        <h2>Sales Report</h2>
        <p class="text-muted">Generate detailed cost reports for clients</p>
    </div>
</div>

<div class="row">
    <div class="col-md-3 mb-4">
        <div class="card">
            <div class="card-header">
                <h5>Select Client</h5>
            </div>
            <div class="card-body">
                <div class="list-group">
                    <?php while($client = $clients->fetch_assoc()): ?>
                    <a href="?client_id=<?php echo $client['id']; ?>" 
                       class="list-group-item list-group-item-action <?php echo $selectedClient == $client['id'] ? 'active' : ''; ?>">
                        <?php echo htmlspecialchars($client['name']); ?>
                    </a>
                    <?php endwhile; ?>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-md-9">
        <?php if($reportData && $technologies): ?>
        <form method="POST" id="reportForm">
            <input type="hidden" name="project_id" value="<?php echo $reportData['project']['id']; ?>">
            <input type="hidden" name="client_id" value="<?php echo $selectedClient; ?>">
            
            <div class="card mb-4">
                <div class="card-header">
                    <h5>Project Information</h5>
                </div>
                <div class="card-body">
                    <p><strong>Project:</strong> <?php echo htmlspecialchars($reportData['project']['name']); ?></p>
                    <p><strong>Description:</strong> <?php echo htmlspecialchars($reportData['project']['description']); ?></p>
                    <p><strong>Timeline:</strong> <?php echo date('M d, Y', strtotime($reportData['project']['timeline_start'])); ?> - <?php echo date('M d, Y', strtotime($reportData['project']['timeline_end'])); ?></p>
                </div>
            </div>
            
            <div class="card mb-4">
                <div class="card-header">
                    <h5>Technology Costs</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Technology</th>
                                    <th>Category</th>
                                    <th>Monthly Cost</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $techTotal = 0;
                                $technologies->data_seek(0);
                                while($tech = $technologies->fetch_assoc()): 
                                    $techTotal += $tech['estimated_cost'];
                                ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($tech['tech_name']); ?></td>
                                    <td><?php echo htmlspecialchars($tech['category']); ?></td>
                                    <td>$<?php echo number_format($tech['estimated_cost'], 2); ?></td>
                                </tr>
                                <?php endwhile; ?>
                                <tr class="table-primary">
                                    <td colspan="2"><strong>Total Technology Cost</strong></td>
                                    <td><strong>$<?php echo number_format($techTotal, 2); ?></strong></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <input type="hidden" name="technology_cost" value="<?php echo $techTotal; ?>">
                </div>
            </div>
            
            <div class="card mb-4">
                <div class="card-header">
                    <h5>Developer Costs</h5>
                </div>
                <div class="card-body">
                    <p>Based on developer allocation and daily rates ($500/day per developer)</p>
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                                32<th>Role</th>
                                    <th>Number of Developers</th>
                                    <th>Monthly Cost</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $allocations->data_seek(0);
                                $devTotal = 0;
                                while($alloc = $allocations->fetch_assoc()):
                                    $cost = $alloc['required_count'] * 500 * 30;
                                    $devTotal += $cost;
                                ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($alloc['role']); ?></td>
                                    <td><?php echo $alloc['required_count']; ?></td>
                                    <td>$<?php echo number_format($cost, 2); ?></td>
                                </tr>
                                <?php endwhile; ?>
                                <tr class="table-primary">
                                    <td colspan="2"><strong>Total Developer Cost</strong></td>
                                    <td><strong>$<?php echo number_format($devTotal, 2); ?></strong></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <input type="hidden" name="developer_cost" value="<?php echo $devTotal; ?>">
                </div>
            </div>
            
            <div class="card mb-4">
                <div class="card-header">
                    <h5>Resource Costs</h5>
                </div>
                <div class="card-body">
                    <p>Infrastructure and hosting costs from technology recommendations</p>
                    <div class="alert alert-info">
                        <strong>Total Resource Cost:</strong> $<?php echo number_format($techTotal, 2); ?>/month
                    </div>
                    <input type="hidden" name="resource_cost" value="<?php echo $techTotal; ?>">
                </div>
            </div>
            
            <div class="card mb-4">
                <div class="card-header">
                    <h5>Other Fees (Optional)</h5>
                </div>
                <div class="card-body">
                    <div id="otherFeesContainer">
                        <div class="other-fee-item row mb-2">
                            <div class="col-md-6">
                                <input type="text" name="other_fees_list[0][name]" class="form-control" placeholder="Fee Name">
                            </div>
                            <div class="col-md-4">
                                <input type="number" name="other_fees_list[0][cost]" class="form-control" placeholder="Cost ($)" step="0.01">
                            </div>
                            <div class="col-md-2">
                                <button type="button" class="btn btn-danger btn-sm" onclick="removeFee(this)">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                    <button type="button" class="btn btn-sm btn-secondary mt-2" onclick="addFee()">
                        <i class="fas fa-plus"></i> Add Another Fee
                    </button>
                    <small class="text-muted d-block mt-2">Note: Other fees will not appear on the receipt</small>
                </div>
            </div>
            
            <div class="card mb-4">
                <div class="card-header">
                    <h5>Cost Summary</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <table class="table">
                                <tr>
                                    <td><strong>Technology Costs:</strong></td>
                                    <td class="text-end">$<span id="summaryTech"><?php echo number_format($techTotal, 2); ?></span></td>
                                </tr>
                                <tr>
                                    <td><strong>Developer Costs:</strong></td>
                                    <td class="text-end">$<span id="summaryDev"><?php echo number_format($devTotal, 2); ?></span></td>
                                </tr>
                                <tr>
                                    <td><strong>Resource Costs:</strong></td>
                                    <td class="text-end">$<span id="summaryResource"><?php echo number_format($techTotal, 2); ?></span></td>
                                </tr>
                                <tr id="otherFeesSummary"></tr>
                                <tr class="table-primary">
                                    <td><strong>Total Monthly Cost:</strong></td>
                                    <td class="text-end"><strong>$<span id="summaryTotal"><?php echo number_format($techTotal + $devTotal + $techTotal, 2); ?></span></strong></td>
                                </tr>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="text-end">
                <button type="submit" name="save_report" class="btn btn-success btn-lg">
                    <i class="fas fa-save"></i> Save Report & Generate Receipt
                </button>
            </div>
        </form>
        <?php elseif($selectedClient): ?>
        <div class="alert alert-warning">
            <i class="fas fa-exclamation-triangle"></i>
            No project found for this client. Please create a project first.
        </div>
        <?php else: ?>
        <div class="alert alert-info">
            <i class="fas fa-info-circle"></i>
            Select a client to generate a report.
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
let feeIndex = 1;

function addFee() {
    const container = document.getElementById('otherFeesContainer');
    const newFee = `
        <div class="other-fee-item row mb-2">
            <div class="col-md-6">
                <input type="text" name="other_fees_list[${feeIndex}][name]" class="form-control" placeholder="Fee Name">
            </div>
            <div class="col-md-4">
                <input type="number" name="other_fees_list[${feeIndex}][cost]" class="form-control fee-cost" placeholder="Cost ($)" step="0.01" onchange="updateSummary()">
            </div>
            <div class="col-md-2">
                <button type="button" class="btn btn-danger btn-sm" onclick="removeFee(this)">
                    <i class="fas fa-trash"></i>
                </button>
            </div>
        </div>
    `;
    container.insertAdjacentHTML('beforeend', newFee);
    feeIndex++;
}

function removeFee(button) {
    button.closest('.other-fee-item').remove();
    updateSummary();
}

function updateSummary() {
    const tech = parseFloat(document.querySelector('input[name="technology_cost"]')?.value) || 0;
    const dev = parseFloat(document.querySelector('input[name="developer_cost"]')?.value) || 0;
    const resource = parseFloat(document.querySelector('input[name="resource_cost"]')?.value) || 0;
    
    let otherTotal = 0;
    document.querySelectorAll('.fee-cost').forEach(input => {
        otherTotal += parseFloat(input.value) || 0;
    });
    
    const total = tech + dev + resource + otherTotal;
    
    document.getElementById('summaryTech').textContent = tech.toFixed(2);
    document.getElementById('summaryDev').textContent = dev.toFixed(2);
    document.getElementById('summaryResource').textContent = resource.toFixed(2);
    document.getElementById('summaryTotal').textContent = total.toFixed(2);
    
    if (otherTotal > 0) {
        document.getElementById('otherFeesSummary').innerHTML = `
            <tr>
                <td><strong>Other Fees:</strong></td>
                <td class="text-end">$${otherTotal.toFixed(2)}</td>
            </tr>
        `;
    } else {
        document.getElementById('otherFeesSummary').innerHTML = '';
    }
}

// Initialize
setTimeout(updateSummary, 100);
</script>

<?php include '../../includes/footer.php'; ?>