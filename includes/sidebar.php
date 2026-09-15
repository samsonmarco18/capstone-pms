<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/decision-insights.php';

$currentPage = basename($_SERVER['PHP_SELF']);
$currentPath = '/' . trim(str_replace('\\', '/', (string) ($_SERVER['PHP_SELF'] ?? '')), '/');
$user = getCurrentUser();
$role = $user['role'] ?? 'guest';

$menuItems = [
    'admin' => [
        ['label' => 'Dashboard', 'url' => '/modules/admin/dashboard.php', 'icon' => 'tachometer-alt'],
        ['label' => 'Calendar', 'url' => '/calendar.php', 'icon' => 'calendar-alt'],
        ['label' => 'Project Insights', 'url' => '/modules/insights/decision-center', 'icon' => 'lightbulb', 'attention' => true],
        ['label' => 'Process History', 'url' => '/modules/insights/process-history', 'icon' => 'clock-rotate-left'],
        ['label' => 'Tech Stack', 'url' => '/modules/admin/techstack.php', 'icon' => 'microchip'],
        ['label' => 'User Management', 'url' => '/modules/admin/user-management.php', 'icon' => 'users', 'matches' => ['/modules/admin/edit-user.php', '/modules/admin/view-user.php']],
        ['label' => 'Password Resets', 'url' => '/modules/admin/password-reset-requests.php', 'icon' => 'key'],
        ['label' => 'Audit Log', 'url' => '/modules/admin/audit-log.php', 'icon' => 'history'],
        ['label' => 'Settings', 'url' => '/modules/admin/settings.php', 'icon' => 'cog'],
    ],
    'sales' => [
        ['label' => 'Dashboard', 'url' => '/modules/sales/dashboard.php', 'icon' => 'chart-line'],
        ['label' => 'Calendar', 'url' => '/calendar.php', 'icon' => 'calendar-alt'],
        ['label' => 'Project Insights', 'url' => '/modules/insights/decision-center', 'icon' => 'lightbulb', 'attention' => true],
        ['label' => 'Process History', 'url' => '/modules/insights/process-history', 'icon' => 'clock-rotate-left'],
        ['label' => 'Clients', 'url' => '/modules/sales/clients.php', 'icon' => 'building'],
        ['label' => 'Estimation Approval', 'url' => '/modules/sales/estimation-approval.php', 'icon' => 'clipboard-check'],
        ['label' => 'Release Status', 'url' => '/modules/sales/release-status.php', 'icon' => 'bullhorn'],
        ['label' => 'Report', 'url' => '/modules/sales/report.php', 'icon' => 'file-invoice-dollar'],
        ['label' => 'Receipt', 'url' => '/modules/sales/receipt.php', 'icon' => 'receipt'],
    ],
    'architect' => [
        ['label' => 'Dashboard', 'url' => '/modules/architect/dashboard.php', 'icon' => 'drafting-compass'],
        ['label' => 'Calendar', 'url' => '/calendar.php', 'icon' => 'calendar-alt'],
        ['label' => 'Project Insights', 'url' => '/modules/insights/decision-center', 'icon' => 'lightbulb', 'attention' => true],
        ['label' => 'QA Revisions', 'url' => '/modules/architect/qa-revisions.php', 'icon' => 'bell', 'attention_key' => 'qa_revisions'],
        ['label' => 'Process History', 'url' => '/modules/insights/process-history', 'icon' => 'clock-rotate-left'],
        [
            'label' => 'Projects',
            'url' => '/modules/architect/projects.php',
            'icon' => 'project-diagram',
            'matches' => [
                '/modules/architect/project-review.php',
                '/modules/architect/features.php',
                '/modules/architect/tech-recommendation.php',
                '/modules/architect/developer-allocation.php',
                '/modules/roadmap/methodology.php',
                '/modules/architect/upload-diagram.php',
                '/modules/architect/summary.php',
            ],
        ],
        ['label' => 'Final Approval', 'url' => '/modules/architect/final-approval.php', 'icon' => 'code-merge'],
        ['label' => 'Version Control', 'url' => '/modules/version-control/repositories.php', 'icon' => 'code-branch'],
    ],
    'sul' => [
        ['label' => 'Dashboard', 'url' => '/modules/sul/dashboard.php', 'icon' => 'chart-pie'],
        ['label' => 'Calendar', 'url' => '/calendar.php', 'icon' => 'calendar-alt'],
        ['label' => 'Project Insights', 'url' => '/modules/insights/decision-center', 'icon' => 'lightbulb', 'attention' => true],
        ['label' => 'Process History', 'url' => '/modules/insights/process-history', 'icon' => 'clock-rotate-left'],
        ['label' => 'Task Allocation', 'url' => '/modules/sul/task-allocation.php', 'icon' => 'tasks'],
        ['label' => 'Roadmap Monitoring', 'url' => '/modules/roadmap/methodology.php', 'icon' => 'route'],
        ['label' => 'Resource Management', 'url' => '/modules/sul/resource-management.php', 'icon' => 'users-cog'],
        ['label' => 'Performance / Report', 'url' => '/modules/sul/performance-report.php', 'icon' => 'chart-bar'],
        ['label' => 'Version Control', 'url' => '/modules/version-control/repositories.php', 'icon' => 'code-branch'],
    ],
    'qa' => [
        ['label' => 'Dashboard', 'url' => '/modules/qa/dashboard.php', 'icon' => 'chart-pie'],
        ['label' => 'QA Testing', 'url' => '/modules/qa/testing.php', 'icon' => 'clipboard-check'],
        ['label' => 'Calendar', 'url' => '/calendar.php', 'icon' => 'calendar-alt'],
        ['label' => 'Project Insights', 'url' => '/modules/insights/decision-center', 'icon' => 'lightbulb', 'attention' => true],
        ['label' => 'Process History', 'url' => '/modules/insights/process-history', 'icon' => 'clock-rotate-left'],
        ['label' => 'Version Control', 'url' => '/modules/version-control/repositories.php', 'icon' => 'code-branch'],
    ],
    'expert' => [
        ['label' => 'Dashboard', 'url' => '/modules/expert/dashboard.php', 'icon' => 'clipboard-list'],
        ['label' => 'Calendar', 'url' => '/calendar.php', 'icon' => 'calendar-alt'],
        ['label' => 'Project Insights', 'url' => '/modules/insights/decision-center', 'icon' => 'lightbulb', 'attention' => true],
        ['label' => 'Process History', 'url' => '/modules/insights/process-history', 'icon' => 'clock-rotate-left'],
        [
            'label' => 'Project Estimation',
            'url' => '/modules/expert/feature-estimation.php',
            'icon' => 'calculator',
            'matches' => [
                '/modules/expert/cost-estimation.php',
                '/modules/expert/team-composition.php',
            ],
        ],
    ],
    'developer' => [
        ['label' => 'Dashboard', 'url' => '/modules/developer/dashboard.php', 'icon' => 'code'],
        ['label' => 'Calendar', 'url' => '/calendar.php', 'icon' => 'calendar-alt'],
        ['label' => 'Project Insights', 'url' => '/modules/insights/decision-center', 'icon' => 'lightbulb', 'attention' => true],
        ['label' => 'Process History', 'url' => '/modules/insights/process-history', 'icon' => 'clock-rotate-left'],
        ['label' => 'Task', 'url' => '/modules/developer/task.php', 'icon' => 'tasks'],
        ['label' => 'Version Control', 'url' => '/modules/version-control/repositories.php', 'icon' => 'code-branch'],
    ],
];

