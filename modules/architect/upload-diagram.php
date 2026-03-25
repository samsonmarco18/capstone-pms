<?php
require_once '../../includes/auth.php';
requireRole('architect');

$conn = getConnection();
$user = getCurrentUser();
$projectId = isset($_GET['id']) ? $_GET['id'] : 0;

$project = $conn->query("SELECT * FROM projects WHERE id = $projectId")->fetch_assoc();

if (!$project) {
    header('Location: projects.php');
    exit();
}

// Get existing documents
$documents = $conn->query("
    SELECT * FROM architecture_documents 
    WHERE project_id = $projectId 
    ORDER BY created_at DESC
");

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $docType = $_POST['document_type'];
    $description = $_POST['description'];
    
    // Handle file upload
    if(isset($_FILES['diagram']) && $_FILES['diagram']['error'] == 0) {
        $uploadDir = '../../uploads/architecture/';
        if (!file_exists($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }
        
        $fileName = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '', $_FILES['diagram']['name']);
        $filePath = $uploadDir . $fileName;
        $relativePath = str_replace('../../', '', $filePath);
        
        if (move_uploaded_file($_FILES['diagram']['tmp_name'], $filePath)) {
            $stmt = $conn->prepare("INSERT INTO architecture_documents (project_id, document_type, file_path, description, uploaded_by) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param("isssi", $projectId, $docType, $relativePath, $description, $user['id']);
            $stmt->execute();
            
            logActivity($user['id'], 'upload_diagram', 'projects', $projectId, null, $_POST);
            $_SESSION['success'] = "Document uploaded successfully";
        }
    }
    
    header('Location: upload-diagram.php?id=' . $projectId);
    exit();
}

include '../../includes/header.php';
include '../../includes/sidebar.php';
include '../../includes/navbar.php';
?>

<div class="row">
    <div class="col-12 mb-4">
        <h2>Upload Architecture Plan and Workflow Diagram</h2>
        <p class="text-muted">Project: <?php echo htmlspecialchars($project['name']); ?></p>
    </div>
</div>

<div class="row">
    <div class="col-md-6 mb-4">
        <div class="card">
            <div class="card-header">
                <h5>Upload New Document</h5>
            </div>
            <div class="card-body">
                <form method="POST" enctype="multipart/form-data">
                    <div class="mb-3">
                        <label class="form-label">Document Type</label>
                        <select name="document_type" class="form-select" required>
                            <option value="architecture_plan">Architecture Plan</option>
                            <option value="workflow_diagram">Workflow Diagram</option>
                            <option value="system_flow">System Flow Diagram</option>
                            <option value="deployment_diagram">Deployment Diagram</option>
                            <option value="database_schema">Database Schema</option>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea name="description" class="form-control" rows="3" required></textarea>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">File (PDF, Image, or Document)</label>
                        <input type="file" name="diagram" class="form-control" accept=".pdf,.png,.jpg,.jpeg,.doc,.docx" required>
                        <small class="text-muted">Accepted formats: PDF, PNG, JPG, DOC, DOCX (Max 10MB)</small>
                    </div>
                    
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-upload"></i> Upload Document
                    </button>
                    <a href="summary.php?id=<?php echo $projectId; ?>" class="btn btn-success float-end">
                        <i class="fas fa-check"></i> Proceed to Summary
                    </a>
                </form>
            </div>
        </div>
    </div>
    
    <div class="col-md-6 mb-4">
        <div class="card">
            <div class="card-header">
                <h5>Uploaded Documents</h5>
            </div>
            <div class="card-body">
                <?php if($documents->num_rows > 0): ?>
                    <div class="list-group">
                        <?php while($doc = $documents->fetch_assoc()): ?>
                        <div class="list-group-item">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <i class="fas fa-file-<?php 
                                        echo $doc['document_type'] == 'architecture_plan' ? 'code' : 
                                            ($doc['document_type'] == 'workflow_diagram' ? 'project-diagram' : 'file');
                                    ?> fa-2x text-primary me-3"></i>
                                    <strong><?php echo str_replace('_', ' ', ucfirst($doc['document_type'])); ?></strong>
                                    <br>
                                    <small class="text-muted"><?php echo htmlspecialchars($doc['description']); ?></small>
                                    <br>
                                    <small class="text-muted">Uploaded: <?php echo date('M d, Y H:i', strtotime($doc['created_at'])); ?></small>
                                </div>
                                <div>
                                    <a href="<?php echo BASE_URL; ?>/<?php echo $doc['file_path']; ?>" class="btn btn-sm btn-primary" target="_blank">
                                        <i class="fas fa-download"></i> View
                                    </a>
                                    <button class="btn btn-sm btn-danger" onclick="deleteDocument(<?php echo $doc['id']; ?>)">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                        <?php endwhile; ?>
                    </div>
                <?php else: ?>
                    <p class="text-muted text-center">No documents uploaded yet</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h5>Submission Checklist</h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-3">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="check1" <?php echo $documents->num_rows > 0 ? 'checked' : ''; ?> disabled>
                            <label class="form-check-label" for="check1">
                                Architecture Plan Uploaded
                            </label>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="check2" disabled>
                            <label class="form-check-label" for="check2">
                                Workflow Diagram Uploaded
                            </label>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="check3" disabled>
                            <label class="form-check-label" for="check3">
                                Technical Documentation Complete
                            </label>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="check4" disabled>
                            <label class="form-check-label" for="check4">
                                Ready for SUL Review
                            </label>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function deleteDocument(id) {
    if(confirm('Are you sure you want to delete this document?')) {
        $.ajax({
            url: '../../api/delete-document.php',
            method: 'POST',
            data: { id: id },
            success: function() {
                location.reload();
            }
        });
    }
}
</script>

<?php include '../../includes/footer.php'; ?>