<?php
require_once __DIR__ . '/auth.php';

$currentPage = basename($_SERVER['PHP_SELF']);
$user = getCurrentUser();
$role = $user['role'] ?? 'guest';

$menuItems = [
    'admin' => [
        ['label' => 'Dashboard', 'url' => '/modules/admin/dashboard.php', 'icon' => 'tachometer-alt'],
        ['label' => 'Tech Stack', 'url' => '/modules/admin/techstack.php', 'icon' => 'microchip'],
        ['label' => 'Version Control', 'url' => '/modules/version-control/repositories.php', 'icon' => 'code-branch'],
        ['label' => 'User Management', 'url' => '/modules/admin/user-management.php', 'icon' => 'users'],
        ['label' => 'Audit Log', 'url' => '/modules/admin/audit-log.php', 'icon' => 'history'],
        ['label' => 'Settings', 'url' => '/modules/admin/settings.php', 'icon' => 'cog'],
    ],
    'sales' => [
        ['label' => 'Dashboard', 'url' => '/modules/sales/dashboard.php', 'icon' => 'chart-line'],
        ['label' => 'Clients', 'url' => '/modules/sales/clients.php', 'icon' => 'building'],
        ['label' => 'Report', 'url' => '/modules/sales/report.php', 'icon' => 'file-invoice-dollar'],
        ['label' => 'Receipt', 'url' => '/modules/sales/receipt.php', 'icon' => 'receipt'],
    ],
    'architect' => [
        ['label' => 'Dashboard', 'url' => '/modules/architect/dashboard.php', 'icon' => 'drafting-compass'],
        ['label' => 'Projects', 'url' => '/modules/architect/projects.php', 'icon' => 'project-diagram'],
        ['label' => 'Version Control', 'url' => '/modules/version-control/repositories.php', 'icon' => 'code-branch'],
    ],
    'sul' => [
        ['label' => 'Dashboard', 'url' => '/modules/sul/dashboard.php', 'icon' => 'chart-pie'],
        ['label' => 'Task Allocation', 'url' => '/modules/sul/task-allocation.php', 'icon' => 'tasks'],
        ['label' => 'Resource Management', 'url' => '/modules/sul/resource-management.php', 'icon' => 'users-cog'],
        ['label' => 'Performance / Report', 'url' => '/modules/sul/performance-report.php', 'icon' => 'chart-bar'],
    ],
    'expert' => [
        ['label' => 'Dashboard', 'url' => '/modules/expert/dashboard.php', 'icon' => 'clipboard-list'],
        ['label' => 'Feature Estimation', 'url' => '/modules/expert/feature-estimation.php', 'icon' => 'calculator'],
        ['label' => 'Team Composition', 'url' => '/modules/expert/team-composition.php', 'icon' => 'users'],
        ['label' => 'Cost Estimation', 'url' => '/modules/expert/cost-estimation.php', 'icon' => 'cloud'],
    ],
    'developer' => [
        ['label' => 'Dashboard', 'url' => '/modules/developer/dashboard.php', 'icon' => 'code'],
        ['label' => 'Task', 'url' => '/modules/developer/task.php', 'icon' => 'tasks'],
        ['label' => 'Version Control', 'url' => '/modules/version-control/repositories.php', 'icon' => 'code-branch'],
    ],
];

$currentMenu = $menuItems[$role] ?? [];
?>

<aside class="sidebar-wrapper" id="sidebar-wrapper">
    <div class="sidebar-brand">
        <div class="brand-mark">
            <img src="<?php echo BASE_URL; ?>assets/img/logoInside.png" alt="ERNI logo">
        </div>
        <div class="brand-copy sidebar-label">
            <div class="brand-title">ERNI</div>
            <small>Development Center Philippines, Inc.</small>
        </div>
    </div>

    <div class="sidebar-role sidebar-label">
        <span>Logged in as</span>
        <strong><?php echo htmlspecialchars(getRoleDisplayName($role)); ?></strong>
    </div>

    <div class="sidebar-nav py-2">
        <?php foreach ($currentMenu as $item): ?>
            <a
                href="<?php echo BASE_URL . ltrim($item['url'], '/'); ?>"
                class="menu-item <?php echo $currentPage === basename($item['url']) ? 'active' : ''; ?>"
            >
                <i class="fas fa-<?php echo htmlspecialchars($item['icon']); ?> menu-icon"></i>
                <span class="menu-label sidebar-label"><?php echo htmlspecialchars($item['label']); ?></span>
            </a>
        <?php endforeach; ?>
    </div>

    <div class="sidebar-footer">
        <small>Decision-Driven Project Management Support System</small>
    </div>
</aside>
