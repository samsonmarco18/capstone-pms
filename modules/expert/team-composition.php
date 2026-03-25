<?php
require_once '../../includes/auth.php';
requireRole('expert');

$conn = getConnection();
$user = getCurrentUser();

// Get projects assigned to this expert
$projects = $conn->query("
    SELECT DISTINCT p.id, p.name 
    FROM projects p
    JOIN tasks t ON p.id = t.project_id
    WHERE t.assigned_to = {$user['id']}
    ORDER BY p.created_at DESC
");

$selectedProject = isset($_GET['project_id']) ? $_GET['project_id'] : 0;

if($selectedProject) {
    $existingCompositions = [];
    $compResult = $conn->query("SELECT * FROM team_compositions WHERE project_id = $selectedProject");
    while($row = $compResult->fetch_assoc()) {
        $existingCompositions[$row['role']] = $row;
    }
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $projectId = $_POST['project_id'];
    
    // Clear existing compositions
    $conn->query("DELETE FROM team_compositions WHERE project_id = $projectId");
    
    // Save new compositions
    if(isset($_POST['compositions'])) {
        foreach($_POST['compositions'] as $role => $data) {
            $requiredCount = $data['count'];
            $allocation = $data['allocation'];
            
            $stmt = $conn->prepare("INSERT INTO team_compositions (project_id, role, required_count, allocation_percentage) VALUES (?, ?, ?, ?)");
            $stmt->bind_param("isii", $projectId, $role, $requiredCount, $allocation);
            $stmt->execute();
        }
    }
    
    logActivity($user['id'], 'team_composition', 'projects', $projectId, null, $_POST);
    $_SESSION['success'] = "Team composition saved successfully";
    
    header('Location: team-composition.php?project_id=' . $projectId);
    exit();
}

include '../../includes/header.php';
include '../../includes/sidebar.php';
include '../../includes/navbar.php';
?>

<div class="row">
    <div class="col-12 mb-4">
        <h2>Team Composition</h2>
        <p class="text-muted">Define the recommended team structure for each project</p>
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
                <h5>Team Composition for Project</h5>
            </div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="project_id" value="<?php echo $selectedProject; ?>">
                    
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i>
                        Define the roles needed for this project, including the number of people required and their allocation percentage.
                    </div>
                    
                    <div id="compositionContainer">
                        <?php
                        $roles = [
                            'Senior Backend Developer' => ['min' => 0, 'max' => 5, 'default_count' => 1, 'default_allocation' => 100],
                            'Senior Frontend Developer' => ['min' => 0, 'max' => 5, 'default_count' => 1, 'default_allocation' => 100],
                            'Professional Web Developer' => ['min' => 0, 'max' => 10, 'default_count' => 2, 'default_allocation' => 100],
                            'QA Engineer' => ['min' => 0, 'max' => 5, 'default_count' => 1, 'default_allocation' => 50],
                            'UI/UX Designer' => ['min' => 0, 'max' => 3, 'default_count' => 1, 'default_allocation' => 50],
                            'DevOps Engineer' => ['min' => 0, 'max' => 2, 'default_count' => 1, 'default_allocation' => 25],
                            'Database Administrator' => ['min' => 0, 'max' => 2, 'default_count' => 1, 'default_allocation' => 25],
                            'Project Manager' => ['min' => 0, 'max' => 1, 'default_count' => 1, 'default_allocation' => 50]
                        ];
                        
                        foreach($roles as $role => $limits):
                            $existing = $existingCompositions[$role] ?? null;
                            $count = $existing['required_count'] ?? $limits['default_count'];
                            $allocation = $existing['allocation_percentage'] ?? $limits['default_allocation'];
                        ?>
                        <div class="composition-item mb-3 p-3 border rounded">
                            <div class="row">
                                <div class="col-md-5">
                                    <label class="form-label fw-bold"><?php echo $role; ?></label>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Number of People</label>
                                    <input type="number" 
                                           name="compositions[<?php echo $role; ?>][count]" 
                                           class="form-control" 
                                           value="<?php echo $count; ?>"
                                           min="<?php echo $limits['min']; ?>" 
                                           max="<?php echo $limits['max']; ?>"
                                           onchange="updateSummary()">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Allocation Percentage</label>
                                    <div class="input-group">
                                        <input type="number" 
                                               name="compositions[<?php echo $role; ?>][allocation]" 
                                               class="form-control" 
                                               value="<?php echo $allocation; ?>"
                                               min="0" 
                                               max="100"
                                               onchange="updateSummary()">
                                        <span class="input-group-text">%</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <div class="mt-3">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Save Team Composition
                        </button>
                    </div>
                    
                    <div class="mt-4 p-3 bg-light rounded">
                        <h5>Team Summary</h5>
                        <div id="teamSummary">
                            <p class="text-muted">Adjust values to see team summary</p>
                        </div>
                    </div>
                </form>
            </div>
        </div>
        <?php else: ?>
        <div class="alert alert-info">
            <i class="fas fa-info-circle"></i> Please select a project to define team composition.
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
function updateSummary() {
    let totalPeople = 0;
    let totalAllocation = 0;
    let roles = [];
    
    document.querySelectorAll('.composition-item').forEach(item => {
        const role = item.querySelector('.fw-bold').textContent;
        const count = parseInt(item.querySelector('input[type="number"]').value) || 0;
        const allocation = parseInt(item.querySelector('input[type="number"]:last-of-type').value) || 0;
        
        totalPeople += count;
        totalAllocation += (count * allocation);
        
        if(count > 0) {
            roles.push(`${role}: ${count} person(s) at ${allocation}%`);
        }
    });
    
    const avgAllocation = totalPeople > 0 ? (totalAllocation / totalPeople).toFixed(1) : 0;
    
    let html = `
        <div class="row">
            <div class="col-md-4">
                <strong>Total Team Size:</strong> ${totalPeople} people
            </div>
            <div class="col-md-4">
                <strong>Average Allocation:</strong> ${avgAllocation}%
            </div>
            <div class="col-md-4">
                <strong>Total FTE:</strong> ${(totalAllocation / 100).toFixed(1)} FTEs
            </div>
        </div>
        <hr>
        <strong>Team Breakdown:</strong>
        <ul class="mt-2">
            ${roles.map(r => `<li>${r}</li>`).join('')}
        </ul>
    `;
    
    document.getElementById('teamSummary').innerHTML = html;
}

// Initialize summary on page load
setTimeout(updateSummary, 100);
</script>

<?php include '../../includes/footer.php'; ?>