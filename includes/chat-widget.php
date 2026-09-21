<?php
$isSulRoute = str_contains($_SERVER['REQUEST_URI'] ?? '', '/modules/sul/');
$evortixProjectTeamNotification = null;
$evortixSulRevisionNotification = null;
$evortixRoleDashboardSummary = null;
$evortixCompilationImpact = is_array($_SESSION['evortix_change_impact'] ?? null) ? $_SESSION['evortix_change_impact'] : null;
$evortixDecisionRoute = preg_match('#/modules/(architect/tech-recommendation|architect/developer-allocation|roadmap/methodology)\.php#', (string) ($_SERVER['REQUEST_URI'] ?? '')) === 1;
$evortixDeferredCompilationImpact = $evortixDecisionRoute ? $evortixCompilationImpact : null;
if ($evortixDecisionRoute) $evortixCompilationImpact = null;
if ($evortixCompilationImpact) unset($_SESSION['evortix_change_impact']);
if ($evortixDeferredCompilationImpact) unset($_SESSION['evortix_change_impact']);
if (function_exists('isLoggedIn') && isLoggedIn()) {
    try {
        $evortixUser = getCurrentUser();
        $evortixUserId = (int) ($evortixUser['id'] ?? 0);
        if ($evortixUserId > 0) {
            $evortixStmt = getConnection()->prepare(
                "SELECT id, title, message, action_url, related_id
                 FROM notifications
                 WHERE user_id = ?
                   AND is_read = FALSE
                   AND related_table = 'projects'
                   AND title LIKE 'Project team message:%'
                 ORDER BY created_at DESC, id DESC
                 LIMIT 1"
            );
            if ($evortixStmt) {
                $evortixStmt->bind_param('i', $evortixUserId);
                $evortixStmt->execute();
                $evortixProjectTeamNotification = $evortixStmt->get_result()->fetch_assoc() ?: null;
                if ($evortixProjectTeamNotification && (int) ($evortixProjectTeamNotification['related_id'] ?? 0) > 0) {
                    $evortixProjectTeamNotification['action_url'] = BASE_URL
                        . 'modules/roadmap/methodology.php?project_id='
                        . (int) $evortixProjectTeamNotification['related_id'];
                }
                $evortixStmt->close();
            }
            $revisionStmt = getConnection()->prepare(
                "SELECT id, title, message, action_url, related_table, related_id
                 FROM notifications
                 WHERE user_id = ?
                   AND is_read = FALSE
                   AND (
                       title LIKE '%Revision Requested:%'
                       OR message LIKE 'SUL requested revision%'
                       OR title LIKE 'QA Revision Assigned:%'
                       OR title LIKE 'QA Revision Queued:%'
                       OR title LIKE 'QA Revision Now Assigned:%'
                       OR title LIKE 'QA Passed:%'
                   )
                 ORDER BY created_at DESC, id DESC
                 LIMIT 1"
            );
            if ($revisionStmt) {
                $revisionStmt->bind_param('i', $evortixUserId);
                $revisionStmt->execute();
                $evortixSulRevisionNotification = $revisionStmt->get_result()->fetch_assoc() ?: null;
                $revisionStmt->close();
            }
        }
    } catch (Throwable $e) {
        error_log('Evortix project notification load failed: ' . $e->getMessage());
    }
}

$evortixRequestPath = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
if (
    preg_match('#/modules/(admin|sales|architect|sul|qa|expert|developer)/dashboard\.php$#', $evortixRequestPath, $evortixDashboardMatch)
) {
    try {
        $summaryUser = getCurrentUser();
        $summaryRole = (string) ($summaryUser['role'] ?? '');
        $summaryUserId = (int) ($summaryUser['id'] ?? 0);
        if ($summaryRole === (string) ($evortixDashboardMatch[1] ?? '') && $summaryUserId > 0) {
            $summaryConn = getConnection();
            $summaryCount = static function (string $sql) use ($summaryConn): int {
                try {
                    $result = $summaryConn->query($sql);
                    return $result ? (int) (($result->fetch_assoc()['count'] ?? 0)) : 0;
                } catch (Throwable $e) {
                    error_log('Evortix dashboard metric unavailable: ' . $e->getMessage());
                    return 0;
                }
            };
            $summaryFirstId = static function (string $sql) use ($summaryConn): int {
                try {
                    $result = $summaryConn->query($sql);
                    return $result ? (int) (($result->fetch_assoc()['id'] ?? 0)) : 0;
                } catch (Throwable $e) {
                    error_log('Evortix dashboard navigation unavailable: ' . $e->getMessage());
                    return 0;
                }
            };
            $adminPasswordRequests = 0;
            $adminTechnologyCount = 0;
            $adminUserCount = 0;
            $adminSecurityReports = 0;
            $adminExpiringTechnologyCount = 0;
            $adminExpiringTechnologies = [];
            $adminLoginThreat = null;
            $adminOverviewItem = null;
            $adminOverviewItems = [];
            $adminWatchGroups = [];
            if ($summaryRole === 'admin') {
                $adminPasswordRequests = dbTableExists($summaryConn, 'password_reset_requests')
                    ? $summaryCount("SELECT COUNT(*) AS count FROM password_reset_requests WHERE status = 'pending'")
                    : 0;
                $adminTechnologyCount = $summaryCount("SELECT COUNT(*) AS count FROM technologies");
                $adminUserCount = $summaryCount("SELECT COUNT(*) AS count FROM users");
                $adminSecurityReports = $summaryCount("SELECT COUNT(*) AS count FROM audit_logs WHERE action LIKE '%security%' OR action LIKE 'login_failed%'");
                $adminExpiringTechnologyCount = $summaryCount("
                    SELECT COUNT(*) AS count
                    FROM technologies
                    WHERE status IN ('expiring', 'expired')
                       OR (expiry_date IS NOT NULL AND expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY))
                ");
                $adminExpiringResult = $summaryConn->query("
                    SELECT id, name, category, status, expiry_date,
                           CASE WHEN expiry_date IS NULL THEN NULL ELSE DATEDIFF(expiry_date, CURDATE()) END AS days_left
                    FROM technologies
                    WHERE status IN ('expiring', 'expired')
                       OR (expiry_date IS NOT NULL AND expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY))
                    ORDER BY CASE WHEN expiry_date IS NULL THEN 1 ELSE 0 END, expiry_date ASC, id ASC
                    LIMIT 5
                ");
                if ($adminExpiringResult) {
                    while ($expiringTechnology = $adminExpiringResult->fetch_assoc()) {
                        $adminExpiringTechnologies[] = $expiringTechnology;
                    }
                }

                $loginThreatResult = $summaryConn->query("
                    SELECT al.user_id, al.ip_address, MAX(al.created_at) AS last_seen,
                           COUNT(*) AS attempt_count,
                           SUM(CASE WHEN al.action = 'login_failed_locked' THEN 1 ELSE 0 END) AS lock_count,
                           u.full_name, u.email
                    FROM audit_logs al
                    LEFT JOIN users u ON u.id = al.user_id
                    WHERE al.action IN (
                        'login_failed_wrong_password',
                        'login_failed_inactive',
                        'login_failed_locked',
                        'login_failed_user_not_found'
                    )
                      AND al.created_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)
                    GROUP BY al.user_id, al.ip_address, u.full_name, u.email
                    HAVING attempt_count >= 3 OR lock_count > 0
                    ORDER BY lock_count DESC, attempt_count DESC, last_seen DESC
                    LIMIT 1
                ");
                $adminLoginThreat = $loginThreatResult ? ($loginThreatResult->fetch_assoc() ?: null) : null;

                if ($adminLoginThreat) {
                    $threatName = trim((string) ($adminLoginThreat['full_name'] ?? ''));
                    $threatEmail = trim((string) ($adminLoginThreat['email'] ?? ''));
                    $threatTarget = $threatName !== ''
                        ? $threatName
                        : ($threatEmail !== '' ? $threatEmail : 'Unknown account');
                    $threatIp = trim((string) ($adminLoginThreat['ip_address'] ?? 'Unknown IP'));
                    $lockCount = (int) ($adminLoginThreat['lock_count'] ?? 0);
                    $attemptCount = (int) ($adminLoginThreat['attempt_count'] ?? 0);
                    $adminOverviewItem = [
                        'icon' => 'fa-user-lock',
                        'title' => 'Brute-force lockout watch: ' . $threatTarget,
                        'meta' => $attemptCount . ' failed attempt' . ($attemptCount === 1 ? '' : 's')
                            . ($lockCount > 0 ? ' · ' . $lockCount . ' lockout' . ($lockCount === 1 ? '' : 's') : '')
                            . ' · ' . $threatIp,
                        'action_url' => BASE_URL . 'modules/admin/audit-log.php?search=login_failed#auditDirectory',
                    ];
                } elseif ($adminExpiringTechnologies !== []) {
                    $expiringTechnology = $adminExpiringTechnologies[0];
                    $daysLeft = isset($expiringTechnology['days_left']) ? (int) $expiringTechnology['days_left'] : null;
                    $expiryLabel = $daysLeft === null
                        ? 'expiry date needs review'
                        : ($daysLeft < 0
                            ? 'expired ' . abs($daysLeft) . ' day' . (abs($daysLeft) === 1 ? '' : 's') . ' ago'
                            : ($daysLeft === 0 ? 'expires today' : 'expires in ' . $daysLeft . ' day' . ($daysLeft === 1 ? '' : 's')));
                    $adminOverviewItem = [
                        'icon' => 'fa-clock-rotate-left',
                        'title' => 'Renew: ' . (string) ($expiringTechnology['name'] ?? 'technology stack'),
                        'meta' => (string) ($expiringTechnology['category'] ?? 'Technology')
                            . ' · ' . $expiryLabel
                            . ' · ' . $adminExpiringTechnologyCount . ' renewal signal' . ($adminExpiringTechnologyCount === 1 ? '' : 's'),
                        'action_url' => BASE_URL . 'modules/admin/techstack.php?status=expiring#technologyDirectory',
                    ];
                } elseif ($adminPasswordRequests > 0) {
                    $adminOverviewItem = [
                        'icon' => 'fa-heart-pulse',
                        'title' => 'Review password requests',
                        'meta' => "{$adminPasswordRequests} pending account recovery",
                        'action_url' => BASE_URL . 'modules/admin/password-reset-requests.php',
                    ];
                } elseif ($adminSecurityReports > 0) {
                    $adminOverviewItem = [
                        'icon' => 'fa-shield-halved',
                        'title' => 'Review security reports',
                        'meta' => "{$adminSecurityReports} audit-backed security events",
                        'action_url' => BASE_URL . 'modules/admin/audit-log.php?search=security#auditDirectory',
                    ];
                } else {
                    $adminOverviewItem = [
                        'icon' => 'fa-users',
                        'title' => 'Open user directory',
                        'meta' => "{$adminUserCount} users · Manage roles and access",
                        'action_url' => BASE_URL . 'modules/admin/user-management.php',
                    ];
                }
                if ($adminExpiringTechnologies !== []) {
                    $renewalTechnology = $adminExpiringTechnologies[0];
                    $renewalDaysLeft = isset($renewalTechnology['days_left']) ? (int) $renewalTechnology['days_left'] : null;
                    $renewalExpiryLabel = $renewalDaysLeft === null
                        ? 'Expiry date needs review'
                        : ($renewalDaysLeft < 0
                            ? 'Expired ' . abs($renewalDaysLeft) . ' day' . (abs($renewalDaysLeft) === 1 ? '' : 's') . ' ago'
                            : ($renewalDaysLeft === 0 ? 'Expires today' : 'Expires in ' . $renewalDaysLeft . ' day' . ($renewalDaysLeft === 1 ? '' : 's')));
                    $adminOverviewItems[] = [
                        'icon' => 'fa-clock-rotate-left',
                        'title' => 'Renewal Watch',
                        'meta' => (string) ($renewalTechnology['name'] ?? 'Technology stack') . ' - ' . $renewalExpiryLabel,
                        'action_url' => BASE_URL . 'modules/admin/techstack.php?status=expiring#technologyDirectory',
                    ];
                } else {
                    $adminOverviewItems[] = [
                        'icon' => 'fa-circle-check',
                        'title' => 'Renewal Watch',
                        'meta' => 'No technology stack is expiring in the next 30 days.',
                        'action_url' => BASE_URL . 'modules/admin/techstack.php?status=expiring#technologyDirectory',
                    ];
                }

                if ($adminLoginThreat) {
                    $adminOverviewItems[] = [
                        'icon' => 'fa-user-lock',
                        'title' => 'Login Attack Watch',
                        'meta' => (string) ($adminOverviewItem['meta'] ?? 'Review recent failed login activity.'),
                        'action_url' => BASE_URL . 'modules/admin/audit-log.php?search=login_failed#auditDirectory',
                    ];
                } else {
                    $adminOverviewItems[] = [
                        'icon' => 'fa-shield-halved',
                        'title' => 'Login Attack Watch',
                        'meta' => 'No brute-force lockout pattern detected in the last 15 minutes.',
                        'action_url' => BASE_URL . 'modules/admin/audit-log.php?search=login_failed#auditDirectory',
                    ];
                }
                $adminRenewalRows = [];
                foreach ($adminExpiringTechnologies as $renewalRow) {
                    $rowDaysLeft = isset($renewalRow['days_left']) ? (int) $renewalRow['days_left'] : null;
                    $rowExpiryLabel = $rowDaysLeft === null
                        ? 'Expiry date needs review'
                        : ($rowDaysLeft < 0
                            ? 'Expired ' . abs($rowDaysLeft) . ' day' . (abs($rowDaysLeft) === 1 ? '' : 's') . ' ago'
                            : ($rowDaysLeft === 0 ? 'Expires today' : 'Expires in ' . $rowDaysLeft . ' day' . ($rowDaysLeft === 1 ? '' : 's')));
                    $adminRenewalRows[] = [
                        'name' => (string) ($renewalRow['name'] ?? 'Technology stack'),
                        'meta' => (string) ($renewalRow['category'] ?? 'Technology') . ' - ' . $rowExpiryLabel,
                        'url' => BASE_URL . 'modules/admin/techstack.php?status=expiring#technologyDirectory',
                    ];
                }

                $adminLoginRows = [];
                if ($adminLoginThreat) {
                    $adminLoginRows[] = [
                        'name' => 'Login Attack Watch',
                        'meta' => (string) ($adminOverviewItems[1]['meta'] ?? 'Review recent failed login activity.'),
                        'url' => BASE_URL . 'modules/admin/audit-log.php?search=login_failed#auditDirectory',
                    ];
                }

                $adminRecoveryRows = [];
                if ($adminPasswordRequests > 0) {
                    $adminRecoveryRows[] = [
                        'name' => 'Password reset requests',
                        'meta' => $adminPasswordRequests . ' pending account recovery',
                        'url' => BASE_URL . 'modules/admin/password-reset-requests.php',
                    ];
                }

                $adminWatchGroups = [
                    'renewals' => [
                        'label' => 'Renewals',
                        'icon' => 'fa-clock-rotate-left',
                        'total' => $adminExpiringTechnologyCount,
                        'list_title' => 'Renewal Watch',
                        'entity_label' => 'technology',
                        'empty' => 'No technology stack is expiring in the next 30 days.',
                        'items' => $adminRenewalRows,
                    ],
                    'login' => [
                        'label' => 'Login',
                        'icon' => 'fa-user-lock',
                        'total' => $adminLoginThreat ? 1 : 0,
                        'list_title' => 'Login Attack Watch',
                        'entity_label' => 'signal',
                        'empty' => 'No brute-force lockout pattern detected in the last 15 minutes.',
                        'items' => $adminLoginRows,
                    ],
                    'recovery' => [
                        'label' => 'Recovery',
                        'icon' => 'fa-heart-pulse',
                        'total' => $adminPasswordRequests,
                        'list_title' => 'Password Recovery',
                        'entity_label' => 'request',
                        'empty' => 'No password recovery requests are pending.',
                        'items' => $adminRecoveryRows,
                    ],
                ];
            }
            $roleSummaries = [
                'admin' => [
                    'variant' => 'tabbed',
                    'title' => 'Live Overview',
                    'intro' => "{$adminPasswordRequests} password requests, {$adminExpiringTechnologyCount} expiring technologies, {$adminUserCount} users, and {$adminSecurityReports} login/security events.",
                    'item' => $adminOverviewItem,
                    'items' => $adminOverviewItems,
                    'tab_groups' => $adminWatchGroups,
                    'tab_order' => ['renewals', 'login', 'recovery'],
                    'metrics' => [],
                ],
                'architect' => [
                    'title' => 'Your architect dashboard snapshot',
                    'metrics' => [
                        ['icon' => 'fa-folder-open', 'label' => 'Total Projects', 'value' => $summaryCount("SELECT COUNT(*) AS count FROM projects WHERE architect_id = {$summaryUserId} OR architect_id IS NULL"), 'meta' => 'In your architecture scope'],
                        ['icon' => 'fa-hourglass-half', 'label' => 'Pending Review', 'value' => $summaryCount("SELECT COUNT(*) AS count FROM projects WHERE (architect_id = {$summaryUserId} OR architect_id IS NULL) AND status IN ('pending','review','architectural_review')"), 'meta' => 'Needs architecture action'],
                        ['icon' => 'fa-gears', 'label' => 'In Progress', 'value' => $summaryCount("SELECT COUNT(*) AS count FROM projects WHERE (architect_id = {$summaryUserId} OR architect_id IS NULL) AND status NOT IN ('pending','completed','cancelled')"), 'meta' => 'Active technical work'],
                        ['icon' => 'fa-triangle-exclamation', 'label' => 'Overdue', 'value' => $summaryCount("SELECT COUNT(*) AS count FROM projects WHERE (architect_id = {$summaryUserId} OR architect_id IS NULL) AND timeline_end < CURRENT_DATE AND status NOT IN ('completed','cancelled')"), 'meta' => 'Past project deadline', 'overdue' => true],
                    ],
                ],
                'qa' => [
                    'title' => 'Your QA dashboard snapshot',
                    'metrics' => [
                        ['icon' => 'fa-vial', 'label' => 'Ready for Testing', 'value' => $summaryCount("SELECT COUNT(*) AS count FROM tasks WHERE status = 'qa_testing'"), 'meta' => 'Awaiting QA'],
                        ['icon' => 'fa-circle-xmark', 'label' => 'Failed Checks', 'value' => $summaryCount("SELECT COUNT(*) AS count FROM tasks WHERE status = 'qa_failed'"), 'meta' => 'Returned for fixes'],
                        ['icon' => 'fa-circle-check', 'label' => 'QA Passed', 'value' => $summaryCount("SELECT COUNT(*) AS count FROM tasks WHERE status = 'qa_passed'"), 'meta' => 'Ready for final review'],
                        ['icon' => 'fa-triangle-exclamation', 'label' => 'Overdue', 'value' => $summaryCount("SELECT COUNT(*) AS count FROM tasks WHERE deadline < NOW() AND status IN ('qa_testing','qa_failed')"), 'meta' => 'Needs QA attention', 'overdue' => true],
                    ],
                ],
                'expert' => [
                    'title' => 'Your expert dashboard snapshot',
                    'metrics' => [
                        ['icon' => 'fa-list-check', 'label' => 'Assigned Tasks', 'value' => $summaryCount("SELECT COUNT(*) AS count FROM tasks t JOIN projects p ON p.id = t.project_id WHERE t.assigned_to = {$summaryUserId} AND t.status != 'completed' AND p.status != 'cancelled'"), 'meta' => 'Current workload'],
                        ['icon' => 'fa-calculator', 'label' => 'To Estimate', 'value' => $summaryCount("SELECT COUNT(*) AS count FROM tasks t JOIN projects p ON p.id = t.project_id WHERE t.assigned_to = {$summaryUserId} AND t.status = 'todo' AND p.status != 'cancelled'"), 'meta' => 'Needs estimation'],
                        ['icon' => 'fa-paper-plane', 'label' => 'In Review', 'value' => $summaryCount("SELECT COUNT(*) AS count FROM tasks t JOIN projects p ON p.id = t.project_id WHERE t.assigned_to = {$summaryUserId} AND t.status = 'review' AND p.status != 'cancelled'"), 'meta' => 'Submitted estimates'],
                        ['icon' => 'fa-triangle-exclamation', 'label' => 'Overdue', 'value' => $summaryCount("SELECT COUNT(*) AS count FROM tasks t JOIN projects p ON p.id = t.project_id WHERE t.assigned_to = {$summaryUserId} AND t.deadline < NOW() AND t.status != 'completed' AND p.status != 'cancelled'"), 'meta' => 'Needs attention', 'overdue' => true],
                    ],
                ],
                'developer' => [
                    'title' => 'Your developer dashboard snapshot',
                    'metrics' => [
                        ['icon' => 'fa-list-check', 'label' => 'Assigned Tasks', 'value' => $summaryCount("SELECT COUNT(*) AS count FROM tasks t JOIN projects p ON p.id = t.project_id WHERE t.assigned_to = {$summaryUserId} AND p.status != 'cancelled'"), 'meta' => 'All assigned work'],
                        ['icon' => 'fa-play', 'label' => 'In Progress', 'value' => $summaryCount("SELECT COUNT(*) AS count FROM tasks t JOIN projects p ON p.id = t.project_id WHERE t.assigned_to = {$summaryUserId} AND t.status = 'in_progress' AND p.status != 'cancelled'"), 'meta' => 'Currently active'],
                        ['icon' => 'fa-circle-check', 'label' => 'Completed', 'value' => $summaryCount("SELECT COUNT(*) AS count FROM tasks t JOIN projects p ON p.id = t.project_id WHERE t.assigned_to = {$summaryUserId} AND t.status = 'completed' AND p.status != 'cancelled'"), 'meta' => 'Finished tasks'],
                        ['icon' => 'fa-triangle-exclamation', 'label' => 'Overdue', 'value' => $summaryCount("SELECT COUNT(*) AS count FROM tasks t JOIN projects p ON p.id = t.project_id WHERE t.assigned_to = {$summaryUserId} AND t.deadline < NOW() AND t.status != 'completed' AND p.status != 'cancelled'"), 'meta' => 'Needs attention', 'overdue' => true],
                    ],
                ],
            ];
            $compactMetricIndexesByRole = [
                'architect' => [1, 2, 3],
                'qa' => [0, 1, 3],
                'expert' => [0, 1, 3],
                'developer' => [1, 2, 3],
            ];
            $compactActionUrlsByRole = [
                'qa' => BASE_URL . 'modules/qa/testing.php',
            ];
            foreach ($compactMetricIndexesByRole as $compactRole => $metricIndexes) {
                $compactMetrics = array_values(array_filter(array_map(
                    static fn(int $index): ?array => $roleSummaries[$compactRole]['metrics'][$index] ?? null,
                    $metricIndexes
                )));
                $introParts = array_map(
                    static fn(array $metric): string => (int) ($metric['value'] ?? 0)
                        . ' '
                        . strtolower((string) ($metric['label'] ?? 'items')),
                    $compactMetrics
                );
                $lastIntroPart = array_pop($introParts);
                $compactIntro = $lastIntroPart === null
                    ? 'No dashboard items need attention.'
                    : ($introParts === []
                        ? $lastIntroPart . '.'
                        : implode(', ', $introParts) . ', and ' . $lastIntroPart . '.');
                $primaryMetric = null;
                foreach ($compactMetrics as $metric) {
                    if (!empty($metric['overdue']) && (int) ($metric['value'] ?? 0) > 0) {
                        $primaryMetric = $metric;
                        break;
                    }
                }
                if ($primaryMetric === null) {
                    foreach ($compactMetrics as $metric) {
                        if ((int) ($metric['value'] ?? 0) > 0) {
                            $primaryMetric = $metric;
                            break;
                        }
                    }
                }
                $primaryMetric ??= $compactMetrics[0] ?? [
                    'icon' => 'fa-circle-check',
                    'label' => 'All clear',
                    'value' => 0,
                    'meta' => 'No action needed',
                ];
                $compactActionUrl = (string) ($compactActionUrlsByRole[$compactRole] ?? '');
                if ((int) ($primaryMetric['value'] ?? 0) > 0 && $compactRole === 'expert') {
                    $expertTaskCondition = match ((string) ($primaryMetric['label'] ?? '')) {
                        'Overdue' => "t.deadline < NOW() AND t.status != 'completed'",
                        'To Estimate' => "t.status = 'todo'",
                        default => "t.status != 'completed'",
                    };
                    $expertProjectId = $summaryFirstId(
                        "SELECT p.id
                         FROM tasks t
                         JOIN projects p ON p.id = t.project_id
                         WHERE t.assigned_to = {$summaryUserId}
                           AND {$expertTaskCondition}
                           AND p.status != 'cancelled'
                         ORDER BY CASE WHEN t.deadline IS NULL THEN 1 ELSE 0 END, t.deadline ASC, t.id ASC
                         LIMIT 1"
                    );
                    $compactActionUrl = $expertProjectId > 0
                        ? BASE_URL . 'modules/expert/feature-estimation.php?project_estimation_id=' . $expertProjectId
                        : '';
                } elseif ((int) ($primaryMetric['value'] ?? 0) > 0 && $compactRole === 'developer') {
                    $developerTaskCondition = match ((string) ($primaryMetric['label'] ?? '')) {
                        'Overdue' => "t.deadline < NOW() AND t.status != 'completed'",
                        'Completed' => "t.status = 'completed'",
                        default => "t.status = 'in_progress'",
                    };
                    $developerTaskId = $summaryFirstId(
                        "SELECT t.id
                         FROM tasks t
                         JOIN projects p ON p.id = t.project_id
                         WHERE t.assigned_to = {$summaryUserId}
                           AND {$developerTaskCondition}
                           AND p.status != 'cancelled'
                         ORDER BY CASE WHEN t.deadline IS NULL THEN 1 ELSE 0 END, t.deadline ASC, t.id ASC
                         LIMIT 1"
                    );
                    $compactActionUrl = $developerTaskId > 0
                        ? BASE_URL . 'modules/developer/task.php?id=' . $developerTaskId
                        : '';
                }
                $roleSummaries[$compactRole]['variant'] = 'compact';
                $roleSummaries[$compactRole]['title'] = 'Live Overview';
                $roleSummaries[$compactRole]['intro'] = $compactIntro;
                $roleSummaries[$compactRole]['item'] = [
                    'icon' => (string) ($primaryMetric['icon'] ?? 'fa-circle-info'),
                    'title' => (string) ($primaryMetric['label'] ?? 'Current status'),
                    'meta' => (int) ($primaryMetric['value'] ?? 0) . ' · ' . (string) ($primaryMetric['meta'] ?? ''),
                    'action_url' => (int) ($primaryMetric['value'] ?? 0) > 0 ? $compactActionUrl : '',
                ];
            }
            $evortixRoleDashboardSummary = $roleSummaries[$summaryRole] ?? null;
            if ($summaryRole === 'architect' && $evortixRoleDashboardSummary) {
                $planningResult = $summaryConn->query("
                    SELECT p.id, p.name, p.status, p.timeline_end,
                           (SELECT COUNT(*) FROM tasks t WHERE t.project_id = p.id) AS task_count
                    FROM projects p
                    WHERE (p.architect_id = {$summaryUserId} OR p.architect_id IS NULL)
                      AND p.status IN ('pending', 'review', 'architectural_review')
                    ORDER BY CASE WHEN p.timeline_end IS NULL THEN 1 ELSE 0 END,
                             p.timeline_end ASC, p.created_at ASC
                    LIMIT 1
                ");
                $planningProject = $planningResult ? $planningResult->fetch_assoc() : null;
                if ($planningProject) {
                    $planningStarted = (int) ($planningProject['task_count'] ?? 0) > 0;
                    $planningDeadline = !empty($planningProject['timeline_end'])
                        ? date('M d, Y', strtotime((string) $planningProject['timeline_end']))
                        : 'No target deadline';
                    $evortixRoleDashboardSummary['item'] = [
                        'icon' => 'fa-diagram-project',
                        'title' => ($planningStarted ? 'Continue: ' : 'Start: ') . (string) $planningProject['name'],
                        'meta' => ($planningStarted ? 'Planning underway' : 'Awaiting architecture review')
                            . ' · Target ' . $planningDeadline,
                        'action_url' => BASE_URL . 'modules/architect/project-review.php?id=' . (int) $planningProject['id'],
                    ];
                } else {
                    $evortixRoleDashboardSummary['item'] = [
                        'icon' => 'fa-circle-check',
                        'title' => 'Architecture queue clear',
                        'meta' => 'No project is waiting for planning',
                        'action_url' => '',
                    ];
                }
            }
            unset($_SESSION['evortix_role_dashboard_welcome_pending']);
        }
    } catch (Throwable $e) {
        error_log('Evortix role dashboard summary failed: ' . $e->getMessage());
    }
}
?>

<?php if ($isSulRoute): ?>
<style>
    #chat-widget,
    #chat-widget-minimized {
        position: fixed !important;
        inset: auto 24px 24px auto !important;
        top: auto !important;
        right: 24px !important;
        bottom: 24px !important;
        left: auto !important;
        margin: 0 !important;
        transform: none !important;
        z-index: 5000 !important;
    }

    #chat-widget-minimized {
        display: flex !important;
        align-items: center !important;
        justify-content: center !important;
        width: 64px !important;
        height: 64px !important;
        opacity: 1 !important;
        visibility: visible !important;
        pointer-events: auto !important;
        box-shadow: 0 18px 36px rgba(10, 48, 79, 0.28) !important;
    }

    #chat-widget-minimized.chat-launcher-hidden {
        display: none !important;
    }

    #chat-bot-widget {
        position: fixed !important;
        inset: auto 24px 96px auto !important;
        top: auto !important;
        right: 24px !important;
        bottom: 96px !important;
        left: auto !important;
        width: 64px !important;
        height: 64px !important;
        z-index: 5001 !important;
    }

    #chat-widget.chat-widget-open {
        display: flex !important;
    }
