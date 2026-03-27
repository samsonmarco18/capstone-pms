<?php
require_once '../../includes/auth.php';
requireRole('admin');

$conn = getConnection();

// Get filters
$search = isset($_GET['search']) ? $_GET['search'] : '';
$actionFilter = isset($_GET['action']) ? $_GET['action'] : '';
$userFilter = isset($_GET['user_id']) ? $_GET['user_id'] : '';
$dateFrom = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$dateTo = isset($_GET['date_to']) ? $_GET['date_to'] : '';

// Build query
$query = "
    SELECT al.*, u.username, u.full_name 
    FROM audit_logs al
    JOIN users u ON al.user_id = u.id
    WHERE 1=1
";

if ($search) {
    $query .= " AND (al.action LIKE '%$search%' OR al.table_name LIKE '%$search%' OR u.full_name LIKE '%$search%')";
}
if ($actionFilter) {
    $query .= " AND al.action = '$actionFilter'";
}
if ($userFilter) {
    $query .= " AND al.user_id = '$userFilter'";
}
if ($dateFrom) {
    $query .= " AND DATE(al.created_at) >= '$dateFrom'";
}
if ($dateTo) {
    $query .= " AND DATE(al.created_at) <= '$dateTo'";
}

$query .= " ORDER BY al.created_at DESC LIMIT 100";

$logs = $conn->query($query);

// Get unique actions for filter
$actions = $conn->query("SELECT DISTINCT action FROM audit_logs ORDER BY action");
$users = $conn->query("SELECT id, full_name FROM users ORDER BY full_name");

// Get statistics
$totalLogs = $conn->query("SELECT COUNT(*) as count FROM audit_logs")->fetch_assoc()['count'];
$todayLogs = $conn->query("SELECT COUNT(*) as count FROM audit_logs WHERE DATE(created_at) = CURDATE()")->fetch_assoc()['count'];
$deleteActions = $conn->query("SELECT COUNT(*) as count FROM audit_logs WHERE action LIKE '%delete%'")->fetch_assoc()['count'];

include '../../includes/header.php';
include '../../includes/sidebar.php';
include '../../includes/navbar.php';
?>

<style>
:root {
    --theme-primary: #1f6392;
    --theme-primary-dark: #0f4a6e;
    --theme-primary-light: #3a7ca3;
    --gray-50: #f8f9fa;
    --gray-100: #f1f3f5;
    --gray-200: #e9ecef;
    --gray-300: #dee2e6;
    --gray-600: #6c757d;
    --gray-900: #212529;
    --shadow-sm: 0 2px 4px rgba(0,0,0,0.02), 0 1px 2px rgba(0,0,0,0.03);
    --shadow-md: 0 4px 6px -1px rgba(0,0,0,0.05), 0 2px 4px -1px rgba(0,0,0,0.03);
    --shadow-lg: 0 10px 15px -3px rgba(0,0,0,0.05), 0 4px 6px -2px rgba(0,0,0,0.02);
}