$currentMenu = $menuItems[$role] ?? [];
$sidebarLogoPath = __DIR__ . '/../assets/img/logoInside.png';
$sidebarLogoVersion = file_exists($sidebarLogoPath) ? (string) filemtime($sidebarLogoPath) : '1';
$projectInsightsAttentionCount = 0;
$projectInsightsAttentionTitle = '';
$qaRevisionNotificationCount = 0;
$qaRevisionNotificationTitle = '';
try {
    if (isset($attentionProjectSummaries) && is_array($attentionProjectSummaries)) {
        $projectInsightsAttentionCount = count($attentionProjectSummaries);
        $projectInsightsAttentionTitle = implode(', ', array_map(
            static fn(array $project): string => (string) ($project['name'] ?? 'Project'),
            array_slice(array_values($attentionProjectSummaries), 0, 4)
        ));
    } elseif (isset($attentionProjectsById) && is_array($attentionProjectsById)) {
        $projectInsightsAttentionCount = count($attentionProjectsById);
    } else {
        $sidebarConn = getConnection();
        $sidebarAttentionProjects = di_load_attention_project_summaries($sidebarConn, $user);
        $projectInsightsAttentionCount = count($sidebarAttentionProjects);
        $projectInsightsAttentionTitle = implode(', ', array_map(
            static fn(array $project): string => (string) ($project['name'] ?? 'Project'),
            array_slice(array_values($sidebarAttentionProjects), 0, 4)
        ));
    }
} catch (Throwable $exception) {
    $projectInsightsAttentionCount = 0;
    $projectInsightsAttentionTitle = '';
}

