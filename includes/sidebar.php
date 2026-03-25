<?php
require_once __DIR__ . '/../includes/auth.php';

$currentPage = basename($_SERVER['PHP_SELF']);
$user = getCurrentUser();
$role = $user['role'] ?? 'guest';

// Array of menu items for each role
$menuItems = [
    'admin' => [
        ['label' => 'Dashboard', 'url' => '/modules/admin/dashboard.php', 'icon' => 'tachometer-alt'],
        ['label' => 'Tech Stack', 'url' => '/modules/admin/techstack.php', 'icon' => 'microchip'],
        ['label' => 'User Management', 'url' => '/modules/admin/user-management.php', 'icon' => 'users'],
        ['label' => 'Audit Log', 'url' => '/modules/admin/audit-log.php', 'icon' => 'history'],
        ['label' => 'Settings', 'url' => '/modules/admin/settings.php', 'icon' => 'cog'],
    ],
    'sales' => [
        ['label' => 'Dashboard', 'url' => '/modules/sales/dashboard.php', 'icon' => 'tachometer-alt'],
        ['label' => 'Clients', 'url' => '/modules/sales/clients.php', 'icon' => 'building'],
        ['label' => 'Reports', 'url' => '/modules/sales/report.php', 'icon' => 'chart-line'],
        ['label' => 'Receipts', 'url' => '/modules/sales/receipt.php', 'icon' => 'receipt'],
    ],
    'architect' => [
        ['label' => 'Dashboard', 'url' => '/modules/architect/dashboard.php', 'icon' => 'tachometer-alt'],
        ['label' => 'Projects', 'url' => '/modules/architect/projects.php', 'icon' => 'project-diagram'],
    ],
    'sul' => [
        ['label' => 'Dashboard', 'url' => '/modules/sul/dashboard.php', 'icon' => 'tachometer-alt'],
        ['label' => 'Task Allocation', 'url' => '/modules/sul/task-allocation.php', 'icon' => 'tasks'],
        ['label' => 'Resource Management', 'url' => '/modules/sul/resource-management.php', 'icon' => 'users-cog'],
        ['label' => 'Performance Report', 'url' => '/modules/sul/performance-report.php', 'icon' => 'chart-bar'],
    ],
    'expert' => [
        ['label' => 'Dashboard', 'url' => '/modules/expert/dashboard.php', 'icon' => 'tachometer-alt'],
        ['label' => 'Feature Estimation', 'url' => '/modules/expert/feature-estimation.php', 'icon' => 'calculator'],
        ['label' => 'Team Composition', 'url' => '/modules/expert/team-composition.php', 'icon' => 'users'],
        ['label' => 'Cost Estimation', 'url' => '/modules/expert/cost-estimation.php', 'icon' => 'dollar-sign'],
    ],
    'developer' => [
        ['label' => 'Dashboard', 'url' => '/modules/developer/dashboard.php', 'icon' => 'tachometer-alt'],
        ['label' => 'Tasks', 'url' => '/modules/developer/task.php', 'icon' => 'tasks'],
    ],
];

$currentMenu = $menuItems[$role] ?? [];
?>

<!-- Sidebar -->
<div class="sidebar-wrapper" id="sidebar-wrapper">
    <!-- Sidebar Header -->
    <div class="p-4 border-bottom">
        <div class="d-flex align-items-center gap-3">
            <div style="width: 45px; height: 45px; border-radius: 10px; background: var(--primary-color); 
                       color: white; display: flex; align-items: center; justify-content: center; font-size: 22px;">
                <i class="fas fa-shield-alt"></i>
            </div>
            <div>
                <div style="color: var(--primary-color); font-weight: 700; font-size: 16px;">ERNI</div>
                <small class="text-muted">Project Management</small>
            </div>
        </div>
    </div>

    <!-- Menu Items -->
    <div class="p-0">
        <?php if (!empty($currentMenu)): ?>
            <?php foreach ($currentMenu as $item): ?>
<a href="<?php echo BASE_URL . $item['url']; ?>" 
                   class="menu-item <?php echo basename($_SERVER['PHP_SELF']) == basename($item['url']) ? 'active' : ''; ?>"
                   title="<?php echo htmlspecialchars($item['label']); ?>">
                    <i class="fas fa-<?php echo $item['icon']; ?> menu-icon"></i>
                    <span class="menu-label sidebar-label"><?php echo htmlspecialchars($item['label']); ?></span>
                </a>
                    <i class="fas fa-<?php echo $item['icon']; ?>" style="width: 20px; color: var(--primary-color);"></i>
                    <span style="flex: 1; font-size: 13px; font-weight: 500;">
                        <?php echo $item['label']; ?>
                    </span>
                </a>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- Sidebar Footer -->
    <div class="border-top p-4 mt-auto" style="position: absolute; bottom: 0; width: 100%; background: white;">
        <div class="text-center text-muted small">
            <p class="mb-2" style="font-size: 11px;">ERNI Development Center</p>
            <p style="font-size: 11px;">Philippines, Inc.</p>
        </div>
    </div>
</div>

<!-- Add styles for sidebar behavior -->
<style>
    #sidebar-wrapper {
        transition: all 0.3s ease;
    }

    #page-content-wrapper {
        margin-left: 280px;
        transition: margin-left 0.3s ease;
        min-height: 100vh;
    }

    #sidebar-wrapper.collapsed {
        width: 72px !important;
    }

    #page-content-wrapper.sidebar-collapsed {
        margin-left: 72px !important;
    }

    /* Mobile sidebar behavior */
    @media (max-width: 768px) {
        #sidebar-wrapper {
            transform: translateX(-100%);
            box-shadow: 2px 0 10px rgba(0, 0, 0, 0.1);
        }

        #sidebar-wrapper.active {
            transform: translateX(0);
        }

        #page-content-wrapper {
            margin-left: 0;
        }
    }

    /* Sidebar link hover effect */
    #sidebar-wrapper a {
        border-left: 4px solid transparent !important;
    }

    #sidebar-wrapper a:hover {
        background-color: rgba(13, 85, 107, 0.05) !important;
        border-left-color: var(--primary-color) !important;
    }

    #sidebar-wrapper a.active,
    #sidebar-wrapper a[href="<?php echo $_SERVER['REQUEST_URI']; ?>"] {
        background-color: rgba(13, 85, 107, 0.1) !important;
        border-left-color: var(--primary-color) !important;
        color: var(--primary-color) !important;
        font-weight: 600;
    }
</style>

<script>
    // Sidebar toggle functionality
    const menuToggle = document.getElementById('menu-toggle');
    const sidebarWrapper = document.getElementById('sidebar-wrapper');

    if (menuToggle) {
        menuToggle.addEventListener('click', function() {
            if (window.innerWidth <= 768) {
                sidebarWrapper.classList.toggle('active');
            }
        });
    }

    // Close sidebar when clicking a link on mobile
    if (window.innerWidth <= 768) {
        const sidebarLinks = sidebarWrapper.querySelectorAll('a');
        sidebarLinks.forEach(link => {
            link.addEventListener('click', function() {
                sidebarWrapper.classList.remove('active');
            });
        });
    }

    // Handle window resize
    window.addEventListener('resize', function() {
        if (window.innerWidth > 768) {
            sidebarWrapper.classList.remove('active');
        }
    });
</script>