/* Page Header */
.page-header-modern {
    background: linear-gradient(135deg, var(--theme-primary) 0%, var(--theme-primary-dark) 100%);
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

/* Stats Row */
.stats-row {
    display: flex;
    gap: 1rem;
    margin-bottom: 2rem;
    flex-wrap: wrap;
}

.stat-card-modern {
    background: white;
    border-radius: 20px;
    padding: 1.25rem 1.5rem;
    flex: 1;
    min-width: 180px;
    box-shadow: var(--shadow-sm);
    border: 1px solid var(--gray-200);
    transition: all 0.2s ease;
    position: relative;
}

.stat-card-modern:hover {
    transform: translateY(-2px);
    box-shadow: var(--shadow-md);
}

.stat-card-modern .stat-icon {
    position: absolute;
    right: 1rem;
    top: 1rem;
    opacity: 0.65;
    font-size: 1.6rem;
    color: var(--theme-primary);
}

.stat-card-modern .stat-label {
    font-size: 0.75rem;
    text-transform: uppercase;
    font-weight: 600;
    color: var(--gray-600);
    letter-spacing: 0.5px;
    margin-bottom: 0.5rem;
}

.stat-card-modern .stat-number {
    font-size: 2rem;
    font-weight: 700;
    color: var(--theme-primary);
    line-height: 1;
}

/* Main Card */
.main-card {
    background: white;
    border-radius: 24px;
    border: 1px solid var(--gray-200);
    overflow: hidden;
    box-shadow: var(--shadow-sm);
}

.card-header-modern {
    padding: 1.5rem 2rem;
    background: white;
    border-bottom: 1px solid var(--gray-200);
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 1rem;
}

.card-header-modern h5 {
    font-size: 1.1rem;
    font-weight: 600;
    color: var(--gray-900);
    margin: 0;
}

/* Filter Section */
.filter-section {
    padding: 1.5rem 2rem;
    background: var(--gray-50);
    border-bottom: 1px solid var(--gray-200);
}

.filter-group {
    display: flex;
    gap: 1rem;
    flex-wrap: wrap;
    align-items: flex-end;
}

.filter-input {
    flex: 1;
    min-width: 150px;
}

.filter-input label {
    font-size: 0.7rem;
    text-transform: uppercase;
    font-weight: 600;
    color: var(--gray-600);
    letter-spacing: 0.5px;
    margin-bottom: 0.25rem;
    display: block;
}

.filter-input input,
.filter-input select {
    width: 100%;
    padding: 0.6rem 1rem;
    border: 1px solid var(--gray-300);
    border-radius: 12px;
    font-size: 0.85rem;
    transition: all 0.2s;
}

.filter-input input:focus,
.filter-input select:focus {
    outline: none;
    border-color: var(--theme-primary);
    box-shadow: 0 0 0 3px rgba(31, 99, 146, 0.1);
}

.btn-apply {
    background: linear-gradient(135deg, var(--theme-primary) 0%, var(--theme-primary-dark) 100%);
    color: white;
    border: none;
    padding: 0.6rem 1.5rem;
    border-radius: 12px;
    font-weight: 500;
    transition: all 0.2s;
    cursor: pointer;
}

.btn-apply:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(31, 99, 146, 0.3);
}

/* Action Buttons */
.btn-action {
    background: linear-gradient(135deg, var(--theme-primary) 0%, var(--theme-primary-dark) 100%);
    color: white;
    border: none;
    padding: 0.5rem 1rem;
    border-radius: 10px;
    font-size: 0.8rem;
    font-weight: 500;
    transition: all 0.2s;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
}

.btn-action:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(31, 99, 146, 0.3);
    color: white;
}

