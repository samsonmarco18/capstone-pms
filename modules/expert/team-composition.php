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

/* Composition Item */
.composition-item {
    background: var(--gray-50);
    border: 1px solid var(--gray-200);
    border-radius: 16px;
    padding: 1rem;
    margin-bottom: 1rem;
    transition: all 0.2s;
}

.composition-item:hover {
    border-color: var(--theme-primary);
    box-shadow: var(--shadow-sm);
}

.composition-item .role-label {
    font-weight: 600;
    color: var(--gray-800);
    margin-bottom: 0;
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

.form-control-custom {
    width: 100%;
    padding: 0.6rem 0.8rem;
    border: 1px solid var(--gray-300);
    border-radius: 10px;
    font-size: 0.85rem;
    transition: all 0.2s;
}

.form-control-custom:focus {
    outline: none;
    border-color: var(--theme-primary);
    box-shadow: 0 0 0 3px rgba(31, 99, 146, 0.1);
}

.input-group-custom {
    display: flex;
    align-items: center;
}

.input-group-custom .input-group-text {
    background: var(--gray-100);
    border: 1px solid var(--gray-300);
    border-left: none;
    border-radius: 0 10px 10px 0;
    padding: 0.6rem 0.8rem;
    font-size: 0.85rem;
    color: var(--gray-600);
}

/* Alert */
.alert-info-custom {
    background: #dbeafe;
    border: 1px solid #bfdbfe;
    border-radius: 12px;
    padding: 0.75rem 1rem;
    margin-bottom: 1.5rem;
}

.alert-info-custom i {
    color: var(--theme-primary);
    margin-right: 0.5rem;
}

.alert-info-custom {
    color: var(--gray-700);
    font-size: 0.85rem;
}

/* Summary Card */
.summary-card {
    background: var(--gray-50);
    border-radius: 16px;
    padding: 1.25rem;
    margin-top: 1.5rem;
}

.summary-card h5 {
    font-size: 0.9rem;
    font-weight: 600;
    color: var(--gray-800);
    margin-bottom: 1rem;
}

.summary-stats {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 1rem;
    margin-bottom: 1rem;
}

.stat-item {
    text-align: center;
}

.stat-value {
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--theme-primary);
    line-height: 1.2;
}

.stat-label {
    font-size: 0.7rem;
    color: var(--gray-500);
    text-transform: uppercase;
}

.breakdown-list {
    list-style: none;
    padding: 0;
    margin: 0;
}

.breakdown-list li {
    padding: 0.4rem 0;
    font-size: 0.8rem;
    color: var(--gray-600);
    border-bottom: 1px solid var(--gray-200);
}

.breakdown-list li:last-child {
    border-bottom: none;
}

/* Buttons */
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

/* Responsive */
@media (max-width: 768px) {
    .page-header-modern {
        padding: 1.5rem;
    }
    
    .page-header-modern h2 {
        font-size: 1.5rem;
    }
    
    .summary-stats {
        grid-template-columns: 1fr;
        gap: 0.5rem;
    }
    
    .stat-item {
        text-align: left;
        display: flex;
        justify-content: space-between;
        align-items: baseline;
    }
    
    .composition-item .row > div {
        margin-bottom: 0.75rem;
    }
}
</style>

<div class="container-fluid px-4 py-4">
    <!-- Page Header -->
    <div class="page-header-modern">
        <div>
            <h2>
                <i class="fas fa-users me-2"></i> 
                Team Composition
            </h2>
            <p>Define the recommended team structure for each project</p>
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
                        <i class="fas fa-users"></i>
                        Team Composition
                    </h5>
                </div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="project_id" value="<?php echo $selectedProject; ?>">
                        
                        <div class="alert-info-custom">
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
                            <div class="composition-item">
                                <div class="row g-3 align-items-end">
                                    <div class="col-md-5">
                                        <div class="role-label"><?php echo $role; ?></div>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Number of People</label>
                                        <input type="number" 
                                               name="compositions[<?php echo $role; ?>][count]" 
                                               class="form-control-custom" 
                                               value="<?php echo $count; ?>"
                                               min="<?php echo $limits['min']; ?>" 
                                               max="<?php echo $limits['max']; ?>"
                                               onchange="updateSummary()">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Allocation Percentage</label>
                                        <div class="input-group-custom">
                                            <input type="number" 
                                                   name="compositions[<?php echo $role; ?>][allocation]" 
                                                   class="form-control-custom" 
                                                   style="border-radius: 10px 0 0 10px;"
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
                        
                        <div class="mt-4">
                            <button type="submit" class="btn-save">
                                <i class="fas fa-save"></i> Save Team Composition
                            </button>
                        </div>
                        
                        <div class="summary-card">
                            <h5>Team Summary</h5>
                            <div id="teamSummary">
                                <p class="text-muted text-center py-3">Adjust values to see team summary</p>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
            <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-folder-open"></i>
                <h5>No Project Selected</h5>
                <p>Please select a project from the sidebar to define team composition.</p>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
function updateSummary() {
    let totalPeople = 0;
    let totalAllocation = 0;
    let roles = [];
    
    document.querySelectorAll('.composition-item').forEach(item => {
        const role = item.querySelector('.role-label').textContent;
        const countInput = item.querySelector('input[type="number"]');
        const allocationInputs = item.querySelectorAll('input[type="number"]');
        const allocation = allocationInputs.length > 1 ? parseInt(allocationInputs[1].value) || 0 : 0;
        const count = parseInt(countInput.value) || 0;
        
        totalPeople += count;
        totalAllocation += (count * allocation);
        
        if(count > 0) {
            roles.push(`${role}: ${count} person(s) at ${allocation}%`);
        }
    });
    
    const avgAllocation = totalPeople > 0 ? (totalAllocation / totalPeople).toFixed(1) : 0;
    const totalFTE = (totalAllocation / 100).toFixed(1);
    
    let html = `
        <div class="summary-stats">
            <div class="stat-item">
                <div class="stat-value">${totalPeople}</div>
                <div class="stat-label">Total Team Size</div>
            </div>
            <div class="stat-item">
                <div class="stat-value">${avgAllocation}%</div>
                <div class="stat-label">Average Allocation</div>
            </div>
            <div class="stat-item">
                <div class="stat-value">${totalFTE}</div>
                <div class="stat-label">Total FTEs</div>
            </div>
        </div>
        ${roles.length > 0 ? `
            <div class="mt-3">
                <strong class="small text-muted">Team Breakdown</strong>
                <ul class="breakdown-list mt-2">
                    ${roles.map(r => `<li>${r}</li>`).join('')}
                </ul>
            </div>
        ` : ''}
    `;
    
    document.getElementById('teamSummary').innerHTML = html;
}

// Initialize summary on page load
setTimeout(updateSummary, 100);
</script>

<?php include '../../includes/footer.php'; ?>