</style>
<?php endif; ?>
<?php if ($evortixDeferredCompilationImpact): ?>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const compilationNo = <?php echo json_encode((int) ($evortixDeferredCompilationImpact['compilation_no'] ?? 0)); ?>;
    window.setTimeout(() => window.appToast?.(
        `Compilation #${compilationNo} saved`,
        'Evortix recorded the change-impact analysis. Continue reviewing the active recommendations.',
        'info'
    ), 1200);
});
</script>
<script>
(() => {
    const dashboardMatch = window.location.pathname.match(/\/modules\/(admin|sales|architect|qa|expert|developer)\/dashboard\.php$/i);
    if (!dashboardMatch) return;

    const role = dashboardMatch[1].toLowerCase();
    const tabSets = {
        admin: [['renewals', 'Renewals', 'fa-clock-rotate-left', 'Renewal Watch', 'No technology stack is expiring in the next 30 days.'], ['login', 'Login', 'fa-user-lock', 'Login Attack Watch', 'No brute-force lockout pattern detected in the last 15 minutes.'], ['recovery', 'Recovery', 'fa-heart-pulse', 'Password Recovery', 'No password recovery requests are pending.']],
        sales: [['projects', 'Projects', 'fa-diagram-project', 'Project Watch', 'No project signals need attention.'], ['clients', 'Clients', 'fa-users', 'Client Watch', 'No client signals need attention.'], ['overdue', 'Overdue', 'fa-triangle-exclamation', 'Overdue Watch', 'No overdue projects need attention.']],
        architect: [['review', 'Review', 'fa-folder-open', 'Architecture Review', 'No architecture reviews need attention.'], ['progress', 'Progress', 'fa-gears', 'Planning Progress', 'No planning work needs attention.'], ['overdue', 'Overdue', 'fa-triangle-exclamation', 'Overdue Watch', 'No overdue architecture work was detected.']],
        qa: [['testing', 'Testing', 'fa-vial', 'Testing Queue', 'No tasks are waiting for QA.'], ['failed', 'Failed', 'fa-circle-xmark', 'Failed Checks', 'No failed QA checks need attention.'], ['overdue', 'Overdue', 'fa-triangle-exclamation', 'Overdue QA', 'No overdue QA items were detected.']],
        expert: [['tasks', 'Tasks', 'fa-list-check', 'Assigned Tasks', 'No assigned tasks need attention.'], ['estimate', 'Estimate', 'fa-calculator', 'Estimation Queue', 'No estimation items need attention.'], ['overdue', 'Overdue', 'fa-triangle-exclamation', 'Overdue Work', 'No overdue expert work was detected.']],
        developer: [['tasks', 'Tasks', 'fa-list-check', 'Assigned Tasks', 'No assigned tasks need attention.'], ['progress', 'Progress', 'fa-play', 'In Progress', 'No active in-progress tasks were detected.'], ['overdue', 'Overdue', 'fa-triangle-exclamation', 'Overdue Work', 'No overdue developer work was detected.']]
    };
    const escapeHtml = value => {
        const node = document.createElement('div');
        node.textContent = String(value ?? '');
        return node.innerHTML;
    };
    const openDashboardEvortix = () => {
        const float = document.getElementById('chat-bot-float');
        const speech = float?.querySelector('.chat-bot-speech');
        if (!float || !speech) return false;

        const tabs = tabSets[role] || tabSets.sales;
        const cards = [...document.querySelectorAll('.admin-stat-card, .admin-metric-card, .stat-card-modern, .dashboard-stat-card, .stats-grid > *, .role-stats-row .card')]
            .map(card => {
                const label = card.querySelector('.admin-stat-label, .stat-label, h6, small, [class*="label"], [class*="title"]')?.textContent?.replace(/\s+/g, ' ').trim() || '';
                const value = card.querySelector('.admin-stat-value, .stat-value, h2, h3, .h1, .display-4, [class*="value"], [class*="count"]')?.textContent?.replace(/\s+/g, ' ').trim() || '';
                const meta = card.querySelector('.admin-stat-sub, .stat-description, p, small:last-child, [class*="meta"], [class*="desc"]')?.textContent?.replace(/\s+/g, ' ').trim() || '';
                return label && value ? { label, meta: `${value}${meta && meta !== label && meta !== value ? ` - ${meta}` : ''}` } : null;
            })
            .filter(Boolean)
            .slice(0, 6);

        const renderTab = selected => {
            const items = cards.slice(0, 3);
            speech.querySelectorAll('[data-evortix-auto-tab]').forEach(button => {
                const active = button.dataset.evortixAutoTab === selected[0];
                button.classList.toggle('is-active', active);
                button.setAttribute('aria-selected', active ? 'true' : 'false');
                if (active) button.querySelector('strong').textContent = String(items.length);
            });
            const title = speech.querySelector('[data-evortix-auto-title]');
            const count = speech.querySelector('[data-evortix-auto-count]');
            const list = speech.querySelector('[data-evortix-auto-list]');
            if (title) title.textContent = selected[3];
            if (count) count.textContent = `${items.length} item${items.length === 1 ? '' : 's'}`;
            if (list) {
                list.innerHTML = items.length
                    ? items.map(item => `<a class="evortix-project-link" href="#"><span class="evortix-project-link-copy"><strong>${escapeHtml(item.label)}</strong><small>${escapeHtml(item.meta)}</small></span><i class="fas fa-arrow-right" aria-hidden="true"></i></a>`).join('')
                    : `<div class="evortix-project-list-empty">${escapeHtml(selected[4])}</div>`;
            }
        };

        speech.className = 'chat-bot-speech evortix-role-dashboard-summary is-compact has-project-list';
        speech.hidden = false;
        speech.removeAttribute('hidden');
        speech.setAttribute('aria-hidden', 'false');
        speech.setAttribute('role', 'status');
        speech.style.pointerEvents = 'auto';
        speech.innerHTML = `
            <div class="evortix-role-summary-head is-compact"><strong class="evortix-role-summary-title">Live Overview</strong><button type="button" class="evortix-role-summary-close" aria-label="Dismiss dashboard overview"><i class="fas fa-xmark"></i></button></div>
            <div class="evortix-role-summary-intro is-compact">${escapeHtml(role.charAt(0).toUpperCase() + role.slice(1))} overview is ready. Review the current dashboard signals.</div>
            <div class="evortix-project-status-tabs" role="tablist" aria-label="Evortix dashboard status">${tabs.map(tab => `<button type="button" class="evortix-project-status-tab" data-evortix-auto-tab="${escapeHtml(tab[0])}" role="tab" aria-selected="false"><i class="fas ${escapeHtml(tab[2])}" aria-hidden="true"></i><span>${escapeHtml(tab[1])}</span><strong>0</strong></button>`).join('')}</div>
            <div class="evortix-project-list-head"><strong data-evortix-auto-title></strong><span data-evortix-auto-count></span></div>
            <div class="evortix-project-list" data-evortix-auto-list></div>`;
        speech.querySelectorAll('[data-evortix-auto-tab]').forEach(button => {
            const tab = tabs.find(item => item[0] === button.dataset.evortixAutoTab) || tabs[0];
            button.addEventListener('click', () => renderTab(tab));
        });
        renderTab(tabs[0]);

        float.hidden = false;
        float.removeAttribute('hidden');
        float.setAttribute('aria-hidden', 'false');
        float.style.display = 'block';
        float.classList.remove('is-returning-bed', 'is-preparing-speech');
        float.classList.add('is-speaking');
        window.chatBotWidgetRobot?.setMode?.('empty-bed');
        window.chatBotFloatRobot?.setMode?.('standing');
        window.setTimeout(() => float.classList.remove('is-speaking'), 700);
        speech.querySelector('.evortix-role-summary-close')?.addEventListener('click', event => {
            event.stopPropagation();
            speech.hidden = true;
            speech.setAttribute('aria-hidden', 'true');
        });
        return true;
    };

    const scheduleOpen = () => [50, 250, 700, 1400, 2600, 5000].forEach(delay => window.setTimeout(openDashboardEvortix, delay));
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', scheduleOpen, { once: true });
    else scheduleOpen();
    window.addEventListener('load', () => window.setTimeout(openDashboardEvortix, 120), { once: true });
    document.addEventListener('chatbot:controllable-robot-ready', () => window.setTimeout(openDashboardEvortix, 100), { once: true });
})();
</script>
<script>
(() => {
    const isRoleDashboard = () => /\/modules\/(admin|sales|architect|qa|expert|developer)\/dashboard\.php$/i.test(window.location.pathname);
    if (!isRoleDashboard()) return;

    const escapeEvortixText = value => {
        const node = document.createElement('div');
        node.textContent = String(value ?? '');
        return node.innerHTML;
    };
    const roleTabs = {
        admin: [
            ['renewals', 'Renewals', 'fa-clock-rotate-left', 'Renewal Watch', 'No technology stack is expiring in the next 30 days.'],
            ['login', 'Login', 'fa-user-lock', 'Login Attack Watch', 'No brute-force lockout pattern detected in the last 15 minutes.'],
            ['recovery', 'Recovery', 'fa-heart-pulse', 'Password Recovery', 'No password recovery requests are pending.']
        ],
        sales: [
            ['projects', 'Projects', 'fa-diagram-project', 'Project Watch', 'No project signals need attention.'],
            ['clients', 'Clients', 'fa-users', 'Client Watch', 'No client signals need attention.'],
            ['overdue', 'Overdue', 'fa-triangle-exclamation', 'Overdue Watch', 'No overdue projects need attention.']
        ],
        architect: [
            ['review', 'Review', 'fa-folder-open', 'Architecture Review', 'No architecture reviews need attention.'],
            ['progress', 'Progress', 'fa-gears', 'Planning Progress', 'No planning work needs attention.'],
            ['overdue', 'Overdue', 'fa-triangle-exclamation', 'Overdue Watch', 'No overdue architecture work was detected.']
        ],
        qa: [
            ['testing', 'Testing', 'fa-vial', 'Testing Queue', 'No tasks are waiting for QA.'],
            ['failed', 'Failed', 'fa-circle-xmark', 'Failed Checks', 'No failed QA checks need attention.'],
            ['overdue', 'Overdue', 'fa-triangle-exclamation', 'Overdue QA', 'No overdue QA items were detected.']
        ],
        expert: [
            ['tasks', 'Tasks', 'fa-list-check', 'Assigned Tasks', 'No assigned tasks need attention.'],
            ['estimate', 'Estimate', 'fa-calculator', 'Estimation Queue', 'No estimation items need attention.'],
            ['overdue', 'Overdue', 'fa-triangle-exclamation', 'Overdue Work', 'No overdue expert work was detected.']
        ],
        developer: [
            ['tasks', 'Tasks', 'fa-list-check', 'Assigned Tasks', 'No assigned tasks need attention.'],
            ['progress', 'Progress', 'fa-play', 'In Progress', 'No active in-progress tasks were detected.'],
            ['overdue', 'Overdue', 'fa-triangle-exclamation', 'Overdue Work', 'No overdue developer work was detected.']
        ]
    };

    const announceRoleDashboard = () => {
        const roleMatch = window.location.pathname.match(/\/modules\/([^/]+)\/dashboard\.php$/i);
        const role = (roleMatch?.[1] || 'dashboard').toLowerCase();
        const float = document.getElementById('chat-bot-float');
        const speech = float?.querySelector('.chat-bot-speech');
        if (!float || !speech) return false;

        const tabs = roleTabs[role] || roleTabs.sales;
        const cards = [...document.querySelectorAll('.admin-stat-card, .admin-metric-card, .stat-card-modern, .dashboard-stat-card, .stats-grid > *, .role-stats-row .card')]
            .map(card => {
                const label = card.querySelector('.admin-stat-label, .stat-label, h6, small, [class*="label"], [class*="title"]')?.textContent?.replace(/\s+/g, ' ').trim() || '';
                const value = card.querySelector('.admin-stat-value, .stat-value, h2, h3, .h1, .display-4, [class*="value"], [class*="count"]')?.textContent?.replace(/\s+/g, ' ').trim() || '';
                const meta = card.querySelector('.admin-stat-sub, .stat-description, p, small:last-child, [class*="meta"], [class*="desc"]')?.textContent?.replace(/\s+/g, ' ').trim() || '';
                return label && value ? { label, meta: `${value}${meta && meta !== label && meta !== value ? ` - ${meta}` : ''}` } : null;
            })
            .filter(Boolean)
            .slice(0, 6);
        const renderItems = tab => {
            const matched = cards.filter(card => card.label.toLowerCase().includes(tab[1].slice(0, 5).toLowerCase()));
            const items = (matched.length ? matched : cards).slice(0, 3);
            const list = speech.querySelector('[data-evortix-final-list]');
            const title = speech.querySelector('[data-evortix-final-title]');
            const count = speech.querySelector('[data-evortix-final-count]');
            speech.querySelectorAll('[data-evortix-final-tab]').forEach(button => {
                const active = button.dataset.evortixFinalTab === tab[0];
                button.classList.toggle('is-active', active);
                button.setAttribute('aria-selected', active ? 'true' : 'false');
                if (active) button.querySelector('strong').textContent = String(items.length);
            });
            if (title) title.textContent = tab[3];
            if (count) count.textContent = `${items.length} item${items.length === 1 ? '' : 's'}`;
            if (list) {
                list.innerHTML = items.length
                    ? items.map(item => `
                        <a class="evortix-project-link" href="#">
                            <span class="evortix-project-link-copy">
                                <strong>${escapeEvortixText(item.label)}</strong>
                                <small>${escapeEvortixText(item.meta)}</small>
                            </span>
                            <i class="fas fa-arrow-right" aria-hidden="true"></i>
                        </a>
                    `).join('')
                    : `<div class="evortix-project-list-empty">${escapeEvortixText(tab[4])}</div>`;
            }
        };

        speech.className = 'chat-bot-speech evortix-role-dashboard-summary is-compact has-project-list';
        speech.hidden = false;
        speech.removeAttribute('hidden');
        speech.setAttribute('aria-hidden', 'false');
        speech.setAttribute('role', 'status');
        speech.style.pointerEvents = 'auto';
        speech.innerHTML = `
            <div class="evortix-role-summary-head is-compact"><strong class="evortix-role-summary-title">Live Overview</strong><button type="button" class="evortix-role-summary-close" aria-label="Dismiss dashboard overview"><i class="fas fa-xmark"></i></button></div>
            <div class="evortix-role-summary-intro is-compact">${escapeEvortixText(role.charAt(0).toUpperCase() + role.slice(1))} overview is ready. Review the current dashboard signals.</div>
            <div class="evortix-project-status-tabs" role="tablist" aria-label="Evortix dashboard status">
                ${tabs.map(tab => `
                    <button type="button" class="evortix-project-status-tab" data-evortix-final-tab="${escapeEvortixText(tab[0])}" role="tab" aria-selected="false">
                        <i class="fas ${escapeEvortixText(tab[2])}" aria-hidden="true"></i>
                        <span>${escapeEvortixText(tab[1])}</span>
                        <strong>0</strong>
                    </button>
                `).join('')}
            </div>
            <div class="evortix-project-list-head">
                <strong data-evortix-final-title></strong>
                <span data-evortix-final-count></span>
            </div>
            <div class="evortix-project-list" data-evortix-final-list></div>`;
        speech.querySelectorAll('[data-evortix-final-tab]').forEach(button => {
            const tab = tabs.find(item => item[0] === button.dataset.evortixFinalTab) || tabs[0];
            button.addEventListener('click', () => renderItems(tab));
        });
        renderItems(tabs[0]);

        float.hidden = false;
        float.removeAttribute('hidden');
        float.setAttribute('aria-hidden', 'false');
        float.style.display = 'block';
        float.classList.remove('is-returning-bed', 'is-preparing-speech');
        float.classList.add('is-speaking');
        window.chatBotWidgetRobot?.setMode?.('empty-bed');
        window.chatBotFloatRobot?.setMode?.('standing');
        window.setTimeout(() => float.classList.remove('is-speaking'), 700);
        speech.querySelector('.evortix-role-summary-close')?.addEventListener('click', event => {
            event.stopPropagation();
            speech.hidden = true;
            speech.setAttribute('aria-hidden', 'true');
        });
        return true;
    };

    const runAfterReady = () => [100, 450, 1000, 2200, 4200].forEach(delay => window.setTimeout(announceRoleDashboard, delay));
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', runAfterReady, { once: true });
    } else {
        runAfterReady();
    }
    window.addEventListener('load', () => window.setTimeout(announceRoleDashboard, 250), { once: true });
    document.addEventListener('chatbot:controllable-robot-ready', () => window.setTimeout(announceRoleDashboard, 120), { once: true });
})();
</script>
<?php endif; ?>

