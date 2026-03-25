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

include '../../includes/header.php';
include '../../includes/sidebar.php';
include '../../includes/navbar.php';
?>

<div class="row mb-4">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h5>Audit Log - System Activity Tracker</h5>
                <p class="text-muted mb-0">This serves as a backup for deleted items and tracks all system activities</p>
            </div>
            <div class="card-body">
                <!-- Filters -->
                <div class="row mb-4">
                    <div class="col-md-3">
                        <input type="text" id="searchInput" class="form-control" placeholder="Search logs..." value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                    <div class="col-md-2">
                        <select id="actionFilter" class="form-select">
                            <option value="">All Actions</option>
                            <?php while($action = $actions->fetch_assoc()): ?>
                            <option value="<?php echo $action['action']; ?>" <?php echo $actionFilter == $action['action'] ? 'selected' : ''; ?>>
                                <?php echo ucfirst(str_replace('_', ' ', $action['action'])); ?>
                            </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <select id="userFilter" class="form-select">
                            <option value="">All Users</option>
                            <?php while($user = $users->fetch_assoc()): ?>
                            <option value="<?php echo $user['id']; ?>" <?php echo $userFilter == $user['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($user['full_name']); ?>
                            </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <input type="date" id="dateFrom" class="form-control" value="<?php echo $dateFrom; ?>" placeholder="Date From">
                    </div>
                    <div class="col-md-2">
                        <input type="date" id="dateTo" class="form-control" value="<?php echo $dateTo; ?>" placeholder="Date To">
                    </div>
                    <div class="col-md-1">
                        <button class="btn btn-primary w-100" onclick="applyFilters()">
                            <i class="fas fa-search"></i>
                        </button>
                    </div>
                </div>
                
                <!-- Export Button -->
                <div class="mb-3">
                    <button class="btn btn-success btn-sm" onclick="exportLogs()">
                        <i class="fas fa-download"></i> Export to CSV
                    </button>
                    <button class="btn btn-info btn-sm" onclick="printLogs()">
                        <i class="fas fa-print"></i> Print
                    </button>
                </div>
                
                <!-- Logs Table -->
                <div class="table-responsive">
                    <table class="table table-hover" id="logsTable">
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
                            <?php if($logs->num_rows > 0): ?>
                                <?php while($log = $logs->fetch_assoc()): ?>
                                <tr>
                                    <td><?php echo date('M d, Y H:i:s', strtotime($log['created_at'])); ?></td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($log['full_name']); ?></strong>
                                        <br>
                                        <small class="text-muted">@<?php echo htmlspecialchars($log['username']); ?></small>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?php 
                                            echo strpos($log['action'], 'delete') !== false ? 'danger' : 
                                                (strpos($log['action'], 'create') !== false ? 'success' : 
                                                (strpos($log['action'], 'update') !== false ? 'warning' : 'info'));
                                        ?>">
                                            <?php echo ucfirst(str_replace('_', ' ', $log['action'])); ?>
                                        </span>
                                    </td>
                                    <td><?php echo ucfirst($log['table_name']); ?></td>
                                    <td><?php echo $log['record_id']; ?></td>
                                    <td><?php echo $log['ip_address']; ?></td>
                                    <td>
                                        <button class="btn btn-sm btn-info" onclick="viewDetails(<?php echo $log['id']; ?>)">
                                            <i class="fas fa-eye"></i> View
                                        </button>
                                        <?php if(strpos($log['action'], 'delete') !== false && $log['old_data']): ?>
                                        <button class="btn btn-sm btn-success" onclick="restoreItem(<?php echo $log['id']; ?>, '<?php echo $log['table_name']; ?>', <?php echo $log['record_id']; ?>)">
                                            <i class="fas fa-undo"></i> Restore
                                        </button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="7" class="text-center text-muted">No audit logs found</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Log Details Modal -->
<div class="modal fade" id="logDetailsModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Log Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="logDetailsContent">
                <!-- Content loaded via AJAX -->
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
                <div class="row">
                    <div class="col-md-6">
                        <p><strong>Date & Time:</strong> ${new Date(log.created_at).toLocaleString()}</p>
                        <p><strong>User:</strong> ${log.full_name} (${log.username})</p>
                        <p><strong>Action:</strong> ${log.action}</p>
                        <p><strong>Table:</strong> ${log.table_name}</p>
                        <p><strong>Record ID:</strong> ${log.record_id}</p>
                        <p><strong>IP Address:</strong> ${log.ip_address}</p>
                    </div>
                    <div class="col-md-6">
                        ${log.old_data ? `
                            <div class="card">
                                <div class="card-header bg-warning">
                                    <strong>Old Data (Before Change)</strong>
                                </div>
                                <div class="card-body">
                                    <pre class="small">${JSON.stringify(JSON.parse(log.old_data), null, 2)}</pre>
                                </div>
                            </div>
                        ` : ''}
                        ${log.new_data ? `
                            <div class="card mt-3">
                                <div class="card-header bg-success text-white">
                                    <strong>New Data (After Change)</strong>
                                </div>
                                <div class="card-body">
                                    <pre class="small">${JSON.stringify(JSON.parse(log.new_data), null, 2)}</pre>
                                </div>
                            </div>
                        ` : ''}
                    </div>
                </div>
            `;
            $('#logDetailsContent').html(html);
            new bootstrap.Modal(document.getElementById('logDetailsModal')).show();
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
                    showToast('Success', 'Item restored successfully');
                    setTimeout(() => location.reload(), 1500);
                } else {
                    showToast('Error', result.error, 'danger');
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
            if(index < 6) { // Exclude actions column
                rowData.push('"' + td.textContent.trim().replace(/"/g, '""') + '"');
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