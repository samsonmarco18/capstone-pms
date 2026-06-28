<?php
require_once '../../includes/auth.php';
requireRole('admin');

$conn = getConnection();
$user = getCurrentUser();

// Handle technology operations (same logic as before)
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['add_technology'])) {
        $name = $_POST['name'];
        $category = $_POST['category'];
        $price = $_POST['price'];
        $expiry_date = $_POST['expiry_date'];
        
        $today = date('Y-m-d');
        if ($expiry_date && $expiry_date < $today) {
            $status = 'expired';
        } elseif ($expiry_date && $expiry_date <= date('Y-m-d', strtotime('+30 days'))) {
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
        
        $oldData = $conn->query("SELECT * FROM technologies WHERE id = $id")->fetch_assoc();
        
        $today = date('Y-m-d');
        if ($expiry_date && $expiry_date < $today) {
            $status = 'expired';
        } elseif ($expiry_date && $expiry_date <= date('Y-m-d', strtotime('+30 days'))) {
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

$conn->query("
    UPDATE technologies
    SET status = CASE
        WHEN expiry_date IS NOT NULL AND expiry_date < CURDATE() THEN 'expired'
        WHEN expiry_date IS NOT NULL AND expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN 'expiring'
        WHEN status <> 'inactive' THEN 'active'
        ELSE status
    END
");
$technologies = $conn->query("SELECT * FROM technologies ORDER BY FIELD(status, 'expired', 'expiring', 'active', 'inactive'), expiry_date ASC");

include '../../includes/header.php';
include '../../includes/sidebar.php';
include '../../includes/navbar.php';
?>

<style>
:root {
    --theme-primary: #1f6392;
    --theme-primary-dark: #0f4a6e;
    --theme-primary-light: #3a7ca3;
    --theme-accent: #2c7da0;
    --gray-50: #f8f9fa;
    --gray-100: #f1f3f5;
    --gray-200: #e9ecef;
    --gray-300: #dee2e6;
    --gray-600: #6c757d;
    --gray-900: #212529;
    --shadow-sm: 0 2px 4px rgba(0,0,0,0.02), 0 1px 2px rgba(0,0,0,0.03);
    --shadow-md: 0 4px 6px -1px rgba(0,0,0,0.05), 0 2px 4px -1px rgba(0,0,0,0.03);
    --shadow-lg: 0 10px 15px -3px rgba(0,0,0,0.05), 0 4px 6px -2px rgba(0,0,0,0.02);
    --shadow-xl: 0 20px 25px -5px rgba(0,0,0,0.05), 0 10px 10px -5px rgba(0,0,0,0.01);
    --tech-surface: #ffffff;
    --tech-surface-alt: #f8fbff;
    --tech-border: var(--gray-200);
    --tech-text: var(--gray-900);
    --tech-muted: var(--gray-600);
    --tech-shadow: var(--shadow-sm);
    --tech-shadow-hover: var(--shadow-xl);
    --tech-panel-gradient: linear-gradient(135deg, var(--gray-50) 0%, #ffffff 100%);
    --tech-panel-tint: rgba(31, 99, 146, 0.1);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) {
    --tech-surface: rgba(8, 20, 33, 0.94);
    --tech-surface-alt: rgba(10, 24, 38, 0.98);
    --tech-border: rgba(138, 210, 255, 0.12);
    --tech-text: #d8e6f3;
    --tech-muted: #90aeca;
    --tech-shadow: 0 10px 28px rgba(0, 0, 0, 0.32);
    --tech-shadow-hover: 0 18px 38px rgba(0, 0, 0, 0.42);
    --tech-panel-gradient: linear-gradient(180deg, rgba(10, 24, 38, 0.98) 0%, rgba(7, 16, 27, 0.98) 100%);
    --tech-panel-tint: rgba(31, 99, 146, 0.16);
}

/* Page Header */
.page-header-modern {
    background: linear-gradient(135deg, var(--theme-primary) 0%, var(--theme-primary-dark) 100%);
    border-radius: 24px;
    padding: 2rem 2rem;
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
    letter-spacing: -0.01em;
    color: white !important;
}

.page-header-modern h2 i {
    color: white !important;
}

.page-header-modern p {
    opacity: 0.9;
    margin-bottom: 0;
    font-size: 0.9rem;
    color: rgba(255, 255, 255, 0.9) !important;
}

/* Add Button */
.btn-add-modern {
    background: rgba(255, 255, 255, 0.15);
    border: 1px solid rgba(255, 255, 255, 0.3);
    color: white !important;
    padding: 0.7rem 1.5rem;
    border-radius: 40px;
    font-weight: 500;
    transition: all 0.2s ease;
    backdrop-filter: blur(10px);
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
}

.btn-add-modern:hover {
    background: white;
    color: var(--theme-primary) !important;
    transform: translateY(-2px);
    border-color: white;
}

.btn-add-modern i {
    color: white !important;
}

.btn-add-modern:hover i {
    color: var(--theme-primary) !important;
}

/* Stats Row */
.stats-row {
    display: flex;
    gap: 1rem;
    margin-bottom: 2rem;
    flex-wrap: wrap;
}

.stat-card-mini {
    background: var(--tech-surface);
    border-radius: 16px;
    padding: 1rem 1.5rem;
    flex: 1;
    min-width: 150px;
    box-shadow: var(--tech-shadow);
    border: 1px solid var(--tech-border);
    transition: all 0.2s ease;
}

.stat-card-mini:hover {
    transform: translateY(-2px);
    box-shadow: var(--shadow-md);
}

.stat-card-mini .stat-label {
    font-size: 0.75rem;
    text-transform: uppercase;
    font-weight: 600;
    color: var(--tech-muted);
    letter-spacing: 0.5px;
}

.stat-card-mini .stat-number {
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--theme-primary);
    margin-top: 0.25rem;
}

/* Technology Cards - Professional Grid */
.tech-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
    gap: 1.5rem;
}

.tech-card-modern {
    background: var(--tech-surface);
    border-radius: 20px;
    overflow: hidden;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    box-shadow: var(--tech-shadow);
    border: 1px solid var(--tech-border);
    position: relative;
}

.tech-card-modern:hover {
    transform: translateY(-4px);
    box-shadow: var(--tech-shadow-hover);
    border-color: var(--theme-primary-light);
}

/* Status Dot */
.status-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    display: inline-block;
    margin-right: 6px;
    background: #9ca3af;
}

.status-dot.active {
    background: #10b981;
}

.status-dot.expiring {
    background: #f59e0b;
}

.status-dot.expired {
    background: #ef4444;
}

.status-text {
    font-size: 0.8rem;
    font-weight: 600;
    color: var(--gray-700, #495057);
    text-transform: uppercase;
    letter-spacing: 0.4px;
}

.status-corner {
    position: absolute;
    top: 12px;
    right: 12px;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 0;
    background: transparent;
}

/* Card Header */
.card-header-modern {
    padding: 1.5rem 1.5rem 0 1.5rem;
    display: flex;
    align-items: center;
    gap: 1rem;
}

.icon-wrapper {
    width: 56px;
    height: 56px;
    background: linear-gradient(135deg, rgba(31, 99, 146, 0.1) 0%, rgba(31, 99, 146, 0.05) 100%);
    border-radius: 18px;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.2s ease;
}

.icon-wrapper i {
    font-size: 28px;
    color: var(--theme-primary);
}

.tech-info h4 {
    font-size: 1.1rem;
    font-weight: 600;
    color: var(--tech-text);
    margin-bottom: 0.25rem;
}

.tech-info .category {
    font-size: 0.75rem;
    color: var(--tech-muted);
    display: inline-block;
    padding: 0.2rem 0.6rem;
    background: var(--tech-surface-alt);
    border-radius: 20px;
}

/* Pricing Section */
.pricing-section {
    padding: 1rem 1.5rem;
    background: var(--tech-panel-gradient);
    margin: 1rem 1.5rem;
    border-radius: 16px;
    text-align: center;
    border: 1px solid var(--tech-border);
}

.price-amount {
    font-size: 2rem;
    font-weight: 700;
    color: var(--theme-primary);
    line-height: 1;
}

.price-period {
    font-size: 0.8rem;
    color: var(--tech-muted);
}

/* Meta Info */
.meta-info {
    padding: 0 1.5rem;
    margin-bottom: 1rem;
}

.meta-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0.5rem 0;
    border-bottom: 1px dashed var(--tech-border);
}

.meta-item:last-child {
    border-bottom: none;
}

.meta-label {
    font-size: 0.75rem;
    color: var(--tech-muted);
}

.meta-value {
    font-size: 0.85rem;
    font-weight: 500;
    color: var(--tech-text);
}

.expiry-warning {
    color: #f59e0b;
    font-size: 0.7rem;
    margin-top: 0.25rem;
}

/* Card Actions */
.card-actions-modern {
    padding: 1rem 1.5rem 1.5rem;
    display: flex;
    gap: 0.75rem;
}

.btn-modern {
    flex: 1;
    padding: 0.6rem;
    border-radius: 12px;
    font-size: 0.8rem;
    font-weight: 500;
    transition: all 0.2s ease;
    cursor: pointer;
    text-align: center;
    border: none;
}

.btn-outline-modern {
    background: transparent;
    border: 1px solid var(--theme-primary);
    color: var(--theme-primary);
}

.btn-outline-modern:hover {
    background: var(--theme-primary);
    color: white;
    transform: translateY(-1px);
}

.btn-danger-modern {
    background: transparent;
    border: 1px solid #ef4444;
    color: #ef4444;
}

.btn-danger-modern:hover {
    background: #ef4444;
    color: white;
    transform: translateY(-1px);
}

.btn-primary-modern {
    background: linear-gradient(135deg, var(--theme-primary) 0%, var(--theme-primary-dark) 100%);
    color: white;
    border: none;
    padding: 0.6rem 1.2rem;
    border-radius: 12px;
    font-size: 0.85rem;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    cursor: pointer;
}

.btn-primary-modern:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(31, 99, 146, 0.3);
}

.btn-success-modern {
    background: linear-gradient(135deg, #10b981 0%, #059669 100%);
    color: white;
}

.btn-success-modern:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
}

/* Empty State */
.empty-state-modern {
    text-align: center;
    padding: 4rem 2rem;
    background: var(--tech-surface);
    border-radius: 24px;
    border: 1px solid var(--tech-border);
    box-shadow: var(--tech-shadow);
}

.empty-state-modern i {
    font-size: 4rem;
    color: var(--tech-muted);
    margin-bottom: 1rem;
}

.empty-state-modern h4 {
    color: var(--tech-text);
    margin-bottom: 0.5rem;
}

/* Modal Styling */
.modal-modern .modal-content {
    border-radius: 24px;
    border: none;
    overflow: hidden;
}

.modal-modern .modal-header {
    background: linear-gradient(135deg, var(--theme-primary) 0%, var(--theme-primary-dark) 100%);
    color: white;
    padding: 1.5rem;
    border: none;
}

.modal-modern .modal-header .modal-title {
    color: white;
}

.modal-modern .modal-body {
    padding: 2rem;
}

.modal-modern .modal-footer {
    padding: 1rem 2rem 2rem;
    border-top: 1px solid var(--tech-border);
}

.modal-modern .modal-content {
    background: var(--tech-surface);
    color: var(--tech-text);
    box-shadow: var(--tech-shadow-hover);
}

.modal-modern .modal-body,
.modal-modern .modal-footer {
    background: var(--tech-surface);
}

.modal-modern .form-label,
.modal-modern .text-muted {
    color: var(--tech-muted) !important;
}

.modal-modern .form-control,
.modal-modern .form-select {
    background: var(--tech-surface-alt);
    border-color: var(--tech-border);
    color: var(--tech-text);
}

.modal-modern .form-control:focus,
.modal-modern .form-select:focus {
    background: var(--tech-surface-alt);
    border-color: var(--theme-primary);
    color: var(--tech-text);
    box-shadow: 0 0 0 0.2rem rgba(31, 99, 146, 0.18);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .page-header-modern {
    box-shadow: 0 16px 38px rgba(0, 0, 0, 0.28);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .page-header-modern::before {
    background: linear-gradient(90deg, transparent, rgba(255,255,255,0.06));
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .stat-card-mini,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .tech-card-modern,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .empty-state-modern,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .modal-modern .modal-content {
    background: var(--tech-surface);
    border-color: var(--tech-border);
    box-shadow: var(--tech-shadow);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .tech-card-modern:hover {
    box-shadow: var(--tech-shadow-hover);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .stat-card-mini .stat-number,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .price-amount {
    color: #8fd3ff;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .tech-info h4,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .empty-state-modern h4,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .meta-value,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .modal-modern .modal-title {
    color: var(--tech-text) !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .stat-card-mini .stat-label,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .tech-info .category,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .price-period,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .meta-label,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .empty-state-modern p,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .expiry-warning,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .modal-modern .text-muted {
    color: var(--tech-muted) !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .tech-info .category {
    background: var(--tech-surface-alt);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .pricing-section {
    background: linear-gradient(180deg, rgba(11, 28, 44, 0.98) 0%, rgba(8, 20, 33, 0.98) 100%);
    border-color: var(--tech-border);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .card-actions-modern .btn-outline-modern {
    border-color: rgba(143, 211, 255, 0.4);
    color: #bfe6ff;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .card-actions-modern .btn-outline-modern:hover {
    background: rgba(31, 99, 146, 0.2);
    color: #ffffff;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .btn-danger-modern {
    border-color: rgba(239, 68, 68, 0.55);
    color: #ff9ca0;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .btn-danger-modern:hover {
    background: rgba(239, 68, 68, 0.9);
    color: #fff;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .btn-primary-modern {
    box-shadow: 0 10px 22px rgba(31, 99, 146, 0.26);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .modal-modern .modal-header {
    background: linear-gradient(135deg, #0f4a6e 0%, #0a3a55 100%);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .modal-modern .modal-footer {
    border-top-color: var(--tech-border);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .modal-modern .form-control::placeholder {
    color: rgba(144, 174, 202, 0.75);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .modal-modern .btn.btn-light {
    background: rgba(144, 174, 202, 0.12);
    border-color: rgba(144, 174, 202, 0.18);
    color: var(--tech-text);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .modal-modern .btn.btn-light:hover {
    background: rgba(144, 174, 202, 0.18);
}

.delete-confirm-note {
    display: flex;
    align-items: flex-start;
    gap: 0.75rem;
    padding: 1rem 1rem;
    border-radius: 16px;
    background: rgba(220, 38, 38, 0.06);
    border: 1px solid rgba(220, 38, 38, 0.12);
    color: var(--tech-text);
}

.delete-confirm-note i {
    color: #dc2626;
    margin-top: 0.15rem;
    flex-shrink: 0;
}

.btn-delete-cancel {
    background: var(--tech-surface-alt);
    border: 1px solid var(--tech-border);
    color: var(--tech-text);
    border-radius: 12px;
    padding: 0.65rem 1.15rem;
    font-weight: 600;
}

.btn-delete-cancel:hover {
    background: var(--tech-panel-tint);
    color: var(--tech-text);
}

.btn-delete-confirm {
    background: linear-gradient(135deg, #dc2626 0%, #b91c1c 100%);
    border: 1px solid #dc2626;
    color: #fff;
    border-radius: 12px;
    padding: 0.65rem 1.15rem;
    font-weight: 600;
    box-shadow: 0 10px 22px rgba(220, 38, 38, 0.18);
}

.btn-delete-confirm:hover {
    background: linear-gradient(135deg, #b91c1c 0%, #991b1b 100%);
    color: #fff;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .delete-confirm-note {
    background: rgba(220, 38, 38, 0.10);
    border-color: rgba(220, 38, 38, 0.16);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .btn-delete-cancel {
    background: var(--tech-surface-alt);
    border-color: var(--tech-border);
    color: var(--tech-text);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .btn-delete-cancel:hover {
    background: rgba(144, 174, 202, 0.12);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .status-text {
    color: #cfe4f4;
}

/* Responsive */
@media (max-width: 768px) {
    .page-header-modern {
        padding: 1.5rem;
    }
    
    .stats-row {
        flex-direction: column;
    }
    
    .tech-grid {
        grid-template-columns: 1fr;
    }
    
    .card-actions-modern {
        flex-wrap: wrap;
    }
    
    .btn-modern {
        min-width: 100px;
    }
}
</style>

<div class="container-fluid px-4 py-4">
    <!-- Modern Header -->
    <div class="page-header-modern">
        <div class="d-flex flex-wrap justify-content-between align-items-center">
            <div>
                <h2>
                    <i class="fas fa-cubes me-2"></i> 
                    Technology Portfolio
                </h2>
                <p>Manage and monitor your organization's technology stack, subscriptions, and renewals</p>
            </div>
            <div class="mt-3 mt-md-0">
                <button class="btn-add-modern" data-bs-toggle="modal" data-bs-target="#addTechnologyModal">
                    <i class="fas fa-plus me-2"></i> Add Technology
                </button>
            </div>
        </div>
    </div>

    <!-- Quick Stats -->
    <?php 
    $activeCount = 0;
    $expiringCount = 0;
    $expiredCount = 0;
    $totalValue = 0;
    
    $technologies->data_seek(0);
    while($tech = $technologies->fetch_assoc()) {
        if($tech['status'] == 'active') $activeCount++;
        elseif($tech['status'] == 'expiring') $expiringCount++;
        elseif($tech['status'] == 'expired') $expiredCount++;
        $totalValue += (float) ($tech['price'] ?? 0);
    }
    $technologies->data_seek(0);
    ?>
    
    <div class="stats-row">
        <div class="stat-card-mini">
            <div class="stat-label">Total Technologies</div>
            <div class="stat-number"><?php echo $technologies->num_rows; ?></div>
        </div>
        <div class="stat-card-mini">
            <div class="stat-label">Active Services</div>
            <div class="stat-number" style="color: #10b981;"><?php echo $activeCount; ?></div>
        </div>
        <div class="stat-card-mini">
            <div class="stat-label">Expiring Soon</div>
            <div class="stat-number" style="color: #f59e0b;"><?php echo $expiringCount; ?></div>
        </div>
        <div class="stat-card-mini">
            <div class="stat-label">Expired Services</div>
            <div class="stat-number" style="color: #ef4444;"><?php echo $expiredCount; ?></div>
        </div>
        <div class="stat-card-mini">
            <div class="stat-label">Monthly Spend</div>
            <div class="stat-number">$<?php echo number_format($totalValue, 0); ?></div>
        </div>
    </div>

    <!-- Technologies Grid -->
    <?php if($technologies->num_rows > 0): ?>
        <div class="tech-grid">
            <?php while($tech = $technologies->fetch_assoc()): ?>
            <div class="tech-card-modern">
                <div class="status-corner">
                    <span class="status-dot <?php echo $tech['status']; ?>"></span>
                    <span class="status-text"><?php echo strtoupper($tech['status']); ?></span>
                </div>
                
                <!-- Card Header -->
                <div class="card-header-modern">
                    <div class="icon-wrapper">
                        <i class="fas fa-<?php 
                            echo $tech['category'] == 'Cloud Platform' ? 'cloud' : 
                                ($tech['category'] == 'Database' ? 'database' : 
                                ($tech['category'] == 'Storage' ? 'hdd' : 
                                ($tech['category'] == 'Networking' ? 'network-wired' : 
                                ($tech['category'] == 'AI/ML' ? 'brain' : 
                                ($tech['category'] == 'IoT' ? 'microchip' : 
                                ($tech['category'] == 'DevOps' ? 'code-branch' : 'shield-alt')))))); 
                        ?>"></i>
                    </div>
                    <div class="tech-info">
                        <h4><?php echo htmlspecialchars($tech['name']); ?></h4>
                        <span class="category"><?php echo htmlspecialchars($tech['category']); ?></span>
                    </div>
                </div>
                
                <!-- Pricing Section -->
                <div class="pricing-section">
                    <span class="price-amount">$<?php echo number_format($tech['price'], 2); ?></span>
                    <span class="price-period">/month</span>
                </div>
                
                <!-- Meta Information -->
                <div class="meta-info">
                    <div class="meta-item">
                        <span class="meta-label">
                            <i class="fas fa-calendar-alt me-1"></i> Expiry Date
                        </span>
                        <span class="meta-value"><?php echo date('M d, Y', strtotime($tech['expiry_date'])); ?></span>
                    </div>
                    <?php 
                    $daysLeft = ceil((strtotime($tech['expiry_date']) - time()) / 86400);
                    if($tech['status'] == 'expiring' && $daysLeft > 0):
                    ?>
                    <div class="expiry-warning">
                        <i class="fas fa-clock me-1"></i> <?php echo $daysLeft; ?> days remaining
                    </div>
                    <?php endif; ?>
                </div>
                
                <!-- Actions -->
                <div class="card-actions-modern">
                    <button class="btn-modern btn-outline-modern" onclick="editTechnology(<?php echo htmlspecialchars(json_encode($tech)); ?>)">
                        <i class="fas fa-edit me-1"></i> Edit
                    </button>
                    <button
                        type="button"
                        class="btn-modern btn-danger-modern"
                        data-tech-id="<?php echo (int) $tech['id']; ?>"
                        data-tech-name="<?php echo htmlspecialchars($tech['name'], ENT_QUOTES); ?>"
                        onclick="openDeleteTechnologyModal(this)"
                    >
                        <i class="fas fa-trash-alt me-1"></i>
                    </button>
                </div>
                
                <?php if(in_array($tech['status'], ['expiring', 'expired'], true)): ?>
                <div style="padding: 0 1.5rem 1.5rem;">
                    <button class="btn-modern btn-success-modern w-100" onclick="openRenewTechnologyModal(<?php echo htmlspecialchars(json_encode($tech)); ?>)">
                        <i class="fas fa-sync-alt me-1"></i> Renew Subscription
                    </button>
                </div>
                <?php endif; ?>
            </div>
            <?php endwhile; ?>
        </div>
    <?php else: ?>
        <div class="empty-state-modern">
            <i class="fas fa-cube"></i>
            <h4>No Technologies in Portfolio</h4>
            <p>Get started by adding your first technology or service</p>
            <button class="btn-primary-modern" style="padding: 0.7rem 2rem; border-radius: 40px;" data-bs-toggle="modal" data-bs-target="#addTechnologyModal">
                <i class="fas fa-plus me-2"></i> Add Your First Technology
            </button>
        </div>
    <?php endif; ?>
</div>

<!-- Delete Technology Modal -->
<div class="modal fade modal-modern" id="deleteTechnologyModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-trash-alt me-2"></i> Delete Technology
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" id="deleteTechnologyForm" data-app-loading data-loading-title="Deleting technology" data-loading-text="Please wait while the technology item is removed.">
                <input type="hidden" name="delete_technology" value="1">
                <input type="hidden" name="id" id="delete_technology_id">
                <div class="modal-body">
                    <div class="delete-confirm-note mb-0">
                        <i class="fas fa-triangle-exclamation fa-lg"></i>
                        <div>
                            <div class="fw-semibold mb-1" id="deleteTechnologyName">Selected technology will appear here.</div>
                            <div class="small text-muted">This action cannot be undone and the technology entry will be removed permanently.</div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-delete-cancel" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn-delete-confirm">
                        <i class="fas fa-trash-alt me-1"></i> Yes, delete it
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Renew Technology Modal -->
<div class="modal fade modal-modern" id="renewTechnologyModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-sync-alt me-2"></i> Renew Subscription
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="renewTechnologyForm">
                <input type="hidden" name="id" id="renew_id">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Technology Name</label>
                        <input type="text" id="renew_name" class="form-control" readonly>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Current Expiry Date</label>
                        <input type="date" id="renew_current_expiry_date" class="form-control" readonly>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Current Monthly Cost ($)</label>
                        <input type="number" id="renew_current_price" class="form-control" step="0.01" readonly>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">New Expiry Date</label>
                        <input type="date" name="expiry_date" id="renew_expiry_date" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Monthly Cost Increase ($)</label>
                        <input type="number" name="price_increase" id="renew_price_increase" class="form-control" step="0.01" min="0" required>
                        <small class="text-muted d-block mt-1">This amount will be added to the current monthly cost.</small>
                    </div>
                    <div class="alert alert-danger d-none mb-0" id="renewError"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn-primary-modern px-4">
                        <i class="fas fa-save me-1"></i> Renew
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Add Technology Modal -->
<div class="modal fade modal-modern" id="addTechnologyModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-plus-circle me-2"></i> Add New Technology
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" data-app-loading data-loading-title="Adding technology" data-loading-text="Please wait while the technology item is saved.">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Technology Name</label>
                        <input type="text" name="name" class="form-control" placeholder="e.g., Microsoft Azure" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Category</label>
                        <select name="category" class="form-select" required>
                            <option value="Cloud Platform">☁️ Cloud Platform</option>
                            <option value="Database">🗄️ Database</option>
                            <option value="Storage">💾 Storage</option>
                            <option value="Networking">🌐 Networking</option>
                            <option value="AI/ML">🧠 AI/ML</option>
                            <option value="IoT">📡 IoT</option>
                            <option value="DevOps">⚙️ DevOps</option>
                            <option value="Security">🔒 Security</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Monthly Price ($)</label>
                        <input type="number" name="price" class="form-control" step="0.01" placeholder="0.00" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Expiry Date</label>
                        <input type="date" name="expiry_date" class="form-control" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="add_technology" class="btn-primary-modern px-4">Add Technology</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Technology Modal -->
<div class="modal fade modal-modern" id="editTechnologyModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-edit me-2"></i> Edit Technology
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" data-app-loading data-loading-title="Updating technology" data-loading-text="Please wait while the technology item is updated.">
                <input type="hidden" name="id" id="edit_id">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Technology Name</label>
                        <input type="text" name="name" id="edit_name" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Category</label>
                        <select name="category" id="edit_category" class="form-select" required>
                            <option value="Cloud Platform">☁️ Cloud Platform</option>
                            <option value="Database">🗄️ Database</option>
                            <option value="Storage">💾 Storage</option>
                            <option value="Networking">🌐 Networking</option>
                            <option value="AI/ML">🧠 AI/ML</option>
                            <option value="IoT">📡 IoT</option>
                            <option value="DevOps">⚙️ DevOps</option>
                            <option value="Security">🔒 Security</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Monthly Price ($)</label>
                        <input type="number" name="price" id="edit_price" class="form-control" step="0.01" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Expiry Date</label>
                        <input type="date" name="expiry_date" id="edit_expiry_date" class="form-control" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="update_technology" class="btn-primary-modern px-4">Update Technology</button>
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

function openDeleteTechnologyModal(button) {
    const techId = button.getAttribute('data-tech-id') || '';
    const techName = button.getAttribute('data-tech-name') || 'this technology';

    document.getElementById('delete_technology_id').value = techId;
    document.getElementById('deleteTechnologyName').textContent = `Technology: ${techName}`;

    new bootstrap.Modal(document.getElementById('deleteTechnologyModal')).show();
}

function openRenewTechnologyModal(tech) {
    document.getElementById('renew_id').value = tech.id;
    document.getElementById('renew_name').value = tech.name;
    document.getElementById('renew_current_expiry_date').value = tech.expiry_date || '';
    document.getElementById('renew_current_price').value = Number(tech.price || 0).toFixed(2);
    document.getElementById('renew_price_increase').value = Number(tech.price || 0).toFixed(2);

    const currentDate = tech.expiry_date ? new Date(`${tech.expiry_date}T00:00:00`) : new Date();
    const baseDate = currentDate < new Date() ? new Date() : currentDate;
    baseDate.setFullYear(baseDate.getFullYear() + 1);
    document.getElementById('renew_expiry_date').value = baseDate.toISOString().slice(0, 10);

    document.getElementById('renewError').classList.add('d-none');
    new bootstrap.Modal(document.getElementById('renewTechnologyModal')).show();
}

document.getElementById('renewTechnologyForm').addEventListener('submit', function(e) {
    e.preventDefault();

    const errorBox = document.getElementById('renewError');
    errorBox.classList.add('d-none');

    $.ajax({
        url: '../../api/renew-technology.php',
        method: 'POST',
        data: $(this).serialize(),
        success: function(response) {
            const result = typeof response === 'string' ? JSON.parse(response) : response;
            if (result.success) {
                location.reload();
                return;
            }

            errorBox.textContent = result.error || 'Unable to renew subscription';
            errorBox.classList.remove('d-none');
        },
        error: function(xhr) {
            let message = 'Unable to renew subscription';
            try {
                message = JSON.parse(xhr.responseText).error || message;
            } catch (error) {}
            errorBox.textContent = message;
            errorBox.classList.remove('d-none');
        }
    });
});
</script>

<?php
include '../../includes/footer.php';
?>