.btn-action-success {
    background: linear-gradient(135deg, #10b981 0%, #059669 100%);
    border: none;
    box-shadow: none;
}

.btn-action-success:hover {
    background: linear-gradient(135deg, #059669 0%, #047857 100%);
}

.btn-action-info {
    background: linear-gradient(135deg, #1f6392 0%, #1f6392 100%);
    border: none;
    box-shadow: none;
}

.btn-action-info:hover {
    background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
}

.btn-action-info:focus,
.btn-action-info:focus-visible,
.btn-action-success:focus,
.btn-action-success:focus-visible {
    outline: none;
    box-shadow: none;
    border: none;
}

/* Header Buttons (match Tech Stack Add button) */
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

/* Table Styles */
.table-container {
    padding: 0;
    overflow-x: auto;
}

.audit-table {
    width: 100%;
    border-collapse: collapse;
}

.audit-table thead {
    background: var(--gray-50);
}

.audit-table th {
    padding: 1rem 1.5rem;
    text-align: left;
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: var(--gray-600);
    border-bottom: 1px solid var(--gray-200);
}

.audit-table td {
    padding: 1rem 1.5rem;
    vertical-align: middle;
    border-bottom: 1px solid var(--gray-200);
}

.audit-table tr:hover {
    background: var(--gray-50);
}

.user-info {
    font-weight: 600;
    color: var(--gray-900);
    margin-bottom: 0.25rem;
}

.user-username {
    font-size: 0.7rem;
    color: var(--gray-600);
}

/* Action Badges */
.badge-action {
    display: inline-block;
    padding: 0.3rem 0.8rem;
    border-radius: 20px;
    font-size: 0.7rem;
    font-weight: 600;
    text-transform: uppercase;
}

.badge-action.create {
    background: #d1fae5;
    color: #059669;
}

.badge-action.update {
    background: #fef3c7;
    color: #d97706;
}

.badge-action.delete {
    background: #fee2e2;
    color: #dc2626;
}

.badge-action.login {
    background: #dbeafe;
    color: #2563eb;
}

/* Empty State */
.empty-state-modern {
    text-align: center;
    padding: 4rem 2rem;
}

.empty-state-modern i {
    font-size: 4rem;
    color: var(--gray-300);
    margin-bottom: 1rem;
}

.empty-state-modern h4 {
    color: var(--gray-900);
    margin-bottom: 0.5rem;
}

.empty-state-modern p {
    color: var(--gray-600);
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

.modal-modern .modal-header .btn-close {
    filter: brightness(0) invert(1);
}

.modal-modern .modal-body {
    padding: 2rem;
    max-height: 70vh;
    overflow-y: auto;
}

.modal-modern .modal-footer {
    padding: 1rem 2rem 2rem;
    border-top: 1px solid var(--gray-200);
}

.data-preview {
    background: var(--gray-50);
    border-radius: 12px;
    padding: 1rem;
    margin-top: 1rem;
}

.data-preview pre {
    margin: 0;
    font-size: 0.8rem;
    white-space: pre-wrap;
    word-wrap: break-word;
}

/* Responsive */
@media (max-width: 768px) {
    .page-header-modern {
        padding: 1.5rem;
    }
    
    .stats-row {
        flex-direction: column;
    }
    
    .stat-card-modern {
        min-width: auto;
    }
    
    .filter-group {
        flex-direction: column;
    }
    
    .filter-input {
        width: 100%;
    }
    
    .btn-apply {
        width: 100%;
    }
    
    .audit-table th,
    .audit-table td {
        padding: 0.75rem 1rem;
    }
}
</style>

<div class="container-fluid px-4 py-4">
    <!-- Page Header -->
    <div class="page-header-modern">
        <div class="d-flex flex-wrap justify-content-between align-items-center">
            <div>
                <h2>
                    <i class="fas fa-history me-2"></i> 
                    Audit Log
                </h2>
                <p>Track system activities, user actions, and data changes with complete audit trail</p>
            </div>
            <div class="mt-3 mt-md-0 d-flex gap-2">
                <button class="btn-add-modern" onclick="exportLogs()">
                    <i class="fas fa-download"></i> Export
                </button>
                <button class="btn-add-modern" onclick="printLogs()">
                    <i class="fas fa-print"></i> Print
                </button>
            </div>
        </div>
    </div>

    <!-- Statistics Cards -->
    <div class="stats-row">
        <div class="stat-card-modern">
            <i class="fas fa-database stat-icon"></i>
            <div class="stat-label">Total Activities</div>
            <div class="stat-number"><?php echo $totalLogs; ?></div>
        </div>
        <div class="stat-card-modern">
            <i class="fas fa-calendar-day stat-icon"></i>
            <div class="stat-label">Today's Activity</div>
            <div class="stat-number" style="color: #10b981;"><?php echo $todayLogs; ?></div>
        </div>
        <div class="stat-card-modern">
            <i class="fas fa-trash-alt stat-icon"></i>
            <div class="stat-label">Deleted Items</div>
            <div class="stat-number" style="color: #f59e0b;"><?php echo $deleteActions; ?></div>
        </div>
    </div>

    <!-- Main Content Card -->
    <div class="main-card">
        <div class="card-header-modern">
            <h5>
                <i class="fas fa-list me-2" style="color: var(--theme-primary);"></i>
                Activity Logs
            </h5>
            <div>
                <span class="text-muted small">Showing last 100 entries</span>
            </div>
        </div>
        
        <!-- Filter Section -->
        <div class="filter-section">
            <div class="filter-group">
                <div class="filter-input">
                    <label>Search</label>
                    <input type="text" id="searchInput" placeholder="Action, table, user..." value="<?php echo htmlspecialchars($search); ?>">
                </div>
                <div class="filter-input">
                    <label>Action Type</label>
                    <select id="actionFilter">
                        <option value="">All Actions</option>
                        <?php 
                        $actions->data_seek(0);
                        while($action = $actions->fetch_assoc()): 
                        ?>
                        <option value="<?php echo $action['action']; ?>" <?php echo $actionFilter == $action['action'] ? 'selected' : ''; ?>>
                            <?php echo ucfirst(str_replace('_', ' ', $action['action'])); ?>
                        </option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div class="filter-input">
                    <label>User</label>
                    <select id="userFilter">
                        <option value="">All Users</option>
                        <?php 
                        $users->data_seek(0);
                        while($user = $users->fetch_assoc()): 
                        ?>
                        <option value="<?php echo $user['id']; ?>" <?php echo $userFilter == $user['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($user['full_name']); ?>
                        </option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div class="filter-input">
                    <label>Date From</label>
                    <input type="date" id="dateFrom" value="<?php echo $dateFrom; ?>">
                </div>
                <div class="filter-input">
                    <label>Date To</label>
                    <input type="date" id="dateTo" value="<?php echo $dateTo; ?>">
                </div>
                <div>
                    <button class="btn-apply" onclick="applyFilters()">
                        <i class="fas fa-search me-1"></i> Apply
                    </button>
                </div>
            </div>
        </div>
        
        <!-- Logs Table -->
        <div class="table-container">
            <?php if($logs->num_rows > 0): ?>
            <table class="audit-table" id="logsTable">
                <thead>
                    <tr>
                        <th>Date & Time</th>
                        <th>User</th>
                        <th>Action</th>
                        <th>Table</th>
                        <th>Record ID</th>
                        <th>IP Address</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while($log = $logs->fetch_assoc()): ?>
                    <tr>
                        <td style="white-space: nowrap;">
                            <i class="far fa-clock me-1 text-muted"></i>
                            <?php echo date('M d, Y H:i:s', strtotime($log['created_at'])); ?>
                        </td>
                        <td>
                            <div class="user-info"><?php echo htmlspecialchars($log['full_name']); ?></div>
                            <div class="user-username">@<?php echo htmlspecialchars($log['username']); ?></div>
                        </td>
                        <td>
                            <span class="badge-action <?php 
                                echo strpos($log['action'], 'delete') !== false ? 'delete' : 
                                    (strpos($log['action'], 'create') !== false ? 'create' : 
                                    (strpos($log['action'], 'update') !== false ? 'update' : 'login'));
                            ?>">
                                <?php echo ucfirst(str_replace('_', ' ', $log['action'])); ?>
                            </span>
                        </td>
                        <td>
                            <i class="fas fa-table me-1 text-muted"></i>
                            <?php echo ucfirst($log['table_name']); ?>
                        </td>
                        <td>
                            <span class="badge bg-light text-dark">#<?php echo $log['record_id']; ?></span>
                        </td>
                        <td>
                            <code><?php echo $log['ip_address']; ?></code>
                        </td>
                        <td>
                            <div class="d-flex gap-2">
                                <button class="btn-action-info" style="padding: 0.4rem 0.8rem;" onclick="viewDetails(<?php echo $log['id']; ?>)">
                                    <i class="fas fa-eye text-white"></i>
                                </button>
                                <?php if(strpos($log['action'], 'delete') !== false && $log['old_data']): ?>
                                <button class="btn-action-success" style="padding: 0.4rem 0.8rem;" onclick="restoreItem(<?php echo $log['id']; ?>, '<?php echo $log['table_name']; ?>', <?php echo $log['record_id']; ?>)">
                                    <i class="fas fa-undo text-white"></i>
                                </button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
            <?php else: ?>
            <div class="empty-state-modern">
                <i class="fas fa-clipboard-list"></i>
                <h4>No Audit Logs Found</h4>
                <p>Try adjusting your filters or check back later for system activity</p>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Log Details Modal -->
<div class="modal fade modal-modern" id="logDetailsModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-info-circle me-2"></i> Log Details
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="logDetailsContent">
                <div class="text-center py-4">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <p class="mt-2 text-muted">Loading details...</p>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function applyFilters() {
    const search = document.getElementById('searchInput').value;
    const action = document.getElementById('actionFilter').value;
    const userId = document.getElementById('userFilter').value;
    const dateFrom = document.getElementById('dateFrom').value;
    const dateTo = document.getElementById('dateTo').value;
    
    let url = 'audit-log.php?';
    if (search) url += 'search=' + encodeURIComponent(search) + '&';
    if (action) url += 'action=' + encodeURIComponent(action) + '&';
    if (userId) url += 'user_id=' + encodeURIComponent(userId) + '&';
    if (dateFrom) url += 'date_from=' + encodeURIComponent(dateFrom) + '&';
    if (dateTo) url += 'date_to=' + encodeURIComponent(dateTo);
    
    window.location.href = url;
}

function viewDetails(logId) {
    $.ajax({
        url: '../../api/get-audit-details.php',
        method: 'GET',
        data: { id: logId },
        success: function(response) {
            const log = JSON.parse(response);
            let html = `
                <div class="row g-4">
                    <div class="col-md-6">
                        <div class="data-preview">
                            <h6 class="mb-3"><i class="fas fa-info-circle me-2"></i>Basic Information</h6>
                            <p><strong>Date & Time:</strong><br> ${new Date(log.created_at).toLocaleString()}</p>
                            <p><strong>User:</strong><br> ${log.full_name} (@${log.username})</p>
                            <p><strong>Action:</strong><br> <span class="badge-action ${log.action.includes('delete') ? 'delete' : (log.action.includes('create') ? 'create' : (log.action.includes('update') ? 'update' : 'login'))}">${log.action}</span></p>
                            <p><strong>Table:</strong><br> ${log.table_name}</p>
                            <p><strong>Record ID:</strong><br> #${log.record_id}</p>
                            <p><strong>IP Address:</strong><br> <code>${log.ip_address}</code></p>
                        </div>
                    </div>
                    <div class="col-md-6">
                        ${log.old_data ? `
                            <div class="data-preview">
                                <h6 class="mb-3 text-warning"><i class="fas fa-history me-2"></i>Before Change</h6>
                                <pre><code>${JSON.stringify(JSON.parse(log.old_data), null, 2)}</code></pre>
                            </div>
                        ` : ''}
                        ${log.new_data ? `
                            <div class="data-preview mt-3">
                                <h6 class="mb-3 text-success"><i class="fas fa-save me-2"></i>After Change</h6>
                                <pre><code>${JSON.stringify(JSON.parse(log.new_data), null, 2)}</code></pre>
                            </div>
                        ` : ''}
                    </div>
                </div>
            `;
            $('#logDetailsContent').html(html);
            new bootstrap.Modal(document.getElementById('logDetailsModal')).show();
        },
        error: function() {
            $('#logDetailsContent').html('<div class="text-center py-4"><i class="fas fa-exclamation-circle fa-3x text-danger mb-3"></i><p>Error loading log details</p></div>');
        }
    });
}

function restoreItem(logId, tableName, recordId) {
    if(confirm('Are you sure you want to restore this deleted item? This will recover all associated data.')) {
        $.ajax({
            url: '../../api/restore-item.php',
            method: 'POST',
            data: { 
                log_id: logId,
                table_name: tableName,
                record_id: recordId
            },
            success: function(response) {
                const result = JSON.parse(response);
                if(result.success) {
                    alert('✓ Item restored successfully');
                    setTimeout(() => location.reload(), 1500);
                } else {
                    alert('✗ Error: ' + result.error);
                }
            }
        });
    }
}

function exportLogs() {
    const table = document.getElementById('logsTable');
    let csv = [];
    
    // Get headers
    let headers = [];
    table.querySelectorAll('thead th').forEach(th => {
        headers.push(th.textContent.trim());
    });
    csv.push(headers.join(','));
    
    // Get rows
    table.querySelectorAll('tbody tr').forEach(row => {
        let rowData = [];
        row.querySelectorAll('td').forEach((td, index) => {
            if(index < 6) {
                let text = td.textContent.trim();
                text = text.replace(/@/g, '').replace(/#/g, '');
                rowData.push('"' + text.replace(/"/g, '""') + '"');
            }
        });
        csv.push(rowData.join(','));
    });
    
    // Download
    const blob = new Blob([csv.join('\n')], { type: 'text/csv' });
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `audit_log_${new Date().toISOString().slice(0,19)}.csv`;
    a.click();
    window.URL.revokeObjectURL(url);
}

function printLogs() {
    const printContents = document.getElementById('logsTable').outerHTML;
    const originalContents = document.body.innerHTML;
    document.body.innerHTML = `
        <div class="container mt-4">
            <h2>Audit Log Report</h2>
            <p>Generated on: ${new Date().toLocaleString()}</p>
            ${printContents}
        </div>
    `;
    window.print();
    document.body.innerHTML = originalContents;
    location.reload();
}

// Auto-submit on enter
document.getElementById('searchInput').addEventListener('keypress', function(e) {
    if (e.key === 'Enter') applyFilters();
});
</script>

<?php include '../../includes/footer.php'; ?>