try {
    if ($role === 'architect') {
        $sidebarConn = isset($sidebarConn) && $sidebarConn ? $sidebarConn : getConnection();
        $architectId = (int) ($user['id'] ?? 0);
        $hasMethodologyTaskDepartments = dbTableExists($sidebarConn, 'methodology_task_departments');
        $hasMethodologyTasks = dbTableExists($sidebarConn, 'methodology_tasks');
        $qaRevisionWhere = "t.status = 'qa_failed'";

        if ($hasMethodologyTaskDepartments && $hasMethodologyTasks) {
            $qaRevisionWhere = "(
                t.status = 'qa_failed'
                OR EXISTS (
                    SELECT 1
                    FROM methodology_task_departments mtd_badge
                    LEFT JOIN methodology_tasks mt_badge ON mt_badge.id = mtd_badge.methodology_task_id
                    WHERE COALESCE(mtd_badge.workflow_task_id, mt_badge.task_id) = t.id
                      AND mtd_badge.status IN ('for_revision', 'planned')
                      AND COALESCE(mtd_badge.revision_notes, '') <> ''
                )
            )";
        }

        $qaRevisionResult = $sidebarConn->query("
            SELECT COUNT(DISTINCT t.id) AS revision_count,
                   GROUP_CONCAT(DISTINCT p.name ORDER BY p.name SEPARATOR ', ') AS project_names
            FROM tasks t
            JOIN projects p ON p.id = t.project_id
            WHERE (p.architect_id = {$architectId} OR p.architect_id IS NULL)
              AND p.status != 'cancelled'
              AND {$qaRevisionWhere}
        ");

        if ($qaRevisionResult) {
            $qaRevisionRow = $qaRevisionResult->fetch_assoc() ?: [];
            $qaRevisionNotificationCount = max(0, (int) ($qaRevisionRow['revision_count'] ?? 0));
            $qaRevisionProjectNames = trim((string) ($qaRevisionRow['project_names'] ?? ''));
            if ($qaRevisionProjectNames !== '') {
                $qaRevisionNotificationTitle = implode(', ', array_slice(array_map('trim', explode(',', $qaRevisionProjectNames)), 0, 4));
            }
        }
    }
} catch (Throwable $exception) {
    $qaRevisionNotificationCount = 0;
    $qaRevisionNotificationTitle = '';
}

function isSidebarItemActive(array $item, string $currentPage, string $currentPath): bool
{
    $itemPage = basename((string) ($item['url'] ?? ''));
    if ($currentPage === $itemPage || $currentPage === $itemPage . '.php') {
        return true;
    }

    foreach ($item['matches'] ?? [] as $matchedPath) {
        if ($currentPath === $matchedPath) {
            return true;
        }
    }

    return false;
}
?>

<aside class="sidebar-wrapper" id="sidebar-wrapper">
    <div class="sidebar-brand">
        <div class="brand-mark">
            <img src="<?php echo BASE_URL; ?>assets/img/logoInside.png?v=<?php echo rawurlencode($sidebarLogoVersion); ?>" alt="ERNI logo">
        </div>
        <div class="brand-copy sidebar-label">
            <div class="brand-title">ERNI</div>
            <small>Development Center<br>Philippines, Inc.</small>

        </div>
    </div>

    <div class="sidebar-role sidebar-label">
        <span>Logged in as</span>
        <strong><?php echo htmlspecialchars(getRoleDisplayName($role)); ?></strong>
    </div>

    <div class="sidebar-nav py-2">
        <?php foreach ($currentMenu as $item): ?>
            <?php
                $itemAttentionKey = (string) ($item['attention_key'] ?? '');
                $itemAttentionCount = !empty($item['attention']) ? $projectInsightsAttentionCount : 0;
                $itemAttentionTitle = !empty($item['attention']) ? $projectInsightsAttentionTitle : '';
                $itemAttentionLabel = 'Project insights need attention';
                $itemAttentionSubject = 'project';
                $itemAttentionVerb = 'need attention';

                if ($itemAttentionKey === 'qa_revisions') {
                    $itemAttentionCount = $qaRevisionNotificationCount;
                    $itemAttentionTitle = $qaRevisionNotificationTitle;
                    $itemAttentionLabel = 'QA revisions need attention';
                    $itemAttentionSubject = 'QA revision';
                    $itemAttentionVerb = 'need attention';
                }
            ?>
            <a
                href="<?php echo BASE_URL . ltrim($item['url'], '/'); ?>"
                class="menu-item <?php echo isSidebarItemActive($item, $currentPage, $currentPath) ? 'active' : ''; ?> <?php echo $itemAttentionCount > 0 ? 'has-attention' : ''; ?>"
            >
                <i class="fas fa-<?php echo htmlspecialchars($item['icon']); ?> menu-icon"></i>
                <span class="menu-label sidebar-label"><?php echo htmlspecialchars($item['label']); ?></span>
                <?php if ($itemAttentionCount > 0): ?>
                    <span class="sidebar-attention-badge" title="<?php echo (int) $itemAttentionCount; ?> <?php echo htmlspecialchars($itemAttentionSubject); ?><?php echo $itemAttentionCount === 1 ? '' : 's'; ?> <?php echo htmlspecialchars($itemAttentionVerb); ?><?php echo $itemAttentionTitle !== '' ? ': ' . htmlspecialchars($itemAttentionTitle) : ''; ?>" aria-label="<?php echo htmlspecialchars($itemAttentionLabel); ?>">
                        <i class="fas fa-exclamation"></i>
                    </span>
                <?php endif; ?>
            </a>
        <?php endforeach; ?>
    </div>

</aside>