<style>
.evortix-guidance-target {
    outline: 3px solid rgba(14, 165, 233, .72) !important;
    outline-offset: 5px;
    border-radius: 14px;
    animation: evortixGuidancePulse 1.1s ease-in-out 3;
}
.chat-bot-speech.evortix-role-dashboard-summary { top:42%; right:calc(100% + 18px); width:min(390px,calc(100vw - 190px)); min-width:340px; padding:1rem 1.05rem 1.05rem; border-radius:22px 22px 8px 22px; pointer-events:auto; animation:evortixRoleSummaryReveal .55s cubic-bezier(.16,.86,.22,1) both }
.evortix-role-summary-head { display:flex; align-items:flex-start; justify-content:space-between; gap:.75rem; padding-left:.75rem }
.evortix-role-summary-eyebrow { color:#157baa; font-size:.65rem; font-weight:800; letter-spacing:.1em; text-transform:uppercase }
.chat-bot-speech .evortix-role-summary-title { min-height:0; padding:0; color:#083a5d; font-size:.98rem; line-height:1.3 }
.evortix-role-summary-close { display:grid; place-items:center; flex:0 0 30px; width:30px; height:30px; border:0; border-radius:50%; background:rgba(255,255,255,.7); color:#0d6f9f }
.evortix-role-summary-intro { margin:.5rem 0 .7rem; color:#175f8f; font-size:.74rem; font-weight:700 }
.evortix-role-summary-grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:.48rem }
.evortix-role-summary-metric { min-width:0; padding:.62rem .68rem; border:1px solid rgba(8,123,185,.17); border-radius:12px; background:rgba(255,255,255,.78); box-shadow:0 6px 16px rgba(15,119,183,.09) }
.evortix-role-summary-top { display:flex; align-items:center; justify-content:space-between; gap:.5rem }
.evortix-role-summary-top i { color:#138ac1; font-size:.78rem }
.chat-bot-speech .evortix-role-summary-value { min-height:0; padding:0; color:#083a5d; font-size:1.1rem; line-height:1 }
.evortix-role-summary-label { margin-top:.36rem; color:#0b466d; font-size:.71rem; font-weight:850 }
.evortix-role-summary-meta { margin-top:.16rem; color:#5b7f97; font-size:.63rem; font-weight:700 }
.evortix-role-planning-suggestion { margin-top:.65rem; padding:.72rem; border:1px solid rgba(8,123,185,.2); border-radius:12px; background:rgba(255,255,255,.82) }
.evortix-role-planning-eyebrow { color:#157baa; font-size:.6rem; font-weight:850; letter-spacing:.08em; text-transform:uppercase }
.evortix-role-planning-title { display:block; margin-top:.18rem; color:#083a5d; font-size:.78rem; line-height:1.35 }
.evortix-role-planning-copy { margin:.25rem 0 0; color:#426f8c; font-size:.66rem; line-height:1.45 }
.evortix-role-planning-action { display:inline-flex; align-items:center; gap:.35rem; margin-top:.5rem; padding:.42rem .65rem; border-radius:9px; background:#137eb3; color:#fff !important; font-size:.67rem; font-weight:800; text-decoration:none }
.evortix-role-summary-metric.is-overdue .evortix-role-summary-value,.evortix-role-summary-metric.is-overdue i { color:#c2413b }
.chat-bot-speech.evortix-role-dashboard-summary.is-compact { width:min(360px,calc(100vw - 190px)); min-width:310px; padding:.9rem .95rem .95rem }
.evortix-role-summary-head.is-compact { align-items:center }
.chat-bot-speech .evortix-role-summary-head.is-compact .evortix-role-summary-title { font-size:1rem }
.evortix-role-summary-intro.is-compact { margin:.65rem 0 .75rem; font-size:.73rem; line-height:1.45 }
.evortix-role-summary-item { align-items:center; background:rgba(255,255,255,.82); border:1px solid rgba(8,123,185,.16); border-radius:13px; color:inherit; display:grid; gap:.68rem; grid-template-columns:32px minmax(0,1fr) auto; padding:.68rem .78rem; text-decoration:none; transition:transform .18s ease,background .18s ease,border-color .18s ease }
.evortix-role-summary-item + .evortix-role-summary-item { margin-top:.45rem }
.evortix-role-summary-item:hover { background:#fff; border-color:rgba(8,123,185,.3); color:inherit; transform:translateY(-1px) }
.evortix-role-summary-item:focus-visible { border-color:rgba(8,123,185,.45); box-shadow:0 0 0 3px rgba(19,126,179,.14); outline:0 }
.chat-bot-speech .evortix-role-summary-item-icon { align-items:center; align-self:center; background:#e4f4fb; border:1px solid rgba(8,123,185,.14); border-radius:9px; color:#157baa; display:inline-flex; font-size:.78rem; height:32px; justify-content:center; margin:0; padding:0; position:static; transform:none; width:32px }
.chat-bot-speech .evortix-role-summary-item-icon i { color:inherit; font-size:.78rem; line-height:1; margin:0; position:static; transform:none }
.evortix-role-summary-item-copy { min-width:0 }
.evortix-role-summary-item-title { color:#083a5d; display:block; font-size:.75rem; font-weight:850; line-height:1.3; overflow:hidden; text-overflow:ellipsis; white-space:nowrap }
.evortix-role-summary-item-meta { color:#5b7f97; display:block; font-size:.65rem; font-weight:700; line-height:1.35; overflow:hidden; text-overflow:ellipsis; white-space:nowrap }
.evortix-role-summary-item-arrow { color:#157baa; font-size:.72rem }
.chat-bot-speech.evortix-role-dashboard-summary.has-project-list { width:min(430px,calc(100vw - 190px)); min-width:380px }
.evortix-project-status-tabs { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:.38rem; margin-bottom:.68rem }
.evortix-project-status-tab { align-items:center; background:rgba(255,255,255,.58); border:1px solid rgba(8,123,185,.14); border-radius:10px; color:#426f8c; display:grid; font-size:.6rem; font-weight:800; gap:.18rem; grid-template-columns:auto minmax(0,1fr) auto; min-width:0; padding:.46rem .48rem; text-align:left; transition:background .16s ease,border-color .16s ease,color .16s ease }
.evortix-project-status-tab i { color:#1784b6; font-size:.61rem }
.evortix-project-status-tab span { overflow:hidden; text-overflow:ellipsis; white-space:nowrap }
.evortix-project-status-tab strong { background:#e5f4fa; border-radius:999px; color:#0d668f; font-size:.58rem; min-width:20px; padding:.14rem .3rem; text-align:center }
.evortix-project-status-tab:hover,.evortix-project-status-tab:focus-visible { background:rgba(255,255,255,.84); border-color:rgba(8,123,185,.3); color:#0b466d; outline:0 }
.evortix-project-status-tab.is-active { background:#fff; border-color:rgba(8,123,185,.38); box-shadow:0 4px 12px rgba(15,119,183,.1); color:#083a5d }
.evortix-project-list-head { align-items:center; color:#0b466d; display:flex; font-size:.64rem; justify-content:space-between; margin:0 .12rem .38rem }
.evortix-project-list-head strong { font-size:.66rem; font-weight:850 }
.evortix-project-list-head span { color:#66869b; font-weight:700 }
.evortix-project-list { display:grid; gap:.34rem; max-height:190px; overflow-x:hidden; overflow-y:auto; overscroll-behavior:contain; padding-right:.16rem; scrollbar-color:rgba(18,126,177,.44) transparent; scrollbar-width:thin }
.evortix-project-link { align-items:center; background:rgba(255,255,255,.76); border:1px solid rgba(8,123,185,.14); border-radius:10px; color:inherit; display:grid; gap:.55rem; grid-template-columns:minmax(0,1fr) auto; padding:.52rem .62rem; text-decoration:none; transition:background .16s ease,border-color .16s ease,transform .16s ease }
.evortix-project-link:hover,.evortix-project-link:focus-visible { background:#fff; border-color:rgba(8,123,185,.3); color:inherit; outline:0; transform:translateY(-1px) }
.evortix-project-link-copy { min-width:0 }
.evortix-project-link-copy strong { color:#083a5d; display:block; font-size:.67rem; font-weight:850; overflow:hidden; text-overflow:ellipsis; white-space:nowrap }
.evortix-project-link-copy small { color:#5b7f97; display:block; font-size:.58rem; font-weight:700; margin-top:.1rem; overflow:hidden; text-overflow:ellipsis; white-space:nowrap }
.evortix-project-link > i { color:#157baa; font-size:.63rem }
.evortix-project-list-empty { background:rgba(255,255,255,.52); border:1px dashed rgba(8,123,185,.18); border-radius:10px; color:#5b7f97; font-size:.62rem; font-weight:700; padding:.7rem; text-align:center }
@keyframes evortixRoleSummaryReveal { from { opacity:0; transform:translateY(-50%) translateX(18px) scale(.94) } to { opacity:1; transform:translateY(-50%) translateX(0) scale(1) } }
@media(max-width:640px){.chat-bot-speech.evortix-role-dashboard-summary,.chat-bot-speech.evortix-role-dashboard-summary.is-compact,.chat-bot-speech.evortix-role-dashboard-summary.has-project-list{right:calc(100% + 9px);width:min(290px,calc(100vw - 145px));min-width:230px}.evortix-role-summary-grid{grid-template-columns:1fr;max-height:238px;overflow-y:auto}.evortix-role-summary-item-title,.evortix-role-summary-item-meta{white-space:normal}.evortix-project-status-tab{grid-template-columns:minmax(0,1fr) auto;padding:.42rem .38rem}.evortix-project-status-tab i{display:none}.evortix-project-list{max-height:164px}}
@keyframes evortixGuidancePulse {
    0%, 100% { box-shadow: 0 0 0 0 rgba(14, 165, 233, 0); }
    50% { box-shadow: 0 0 0 10px rgba(14, 165, 233, .16); }
}
</style>

<div id="chat-widget" class="chat-widget">
    <div class="chat-header">
        <div class="d-flex align-items-center gap-2">
            <strong id="chatHeader">Messages</strong>
        </div>
        <div class="d-flex align-items-center gap-2">
            <button type="button" class="btn btn-link btn-sm text-white p-0 chat-icon-btn" id="chatSearchBtn" title="Search">
                <i class="fas fa-search"></i>
            </button>
            <button type="button" class="btn btn-link btn-sm text-white p-0 chat-icon-btn" data-bs-toggle="modal" data-bs-target="#createGroupModal" title="Create group">
                <i class="fas fa-user-plus"></i>
            </button>
            <button type="button" class="btn btn-link btn-sm text-white p-0 chat-icon-btn" id="toggleChatMinimize" aria-label="Minimize chat" onclick="document.getElementById('chat-widget').style.display='none'; document.getElementById('chat-widget-minimized').style.display='block';">
                <i class="fas fa-minus"></i>
            </button>
        </div>
    </div>

    <div class="chat-body" id="chatBody">
        <div id="chatInboxView" class="chat-inbox-view">
            <div class="chat-search-wrap">
                <i class="fas fa-search"></i>
                <input type="text" id="chatSearchInput" class="form-control" placeholder="Search conversation">
            </div>
            <div class="chat-tabs">
                <button type="button" class="chat-tab active" data-chat-tab="chat">Chat</button>
                <button type="button" class="chat-tab" data-chat-tab="groups">Groups</button>
            </div>
            <div id="chatConversationList" class="chat-conversation-list">
                <div class="chat-list-empty">
                    <i class="fas fa-comments"></i>
                    <div>Loading conversations...</div>
                </div>
            </div>
        </div>

        <div id="chatThreadView" class="chat-thread-view d-none">
            <div class="chat-thread-top">
                <button type="button" class="btn btn-link p-0 chat-back-btn" id="chatBackBtn" title="Back">
                    <i class="fas fa-arrow-left"></i>
                </button>
                <div class="chat-thread-avatar" id="chatThreadAvatar">C</div>
                <div class="chat-thread-meta">
                    <strong id="chatThreadName">Conversation</strong>
                    <small id="chatThreadSubtitle">Select a conversation</small>
                </div>
                <button type="button" class="btn btn-link p-0 chat-thread-info-btn d-none" id="chatGroupInfoBtn" title="Group info" aria-label="Group info">
                    <i class="fas fa-users"></i>
                </button>
            </div>

            <div id="chatMessages" class="chat-messages">
                <div class="text-center text-muted py-5">
                    <i class="fas fa-inbox fa-2x mb-2"></i>
                    <div>Select a conversation to start chatting.</div>
                </div>
            </div>

            <div class="chat-input">
                <div id="chatComposerContext" class="chat-composer-context d-none" aria-live="polite">
                    <span class="chat-composer-context-icon" id="chatComposerContextIcon">
                        <i class="fas fa-reply" aria-hidden="true"></i>
                    </span>
                    <span class="chat-composer-context-copy">
                        <strong id="chatComposerContextTitle">Replying</strong>
                        <small id="chatComposerContextPreview"></small>
                    </span>
                    <button type="button" id="chatComposerContextClose" class="chat-composer-context-close" aria-label="Cancel message action">
                        <i class="fas fa-xmark" aria-hidden="true"></i>
                    </button>
                </div>
                <div class="input-group input-group-sm">
                    <input type="text" id="chatMessageInput" class="form-control" placeholder="Type your message...">
                    <button class="btn btn-primary" type="button" id="sendMessageBtn">
                        <i class="fas fa-paper-plane"></i>
                    </button>
                </div>
            </div>
        </div>

        <div id="chatGroupInfoView" class="chat-group-info-view d-none">
            <div class="chat-thread-top">
                <button type="button" class="btn btn-link p-0 chat-back-btn" id="chatGroupInfoBackBtn" title="Back">
                    <i class="fas fa-arrow-left"></i>
                </button>
                <div class="chat-thread-avatar" id="chatGroupInfoAvatar">G</div>
                <div class="chat-thread-meta">
                    <strong id="chatGroupInfoTitle">Group Info</strong>
                    <small id="chatGroupInfoSubtitle">Members and settings</small>
                </div>
            </div>

            <div id="chatGroupInfoContent" class="chat-group-info-content">
                <div class="chat-list-empty">
                    <i class="fas fa-spinner fa-spin"></i>
                    <div>Loading group info...</div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade create-group-modal add-member-modal" id="addGroupMemberModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h5 class="modal-title">Add Group Members</h5>
                    <p class="create-group-subtitle mb-0">Select one or more teammates to join this group.</p>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-0">
                    <div class="d-flex align-items-center justify-content-between gap-2 mb-2">
                        <label class="form-label mb-0" for="addGroupMemberSearch">Members</label>
                        <span class="group-member-count" id="addGroupMemberCount">0 selected</span>
                    </div>
                    <div class="group-member-picker">
                        <div class="group-member-search">
                            <i class="fas fa-search"></i>
                            <input type="text" id="addGroupMemberSearch" class="form-control" placeholder="Search users">
                        </div>
                        <div id="addGroupMembers" class="group-member-list" aria-live="polite">
                            <div class="group-member-empty">
                                <i class="fas fa-spinner fa-spin"></i>
                                <span>Loading users...</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="addGroupMemberSubmitBtn">Add Members</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade create-group-modal chat-forward-modal" id="chatForwardModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h5 class="modal-title">Forward Message</h5>
                    <p class="create-group-subtitle mb-0">Choose a person or group conversation.</p>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="chat-forward-search">
                    <i class="fas fa-search" aria-hidden="true"></i>
                    <input type="text" id="chatForwardSearch" class="form-control" placeholder="Search conversations">
                </div>
                <div id="chatForwardTargets" class="chat-forward-targets">
                    <div class="chat-list-empty">
                        <i class="fas fa-comments" aria-hidden="true"></i>
                        <div>Select a message to forward.</div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
            </div>
        </div>
    </div>
</div>

<button id="chat-bot-widget" class="chat-bot-widget" type="button" aria-label="Open chat" style="display: flex;">
    <span id="chatBotAnimation" class="chat-bot-animation" aria-hidden="true">
        <i class="fas fa-robot"></i>
    </span>
</button>

<div id="chat-bot-float" class="chat-bot-float" hidden aria-hidden="true">
    <button id="chatBotFloatDismiss" class="chat-bot-float-dismiss" type="button" aria-label="Return Evortix home" title="Return Evortix home">
        <i class="fas fa-xmark" aria-hidden="true"></i>
    </button>
    <div class="chat-bot-speech" aria-hidden="true">
        <strong data-chat-bot-type="title" data-chat-bot-text="Hi, good day!"></strong>
    </div>
    <div id="chatBotFloatAnimation" class="chat-bot-float-animation" aria-hidden="true">
        <i class="fas fa-robot"></i>
    </div>
</div>

<button id="chat-widget-minimized" class="chat-widget-minimized" type="button" title="Open chat" style="display: block;" onclick="document.getElementById('chat-widget').style.display='flex'; this.style.display='none';">
    <span class="chat-launcher-glyph" aria-hidden="true"></span>
    <span id="chatUnreadBadge" class="chat-unread-badge d-none" aria-live="polite">0</span>
</button>

<script src="<?php echo BASE_URL; ?>assets/js/evortix-assistant.js?v=<?php echo filemtime(__DIR__ . '/../assets/js/evortix-assistant.js'); ?>"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const evortixProjectTeamNotification = <?php echo json_encode($evortixProjectTeamNotification, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
    const evortixSulRevisionNotification = <?php echo json_encode($evortixSulRevisionNotification, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
    const evortixEnabled = window.APP_USER_PREFERENCES?.evortixEnabled !== false;
    const evortixSuggestionsEnabled = window.APP_USER_PREFERENCES?.evortixSuggestions !== false;
    const evortixSoundEnabled = window.APP_USER_PREFERENCES?.evortixSound === true;
    const widget = document.getElementById('chat-widget');
    const minimized = document.getElementById('chat-widget-minimized');
    const botWidget = document.getElementById('chat-bot-widget');
    const botFloat = document.getElementById('chat-bot-float');
    const botFloatDismiss = document.getElementById('chatBotFloatDismiss');
    const minimizeBtn = document.getElementById('toggleChatMinimize');
    let botFloatPointer = null;
    let botTypeTimer = null;
    let botTransitionTimer = null;
    let evortixSpeechTimer = null;
    let lastEvortixSpeech = '';
    let lastEvortixSpeechAt = 0;
    let pendingEvortixSpeech = '';

    if (!widget || !minimized) return;

    document.body.appendChild(widget);
    document.body.appendChild(minimized);
    if (botWidget) {
        document.body.appendChild(botWidget);
    }
    if (botFloat) {
        document.body.appendChild(botFloat);
    }

    if (!evortixEnabled) {
        if (botWidget) {
            botWidget.hidden = true;
            botWidget.style.display = 'none';
            botWidget.setAttribute('aria-hidden', 'true');
        }
        if (botFloat) {
            botFloat.hidden = true;
            botFloat.setAttribute('aria-hidden', 'true');
        }
    }

    const playEvortixSuggestionSound = function () {
        if (!evortixSoundEnabled) return;

        try {
            const AudioContextConstructor = window.AudioContext || window.webkitAudioContext;
            if (!AudioContextConstructor) return;
            const context = new AudioContextConstructor();
            const startTime = context.currentTime;
            const masterGain = context.createGain();
            const filter = context.createBiquadFilter();
            masterGain.gain.setValueAtTime(0.0001, startTime);
            masterGain.gain.exponentialRampToValueAtTime(0.09, startTime + 0.015);
            masterGain.gain.setValueAtTime(0.09, startTime + 0.08);
            masterGain.gain.exponentialRampToValueAtTime(0.0001, startTime + 0.42);
            filter.type = 'bandpass';
            filter.frequency.value = 1150;
            filter.Q.value = 3.5;
            filter.connect(masterGain);
            masterGain.connect(context.destination);

            const staticLength = Math.ceil(context.sampleRate * 0.43);
            const staticBuffer = context.createBuffer(1, staticLength, context.sampleRate);
            const staticData = staticBuffer.getChannelData(0);
            for (let index = 0; index < staticLength; index += 1) {
                const fade = 1 - (index / staticLength);
                staticData[index] = (Math.random() * 2 - 1) * fade;
            }
            const staticSource = context.createBufferSource();
            const staticGain = context.createGain();
            const staticFilter = context.createBiquadFilter();
            staticSource.buffer = staticBuffer;
            staticFilter.type = 'highpass';
            staticFilter.frequency.value = 1800;
            staticGain.gain.setValueAtTime(0.018, startTime);
            staticGain.gain.exponentialRampToValueAtTime(0.004, startTime + 0.32);
            staticGain.gain.exponentialRampToValueAtTime(0.0001, startTime + 0.43);
            staticSource.connect(staticFilter);
            staticFilter.connect(staticGain);
            staticGain.connect(context.destination);
            staticSource.start(startTime);
            staticSource.stop(startTime + 0.43);

            const tones = [
                { type: 'square', frequency: 310, detune: -12 },
                { type: 'sawtooth', frequency: 620, detune: 9 }
            ];
            tones.forEach((tone, index) => {
                const oscillator = context.createOscillator();
                oscillator.type = tone.type;
                oscillator.detune.value = tone.detune;
                oscillator.frequency.setValueAtTime(tone.frequency, startTime);
                oscillator.frequency.setValueAtTime(tone.frequency * 1.45, startTime + 0.09);
                oscillator.frequency.setValueAtTime(tone.frequency * 0.82, startTime + 0.18);
                oscillator.frequency.setValueAtTime(tone.frequency * 1.7, startTime + 0.27);
                oscillator.connect(filter);
                oscillator.start(startTime + (index * 0.012));
                oscillator.stop(startTime + 0.43);
                if (index === tones.length - 1) {
                    oscillator.addEventListener('ended', () => context.close().catch(() => {}), { once: true });
                }
            });
        } catch (error) {
            // Browser audio restrictions should not interrupt Evortix guidance.
        }
    };

    const getEvortixVoice = function () {
        if (!('speechSynthesis' in window)) return null;
        const voices = window.speechSynthesis.getVoices();
        return voices.find(voice => /^en(-|_)/i.test(voice.lang) && /aria|jenny|zira|samantha|female|google us english/i.test(voice.name))
            || voices.find(voice => /^en(-|_)/i.test(voice.lang))
            || voices[0]
            || null;
    };

    const speakEvortix = function (text) {
        const spokenText = String(text || '').replace(/\s+/g, ' ').trim();
        if (!evortixSoundEnabled || !spokenText || !('speechSynthesis' in window) || !('SpeechSynthesisUtterance' in window)) return;

        pendingEvortixSpeech = spokenText;
        const now = Date.now();
        if (spokenText === lastEvortixSpeech && now - lastEvortixSpeechAt < 3000) return;
        lastEvortixSpeech = spokenText;
        lastEvortixSpeechAt = now;

        window.speechSynthesis.cancel();
        const utterance = new SpeechSynthesisUtterance(spokenText);
        const voice = getEvortixVoice();
        const isWarning = /\b(warning|overdue|failed|failure|risk|urgent|issue|error|revision|attention)\b/i.test(spokenText);
        const isPositive = /\b(great|good|success|successful|approved|complete|completed|ready|excellent|congratulations)\b/i.test(spokenText);
        if (voice) utterance.voice = voice;
        utterance.lang = voice?.lang || 'en-US';
        utterance.rate = isWarning ? 0.94 : (isPositive ? 1.14 : 1.06);
        utterance.pitch = isWarning ? 1.12 : (isPositive ? 1.38 : 1.28);
        utterance.volume = 0.88;
        utterance.addEventListener('start', () => {
            pendingEvortixSpeech = '';
        }, { once: true });
        utterance.addEventListener('error', event => {
            if (event.error === 'not-allowed' || event.error === 'audio-busy') {
                pendingEvortixSpeech = spokenText;
            }
            lastEvortixSpeech = '';
        }, { once: true });
        window.speechSynthesis.speak(utterance);
        window.setTimeout(() => {
            if (window.speechSynthesis.paused) window.speechSynthesis.resume();
        }, 80);
    };

    const announceEvortixAudio = function (text) {
        if (!evortixSoundEnabled) return;
        clearTimeout(evortixSpeechTimer);
        playEvortixSuggestionSound();
        evortixSpeechTimer = window.setTimeout(() => speakEvortix(text), 480);
    };

    window.evortixSpeak = announceEvortixAudio;

    if (evortixSoundEnabled && 'speechSynthesis' in window) {
        const unlockEvortixVoice = function () {
            window.speechSynthesis.resume();
            if (!pendingEvortixSpeech) return;
            const queuedSpeech = pendingEvortixSpeech;
            lastEvortixSpeech = '';
            speakEvortix(queuedSpeech);
        };
        ['pointerdown', 'keydown', 'touchstart'].forEach(eventName => {
            document.addEventListener(eventName, unlockEvortixVoice, { passive: true });
        });
        window.speechSynthesis.addEventListener?.('voiceschanged', () => {
            if (pendingEvortixSpeech && document.hasFocus()) unlockEvortixVoice();
        });
    }

    const setSharedChatOpen = function (isOpen) {
        widget.classList.toggle('chat-widget-open', isOpen);
        minimized.classList.toggle('chat-launcher-hidden', isOpen);
        botWidget?.classList.toggle('chat-bot-hidden', isOpen);
    };

    const openChatMessages = function () {
        setSharedChatOpen(true);
        widget.style.display = 'flex';
        minimized.style.display = 'none';
    };

    const resetBotSpeech = function () {
        if (!botFloat) {
            return;
        }

        const speech = botFloat.querySelector('.chat-bot-speech');
        if (speech) {
            speech.className = 'chat-bot-speech';
            speech.hidden = false;
            speech.setAttribute('aria-hidden', 'false');
            speech.style.pointerEvents = '';
            speech.removeAttribute('role');
            speech.removeAttribute('data-dashboard-summary-dismissed');
        }
        speech?.querySelector('.evortix-project-notification-copy')?.remove();
        speech?.querySelector('.evortix-project-notification-action')?.remove();
        let title = botFloat.querySelector('[data-chat-bot-type="title"]');
        if (!title) {
            const defaultGreeting = activeEvortixRoleDashboardSummary ? 'Live Overview' : 'Hi, good day!';
            speech.innerHTML = `<strong data-chat-bot-type="title" data-chat-bot-text="${defaultGreeting}"></strong>`;
            title = speech.querySelector('[data-chat-bot-type="title"]');
        }

        clearTimeout(botTypeTimer);
        title.textContent = '';
        title.classList.remove('is-typing');
    };

    const typeBotSpeech = function (startDelay = 0) {
        if (!botFloat) {
            return;
        }

        const title = botFloat.querySelector('[data-chat-bot-type="title"]');
        if (!title) {
            return;
        }

        clearTimeout(botTypeTimer);
        title.textContent = '';

        const typeInto = function (element, text, index, done) {
            if (index <= text.length) {
                element.textContent = text.slice(0, index);
                botTypeTimer = setTimeout(function () {
                    typeInto(element, text, index + 1, done);
                }, 58);
                return;
            }

            element.classList.remove('is-typing');
            done?.();
        };

        const startTyping = function () {
            title.classList.add('is-typing');
            typeInto(title, title.dataset.chatBotText || '', 0);
        };

        if (startDelay > 0) {
            botTypeTimer = setTimeout(startTyping, startDelay);
            return;
        }

        startTyping();
    };

    window.evortixAnnounceNotification = function (heading, message, actionUrl) {
        if (!evortixEnabled || !evortixSuggestionsEnabled || !botFloat) return;
        const speech = botFloat.querySelector('.chat-bot-speech');
        if (!speech) return;

        speech.hidden = false;
        speech.classList.remove('evortix-dashboard-summary');
        speech.removeAttribute('role');
        speech.removeAttribute('data-dashboard-summary-dismissed');
        speech.innerHTML = '';
        speech.classList.add('evortix-project-notification');
        speech.setAttribute('aria-hidden', 'false');
        speech.style.pointerEvents = 'auto';
        const title = document.createElement('strong');
        title.dataset.chatBotType = 'title';
        title.dataset.chatBotText = heading || 'Project update';
        speech.appendChild(title);
        const copy = document.createElement('span');
        copy.className = 'evortix-project-notification-copy';
        copy.textContent = message || '';
        speech.appendChild(copy);
        if (actionUrl) {
            const link = document.createElement('a');
            link.href = actionUrl;
            link.className = 'btn btn-primary evortix-project-notification-action';
            link.textContent = 'Open project';
            speech.appendChild(link);
        }
        botFloat.hidden = false;
        botFloat.setAttribute('aria-hidden', 'false');
        announceEvortixAudio(`${heading || 'Project update'}. ${message || ''}`);
        window.chatBotWidgetRobot?.wake?.();
        window.chatBotFloatRobot?.wink?.('right');
        typeBotSpeech(180);
    };

    const evortixRoleDashboardSummary = <?php echo json_encode($evortixRoleDashboardSummary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    const buildDomDashboardSummary = function () {
        const dashboardMatch = window.location.pathname.match(/\/modules\/([^/]+)\/dashboard\.php$/i);
        if (!dashboardMatch) return null;
        if (/\/modules\/sul\/dashboard\.php$/i.test(window.location.pathname)) return null;
        const dashboardRole = (dashboardMatch[1] || 'dashboard').toLowerCase();
        const roleNames = {
            admin: 'Administration',
            sales: 'Sales',
            architect: 'Architecture',
            qa: 'QA',
            expert: 'Expert',
            developer: 'Developer'
        };
        const readNumber = text => {
            const number = String(text || '').match(/-?\d+/);
            return number ? Number(number[0]) : 0;
        };

        const statCards = [...document.querySelectorAll('.admin-stat-card, .admin-metric-card, .stat-card-modern, .dashboard-stat-card, .role-stats-row .card, .stats-grid .card, .admin-stats-grid > *, .stats-grid > *')]
            .map(card => {
                const label = card.querySelector('.admin-stat-label, .stat-label, .text-uppercase, h6, small, [class*="label"], [class*="title"]')?.textContent?.replace(/\s+/g, ' ').trim() || '';
                const value = card.querySelector('.admin-stat-value, .stat-value, .h1, h3, h2, [class*="value"], [class*="count"], .display-4')?.textContent?.replace(/\s+/g, ' ').trim() || '';
                const meta = card.querySelector('.admin-stat-sub, .stat-description, p, small:last-child, [class*="meta"], [class*="desc"]')?.textContent?.replace(/\s+/g, ' ').trim() || '';
                const normalizedLabel = label || card.getAttribute('aria-label') || '';
                return normalizedLabel && value ? {
                    name: normalizedLabel,
                    meta: `${value}${meta && meta !== normalizedLabel && meta !== value ? ` - ${meta}` : ''}`,
                    value: readNumber(value),
                    url: ''
                } : null;
            })
            .filter(Boolean)
            .slice(0, 8);

        const findStat = patterns => statCards.find(card => patterns.some(pattern => pattern.test(card.name))) || null;
        const makeGroup = (key, label, icon, listTitle, empty, patterns, entityLabel = 'signal') => {
            const matched = patterns.length ? statCards.filter(card => patterns.some(pattern => pattern.test(card.name))) : statCards;
            const items = matched.map(card => ({ name: card.name, meta: card.meta, url: card.url || '#' }));
            return {
                label,
                icon,
                total: matched.reduce((sum, card) => sum + (Number.isFinite(card.value) ? card.value : 0), 0),
                list_title: listTitle,
                entity_label: entityLabel,
                empty,
                items
            };
        };

        if (dashboardRole === 'admin') {
            const renewals = makeGroup(
                'renewals',
                'Renewals',
                'fa-clock-rotate-left',
                'Renewal Watch',
                'No technology stack is expiring in the next 30 days.',
                [/technolog/i, /service/i, /active service/i],
                'technology'
            );
            const login = makeGroup(
                'login',
                'Login',
                'fa-user-lock',
                'Login Attack Watch',
                'No brute-force lockout pattern detected in the last 15 minutes.',
                [/security/i, /audit/i, /login/i],
                'signal'
            );
            const recovery = makeGroup(
                'recovery',
                'Recovery',
                'fa-heart-pulse',
                'Password Recovery',
                'No password recovery requests are pending.',
                [/password/i, /recovery/i],
                'request'
            );
            return {
                variant: 'tabbed',
                title: 'Live Overview',
                intro: 'Administration overview is ready. Review renewals, login security, and recovery requests.',
                tab_order: ['renewals', 'login', 'recovery'],
                tab_groups: { renewals, login, recovery },
                metrics: []
            };
        }

        const relatedTabsByRole = {
            sales: [
                ['projects', 'Projects', 'fa-diagram-project', 'Project Watch', 'No project signals need attention.', [/project/i], 'project'],
                ['clients', 'Clients', 'fa-users', 'Client Watch', 'No client signals need attention.', [/client/i], 'client'],
                ['overdue', 'Overdue', 'fa-triangle-exclamation', 'Overdue Watch', 'No overdue projects need attention.', [/overdue/i], 'project']
            ],
            architect: [
                ['review', 'Review', 'fa-folder-open', 'Architecture Review', 'No architecture reviews need attention.', [/review|pending/i], 'project'],
                ['progress', 'Progress', 'fa-gears', 'Planning Progress', 'No planning work needs attention.', [/progress|active/i], 'project'],
                ['overdue', 'Overdue', 'fa-triangle-exclamation', 'Overdue Watch', 'No overdue architecture work was detected.', [/overdue/i], 'item']
            ],
            qa: [
                ['testing', 'Testing', 'fa-vial', 'Testing Queue', 'No tasks are waiting for QA.', [/testing|ready/i], 'task'],
                ['failed', 'Failed', 'fa-circle-xmark', 'Failed Checks', 'No failed QA checks need attention.', [/failed/i], 'task'],
                ['overdue', 'Overdue', 'fa-triangle-exclamation', 'Overdue QA', 'No overdue QA items were detected.', [/overdue/i], 'task']
            ],
            expert: [
                ['tasks', 'Tasks', 'fa-list-check', 'Assigned Tasks', 'No assigned tasks need attention.', [/assigned|task/i], 'task'],
                ['estimate', 'Estimate', 'fa-calculator', 'Estimation Queue', 'No estimation items need attention.', [/estimate/i], 'task'],
                ['overdue', 'Overdue', 'fa-triangle-exclamation', 'Overdue Work', 'No overdue expert work was detected.', [/overdue/i], 'task']
            ],
            developer: [
                ['tasks', 'Tasks', 'fa-list-check', 'Assigned Tasks', 'No assigned tasks need attention.', [/assigned|task/i], 'task'],
                ['progress', 'Progress', 'fa-play', 'In Progress', 'No active in-progress tasks were detected.', [/progress|active/i], 'task'],
                ['overdue', 'Overdue', 'fa-triangle-exclamation', 'Overdue Work', 'No overdue developer work was detected.', [/overdue/i], 'task']
            ]
        };
        const tabDefinitions = relatedTabsByRole[dashboardRole] || [
            ['overview', 'Overview', 'fa-chart-line', 'Dashboard Signals', 'No dashboard signals are available.', [], 'signal'],
            ['attention', 'Attention', 'fa-triangle-exclamation', 'Items Needing Attention', 'No urgent dashboard items were detected.', [/overdue|pending|failed|risk/i], 'item'],
            ['activity', 'Activity', 'fa-wave-square', 'Recent Activity', 'No recent dashboard activity was detected.', [/active|completed|total/i], 'item']
        ];
        const tabGroups = {};
        tabDefinitions.forEach(([key, label, icon, listTitle, empty, patterns, entityLabel]) => {
            tabGroups[key] = makeGroup(key, label, icon, listTitle, empty, patterns, entityLabel);
        });

        const roleLabel = document.querySelector('[class*="overview-badge"], .sidebar-role, .role-label')?.textContent?.replace(/\s+/g, ' ').trim() || 'Dashboard';
        return {
            variant: 'tabbed',
            title: 'Live Overview',
            intro: `${roleNames[dashboardRole] || roleLabel} overview is ready. Review the current dashboard signals.`,
            tab_order: tabDefinitions.map(tab => tab[0]),
            tab_groups: tabGroups,
            metrics: []
        };
    };
    const domEvortixRoleDashboardSummary = buildDomDashboardSummary();
    const activeEvortixRoleDashboardSummary = evortixRoleDashboardSummary?.variant === 'tabbed'
        ? evortixRoleDashboardSummary
        : (domEvortixRoleDashboardSummary || evortixRoleDashboardSummary);
    let evortixDashboardSummaryLastRender = 0;
    const showEvortixRoleDashboardSummary = function (options = {}) {
        if (!evortixEnabled || !activeEvortixRoleDashboardSummary || !botFloat) return false;
            const speech = botFloat.querySelector('.chat-bot-speech');
            if (!speech) return false;
            if (speech.dataset.dashboardSummaryDismissed === 'true' && !options.force) return false;
            const escapeSummary = value => {
                const node = document.createElement('div');
                node.textContent = String(value ?? '');
                return node.innerHTML;
            };
            const metricMarkup = (activeEvortixRoleDashboardSummary.metrics || []).map(metric => `
                <div class="evortix-role-summary-metric${metric.overdue ? ' is-overdue' : ''}">
                    <div class="evortix-role-summary-top"><i class="fas ${escapeSummary(metric.icon)}"></i><strong class="evortix-role-summary-value">${escapeSummary(metric.value)}</strong></div>
                    <div class="evortix-role-summary-label">${escapeSummary(metric.label)}</div>
                    <div class="evortix-role-summary-meta">${escapeSummary(metric.meta)}</div>
                </div>`).join('');
            const isServerTabbedSummary = activeEvortixRoleDashboardSummary.variant === 'tabbed';
            const compactItem = activeEvortixRoleDashboardSummary.item || {};
            const compactItems = Array.isArray(activeEvortixRoleDashboardSummary.items) && activeEvortixRoleDashboardSummary.items.length
                ? activeEvortixRoleDashboardSummary.items
                : [compactItem];
            const compactItemsMarkup = compactItems.map(item => {
                const itemTag = item.action_url ? 'a' : 'div';
                const itemHref = item.action_url ? ` href="${escapeSummary(item.action_url)}"` : '';
                return `
                <${itemTag} class="evortix-role-summary-item"${itemHref}>
                    <span class="evortix-role-summary-item-icon"><i class="fas ${escapeSummary(item.icon || 'fa-circle-info')}"></i></span>
                    <span class="evortix-role-summary-item-copy"><strong class="evortix-role-summary-item-title">${escapeSummary(item.title)}</strong><span class="evortix-role-summary-item-meta">${escapeSummary(item.meta)}</span></span>
                    ${item.action_url ? '<i class="fas fa-arrow-right evortix-role-summary-item-arrow" aria-hidden="true"></i>' : ''}
                </${itemTag}>`;
            }).join('');
            const compactTabGroups = activeEvortixRoleDashboardSummary.variant === 'compact' ? {
                overview: {
                    label: compactItem.title || 'Overview',
                    icon: compactItem.icon || 'fa-circle-info',
                    total: compactItems.length,
                    list_title: compactItem.title || 'Live Overview',
                    entity_label: 'item',
                    empty: 'No dashboard items need attention.',
                    items: compactItems.map(item => ({
                        name: item.title || 'Current status',
                        meta: item.meta || '',
                        url: item.action_url || ''
                    }))
                }
            } : {};
            const tabGroups = isServerTabbedSummary
                ? (activeEvortixRoleDashboardSummary.tab_groups || {})
                : compactTabGroups;
            const isTabbedSummary = isServerTabbedSummary || Object.keys(compactTabGroups).length > 0;
            const isCompactSummary = false;
            const tabOrder = Array.isArray(activeEvortixRoleDashboardSummary.tab_order)
                ? activeEvortixRoleDashboardSummary.tab_order.filter(key => tabGroups[key])
                : Object.keys(tabGroups);
            const initialTab = tabOrder.find(key => Number(tabGroups[key]?.total || 0) > 0) || tabOrder[0] || '';
            const tabbedMarkup = isTabbedSummary ? `
                <div class="evortix-role-summary-head is-compact"><strong class="evortix-role-summary-title">${escapeSummary(activeEvortixRoleDashboardSummary.title)}</strong><button type="button" class="evortix-role-summary-close" aria-label="Dismiss dashboard overview"><i class="fas fa-xmark"></i></button></div>
                <div class="evortix-role-summary-intro is-compact">${escapeSummary(activeEvortixRoleDashboardSummary.intro)}</div>
                <div class="evortix-project-status-tabs" role="tablist" aria-label="Evortix dashboard status">
                    ${tabOrder.map(key => {
                        const group = tabGroups[key] || {};
                        return `
                        <button type="button" class="evortix-project-status-tab" data-evortix-summary-tab="${escapeSummary(key)}" role="tab" aria-selected="false">
                            <i class="fas ${escapeSummary(group.icon || 'fa-circle-info')}" aria-hidden="true"></i>
                            <span>${escapeSummary(group.label || key)}</span>
                            <strong>${escapeSummary(group.total ?? 0)}</strong>
                        </button>`;
                    }).join('')}
                </div>
                <div class="evortix-project-list-head">
                    <strong data-evortix-summary-list-title></strong>
                    <span data-evortix-summary-list-count></span>
                </div>
                <div class="evortix-project-list" data-evortix-summary-list></div>` : '';
            speech.className = `chat-bot-speech evortix-role-dashboard-summary${(isCompactSummary || isTabbedSummary) ? ' is-compact' : ''}${isTabbedSummary ? ' has-project-list' : ''}`;
            speech.hidden = false;
            speech.style.pointerEvents = 'auto';
            speech.setAttribute('aria-hidden', 'false');
            speech.setAttribute('role', 'status');
            speech.innerHTML = isTabbedSummary ? tabbedMarkup : (isCompactSummary ? `
                <div class="evortix-role-summary-head is-compact"><strong class="evortix-role-summary-title">${escapeSummary(activeEvortixRoleDashboardSummary.title)}</strong><button type="button" class="evortix-role-summary-close" aria-label="Dismiss dashboard overview"><i class="fas fa-xmark"></i></button></div>
                <div class="evortix-role-summary-intro is-compact">${escapeSummary(activeEvortixRoleDashboardSummary.intro)}</div>
                ${compactItemsMarkup}` : `
                <div class="evortix-role-summary-head"><div><div class="evortix-role-summary-eyebrow">Live overview</div><strong class="evortix-role-summary-title">${escapeSummary(activeEvortixRoleDashboardSummary.title)}</strong></div><button type="button" class="evortix-role-summary-close" aria-label="Dismiss dashboard overview"><i class="fas fa-xmark"></i></button></div>
                <div class="evortix-role-summary-intro">Here is the information most relevant to your role right now.</div>
                <div class="evortix-role-summary-grid">${metricMarkup}</div>
                ${activeEvortixRoleDashboardSummary.suggestion ? `<div class="evortix-role-planning-suggestion"><div class="evortix-role-planning-eyebrow">${escapeSummary(activeEvortixRoleDashboardSummary.suggestion.eyebrow)}</div><strong class="evortix-role-planning-title">${escapeSummary(activeEvortixRoleDashboardSummary.suggestion.title)}</strong><p class="evortix-role-planning-copy">${escapeSummary(activeEvortixRoleDashboardSummary.suggestion.message)}</p>${activeEvortixRoleDashboardSummary.suggestion.action_url ? `<a class="evortix-role-planning-action" href="${escapeSummary(activeEvortixRoleDashboardSummary.suggestion.action_url)}"><i class="fas fa-arrow-right"></i>${escapeSummary(activeEvortixRoleDashboardSummary.suggestion.action_label)}</a>` : ''}</div>` : ''}`);
            if (isTabbedSummary) {
                const renderSummaryTab = key => {
                    const group = tabGroups[key] || {};
                    const items = Array.isArray(group.items) ? group.items : [];
                    speech.querySelectorAll('[data-evortix-summary-tab]').forEach(tab => {
                        const isSelected = tab.dataset.evortixSummaryTab === key;
                        tab.classList.toggle('is-active', isSelected);
                        tab.setAttribute('aria-selected', isSelected ? 'true' : 'false');
                    });
                    const title = speech.querySelector('[data-evortix-summary-list-title]');
                    const count = speech.querySelector('[data-evortix-summary-list-count]');
                    const list = speech.querySelector('[data-evortix-summary-list]');
                    if (title) title.textContent = group.list_title || group.label || 'Status';
                    if (count) {
                        const label = group.entity_label || 'item';
                        count.textContent = `${items.length} ${label}${items.length === 1 ? '' : 's'}`;
                    }
                    if (!list) return;
                    list.innerHTML = items.length
                        ? items.map(item => `
                            <a class="evortix-project-link" href="${escapeSummary(item.url || '#')}">
                                <span class="evortix-project-link-copy">
                                    <strong>${escapeSummary(item.name || item.title || 'Item')}</strong>
                                    <small>${escapeSummary(item.meta || '')}</small>
                                </span>
                                <i class="fas fa-arrow-right" aria-hidden="true"></i>
                            </a>
                        `).join('')
                        : `<div class="evortix-project-list-empty">${escapeSummary(group.empty || 'No items need attention.')}</div>`;
                };
                speech.querySelectorAll('[data-evortix-summary-tab]').forEach(tab => {
                    tab.addEventListener('click', () => renderSummaryTab(tab.dataset.evortixSummaryTab));
                });
                if (initialTab) renderSummaryTab(initialTab);
            }
            window.chatBotWidgetRobot?.setMode?.('empty-bed');
            window.chatBotFloatRobot?.setMode?.('standing');
            botFloat.hidden = false;
            botFloat.setAttribute('aria-hidden', 'false');
            evortixDashboardSummaryLastRender = Date.now();
            const metricSpeech = isCompactSummary
                ? `${activeEvortixRoleDashboardSummary.intro || ''} ${compactItems.map(item => `${item.title || ''}. ${item.meta || ''}`).join(' ')}`
                : isTabbedSummary
                    ? `${activeEvortixRoleDashboardSummary.intro || ''} ${tabOrder.map(key => `${tabGroups[key]?.label || key}: ${tabGroups[key]?.total || 0}.`).join(' ')}`
                : (activeEvortixRoleDashboardSummary.metrics || [])
                    .map(metric => `${metric.label}: ${metric.value}. ${metric.meta || ''}`)
                    .join(' ');
            announceEvortixAudio(`${activeEvortixRoleDashboardSummary.title}. ${metricSpeech}`);
            window.chatBotFloatRobot?.point?.('left', 6500);
            speech.querySelector('.evortix-role-summary-close')?.addEventListener('click', () => {
                speech.hidden = true;
                speech.setAttribute('aria-hidden', 'true');
                speech.dataset.dashboardSummaryDismissed = 'true';
            });
            document.dispatchEvent(new CustomEvent('evortix:dashboard-welcome-complete'));
            return true;
    };
    if (evortixEnabled && activeEvortixRoleDashboardSummary && botFloat) {
        const openEvortixDashboardAnnouncer = function (force = false) {
            clearTimeout(botTransitionTimer);
            clearTimeout(botTypeTimer);
            botFloat.hidden = false;
            botFloat.setAttribute('aria-hidden', 'false');
            botFloat.classList.remove('is-returning-bed', 'is-preparing-speech');
            botFloat.classList.add('is-leaving-bed', 'is-speaking');
            window.chatBotWidgetRobot?.wake?.();
            window.chatBotFloatRobot?.setMode?.('standing');
            window.setTimeout(() => {
                botFloat.classList.remove('is-leaving-bed');
                showEvortixRoleDashboardSummary({ force });
                window.chatBotFloatRobot?.point?.('left', 6500);
                window.setTimeout(() => botFloat.classList.remove('is-speaking'), 620);
            }, 180);
        };
        const scheduleDashboardSummary = function (delay = 0, force = false) {
            window.setTimeout(() => openEvortixDashboardAnnouncer(force), delay);
        };
        document.body.classList.add('evortix-dashboard-announcer-ready');
        scheduleDashboardSummary(240, true);
        scheduleDashboardSummary(650, true);
        scheduleDashboardSummary(1600, false);
        document.addEventListener('chatbot:controllable-robot-ready', () => {
            scheduleDashboardSummary(160, true);
        }, { once: true });
        window.addEventListener('pageshow', () => scheduleDashboardSummary(260, true));
        const dashboardSpeechObserver = new MutationObserver(() => {
            const speech = botFloat.querySelector('.chat-bot-speech');
            if (!speech || speech.hidden) return;
            if (speech.classList.contains('evortix-role-dashboard-summary')) return;
            if (Date.now() - evortixDashboardSummaryLastRender < 400) return;
            const text = speech.textContent.replace(/\s+/g, ' ').trim().toLowerCase();
            if (!text || text.includes('hi, good day') || text === 'live overview') {
                scheduleDashboardSummary(40, true);
            }
        });
        dashboardSpeechObserver.observe(botFloat, {
            subtree: true,
            childList: true,
            characterData: true,
            attributes: true,
            attributeFilter: ['class', 'hidden', 'aria-hidden']
        });
    }

    const forceEvortixDashboardOverview = function () {
        const dashboardMatch = window.location.pathname.match(/\/modules\/([^/]+)\/dashboard\.php$/i);
        if (!dashboardMatch || /\/modules\/sul\/dashboard\.php$/i.test(window.location.pathname)) return false;
        if (!evortixEnabled || !botFloat) return false;
        const speech = botFloat.querySelector('.chat-bot-speech');
        if (!speech) return false;

        const role = (dashboardMatch[1] || 'dashboard').toLowerCase();
        const escapeHtml = value => {
            const node = document.createElement('div');
            node.textContent = String(value ?? '');
            return node.innerHTML;
        };
        const currentText = speech.textContent.replace(/\s+/g, ' ').trim().toLowerCase();
        if (speech.classList.contains('evortix-role-dashboard-summary') && !currentText.includes('hi, good day')) {
            botFloat.hidden = false;
            botFloat.setAttribute('aria-hidden', 'false');
            return true;
        }

        const roleTabs = {
            admin: [
                ['renewals', 'Renewals', 'fa-clock-rotate-left', 'Renewal Watch', 'No technology stack is expiring in the next 30 days.'],
                ['login', 'Login', 'fa-user-lock', 'Login Attack Watch', 'No brute-force lockout pattern detected in the last 15 minutes.'],
                ['recovery', 'Recovery', 'fa-heart-pulse', 'Password Recovery', 'No password recovery requests are pending.']
            ],
            sales: [
                ['projects', 'Projects', 'fa-diagram-project', 'Project Watch', 'No project signals need attention.'],
                ['clients', 'Clients', 'fa-users', 'Client Watch', 'No client signals need attention.'],
                ['overdue', 'Overdue', 'fa-triangle-exclamation', 'Overdue Watch', 'No overdue projects need attention.']
            ],
            architect: [
                ['review', 'Review', 'fa-folder-open', 'Architecture Review', 'No architecture reviews need attention.'],
                ['progress', 'Progress', 'fa-gears', 'Planning Progress', 'No planning work needs attention.'],
                ['overdue', 'Overdue', 'fa-triangle-exclamation', 'Overdue Watch', 'No overdue architecture work was detected.']
            ],
            qa: [
                ['testing', 'Testing', 'fa-vial', 'Testing Queue', 'No tasks are waiting for QA.'],
                ['failed', 'Failed', 'fa-circle-xmark', 'Failed Checks', 'No failed QA checks need attention.'],
                ['overdue', 'Overdue', 'fa-triangle-exclamation', 'Overdue QA', 'No overdue QA items were detected.']
            ],
            expert: [
                ['tasks', 'Tasks', 'fa-list-check', 'Assigned Tasks', 'No assigned tasks need attention.'],
                ['estimate', 'Estimate', 'fa-calculator', 'Estimation Queue', 'No estimation items need attention.'],
                ['overdue', 'Overdue', 'fa-triangle-exclamation', 'Overdue Work', 'No overdue expert work was detected.']
            ],
            developer: [
                ['tasks', 'Tasks', 'fa-list-check', 'Assigned Tasks', 'No assigned tasks need attention.'],
                ['progress', 'Progress', 'fa-play', 'In Progress', 'No active in-progress tasks were detected.'],
                ['overdue', 'Overdue', 'fa-triangle-exclamation', 'Overdue Work', 'No overdue developer work was detected.']
            ]
        };
        const tabs = roleTabs[role] || [
            ['overview', 'Overview', 'fa-chart-line', 'Dashboard Signals', 'No dashboard signals are available.'],
            ['attention', 'Attention', 'fa-triangle-exclamation', 'Items Needing Attention', 'No urgent dashboard items were detected.'],
            ['activity', 'Activity', 'fa-wave-square', 'Recent Activity', 'No recent dashboard activity was detected.']
        ];
        const statCards = [...document.querySelectorAll('.admin-stat-card, .admin-metric-card, .stat-card-modern, .dashboard-stat-card, .role-stats-row .card, .stats-grid .card, .stats-grid > *')]
            .map(card => {
                const label = card.querySelector('.admin-stat-label, .stat-label, .text-uppercase, h6, small, [class*="label"], [class*="title"]')?.textContent?.replace(/\s+/g, ' ').trim() || '';
                const value = card.querySelector('.admin-stat-value, .stat-value, .h1, h3, h2, [class*="value"], [class*="count"], .display-4')?.textContent?.replace(/\s+/g, ' ').trim() || '';
                const meta = card.querySelector('.admin-stat-sub, .stat-description, p, small:last-child, [class*="meta"], [class*="desc"]')?.textContent?.replace(/\s+/g, ' ').trim() || '';
                return label && value ? { name: label, meta: `${value}${meta && meta !== label && meta !== value ? ` - ${meta}` : ''}` } : null;
            })
            .filter(Boolean);
        const matchTabItems = label => {
            const needle = String(label || '').toLowerCase();
            const matched = statCards.filter(card => card.name.toLowerCase().includes(needle.slice(0, 5)));
            return (matched.length ? matched : statCards.slice(0, 2)).slice(0, 5);
        };

        speech.className = 'chat-bot-speech evortix-role-dashboard-summary is-compact has-project-list';
        speech.hidden = false;
        speech.style.pointerEvents = 'auto';
        speech.setAttribute('aria-hidden', 'false');
        speech.setAttribute('role', 'status');
        speech.innerHTML = `
            <div class="evortix-role-summary-head is-compact"><strong class="evortix-role-summary-title">Live Overview</strong><button type="button" class="evortix-role-summary-close" aria-label="Dismiss dashboard overview"><i class="fas fa-xmark"></i></button></div>
            <div class="evortix-role-summary-intro is-compact">${escapeHtml(role.charAt(0).toUpperCase() + role.slice(1))} overview is ready. Review the current dashboard signals.</div>
            <div class="evortix-project-status-tabs" role="tablist" aria-label="Evortix dashboard status">
                ${tabs.map(tab => `
                    <button type="button" class="evortix-project-status-tab" data-evortix-force-tab="${escapeHtml(tab[0])}" role="tab" aria-selected="false">
                        <i class="fas ${escapeHtml(tab[2])}" aria-hidden="true"></i>
                        <span>${escapeHtml(tab[1])}</span>
                        <strong>0</strong>
                    </button>
                `).join('')}
            </div>
            <div class="evortix-project-list-head">
                <strong data-evortix-force-list-title></strong>
                <span data-evortix-force-list-count></span>
            </div>
            <div class="evortix-project-list" data-evortix-force-list></div>`;
        const renderForceTab = key => {
            const config = tabs.find(tab => tab[0] === key) || tabs[0];
            const items = matchTabItems(config[1]);
            speech.querySelectorAll('[data-evortix-force-tab]').forEach(tab => {
                const active = tab.dataset.evortixForceTab === config[0];
                tab.classList.toggle('is-active', active);
                tab.setAttribute('aria-selected', active ? 'true' : 'false');
                if (active) tab.querySelector('strong').textContent = String(items.length);
            });
            const title = speech.querySelector('[data-evortix-force-list-title]');
            const count = speech.querySelector('[data-evortix-force-list-count]');
            const list = speech.querySelector('[data-evortix-force-list]');
            if (title) title.textContent = config[3];
            if (count) count.textContent = `${items.length} item${items.length === 1 ? '' : 's'}`;
            if (list) {
                list.innerHTML = items.length
                    ? items.map(item => `
                        <a class="evortix-project-link" href="#">
                            <span class="evortix-project-link-copy">
                                <strong>${escapeHtml(item.name)}</strong>
                                <small>${escapeHtml(item.meta)}</small>
                            </span>
                            <i class="fas fa-arrow-right" aria-hidden="true"></i>
                        </a>
                    `).join('')
                    : `<div class="evortix-project-list-empty">${escapeHtml(config[4])}</div>`;
            }
        };
        speech.querySelectorAll('[data-evortix-force-tab]').forEach(tab => {
            tab.addEventListener('click', () => renderForceTab(tab.dataset.evortixForceTab));
        });
        renderForceTab(tabs[0][0]);
        botFloat.hidden = false;
        botFloat.setAttribute('aria-hidden', 'false');
        botFloat.classList.remove('is-returning-bed', 'is-preparing-speech');
        window.chatBotWidgetRobot?.setMode?.('empty-bed');
        window.chatBotFloatRobot?.setMode?.('standing');
        speech.querySelector('.evortix-role-summary-close')?.addEventListener('click', event => {
            event.stopPropagation();
            speech.hidden = true;
            speech.setAttribute('aria-hidden', 'true');
        });
        document.dispatchEvent(new CustomEvent('evortix:dashboard-welcome-complete'));
        return true;
    };
    if (evortixEnabled && botFloat) {
        [350, 900, 1800, 3200].forEach(delay => window.setTimeout(forceEvortixDashboardOverview, delay));
        document.addEventListener('chatbot:controllable-robot-ready', () => window.setTimeout(forceEvortixDashboardOverview, 120), { once: true });
    }

    if (botFloat && evortixSoundEnabled) {
        let observedSpeechText = '';
        let observedSpeechTimer = null;
        const speechObserver = new MutationObserver(() => {
            clearTimeout(observedSpeechTimer);
            observedSpeechTimer = window.setTimeout(() => {
                const speech = botFloat.querySelector('.chat-bot-speech');
                if (!speech || speech.hidden || speech.getAttribute('aria-hidden') === 'true') return;
                if (![...speech.classList].some(className => className.startsWith('evortix-'))) return;

                const clone = speech.cloneNode(true);
                clone.querySelectorAll('button, a, [aria-hidden="true"]').forEach(node => node.remove());
                const text = clone.textContent.replace(/\s+/g, ' ').trim();
                if (!text || text === observedSpeechText) return;
                observedSpeechText = text;
                if (Date.now() - lastEvortixSpeechAt < 1500) return;
                announceEvortixAudio(text);
            }, 450);
        });
        speechObserver.observe(botFloat, {
            subtree: true,
            childList: true,
            characterData: true,
            attributes: true,
            attributeFilter: ['class', 'hidden', 'aria-hidden']
        });
    }

    const evortixGuidanceParams = new URLSearchParams(window.location.search);
    const evortixGuidanceIssue = evortixGuidanceParams.get('evortix_issue') || '';
    if (evortixGuidanceIssue) {
        const issueCount = Math.max(0, Number(evortixGuidanceParams.get('evortix_count') || 0));
        const projectName = evortixGuidanceParams.get('evortix_project') || 'this project';
        const issueGuidance = {
            task_planning: {
                heading: 'Task planning needs attention',
                message: `${issueCount || 'Some'} task${issueCount === 1 ? '' : 's'} in ${projectName} need an owner, a deadline, or both. Review the highlighted tasks; the SUL must assign each task to the correct role and set a deadline inside its roadmap window.`
            },
            missing_features: {
                heading: 'Features are missing',
                message: `${projectName} has no usable feature breakdown. Add the required features and acceptance details, then save them before continuing.`
            },
            missing_technology: {
                heading: 'Technology plan is missing',
                message: `Select and save the stack required by ${projectName}. Confirm that every selected technology supports the approved features and integrations.`
            },
            missing_allocation: {
                heading: 'Developer allocation is missing',
                message: `Add the developer roles and headcount required by ${projectName}, then save the allocation so roadmap staffing can use it.`
            },
            missing_documents: {
                heading: 'Architecture documents are missing',
                message: `Upload the required architecture plan and workflow diagram for ${projectName}, then verify both documents before submission.`
            },
            delayed_tasks: {
                heading: 'Delivery tasks need attention',
                message: `${issueCount || 'Some'} task${issueCount === 1 ? '' : 's'} in ${projectName} are overdue or blocked. Review the owner, deadline, blocker, and next action for each highlighted task.`
            }
        }[evortixGuidanceIssue];

        window.setTimeout(() => {
            const targetId = window.location.hash ? decodeURIComponent(window.location.hash.slice(1)) : '';
            const target = targetId ? document.getElementById(targetId) : null;
            if (target) {
                target.classList.add('evortix-guidance-target');
                target.scrollIntoView({ behavior: 'smooth', block: 'center' });
                window.setTimeout(() => target.classList.remove('evortix-guidance-target'), 6500);
            }
            if (issueGuidance) window.evortixAnnounceNotification(issueGuidance.heading, issueGuidance.message, '');
        }, 850);
    }

    if (evortixProjectTeamNotification) {
        const storageKey = `evortix.projectNotification.${evortixProjectTeamNotification.id}`;
        const announceProjectNotification = () => {
            if (sessionStorage.getItem(storageKey)) return;
            sessionStorage.setItem(storageKey, '1');
            window.setTimeout(() => window.evortixAnnounceNotification(
                evortixProjectTeamNotification.title,
                evortixProjectTeamNotification.message,
                evortixProjectTeamNotification.action_url
            ), 700);
        };
        if (window.evortixDashboardWelcomeActive) {
            document.addEventListener('evortix:dashboard-welcome-complete', announceProjectNotification, { once:true });
        } else {
            announceProjectNotification();
        }
    }

    if (evortixSulRevisionNotification) {
        const revisionStorageKey = `evortix.sulRevision.${evortixSulRevisionNotification.id}`;
        if (!sessionStorage.getItem(revisionStorageKey)) {
            sessionStorage.setItem(revisionStorageKey, '1');
            window.setTimeout(() => {
                const isExpertRevision = evortixSulRevisionNotification.related_table === 'expert_project_estimations';
                const notificationTitle = String(evortixSulRevisionNotification.title || '');
                const isQaRevision = notificationTitle.startsWith('QA Revision');
                const isQaPassed = notificationTitle.startsWith('QA Passed:');
                const guidance = isExpertRevision
                    ? 'Review the SUL reason, update the affected estimates, staffing, cost, and timeline, then save and resubmit the project estimation.'
                    : isQaRevision
                        ? 'Evortix is relaying the QA decision. Review the QA notes, follow the assigned department order, correct the finding, and resubmit the work to your SUL.'
                        : isQaPassed
                            ? 'QA passed this feature. Review the result and continue with Architect final approval.'
                            : 'Review the SUL comment, update the implementation and evidence, then resubmit the task or pull request for SUL review.';
                window.evortixAnnounceNotification?.(
                    notificationTitle || (isQaRevision ? 'QA revision received' : 'SUL revision requested'),
                    `${evortixSulRevisionNotification.message || 'Your SUL requested changes.'} ${guidance}`,
                    evortixSulRevisionNotification.action_url || ''
                );
                const action = document.querySelector('#chat-bot-float .evortix-project-notification-action');
                if (action) {
                    action.textContent = isQaPassed
                        ? 'Review QA result'
                        : (isQaRevision ? 'Open QA revision' : 'Review revision');
                }

                const currentPath = window.location.pathname;
                let actionUrl = null;
                const actionPath = (() => {
                    try {
                        actionUrl = new URL(evortixSulRevisionNotification.action_url || '', window.location.origin);
                        return actionUrl.pathname;
                    } catch (error) { return ''; }
                })();
                if (actionPath && currentPath === actionPath) {
                    const taskId = String(
                        actionUrl?.searchParams.get('task_id')
                        || actionUrl?.searchParams.get('id')
                        || evortixSulRevisionNotification.related_id
                        || ''
                    ).replace(/[^0-9]/g, '');
                    const target = (taskId ? document.querySelector(`[data-task-id="${taskId}"]`) : null)
                        || document.querySelector('.estimation-card:not([style*="display: none"])')
                        || document.querySelector('main form, #page-content-wrapper form');
                    if (target) {
                        target.classList.add('evortix-guidance-target');
                        target.scrollIntoView({ behavior:'smooth', block:'center' });
                        window.setTimeout(() => target.classList.remove('evortix-guidance-target'), 7000);
                    }
                }
            }, 1600);
        }
    }

    minimized.addEventListener('click', function () {
        openChatMessages();
    });

    const returnEvortixHome = function () {
        clearTimeout(botTransitionTimer);
        clearTimeout(botTypeTimer);
        clearTimeout(evortixSpeechTimer);
        window.speechSynthesis?.cancel?.();
        botFloat?.classList.remove('is-leaving-bed', 'is-preparing-speech', 'is-speaking', 'is-dragging');
        const speech = botFloat?.querySelector('.chat-bot-speech');
        if (speech) {
            speech.setAttribute('aria-hidden', 'true');
        }
        if (botFloat) {
            botFloat.setAttribute('aria-hidden', 'true');
            botFloat.classList.add('is-returning-bed');
        }
        window.chatBotWidgetRobot?.sleep?.();
        botTransitionTimer = window.setTimeout(function () {
            if (!botFloat) {
                return;
            }

            botFloat.hidden = true;
            botFloat.classList.remove('is-returning-bed');
            botFloat.style.removeProperty('left');
            botFloat.style.removeProperty('top');
            botFloat.style.removeProperty('right');
            botFloat.style.removeProperty('bottom');
        }, 760);
    };

    botWidget?.addEventListener('click', function () {
        if (!botFloat) {
            return;
        }

        const shouldShow = botFloat.hidden;
        clearTimeout(botTransitionTimer);
        botFloat.classList.remove('is-leaving-bed', 'is-returning-bed', 'is-preparing-speech', 'is-speaking');
        if (shouldShow) {
            resetBotSpeech();
            botFloat.hidden = false;
            botFloat.setAttribute('aria-hidden', 'false');
            botFloat.classList.add('is-leaving-bed', 'is-preparing-speech');
            window.chatBotWidgetRobot?.wake?.();
            window.setTimeout(function () {
                window.chatBotFloatRobot?.wink?.('right', 2600);
            }, 420);
            botTransitionTimer = window.setTimeout(function () {
                botFloat.classList.remove('is-leaving-bed');
                botFloat.classList.remove('is-preparing-speech');
                botFloat.classList.add('is-speaking');
                if (!showEvortixRoleDashboardSummary()) {
                    typeBotSpeech(260);
                }
                botTransitionTimer = window.setTimeout(function () {
                    botFloat.classList.remove('is-speaking');
                }, 520);
            }, 760);
        } else {
            returnEvortixHome();
        }
    });

    botFloatDismiss?.addEventListener('pointerdown', function (event) {
        event.stopPropagation();
    });

    botFloatDismiss?.addEventListener('click', function (event) {
        event.preventDefault();
        event.stopPropagation();
        returnEvortixHome();
    });

    const clampBotFloatPosition = function (x, y) {
        const rect = botFloat.getBoundingClientRect();
        const padding = 10;
        return {
            x: Math.min(Math.max(padding, x), window.innerWidth - rect.width - padding),
            y: Math.min(Math.max(padding, y), window.innerHeight - rect.height - padding)
        };
    };

    botFloat?.addEventListener('pointerdown', function (event) {
        if (event.target instanceof Element && event.target.closest('.chat-bot-speech, .chat-bot-float-dismiss')) {
            return;
        }

        const rect = botFloat.getBoundingClientRect();
        botFloatPointer = {
            id: event.pointerId,
            offsetX: event.clientX - rect.left,
            offsetY: event.clientY - rect.top,
            startX: event.clientX,
            startY: event.clientY,
            moved: false,
            onRobot: true
        };
        botFloat.classList.add('is-dragging');
        botFloat.setPointerCapture?.(event.pointerId);
    });

    botFloat?.addEventListener('pointermove', function (event) {
        if (!botFloatPointer || botFloatPointer.id !== event.pointerId) {
            return;
        }

        if (Math.hypot(event.clientX - botFloatPointer.startX, event.clientY - botFloatPointer.startY) > 6) {
            botFloatPointer.moved = true;
        }

        const next = clampBotFloatPosition(
            event.clientX - botFloatPointer.offsetX,
            event.clientY - botFloatPointer.offsetY
        );
        botFloat.style.left = `${next.x}px`;
        botFloat.style.top = `${next.y}px`;
        botFloat.style.right = 'auto';
        botFloat.style.bottom = 'auto';
    });

    const finishBotFloatDrag = function (event) {
        if (!botFloatPointer || botFloatPointer.id !== event.pointerId) {
            return;
        }

        const shouldReact = event.type === 'pointerup'
            && !botFloatPointer.moved
            && botFloatPointer.onRobot
            && !botFloat.hidden
            && !botFloat.classList.contains('is-leaving-bed')
            && !botFloat.classList.contains('is-returning-bed');
        botFloatPointer = null;
        botFloat.classList.remove('is-dragging');
        botFloat.releasePointerCapture?.(event.pointerId);
        if (shouldReact) {
            window.chatBotFloatRobot?.setEmotion?.('clicked', 1400);
        }
    };

    botFloat?.addEventListener('pointerup', finishBotFloatDrag);
    botFloat?.addEventListener('pointercancel', finishBotFloatDrag);

    if (minimizeBtn) {
        minimizeBtn.addEventListener('click', function () {
            setSharedChatOpen(false);
            widget.style.display = 'none';
            minimized.style.display = 'block';
        });
    }

    let robotSvgBlueprint = null;
    let robotInstanceSequence = 0;
    const robotAnimatedAttributes = [
        'transform', 'opacity', 'd', 'fill', 'fill-opacity', 'stroke', 'stroke-opacity',
        'stroke-width', 'offset', 'stop-color', 'stop-opacity', 'x', 'y', 'x1', 'y1',
        'x2', 'y2', 'cx', 'cy', 'fx', 'fy', 'r', 'rx', 'ry', 'width', 'height'
    ];
    const normalizeRobotPart = function (name) {
        return String(name || '').trim().toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
    };
    const tagRobotParts = function (animation) {
        const elements = animation?.renderer?.elements || [];
        elements.forEach(function (element) {
            const layerName = String(element?.data?.nm || '');
            const layerNode = element?.baseElement || element?.layerElement;
            if (layerNode && layerName) {
                layerNode.setAttribute('data-robot-part', normalizeRobotPart(layerName));
                layerNode.setAttribute('data-robot-part-name', layerName);
            }

            if (layerName.toLowerCase() !== 'eyes') return;
            const features = {
                'group 1':'right-eye',
                'group 2':'mouth',
                'group 3':'right-eye',
                'group 4':'mouth',
                'group 5':'left-eye',
                'group 6':'left-eye'
            };
            const shapes = element?.data?.shapes || element?.shapesData || [];
            const items = element?.itemsData || [];
            shapes.forEach(function (shape, shapeIndex) {
                const feature = features[String(shape?.nm || '').toLowerCase()];
                const shapeNode = items[shapeIndex]?.gr || items[shapeIndex]?.baseElement;
                if (feature && shapeNode) {
                    shapeNode.setAttribute('data-robot-feature', feature);
                }
            });
        });
    };
    const readRobotNodeState = function (node) {
        return {
            attributes: robotAnimatedAttributes.map(function (attribute) {
                return node.getAttribute(attribute);
            }),
            styleTransform: node.style?.transform || '',
            styleOpacity: node.style?.opacity || '',
            styleDisplay: node.style?.display || ''
        };
    };
    const robotStatesMatch = function (left, right) {
        if (!left || !right || left.styleTransform !== right.styleTransform
            || left.styleOpacity !== right.styleOpacity || left.styleDisplay !== right.styleDisplay) {
            return false;
        }
        return left.attributes.every(function (value, index) {
            return value === right.attributes[index];
        });
    };
    const captureRobotBlueprint = function (animation, container) {
        tagRobotParts(animation);
        animation.goToAndStop(0, true);
        const sourceSvg = container.querySelector('svg');
        if (!sourceSvg) throw new Error('Robot SVG was not rendered.');

        const sourceNodes = [sourceSvg].concat(Array.from(sourceSvg.querySelectorAll('*')));
        sourceNodes.forEach(function (node, nodeIndex) {
            node.setAttribute('data-robot-node', String(nodeIndex));
        });

        const totalFrames = Math.max(1, Math.floor(Number(animation.totalFrames || animation.animationData?.op || 1)));
        const frameRate = Math.max(1, Number(animation.frameRate || animation.animationData?.fr || 60));
        const statesByNode = sourceNodes.map(function () { return []; });
        for (let frame = 0; frame < totalFrames; frame++) {
            animation.goToAndStop(frame, true);
            sourceNodes.forEach(function (node, nodeIndex) {
                statesByNode[nodeIndex].push(readRobotNodeState(node));
            });
        }
        animation.goToAndStop(0, true);

        const tracks = [];
        statesByNode.forEach(function (states, nodeIndex) {
            const first = states[0];
            if (!states.some(function (state) { return !robotStatesMatch(first, state); })) return;
            const attributeIndexes = robotAnimatedAttributes.map(function (_attribute, attributeIndex) {
                return states.some(function (state) {
                    return state.attributes[attributeIndex] !== first.attributes[attributeIndex];
                }) ? attributeIndex : -1;
            }).filter(function (attributeIndex) { return attributeIndex >= 0; });
            tracks.push({
                nodeId:String(nodeIndex),
                states,
                attributeIndexes,
                changesStyleTransform:states.some(function (state) { return state.styleTransform !== first.styleTransform; }),
                changesStyleOpacity:states.some(function (state) { return state.styleOpacity !== first.styleOpacity; }),
                changesStyleDisplay:states.some(function (state) { return state.styleDisplay !== first.styleDisplay; })
            });
        });

        return {
            svgTemplate:sourceSvg.cloneNode(true),
            totalFrames,
            frameRate,
            tracks
        };
    };
    const applyRobotState = function (track, state) {
        track.attributeIndexes.forEach(function (attributeIndex) {
            const attribute = robotAnimatedAttributes[attributeIndex];
            const value = state.attributes[attributeIndex];
            if (value === null) track.node.removeAttribute(attribute);
            else track.node.setAttribute(attribute, value);
        });
        if (track.changesStyleTransform) track.node.style.transform = state.styleTransform;
        if (track.changesStyleOpacity) track.node.style.opacity = state.styleOpacity;
        if (track.changesStyleDisplay) track.node.style.display = state.styleDisplay;
    };
    const namespaceRobotSvgIds = function (svg) {
        const suffix = `-erni-robot-${++robotInstanceSequence}`;
        const idMap = new Map();
        Array.from(svg.querySelectorAll('[id]')).forEach(function (node) {
            const oldId = node.id;
            const newId = `${oldId}${suffix}`;
            idMap.set(oldId, newId);
            node.id = newId;
        });
        if (!idMap.size) return;
        [svg].concat(Array.from(svg.querySelectorAll('*'))).forEach(function (node) {
            Array.from(node.attributes || []).forEach(function (attribute) {
                let value = attribute.value;
                idMap.forEach(function (newId, oldId) {
                    value = value.split(`url(#${oldId})`).join(`url(#${newId})`);
                    if (value === `#${oldId}`) value = `#${newId}`;
                });
                if (value !== attribute.value) node.setAttribute(attribute.name, value);
            });
        });
    };
    const prepareRobotFeatureControls = function (svg) {
        const eyeLayer = svg.querySelector('[data-robot-part="eyes"]');
        if (eyeLayer && !eyeLayer.querySelector('[data-robot-feature]')) {
            const layerRect = eyeLayer.getBoundingClientRect();
            const centerX = layerRect.left + layerRect.width / 2;
            Array.from(eyeLayer.querySelectorAll('path')).forEach(function (path) {
                const rect = path.getBoundingClientRect();
                const pathX = rect.left + rect.width / 2;
                const pathY = rect.top + rect.height / 2;
                const isEye = rect.width > 0 && rect.height > 0
                    && Math.abs(pathX - centerX) > layerRect.width * .16
                    && pathY < layerRect.top + layerRect.height * .72;
                if (isEye) {
                    path.setAttribute('data-robot-feature', pathX < centerX ? 'left-eye' : 'right-eye');
                }
            });
        }

        Array.from(svg.querySelectorAll('[data-robot-feature]')).forEach(function (feature) {
            if (feature.parentElement?.hasAttribute('data-robot-feature-control')) return;
            const wrapper = document.createElementNS('http://www.w3.org/2000/svg', 'g');
            wrapper.setAttribute('data-robot-feature-control', feature.getAttribute('data-robot-feature'));
            wrapper.style.transformBox = 'fill-box';
            wrapper.style.transformOrigin = 'center';
            feature.parentNode?.insertBefore(wrapper, feature);
            wrapper.appendChild(feature);
        });

        ['l-hand', 'l-hand-2', 'r-hand', 'r-hand-2'].forEach(function (partName) {
            const part = svg.querySelector(`[data-robot-part="${partName}"]`);
            if (!part || part.parentElement?.hasAttribute('data-robot-part-control')) return;
            const wrapper = document.createElementNS('http://www.w3.org/2000/svg', 'g');
            wrapper.setAttribute('data-robot-part-control', partName);
            wrapper.style.transformBox = 'fill-box';
            wrapper.style.transformOrigin = 'center';
            part.parentNode?.insertBefore(wrapper, part);
            wrapper.appendChild(part);
        });

        if (eyeLayer && !eyeLayer.querySelector('[data-robot-wink-mark]')) {
            const winkMarks = {
                right:'M 660 304 L 620 334 L 660 364',
                left:'M 420 304 L 460 334 L 420 364'
            };
            Object.entries(winkMarks).forEach(function ([side, pathData]) {
                const mark = document.createElementNS('http://www.w3.org/2000/svg', 'g');
                mark.setAttribute('data-robot-wink-mark', side);
                mark.style.opacity = '0';
                mark.style.pointerEvents = 'none';
                mark.style.transformBox = 'fill-box';
                mark.style.transformOrigin = 'center';

                const glow = document.createElementNS('http://www.w3.org/2000/svg', 'path');
                glow.setAttribute('d', pathData);
                glow.setAttribute('fill', 'none');
                glow.setAttribute('stroke', '#39eff4');
                glow.setAttribute('stroke-width', '28');
                glow.setAttribute('stroke-linecap', 'round');
                glow.setAttribute('stroke-linejoin', 'round');
                glow.setAttribute('opacity', '.2');

                const line = document.createElementNS('http://www.w3.org/2000/svg', 'path');
                line.setAttribute('d', pathData);
                line.setAttribute('fill', 'none');
                line.setAttribute('stroke', '#62f4f5');
                line.setAttribute('stroke-width', '16');
                line.setAttribute('stroke-linecap', 'round');
                line.setAttribute('stroke-linejoin', 'round');

                mark.append(glow, line);
                eyeLayer.appendChild(mark);
            });
        }
    };
    const instantiateControllableRobot = function (blueprint, container) {
        const svg = blueprint.svgTemplate.cloneNode(true);
        namespaceRobotSvgIds(svg);
        svg.classList.add('erni-controllable-robot');
        container.replaceChildren(svg);
        prepareRobotFeatureControls(svg);

        const tracks = blueprint.tracks.map(function (track) {
            return {
                node:track.nodeId === '0' ? svg : svg.querySelector(`[data-robot-node="${track.nodeId}"]`),
                states:track.states,
                attributeIndexes:track.attributeIndexes,
                changesStyleTransform:track.changesStyleTransform,
                changesStyleOpacity:track.changesStyleOpacity,
                changesStyleDisplay:track.changesStyleDisplay
            };
        }).filter(function (track) { return Boolean(track.node); });
        let currentFrame = 0;
        let playing = true;
        let animationFrame = null;
        let startedAt = performance.now();
        let winkSequence = 0;
        let resumeRobotAfterWink = false;
        let winkContainerAnimationState = null;

        const applyFrame = function (frame) {
            currentFrame = ((Math.floor(frame) % blueprint.totalFrames) + blueprint.totalFrames) % blueprint.totalFrames;
            tracks.forEach(function (track) {
                applyRobotState(track, track.states[currentFrame]);
            });
        };
        const tick = function (now) {
            if (!playing) return;
            const elapsedFrames = (now - startedAt) * blueprint.frameRate / 1000;
            const nextFrame = Math.floor(elapsedFrames) % blueprint.totalFrames;
            if (nextFrame !== currentFrame) applyFrame(nextFrame);
            animationFrame = requestAnimationFrame(tick);
        };
        const controller = {
            root:svg,
            totalFrames:blueprint.totalFrames,
            frameRate:blueprint.frameRate,
            get isPaused() { return !playing; },
            get currentFrame() { return currentFrame; },
            getPart:function (name) {
                const partName = normalizeRobotPart(name);
                return svg.querySelector(`[data-robot-part-control="${partName}"]`)
                    || svg.querySelector(`[data-robot-part="${partName}"]`);
            },
            getFeatures:function (name) {
                const featureName = normalizeRobotPart(name);
                const controls = Array.from(svg.querySelectorAll(`[data-robot-feature-control="${featureName}"]`));
                return controls.length ? controls : Array.from(svg.querySelectorAll(`[data-robot-feature="${featureName}"]`));
            },
            animatePart:function (name, keyframes, options) {
                return this.getPart(name)?.animate?.(keyframes, options) || null;
            },
            animateFeatures:function (name, keyframes, options) {
                return this.getFeatures(name).map(function (feature) {
                    feature.style.transformBox = 'fill-box';
                    feature.style.transformOrigin = 'center';
                    return feature.animate?.(keyframes, options) || null;
                }).filter(Boolean);
            },
            wink:function (side = 'right') {
                const sequence = ++winkSequence;
                resumeRobotAfterWink = resumeRobotAfterWink || playing;
                this.pause();
                if (winkContainerAnimationState === null) {
                    winkContainerAnimationState = container.style.animationPlayState;
                }
                container.style.animationPlayState = 'paused';
                const featureName = side === 'left' ? 'left-eye' : 'right-eye';
                const duration = 920;
                const animations = this.animateFeatures(featureName, [
                    { opacity:1, offset:0 },
                    { opacity:1, offset:.22 },
                    { opacity:0, offset:.34 },
                    { opacity:0, offset:.72 },
                    { opacity:1, offset:.84 },
                    { opacity:1, offset:1 }
                ], { duration, easing:'cubic-bezier(.4,0,.2,1)' });

                const winkMark = svg.querySelector(`[data-robot-wink-mark="${side}"]`);
                if (winkMark?.animate) {
                    animations.push(winkMark.animate([
                        { opacity:0, transform:'scale(.9)', offset:0 },
                        { opacity:0, transform:'scale(.9)', offset:.22 },
                        { opacity:1, transform:'scale(1.06)', offset:.36 },
                        { opacity:1, transform:'scale(1)', offset:.72 },
                        { opacity:0, transform:'scale(.96)', offset:.86 },
                        { opacity:0, transform:'scale(.96)', offset:1 }
                    ], { duration, easing:'cubic-bezier(.2,.8,.2,1)' }));
                }

                const handKeyframes = [
                    { transform:'translateY(0)', offset:0 },
                    { transform:'translateY(0)', offset:.16 },
                    { transform:'translateY(-72px)', offset:.4 },
                    { transform:'translateY(-72px)', offset:.72 },
                    { transform:'translateY(0)', offset:1 }
                ];
                ['r hand', 'r hand 2'].forEach((handName) => {
                    const handAnimation = this.animatePart(handName, handKeyframes, {
                        duration,
                        easing:'cubic-bezier(.2,.8,.2,1)'
                    });
                    if (handAnimation) animations.push(handAnimation);
                });
                window.setTimeout(() => {
                    if (sequence !== winkSequence) return;
                    this.getFeatures(featureName).forEach(function (feature) {
                        feature.style.opacity = '';
                        feature.style.transform = '';
                    });
                    if (winkMark) {
                        winkMark.style.opacity = '0';
                        winkMark.style.transform = '';
                    }
                    container.style.animationPlayState = winkContainerAnimationState || '';
                    winkContainerAnimationState = null;
                    if (resumeRobotAfterWink) {
                        resumeRobotAfterWink = false;
                        this.play();
                    }
                }, duration + 30);
                return animations;
            },
            play:function () {
                if (playing) return;
                playing = true;
                startedAt = performance.now() - (currentFrame * 1000 / blueprint.frameRate);
                animationFrame = requestAnimationFrame(tick);
            },
            pause:function () {
                playing = false;
                if (animationFrame !== null) cancelAnimationFrame(animationFrame);
                animationFrame = null;
            },
            goToAndStop:function (frame) {
                this.pause();
                applyFrame(frame);
            },
            playSegments:function () {
                this.play();
            },
            destroy:function () {
                this.pause();
                svg.remove();
            }
        };
        applyFrame(0);
        animationFrame = requestAnimationFrame(tick);
        return controller;
    };
    const convertLottieRobot = function (animation, container, globalName) {
        try {
            if (!robotSvgBlueprint) {
                robotSvgBlueprint = captureRobotBlueprint(animation, container);
            }
            animation.destroy();
            const controller = instantiateControllableRobot(robotSvgBlueprint, container);
            window[globalName] = controller;
            document.dispatchEvent(new CustomEvent('chatbot:controllable-robot-ready', {
                detail:{ name:globalName, controller }
            }));
            return controller;
        } catch (error) {
            console.warn('Controllable robot fallback:', error);
            animation.loop = true;
            animation.play?.();
            window[globalName] = animation;
            return animation;
        }
    };

    const loadChatBotAnimation = function () {
        const bot = document.getElementById('chatBotAnimation');
        const floatingBot = document.getElementById('chatBotFloatAnimation');
        if (!window.lottie) {
            return;
        }

        const baseUrl = (window.BASE_URL || '/erni-pms/').replace(/\/?$/, '/');
        if (bot && !bot.dataset.lottieReady) {
            bot.dataset.lottieReady = 'true';
            bot.innerHTML = '';
            const widgetRobotSource = window.lottie.loadAnimation({
                container: bot,
                renderer: 'svg',
                loop: false,
                autoplay: false,
                path: `${baseUrl}assets/animations/Ai%20Robot%20Vector%20Art.json?v=20260714-controllable-svg`
            });
            widgetRobotSource.addEventListener('DOMLoaded', function () {
                convertLottieRobot(widgetRobotSource, bot, 'chatBotWidgetRobot');
            });
        }

        if (floatingBot && !floatingBot.dataset.lottieReady) {
            floatingBot.dataset.lottieReady = 'true';
            floatingBot.innerHTML = '';
            const floatingRobotSource = window.lottie.loadAnimation({
                container: floatingBot,
                renderer: 'svg',
                loop: false,
                autoplay: false,
                path: `${baseUrl}assets/animations/Ai%20Robot%20Vector%20Art.json?v=20260714-controllable-svg`
            });
            window.chatBotFloatLottie = floatingRobotSource;
            floatingRobotSource.addEventListener('DOMLoaded', function () {
                const controller = convertLottieRobot(floatingRobotSource, floatingBot, 'chatBotFloatRobot');
                window.chatBotFloatLottie = controller;
            });
        }
    };

    if (window.EvortixAssistant) {
        window.chatBotWidgetRobot = window.EvortixAssistant.mount(botWidget?.querySelector('#chatBotAnimation'), { size:'launcher', mode:'sleeping' });
        window.chatBotFloatRobot = window.EvortixAssistant.mount(botFloat?.querySelector('#chatBotFloatAnimation'), { size:'full', mode:'standing' });
        window.chatBotFloatLottie = window.chatBotFloatRobot;
        document.dispatchEvent(new CustomEvent('chatbot:controllable-robot-ready', {
            detail:{ name:'chatBotFloatRobot', controller:window.chatBotFloatRobot }
        }));
    }
});
</script>
<script>
(() => {
    const dashboardMatch = window.location.pathname.match(/\/modules\/(admin|sales|architect|qa|expert|developer)\/dashboard\.php$/i);
    if (!dashboardMatch) return;

    const role = dashboardMatch[1].toLowerCase();
    const tabsByRole = {
        admin: [['renewals', 'Renewals', 'fa-clock-rotate-left', 'Renewal Watch', 'No technology stack is expiring in the next 30 days.'], ['login', 'Login', 'fa-user-lock', 'Login Attack Watch', 'No brute-force lockout pattern detected in the last 15 minutes.'], ['recovery', 'Recovery', 'fa-heart-pulse', 'Password Recovery', 'No password recovery requests are pending.']],
        sales: [['projects', 'Projects', 'fa-diagram-project', 'Project Watch', 'No project signals need attention.'], ['clients', 'Clients', 'fa-users', 'Client Watch', 'No client signals need attention.'], ['overdue', 'Overdue', 'fa-triangle-exclamation', 'Overdue Watch', 'No overdue projects need attention.']],
        architect: [['review', 'Review', 'fa-folder-open', 'Architecture Review', 'No architecture reviews need attention.'], ['progress', 'Progress', 'fa-gears', 'Planning Progress', 'No planning work needs attention.'], ['overdue', 'Overdue', 'fa-triangle-exclamation', 'Overdue Watch', 'No overdue architecture work was detected.']],
        qa: [['testing', 'Testing', 'fa-vial', 'Testing Queue', 'No tasks are waiting for QA.'], ['failed', 'Failed', 'fa-circle-xmark', 'Failed Checks', 'No failed QA checks need attention.'], ['overdue', 'Overdue', 'fa-triangle-exclamation', 'Overdue QA', 'No overdue QA items were detected.']],
        expert: [['tasks', 'Tasks', 'fa-list-check', 'Assigned Tasks', 'No assigned tasks need attention.'], ['estimate', 'Estimate', 'fa-calculator', 'Estimation Queue', 'No estimation items need attention.'], ['overdue', 'Overdue', 'fa-triangle-exclamation', 'Overdue Work', 'No overdue expert work was detected.']],
        developer: [['tasks', 'Tasks', 'fa-list-check', 'Assigned Tasks', 'No assigned tasks need attention.'], ['progress', 'Progress', 'fa-play', 'In Progress', 'No active in-progress tasks were detected.'], ['overdue', 'Overdue', 'fa-triangle-exclamation', 'Overdue Work', 'No overdue developer work was detected.']]
    };
    const escapeHtml = value => {
        const node = document.createElement('div');
        node.textContent = String(value ?? '');
        return node.innerHTML;
    };
    const openEvortixOverview = () => {
        const float = document.getElementById('chat-bot-float');
        const speech = float?.querySelector('.chat-bot-speech');
        if (!float || !speech) return false;

        const tabs = tabsByRole[role] || tabsByRole.sales;
        const cards = [...document.querySelectorAll('.admin-stat-card, .admin-metric-card, .stat-card-modern, .dashboard-stat-card, .stats-grid > *, .role-stats-row .card')]
            .map(card => {
                const label = card.querySelector('.admin-stat-label, .stat-label, h6, small, [class*="label"], [class*="title"]')?.textContent?.replace(/\s+/g, ' ').trim() || '';
                const value = card.querySelector('.admin-stat-value, .stat-value, h2, h3, .h1, .display-4, [class*="value"], [class*="count"]')?.textContent?.replace(/\s+/g, ' ').trim() || '';
                const meta = card.querySelector('.admin-stat-sub, .stat-description, p, small:last-child, [class*="meta"], [class*="desc"]')?.textContent?.replace(/\s+/g, ' ').trim() || '';
                return label && value ? { label, meta: `${value}${meta && meta !== label && meta !== value ? ` - ${meta}` : ''}` } : null;
            })
            .filter(Boolean)
            .slice(0, 6);
        const renderTab = tab => {
            const items = cards.slice(0, 3);
            speech.querySelectorAll('[data-evortix-tail-tab]').forEach(button => {
                const active = button.dataset.evortixTailTab === tab[0];
                button.classList.toggle('is-active', active);
                button.setAttribute('aria-selected', active ? 'true' : 'false');
                if (active) button.querySelector('strong').textContent = String(items.length);
            });
            speech.querySelector('[data-evortix-tail-title]').textContent = tab[3];
            speech.querySelector('[data-evortix-tail-count]').textContent = `${items.length} item${items.length === 1 ? '' : 's'}`;
            speech.querySelector('[data-evortix-tail-list]').innerHTML = items.length
                ? items.map(item => `<a class="evortix-project-link" href="#"><span class="evortix-project-link-copy"><strong>${escapeHtml(item.label)}</strong><small>${escapeHtml(item.meta)}</small></span><i class="fas fa-arrow-right" aria-hidden="true"></i></a>`).join('')
                : `<div class="evortix-project-list-empty">${escapeHtml(tab[4])}</div>`;
        };

        speech.className = 'chat-bot-speech evortix-role-dashboard-summary is-compact has-project-list';
        speech.hidden = false;
        speech.removeAttribute('hidden');
        speech.setAttribute('aria-hidden', 'false');
        speech.setAttribute('role', 'status');
        speech.style.pointerEvents = 'auto';
        speech.innerHTML = `
            <div class="evortix-role-summary-head is-compact"><strong class="evortix-role-summary-title">Live Overview</strong><button type="button" class="evortix-role-summary-close" aria-label="Dismiss dashboard overview"><i class="fas fa-xmark"></i></button></div>
            <div class="evortix-role-summary-intro is-compact">${escapeHtml(role.charAt(0).toUpperCase() + role.slice(1))} overview is ready. Review the current dashboard signals.</div>
            <div class="evortix-project-status-tabs" role="tablist" aria-label="Evortix dashboard status">${tabs.map(tab => `<button type="button" class="evortix-project-status-tab" data-evortix-tail-tab="${escapeHtml(tab[0])}" role="tab" aria-selected="false"><i class="fas ${escapeHtml(tab[2])}" aria-hidden="true"></i><span>${escapeHtml(tab[1])}</span><strong>0</strong></button>`).join('')}</div>
            <div class="evortix-project-list-head"><strong data-evortix-tail-title></strong><span data-evortix-tail-count></span></div>
            <div class="evortix-project-list" data-evortix-tail-list></div>`;
        speech.querySelectorAll('[data-evortix-tail-tab]').forEach(button => {
            const tab = tabs.find(item => item[0] === button.dataset.evortixTailTab) || tabs[0];
            button.addEventListener('click', () => renderTab(tab));
        });
        renderTab(tabs[0]);

        float.hidden = false;
        float.removeAttribute('hidden');
        float.setAttribute('aria-hidden', 'false');
        float.style.display = 'block';
        float.classList.remove('is-returning-bed', 'is-preparing-speech');
        float.classList.add('is-speaking');
        window.chatBotWidgetRobot?.setMode?.('empty-bed');
        window.chatBotFloatRobot?.setMode?.('standing');
        window.setTimeout(() => float.classList.remove('is-speaking'), 700);
        speech.querySelector('.evortix-role-summary-close')?.addEventListener('click', event => {
            event.stopPropagation();
            speech.hidden = true;
            speech.setAttribute('aria-hidden', 'true');
        });
        return true;
    };

    const scheduleEvortixOverview = () => [80, 300, 800, 1500, 2800, 5200].forEach(delay => window.setTimeout(openEvortixOverview, delay));
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', scheduleEvortixOverview, { once: true });
    else scheduleEvortixOverview();
    window.addEventListener('load', () => window.setTimeout(openEvortixOverview, 140), { once: true });
    document.addEventListener('chatbot:controllable-robot-ready', () => window.setTimeout(openEvortixOverview, 120), { once: true });
})();
</script>
