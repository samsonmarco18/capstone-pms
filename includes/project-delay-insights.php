<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

function delayInsightsLoadInsights(mysqli|PgsqlMysqliCompatConnection $conn, array $user, array $options = []): array
{
    $projectId = isset($options['project_id']) ? (int) $options['project_id'] : null;
    $limit = isset($options['limit']) ? max(1, (int) $options['limit']) : 3;

    $projectIds = delayInsightsResolveProjectIds($conn, $user, $projectId, $limit);
    if ($projectIds === []) {
        return [];
    }

    $insights = [];
    foreach ($projectIds as $resolvedProjectId) {
        $projectInsight = delayInsightsBuildProjectInsight($conn, $resolvedProjectId);
        if ($projectInsight !== null) {
            $insights[] = $projectInsight;
        }
    }

    return $insights;
}

function delayInsightsRenderSection(mysqli|PgsqlMysqliCompatConnection $conn, array $user, array $options = []): void
{
    delayInsightsRenderDetailCards($conn, $user, $options);
}

function delayInsightsProblemAction(array $insight, array $user): ?array
{
    $project = (array) ($insight['project'] ?? []);
    $counts = (array) ($project['issue_counts'] ?? []);
    $projectId = (int) ($project['id'] ?? 0);
    $clientId = (int) ($project['client_id'] ?? 0);
    $role = (string) ($user['role'] ?? '');
    if ($projectId <= 0) return null;

    $issue = '';
    $count = 0;
    $target = '';
    $anchor = '';
    if ((int) ($counts['unassigned_tasks'] ?? 0) > 0 || (int) ($counts['tasks_without_deadline'] ?? 0) > 0) {
        $issue = 'task_planning';
        $count = max((int) ($counts['unassigned_tasks'] ?? 0), (int) ($counts['tasks_without_deadline'] ?? 0));
        if ($role === 'sul') {
            $target = BASE_URL . 'modules/sul/task-allocation.php?project_id=' . $projectId;
            $anchor = 'expert-unassigned-panel';
        } elseif ($role === 'sales') {
            $target = BASE_URL . 'modules/sales/release-status.php?client_id=' . $clientId . '&project_id=' . $projectId;
            $anchor = 'insight-task-problems';
        } elseif ($role === 'architect') {
            $target = BASE_URL . 'modules/roadmap/methodology.php?project_id=' . $projectId;
            $anchor = 'roadmap-task-plan';
        }
    } elseif ((int) ($counts['feature_count'] ?? 0) === 0) {
        $issue = 'missing_features';
        $target = $role === 'architect' ? BASE_URL . 'modules/architect/features.php?id=' . $projectId : '';
    } elseif ((int) ($counts['tech_count'] ?? 0) === 0) {
        $issue = 'missing_technology';
        $target = $role === 'architect' ? BASE_URL . 'modules/architect/tech-recommendation.php?id=' . $projectId : '';
    } elseif ((int) ($counts['allocation_count'] ?? 0) === 0 || (int) ($counts['total_resources'] ?? 0) === 0) {
        $issue = 'missing_allocation';
        $target = $role === 'architect' ? BASE_URL . 'modules/architect/developer-allocation.php?id=' . $projectId : '';
    } elseif ((int) ($counts['document_count'] ?? 0) === 0) {
        $issue = 'missing_documents';
        $target = $role === 'architect' ? BASE_URL . 'modules/architect/upload-diagram.php?id=' . $projectId : '';
    } elseif ((int) ($counts['overdue_tasks'] ?? 0) > 0 || (int) ($counts['blocked_tasks'] ?? 0) > 0) {
        $issue = 'delayed_tasks';
        $count = (int) ($counts['overdue_tasks'] ?? 0) + (int) ($counts['blocked_tasks'] ?? 0);
    }

    if ($issue === '') return null;
    if ($target === '') {
        $target = BASE_URL . 'modules/insights/process-history.php?project_id=' . $projectId;
        $anchor = 'project-process-history';
    }
    $target .= (str_contains($target, '?') ? '&' : '?') . http_build_query([
        'evortix_issue' => $issue,
        'evortix_count' => $count,
        'evortix_project' => (string) ($project['name'] ?? 'Project'),
    ]);
    if ($anchor !== '') $target .= '#' . rawurlencode($anchor);

    return ['url' => $target, 'label' => 'Go to problem'];
}

function delayInsightsEnsureStyles(): void
{
    static $stylesPrinted = false;
    if ($stylesPrinted) {
        return;
    }

    $stylesPrinted = true;
    echo '<style>
        .delay-insights-wrap{margin-bottom:2rem;}
        .delay-insights-card{background:#fff;border:1px solid rgba(31,99,146,.14);border-radius:24px;box-shadow:0 12px 28px rgba(15,76,129,.08);overflow:hidden;}
        .delay-insights-head{padding:1.5rem 1.75rem;border-bottom:1px solid rgba(31,99,146,.10);background:#f8fcff;}
        .delay-insights-head h4{margin:0 0 .35rem;font-size:1.1rem;font-weight:600;color:#1f6392;display:flex;align-items:center;gap:.6rem;}
        .delay-insights-head p{margin:0;color:#6c757d;font-size:.85rem;line-height:1.5;}
        .delay-insight-item{padding:1.5rem 1.75rem;border-bottom:1px solid #e9ecef;}
        .delay-insight-item:last-child{border-bottom:none;}
        .delay-insight-project{display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;margin-bottom:1.25rem;flex-wrap:wrap;}
        .delay-insight-project h5{margin:0 0 .35rem;font-size:1rem;font-weight:600;color:#212529;}
        .delay-insight-project p{margin:0;color:#6c757d;font-size:.8rem;}
        .delay-risk-badge{display:inline-flex;align-items:center;gap:.4rem;padding:.45rem .8rem;border-radius:999px;font-size:.72rem;font-weight:600;white-space:nowrap;flex-shrink:0;position:absolute;top:.75rem;right:1rem;}
        .delay-risk-low{background:#d1fae5;color:#047857;}
        .delay-risk-medium{background:#fef3c7;color:#b45309;}
        .delay-risk-high{background:#fee2e2;color:#b91c1c;}
        .delay-panels{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:1rem;}
        .delay-panel{border:1px solid #e9ecef;border-radius:18px;padding:1rem 1.1rem;background:#fff;}
        .delay-panel.before{background:#fff;}
        .delay-panel.after{background:#fff;}
        .delay-panel-title{display:flex;align-items:center;gap:.5rem;font-size:.82rem;font-weight:600;color:#212529;margin-bottom:.9rem;text-transform:uppercase;letter-spacing:.04em;}
        .delay-metric-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:.75rem;margin-bottom:.85rem;}
        .delay-metric{background:#fff;border:1px solid #e9ecef;border-radius:14px;padding:.8rem .9rem;}
        .delay-metric span{display:block;font-size:.7rem;text-transform:uppercase;letter-spacing:.05em;color:#6c757d;margin-bottom:.25rem;}
        .delay-metric strong{font-size:1.45rem;line-height:1;color:#1f6392;}
        .delay-bar{height:10px;border-radius:999px;background:#e9ecef;overflow:hidden;margin:.85rem 0 1rem;}
        .delay-bar span{display:block;height:100%;border-radius:999px;}
        .delay-bar.delay span{background:#dc2626;}
        .delay-bar.success span{background:#10b981;}
        .delay-copy{font-size:.82rem;color:#495057;line-height:1.55;margin:0 0 .8rem;}
        .delay-list{margin:0;padding-left:1rem;color:#495057;font-size:.8rem;}
        .delay-list li{margin-bottom:.45rem;}
        .delay-list li:last-child{margin-bottom:0;}
        .delay-tags{display:flex;flex-wrap:wrap;gap:.5rem;margin:.8rem 0 0;}
        .delay-tag{display:inline-flex;align-items:center;padding:.38rem .7rem;border-radius:999px;background:#fff7ed;color:#9a3412;border:1px solid #fdba74;font-size:.74rem;font-weight:600;}
        .delay-owners{display:flex;flex-wrap:wrap;gap:.5rem;margin-top:.85rem;}
        .delay-owner{display:inline-flex;align-items:center;padding:.42rem .72rem;border-radius:999px;background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;font-size:.74rem;font-weight:600;}
        .delay-note{display:inline-flex;align-items:center;gap:.45rem;padding:.6rem .75rem;border-radius:14px;background:#f8fafc;border:1px solid #e2e8f0;color:#475569;font-size:.76rem;margin-top:.8rem;}
        .delay-action{display:inline-flex;align-items:center;justify-content:center;gap:.45rem;padding:.62rem .95rem;border:none;border-radius:999px;background:#1f6392;color:#fff;text-decoration:none;font-size:.78rem;font-weight:600;transition:background .2s ease,box-shadow .2s ease;}
        .delay-action:hover{color:#fff;background:#184f73;box-shadow:0 6px 16px rgba(31,99,146,.25);}
        .delay-fix-action{margin-top:.85rem;width:max-content;max-width:100%;}
        .delay-insight-project-right{display:flex;align-items:center;gap:.75rem;flex-wrap:wrap;justify-content:flex-end;}
        .delay-compact-card{background:#fff;border:1px solid rgba(31,99,146,.14);border-radius:24px;box-shadow:0 12px 28px rgba(15,76,129,.08);overflow:hidden;margin-bottom:0;display:flex;flex-direction:column;}
        .delay-compact-head{display:flex;align-items:flex-start;justify-content:space-between;gap:1rem;padding:1.35rem 1.5rem;border-bottom:1px solid rgba(31,99,146,.10);background:#f8fcff;}
        .delay-compact-head-copy{min-width:0;}
        .delay-compact-head h4{margin:0 0 .3rem;font-size:1rem;font-weight:600;color:#1f6392;display:flex;align-items:center;gap:.55rem;}
        .delay-compact-head p{margin:0;color:#6c757d;font-size:.82rem;line-height:1.55;max-width:700px;}
        .delay-compact-count{display:inline-flex;align-items:center;justify-content:center;padding:.45rem .8rem;border-radius:999px;background:#fff;color:#1f6392;border:1px solid rgba(31,99,146,.16);font-size:.72rem;font-weight:600;white-space:nowrap;}
        .delay-compact-list{display:grid;gap:1rem;padding:1rem 1.1rem;flex-grow:1;}
        .delay-compact-row{display:grid;grid-template-columns:1fr 1fr auto;grid-template-rows:auto auto;gap:.8rem 1rem;padding:.75rem 1rem;border:1px solid #d9e8f3;border-radius:14px;background:#fff;box-shadow:0 4px 12px rgba(15,74,110,.04);transition:border-color .2s ease,box-shadow .2s ease;position:relative;}
        .delay-compact-row:hover{border-color:#aecadf;box-shadow:0 14px 28px rgba(15,74,110,.09);}
        .delay-compact-row.delay-row-risk-high{border-color:rgba(220,38,38,.46);box-shadow:0 0 0 1px rgba(220,38,38,.12),0 0 22px rgba(220,38,38,.16),0 14px 30px rgba(15,74,110,.06);}
        .delay-compact-row.delay-row-risk-high:hover{border-color:rgba(220,38,38,.58);box-shadow:0 0 0 1px rgba(220,38,38,.18),0 0 28px rgba(220,38,38,.22),0 16px 34px rgba(15,74,110,.08);}
        .delay-compact-main{min-width:0;display:block;grid-column:1 / 3;grid-row:1;}
        .delay-compact-top{display:block;min-width:0;}
        .delay-compact-title{min-width:0;display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;width:100%;}
        .delay-compact-title-row{display:block;min-width:0;}
        .delay-compact-title strong{display:inline;font-size:.9rem;font-weight:600;color:#212529;line-height:1.2;}
        .delay-compact-meta{display:flex;flex-wrap:wrap;gap:.5rem;}
        .delay-compact-meta span{display:inline-flex;align-items:center;gap:.35rem;padding:.35rem .65rem;border-radius:999px;background:#f8fafc;border:1px solid #e2eaf1;color:#52616f;font-size:.72rem;line-height:1;}
        .delay-compact-note{max-width:780px;font-size:.82rem;line-height:1.55;color:#425466;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;}
        .delay-compact-metric{min-width:0;display:flex;flex-direction:column;justify-content:center;min-height:50px;padding:.6rem .7rem;border:1px solid #dce7ef;border-radius:10px;background:#fbfdff;box-shadow:inset 0 1px 0 rgba(255,255,255,.7);grid-row:2;}
        .delay-metric-delay{grid-column:1;}
        .delay-metric-success{grid-column:2;}
        .delay-compact-metric label{display:block;font-size:.58rem;letter-spacing:.06em;text-transform:uppercase;color:#6c757d;margin-bottom:.25rem;}
        .delay-compact-metric strong{font-size:1.1rem;font-weight:600;color:#1f6392;line-height:1.1;}
        .delay-row-risk-high .delay-metric-delay{border-color:rgba(220,38,38,.26);background:#fffafa;}
        .delay-row-risk-high .delay-metric-delay strong{color:#b91c1c;}
        .delay-compact-action{align-self:center;justify-self:stretch;min-height:40px;white-space:nowrap;border-radius:10px;font-size:0.85rem;grid-column:3;grid-row:2;display:flex;align-items:center;justify-content:center;}
        .delay-compact-footer{display:flex;justify-content:flex-end;align-items:center;padding:.95rem 1.1rem 1.1rem;border-top:1px solid rgba(31,99,146,.10);background:linear-gradient(180deg,#fff 0%,#f8fcff 100%);}
        .delay-compact-more-action{min-height:44px;padding:.72rem 1rem;border-radius:12px;font-size:.86rem;box-shadow:0 10px 22px rgba(31,99,146,.14);}
        .delay-compact-more-action i{transition:transform .2s ease;}
        .delay-compact-more-action:hover i{transform:translateX(2px);}
         .delay-compact-empty{min-height:220px;padding:2rem 1.5rem;display:grid;place-items:center;align-content:center;gap:.55rem;color:#6c757d;text-align:center;}
         .delay-compact-empty i{font-size:2rem;color:#1f6392;opacity:.72;}
         .delay-compact-empty strong{color:#212529;font-size:.95rem;font-weight:600;}
         .delay-compact-empty span{max-width:380px;color:#6c757d;font-size:.82rem;line-height:1.5;}
         @media (max-width: 1199.98px){.delay-compact-row{grid-template-columns:1fr auto;grid-template-rows:auto auto auto;gap:.6rem;padding-top:2.5rem;}.delay-compact-main{grid-column:1;grid-row:1;}.delay-metric-delay{grid-column:1;grid-row:2;}.delay-metric-success{grid-column:1;grid-row:3;}.delay-compact-action{grid-column:2;grid-row:2 / 4;}.delay-risk-badge{position:absolute;top:.75rem;right:1rem;}}
         @media (max-width: 991.98px){.delay-panels{grid-template-columns:1fr;}}
         @media (max-width: 767.98px){.delay-insights-head,.delay-insight-item{padding:1.2rem;}.delay-compact-head{padding:1.2rem;flex-direction:column;align-items:flex-start;}.delay-compact-list{padding:1rem;}.delay-compact-footer{padding:.85rem 1rem 1rem;}.delay-metric-grid{grid-template-columns:1fr;}.delay-compact-row{grid-template-columns:1fr;grid-template-rows:auto auto auto auto;gap:.6rem;padding:2.5rem 1rem 1rem 1rem;}.delay-compact-main{grid-column:1;grid-row:1;}.delay-compact-top{flex-direction:column;align-items:flex-start;}.delay-metric-delay{grid-column:1;grid-row:2;width:100%;}.delay-metric-success{grid-column:1;grid-row:3;width:100%;}.delay-compact-action{grid-column:1;grid-row:4;width:100%;justify-self:stretch;min-height:44px;}.delay-risk-badge{position:absolute;top:.75rem;right:1rem;}.delay-insight-project-right{justify-content:flex-start;}}
         html[data-theme="dark"] .delay-insights-card,
         html[data-theme="dark"] .delay-compact-card{
             background:rgba(10,22,35,.88);
             border-color:rgba(138,210,255,.10);
             box-shadow:0 14px 32px rgba(0,0,0,.32);
         }
         html[data-theme="dark"] .delay-insights-head,
         html[data-theme="dark"] .delay-compact-head{
             background:rgba(12,31,49,.96);
             border-bottom-color:rgba(138,210,255,.10);
         }
         html[data-theme="dark"] .delay-insights-head h4,
         html[data-theme="dark"] .delay-compact-head h4,
          html[data-theme="dark"] .delay-panel-title,
          html[data-theme="dark"] .delay-insight-project h5,
          html[data-theme="dark"] .delay-compact-title strong,
          html[data-theme="dark"] .delay-compact-empty strong{
              color:#d8e6f3;
          }
         html[data-theme="dark"] .delay-insights-head p,
         html[data-theme="dark"] .delay-compact-head p,
         html[data-theme="dark"] .delay-insight-project p,
         html[data-theme="dark"] .delay-copy,
          html[data-theme="dark"] .delay-list,
          html[data-theme="dark"] .delay-compact-note,
          html[data-theme="dark"] .delay-compact-empty span{
              color:#a8c0d6;
          }
          html[data-theme="dark"] .delay-compact-empty i{
              color:#8cc7ff;
          }
         html[data-theme="dark"] .delay-compact-count{
             background:rgba(12,31,49,.95);
             color:#8cc7ff;
             border-color:rgba(138,210,255,.12);
         }
         html[data-theme="dark"] .delay-insight-item,
         html[data-theme="dark"] .delay-compact-row{
             border-color:rgba(138,210,255,.10);
             background:rgba(12,31,49,.96);
             box-shadow:0 10px 24px rgba(0,0,0,.24);
         }
         html[data-theme="dark"] .delay-compact-row:hover{
             border-color:rgba(138,210,255,.18);
             box-shadow:0 14px 28px rgba(0,0,0,.30);
         }
         html[data-theme="dark"] .delay-compact-footer{
             background:linear-gradient(180deg,rgba(10,22,35,.88) 0%,rgba(12,31,49,.96) 100%);
             border-top-color:rgba(138,210,255,.10);
         }
         html[data-theme="dark"] .delay-compact-row.delay-row-risk-high{
             border-color:rgba(248,113,113,.48);
             box-shadow:0 0 0 1px rgba(248,113,113,.14),0 0 26px rgba(127,29,29,.34),0 14px 30px rgba(0,0,0,.24);
         }
         html[data-theme="dark"] .delay-compact-row.delay-row-risk-high:hover{
             border-color:rgba(248,113,113,.62);
             box-shadow:0 0 0 1px rgba(248,113,113,.2),0 0 34px rgba(127,29,29,.42),0 16px 34px rgba(0,0,0,.30);
         }
         html[data-theme="dark"] .delay-panel,
         html[data-theme="dark"] .delay-metric,
         html[data-theme="dark"] .delay-compact-metric{
             background:rgba(9,24,38,.92);
             border-color:rgba(138,210,255,.10);
             box-shadow:none;
         }
         html[data-theme="dark"] .delay-panel.before{
             background:rgba(9,24,38,.96);
         }
         html[data-theme="dark"] .delay-panel.after{
             background:rgba(9,24,38,.96);
         }
         html[data-theme="dark"] .delay-metric span,
         html[data-theme="dark"] .delay-compact-metric label,
         html[data-theme="dark"] .delay-compact-meta span,
         html[data-theme="dark"] .delay-note{
             color:#90aeca;
         }
         html[data-theme="dark"] .delay-metric strong,
         html[data-theme="dark"] .delay-compact-metric strong{
             color:#8cc7ff;
         }
         html[data-theme="dark"] .delay-row-risk-high .delay-metric-delay{
             background:rgba(69,16,16,.35);
             border-color:rgba(248,113,113,.28);
         }
         html[data-theme="dark"] .delay-row-risk-high .delay-metric-delay strong{
             color:#fca5a5;
         }
         html[data-theme="dark"] .delay-bar{
             background:#163044;
         }
         html[data-theme="dark"] .delay-compact-meta span{
             background:rgba(12,31,49,.95);
             border-color:rgba(138,210,255,.10);
         }
         html[data-theme="dark"] .delay-tag{
             background:rgba(146,77,0,.18);
             color:#fdba74;
             border-color:rgba(253,186,116,.24);
         }
         html[data-theme="dark"] .delay-owner{
             background:rgba(29,78,216,.14);
             color:#93c5fd;
             border-color:rgba(147,197,253,.24);
         }
         html[data-theme="dark"] .delay-note{
             background:rgba(12,31,49,.92);
             border-color:rgba(138,210,255,.10);
         }
         html[data-theme="dark"] .delay-risk-badge{
             border:1px solid rgba(138,210,255,.12);
         }
         html[data-theme="dark"] .delay-risk-low{
             background:rgba(16,185,129,.15);
             color:#86efac;
         }
         html[data-theme="dark"] .delay-risk-medium{
             background:rgba(245,158,11,.16);
             color:#fcd34d;
         }
         html[data-theme="dark"] .delay-risk-high{
             background:rgba(239,68,68,.16);
             color:#fca5a5;
         }
         html[data-theme="dark"] .delay-action{
             box-shadow:0 6px 16px rgba(31,99,146,.18);
         }
         html[data-theme="dark"] .delay-action:hover{
             box-shadow:0 8px 20px rgba(31,99,146,.28);
         }
         </style>';
}

function delayInsightsRenderCompactList(mysqli|PgsqlMysqliCompatConnection $conn, array $user, array $options = []): void
{
    $title = trim((string) ($options['title'] ?? 'Project Insights'));
    $subtitle = trim((string) ($options['subtitle'] ?? 'Quick project delay signals and direct access to each project insight page.'));
    $buttonLabel = trim((string) ($options['button_label'] ?? 'Open Insights'));
    $limit = isset($options['limit']) ? max(1, (int) $options['limit']) : 3;
    $moreUrl = trim((string) ($options['more_url'] ?? ''));
    $moreLabel = trim((string) ($options['more_label'] ?? 'See More'));
    $showEmpty = !empty($options['show_empty']);
    $emptyTitle = trim((string) ($options['empty_title'] ?? 'No project insights yet'));
    $emptyMessage = trim((string) ($options['empty_message'] ?? 'Project insight signals will appear here when they become available.'));
    $insights = delayInsightsLoadInsights($conn, $user, $options);
    $riskLevelFilter = strtolower(trim((string) ($options['risk_level'] ?? '')));
    if ($riskLevelFilter !== '') {
        $insights = array_values(array_filter(
            $insights,
            static fn(array $insight): bool => strtolower((string) ($insight['before']['risk_level'] ?? '')) === $riskLevelFilter
        ));
    }

    if ($insights === [] && !$showEmpty) {
        return;
    }

    delayInsightsEnsureStyles();

    echo '<section class="delay-insights-wrap">';
    echo '<div class="delay-compact-card">';
    echo '<div class="delay-compact-head">';
    echo '<div class="delay-compact-head-copy">';
    echo '<h4><i class="fas fa-list-ul"></i> ' . htmlspecialchars($title) . '</h4>';
    echo '<p>' . htmlspecialchars($subtitle) . '</p>';
    echo '</div>';
    echo '<span class="delay-compact-count">' . count($insights) . ' project' . (count($insights) === 1 ? '' : 's') . '</span>';
    echo '</div>';
    echo '<div class="delay-compact-list">';

    if ($insights === []) {
        echo '<div class="delay-compact-empty">';
        echo '<i class="fas fa-chart-line" aria-hidden="true"></i>';
        echo '<strong>' . htmlspecialchars($emptyTitle) . '</strong>';
        echo '<span>' . htmlspecialchars($emptyMessage) . '</span>';
        echo '</div>';
    }

    foreach ($insights as $insight) {
        $riskLevel = (string) $insight['before']['risk_level'];
        $riskClass = $riskLevel === 'High' ? 'delay-risk-high' : ($riskLevel === 'Medium' ? 'delay-risk-medium' : 'delay-risk-low');
        $riskRowClass = $riskLevel === 'High' ? ' delay-row-risk-high' : '';
        $projectUrl = BASE_URL . 'modules/insights/decision-center.php?project_id=' . (int) $insight['project']['id'];

        echo '<article class="delay-compact-row' . $riskRowClass . '">';
        echo '<div class="delay-compact-main">';
        echo '<div class="delay-compact-top">';
        echo '<div class="delay-compact-title">';
        echo '<div class="delay-compact-title-row">';
        echo '<strong>' . htmlspecialchars($insight['project']['name']) . '</strong>';
        echo '<span class="delay-risk-badge ' . $riskClass . '"><i class="fas fa-signal"></i> ' . htmlspecialchars($riskLevel) . ' Risk</span>';
        echo '</div>';
        echo '</div>';
        echo '</div>';
        echo '</div>';
        echo '<div class="delay-compact-metric delay-metric-delay"><label>Delay Chance</label><strong>' . (int) $insight['before']['delay_probability'] . '%</strong></div>';
        echo '<div class="delay-compact-metric delay-metric-success"><label>Success Chance</label><strong>' . (int) $insight['before']['success_probability'] . '%</strong></div>';
        echo '<a class="delay-action delay-compact-action" href="' . htmlspecialchars($projectUrl) . '"><i class="fas fa-arrow-right"></i> ' . htmlspecialchars($buttonLabel) . '</a>';
        echo '</article>';
    }

    echo '</div>';
    if ($moreUrl !== '' && count($insights) >= $limit) {
        echo '<div class="delay-compact-footer">';
        echo '<a class="delay-action delay-compact-more-action" href="' . htmlspecialchars($moreUrl) . '"><span>' . htmlspecialchars($moreLabel) . '</span><i class="fas fa-arrow-right"></i></a>';
        echo '</div>';
    }
    echo '</div>';
    echo '</section>';
}

function delayInsightsRenderDetailCards(mysqli|PgsqlMysqliCompatConnection $conn, array $user, array $options = []): void
{
    $title = trim((string) ($options['title'] ?? 'Insight Notifications'));
    $subtitle = trim((string) ($options['subtitle'] ?? 'See the likely delay percentage before delivery, success outlook, tips to reduce risk, and the current reasons or owners behind schedule slippage.'));
    $showActionButton = isset($options['show_action_button']) ? (bool) $options['show_action_button'] : false;
    $buttonLabel = trim((string) ($options['button_label'] ?? 'Open Full Insights'));
    $customActionUrl = trim((string) ($options['action_url'] ?? ''));
    $insights = delayInsightsLoadInsights($conn, $user, $options);

    if ($insights === []) {
        return;
    }

    delayInsightsEnsureStyles();

    echo '<section class="delay-insights-wrap">';
    echo '<div class="delay-insights-card">';
    echo '<div class="delay-insights-head">';
    echo '<h4><i class="fas fa-bell"></i> ' . htmlspecialchars($title) . '</h4>';
    echo '<p>' . htmlspecialchars($subtitle) . '</p>';
    echo '</div>';

    foreach ($insights as $insight) {
        $problemAction = delayInsightsProblemAction($insight, $user);
        $riskClass = $insight['before']['risk_level'] === 'High' ? 'delay-risk-high' : ($insight['before']['risk_level'] === 'Medium' ? 'delay-risk-medium' : 'delay-risk-low');
        $projectUrl = $customActionUrl !== ''
            ? $customActionUrl
            : (BASE_URL . 'modules/insights/decision-center.php?project_id=' . (int) $insight['project']['id']);

        echo '<div class="delay-insight-item">';
        echo '<div class="delay-insight-project">';
        echo '<div>';
        echo '<h5>' . htmlspecialchars($insight['project']['name']) . '</h5>';
        echo '<p>Client: ' . htmlspecialchars($insight['project']['client_name']) . ' | Status: ' . htmlspecialchars($insight['project']['status_label']) . ' | Timeline: ' . htmlspecialchars($insight['project']['timeline_label']) . '</p>';
        echo '</div>';
        echo '<div class="delay-insight-project-right">';
        echo '<span class="delay-risk-badge ' . $riskClass . '"><i class="fas fa-signal"></i> ' . htmlspecialchars($insight['before']['risk_level']) . ' Risk</span>';
        if ($showActionButton) {
            echo '<a class="delay-action" href="' . htmlspecialchars($projectUrl) . '"><i class="fas fa-arrow-right"></i> ' . htmlspecialchars($buttonLabel) . '</a>';
        }
        echo '</div>';
        echo '</div>';

        echo '<div class="delay-panels">';
        echo '<div class="delay-panel before">';
        echo '<div class="delay-panel-title"><i class="fas fa-drafting-compass"></i> Before Project Insight</div>';
        echo '<div class="delay-metric-grid">';
        echo '<div class="delay-metric"><span>Delay Chance</span><strong>' . (int) $insight['before']['delay_probability'] . '%</strong></div>';
        echo '<div class="delay-metric"><span>Success Chance</span><strong>' . (int) $insight['before']['success_probability'] . '%</strong></div>';
        echo '</div>';
        echo '<div class="delay-bar delay"><span style="width:' . (int) $insight['before']['delay_probability'] . '%"></span></div>';
        echo '<div class="delay-bar success"><span style="width:' . (int) $insight['before']['success_probability'] . '%"></span></div>';
        echo '<p class="delay-copy">' . htmlspecialchars($insight['before']['summary']) . '</p>';
        echo '<ul class="delay-list">';
        foreach ($insight['before']['tips'] as $tip) {
            echo '<li>' . htmlspecialchars($tip) . '</li>';
        }
        echo '</ul>';
        echo '</div>';

        echo '<div class="delay-panel after">';
        echo '<div class="delay-panel-title"><i class="fas fa-clipboard-list"></i> After Project Review</div>';
        echo '<p class="delay-copy">' . htmlspecialchars($insight['after']['summary']) . '</p>';
        if ($insight['after']['reasons'] !== []) {
            echo '<div class="delay-tags">';
            foreach ($insight['after']['reasons'] as $reason) {
                echo '<span class="delay-tag">' . htmlspecialchars($reason) . '</span>';
            }
            echo '</div>';
        }
        if ($insight['after']['owners'] !== []) {
            echo '<div class="delay-owners">';
            foreach ($insight['after']['owners'] as $owner) {
                echo '<span class="delay-owner">' . htmlspecialchars($owner) . '</span>';
            }
            echo '</div>';
        }
        echo '<div class="delay-note"><i class="fas fa-info-circle"></i> ' . htmlspecialchars($insight['after']['note']) . '</div>';
        if ($problemAction !== null) {
            echo '<a class="delay-action delay-fix-action" href="' . htmlspecialchars((string) $problemAction['url']) . '"><i class="fas fa-location-arrow"></i> ' . htmlspecialchars((string) $problemAction['label']) . '</a>';
        }
        echo '</div>';
        echo '</div>';
        echo '</div>';
    }

    echo '</div>';
    echo '</section>';
}

function delayInsightsResolveProjectIds(mysqli|PgsqlMysqliCompatConnection $conn, array $user, ?int $projectId, int $limit): array
{
    if ($projectId !== null && $projectId > 0) {
        return [$projectId];
    }

    $userId = (int) ($user['id'] ?? 0);
    $role = (string) ($user['role'] ?? '');
    $projectIds = [];

    switch ($role) {
        case 'architect':
            $sql = "
                SELECT p.id
                FROM projects p
                WHERE p.status != 'cancelled'
                  AND (p.architect_id = {$userId} OR p.architect_id IS NULL)
                ORDER BY
                    CASE WHEN p.status = 'completed' THEN 1 ELSE 0 END,
                    CASE WHEN p.timeline_end IS NOT NULL AND p.timeline_end < CURDATE() AND p.status != 'completed' THEN 0 ELSE 1 END,
                    CASE WHEN p.timeline_end IS NULL THEN 1 ELSE 0 END,
                    p.timeline_end ASC,
                    p.updated_at DESC
                LIMIT {$limit}
            ";
            break;
        case 'sul':
            $sql = "
                SELECT p.id
                FROM projects p
                WHERE p.status != 'cancelled'
                  AND (p.sul_id = {$userId} OR p.sul_id IS NULL OR p.status IN ('in_progress','review','in_development'))
                ORDER BY
                    CASE WHEN p.status = 'completed' THEN 1 ELSE 0 END,
                    CASE WHEN p.timeline_end IS NOT NULL AND p.timeline_end < CURDATE() AND p.status != 'completed' THEN 0 ELSE 1 END,
                    CASE WHEN p.timeline_end IS NULL THEN 1 ELSE 0 END,
                    p.timeline_end ASC,
                    p.updated_at DESC
                LIMIT {$limit}
            ";
            break;
        case 'expert':
            $sql = "
                SELECT p.id
                FROM projects p
                LEFT JOIN tasks t ON t.project_id = p.id
                LEFT JOIN feature_estimations fe ON fe.project_id = p.id
                WHERE p.status != 'cancelled'
                  AND (t.assigned_to = {$userId} OR fe.expert_id = {$userId})
                GROUP BY p.id, p.status, p.timeline_end, p.updated_at
                ORDER BY
                    CASE WHEN p.status = 'completed' THEN 1 ELSE 0 END,
                    CASE WHEN p.timeline_end IS NOT NULL AND p.timeline_end < CURDATE() AND p.status != 'completed' THEN 0 ELSE 1 END,
                    CASE WHEN p.timeline_end IS NULL THEN 1 ELSE 0 END,
                    p.timeline_end ASC,
                    p.updated_at DESC
                LIMIT {$limit}
            ";
            break;
        case 'developer':
            $sql = "
                SELECT p.id
                FROM projects p
                INNER JOIN tasks t ON t.project_id = p.id
                WHERE p.status != 'cancelled'
                  AND t.assigned_to = {$userId}
                GROUP BY p.id, p.status, p.timeline_end, p.updated_at
                ORDER BY
                    CASE WHEN p.status = 'completed' THEN 1 ELSE 0 END,
                    CASE WHEN p.timeline_end IS NOT NULL AND p.timeline_end < CURDATE() AND p.status != 'completed' THEN 0 ELSE 1 END,
                    CASE WHEN p.timeline_end IS NULL THEN 1 ELSE 0 END,
                    p.timeline_end ASC,
                    p.updated_at DESC
                LIMIT {$limit}
            ";
            break;
        case 'admin':
        case 'sales':
        default:
            $sql = "
                SELECT p.id
                FROM projects p
                WHERE p.status != 'cancelled'
                ORDER BY
                    CASE WHEN p.status = 'completed' THEN 1 ELSE 0 END,
                    CASE WHEN p.timeline_end IS NOT NULL AND p.timeline_end < CURDATE() AND p.status != 'completed' THEN 0 ELSE 1 END,
                    CASE WHEN p.timeline_end IS NULL THEN 1 ELSE 0 END,
                    p.timeline_end ASC,
                    p.updated_at DESC
                LIMIT {$limit}
            ";
            break;
    }

    $result = $conn->query($sql);
    if (!is_object($result) || !method_exists($result, 'fetch_assoc')) {
        return [];
    }

    while ($row = $result->fetch_assoc()) {
        $id = (int) ($row['id'] ?? 0);
        if ($id > 0) {
            $projectIds[] = $id;
        }
    }

    return array_values(array_unique($projectIds));
}

function delayInsightsBuildProjectInsight(mysqli|PgsqlMysqliCompatConnection $conn, int $projectId): ?array
{
    $projectId = (int) $projectId;
    if ($projectId <= 0) {
        return null;
    }

    $projectSql = "
        SELECT
            p.id,
            p.client_id,
            p.name,
            p.status,
            p.timeline_start,
            p.timeline_end,
            p.complexity_level,
            p.created_at,
            c.name AS client_name,
            architect.full_name AS architect_name,
            sul.full_name AS sul_name,
            creator.full_name AS created_by_name,
            (SELECT COUNT(*) FROM tasks t WHERE t.project_id = p.id) AS total_tasks,
            (SELECT COUNT(*) FROM tasks t WHERE t.project_id = p.id AND t.task_type = 'feature') AS feature_count,
            (SELECT COUNT(*) FROM tasks t WHERE t.project_id = p.id AND t.status = 'completed') AS completed_tasks,
            (SELECT COUNT(*) FROM tasks t WHERE t.project_id = p.id AND t.status IN ('blocked', 'overdue')) AS blocked_tasks,
            (SELECT COUNT(*) FROM tasks t WHERE t.project_id = p.id AND t.status IN ('in_review', 'review')) AS review_tasks,
            (SELECT COUNT(*) FROM tasks t WHERE t.project_id = p.id AND (t.assigned_to IS NULL OR t.assigned_to = 0)) AS unassigned_tasks,
            (SELECT COUNT(*) FROM tasks t WHERE t.project_id = p.id AND t.deadline IS NULL) AS tasks_without_deadline,
            (SELECT COUNT(*) FROM tasks t WHERE t.project_id = p.id AND t.deadline IS NOT NULL AND t.status != 'completed' AND t.deadline < CURDATE()) AS overdue_tasks,
            (SELECT COUNT(*) FROM tasks t WHERE t.project_id = p.id AND t.deadline IS NOT NULL AND t.completed_at IS NOT NULL AND CAST(t.completed_at AS DATE) > t.deadline) AS late_completed_tasks,
            (SELECT COALESCE(SUM(COALESCE(t.sales_estimate_days, t.estimated_days_likely, t.estimated_days_max, t.estimated_days_min, 0)), 0) FROM tasks t WHERE t.project_id = p.id) AS estimated_task_days,
            (SELECT COUNT(*) FROM tech_recommendations tr WHERE tr.project_id = p.id) AS tech_count,
            (SELECT COUNT(*) FROM developer_allocations da WHERE da.project_id = p.id) AS allocation_count,
            (SELECT COALESCE(SUM(da.required_count), 0) FROM developer_allocations da WHERE da.project_id = p.id) AS total_resources,
            (SELECT COUNT(*) FROM architecture_documents ad WHERE ad.project_id = p.id) AS document_count
        FROM projects p
        INNER JOIN clients c ON c.id = p.client_id
        LEFT JOIN users architect ON architect.id = p.architect_id
        LEFT JOIN users sul ON sul.id = p.sul_id
        LEFT JOIN users creator ON creator.id = p.created_by
        WHERE p.id = {$projectId}
        LIMIT 1
    ";

    $projectResult = $conn->query($projectSql);
    if (!is_object($projectResult) || !method_exists($projectResult, 'fetch_assoc')) {
        return null;
    }

    $project = $projectResult->fetch_assoc();
    if (!$project) {
        return null;
    }

    $issueSql = "
        SELECT
            t.title,
            t.status,
            t.deadline,
            t.completed_at,
            u.full_name,
            u.role,
            CASE
                WHEN t.status != 'completed' AND t.deadline IS NOT NULL AND t.deadline < CURDATE() THEN DATEDIFF(CURDATE(), t.deadline)
                WHEN t.status = 'completed' AND t.deadline IS NOT NULL AND t.completed_at IS NOT NULL AND CAST(t.completed_at AS DATE) > t.deadline THEN DATEDIFF(CAST(t.completed_at AS DATE), t.deadline)
                ELSE 0
            END AS slip_days
        FROM tasks t
        LEFT JOIN users u ON u.id = t.assigned_to
        WHERE t.project_id = {$projectId}
          AND (
              (t.status != 'completed' AND t.deadline IS NOT NULL AND t.deadline < CURDATE())
              OR t.status IN ('blocked', 'overdue', 'in_review', 'review')
              OR (t.assigned_to IS NULL OR t.assigned_to = 0)
              OR (t.status = 'completed' AND t.deadline IS NOT NULL AND t.completed_at IS NOT NULL AND CAST(t.completed_at AS DATE) > t.deadline)
          )
        ORDER BY slip_days DESC, t.deadline ASC, t.title ASC
        LIMIT 25
    ";

    $issueRows = [];
    $issueResult = $conn->query($issueSql);
    if (is_object($issueResult) && method_exists($issueResult, 'fetch_assoc')) {
        while ($row = $issueResult->fetch_assoc()) {
            $issueRows[] = $row;
        }
    }

    $before = delayInsightsBuildBeforeMetrics($project);
    $after = delayInsightsBuildAfterMetrics($project, $issueRows);

    return [
        'project' => [
            'id' => (int) $project['id'],
            'client_id' => (int) ($project['client_id'] ?? 0),
            'name' => (string) $project['name'],
            'client_name' => (string) $project['client_name'],
            'status_label' => ucwords(str_replace('_', ' ', (string) $project['status'])),
            'timeline_label' => delayInsightsTimelineLabel((string) ($project['timeline_start'] ?? ''), (string) ($project['timeline_end'] ?? '')),
            'issue_counts' => [
                'feature_count' => (int) ($project['feature_count'] ?? 0),
                'tech_count' => (int) ($project['tech_count'] ?? 0),
                'allocation_count' => (int) ($project['allocation_count'] ?? 0),
                'total_resources' => (int) ($project['total_resources'] ?? 0),
                'document_count' => (int) ($project['document_count'] ?? 0),
                'tasks_without_deadline' => (int) ($project['tasks_without_deadline'] ?? 0),
                'unassigned_tasks' => (int) ($project['unassigned_tasks'] ?? 0),
                'overdue_tasks' => (int) ($project['overdue_tasks'] ?? 0),
                'blocked_tasks' => (int) ($project['blocked_tasks'] ?? 0),
            ],
        ],
        'before' => $before,
        'after' => $after,
    ];
}

function delayInsightsBuildBeforeMetrics(array $project): array
{
    $featureCount = (int) ($project['feature_count'] ?? 0);
    $techCount = (int) ($project['tech_count'] ?? 0);
    $allocationCount = (int) ($project['allocation_count'] ?? 0);
    $totalResources = (int) ($project['total_resources'] ?? 0);
    $documentCount = (int) ($project['document_count'] ?? 0);
    $tasksWithoutDeadline = (int) ($project['tasks_without_deadline'] ?? 0);
    $overdueTasks = (int) ($project['overdue_tasks'] ?? 0);
    $blockedTasks = (int) ($project['blocked_tasks'] ?? 0);
    $unassignedTasks = (int) ($project['unassigned_tasks'] ?? 0);
    $totalTasks = (int) ($project['total_tasks'] ?? 0);
    $estimatedTaskDays = (int) round((float) ($project['estimated_task_days'] ?? 0));
    $scheduleDays = delayInsightsScheduleDays((string) ($project['timeline_start'] ?? ''), (string) ($project['timeline_end'] ?? ''));

    $checkpoints = 5;
    $readyCount = 0;
    $readyCount += $scheduleDays !== null ? 1 : 0;
    $readyCount += $featureCount > 0 ? 1 : 0;
    $readyCount += $techCount > 0 ? 1 : 0;
    $readyCount += ($allocationCount > 0 && $totalResources > 0) ? 1 : 0;
    $readyCount += $documentCount > 0 ? 1 : 0;
    $planningReadiness = (int) round(($readyCount / $checkpoints) * 100);

    $complexity = (string) ($project['complexity_level'] ?? 'medium');
    $complexityRiskMap = [
        'low' => 4,
        'simple' => 4,
        'medium' => 10,
        'high' => 18,
        'complex' => 18,
    ];

    $delayProbability = 8 + ($complexityRiskMap[$complexity] ?? 10);

    if ($scheduleDays === null) {
        $delayProbability += 18;
    }
    if ($featureCount === 0) {
        $delayProbability += 16;
    }
    if ($techCount === 0) {
        $delayProbability += 12;
    }
    if ($allocationCount === 0 || $totalResources === 0) {
        $delayProbability += 16;
    }
    if ($documentCount === 0) {
        $delayProbability += 12;
    }
    if ($tasksWithoutDeadline > 0) {
        $delayProbability += min(10, $tasksWithoutDeadline * 2);
    }
    if ($overdueTasks > 0) {
        $delayProbability += min(35, 15 + ($overdueTasks * 8));
    }
    if ($blockedTasks > 0) {
        $delayProbability += min(24, $blockedTasks * 12);
    }
    if ($unassignedTasks > 0) {
        $delayProbability += min(18, $unassignedTasks * 6);
    }

    $status = strtolower(trim((string) ($project['status'] ?? '')));
    $timelineEnd = trim((string) ($project['timeline_end'] ?? ''));
    $timelineEndTs = $timelineEnd !== '' ? strtotime($timelineEnd) : false;
    $todayTs = strtotime(date('Y-m-d'));
    $isProjectOverdue = !in_array($status, ['completed', 'cancelled'], true)
        && $timelineEndTs !== false
        && $todayTs > $timelineEndTs;
    if ($isProjectOverdue) {
        $overdueDays = max(1, (int) floor(($todayTs - $timelineEndTs) / 86400));
        $delayProbability += min(45, 25 + $overdueDays);
    }

    $requiredPeople = null;
    $resourceGap = 0;
    $extraDaysNeeded = 0;
    if ($scheduleDays !== null && $scheduleDays > 0 && $estimatedTaskDays > 0) {
        $requiredPeople = (int) ceil($estimatedTaskDays / $scheduleDays);
        $resourceGap = max(0, $requiredPeople - max(1, $totalResources));

        if ($totalResources > 0) {
            $loadPerPerson = (float) $estimatedTaskDays / $totalResources;
            if ($loadPerPerson > $scheduleDays) {
                $extraDaysNeeded = (int) ceil($loadPerPerson - $scheduleDays);
                $delayProbability += min(28, 10 + (int) round((($loadPerPerson - $scheduleDays) / max(1, $scheduleDays)) * 26));
            } elseif (($scheduleDays - $loadPerPerson) <= 5) {
                $delayProbability += 8;
            } elseif (($scheduleDays - $loadPerPerson) <= 10) {
                $delayProbability += 4;
            }
        } else {
            $delayProbability += 18;
            $extraDaysNeeded = $estimatedTaskDays;
        }
    }

    if ($totalTasks > 0 && $totalResources > 0 && ($totalTasks / $totalResources) > 8) {
        $delayProbability += 8;
    }

    $delayProbability = max(5, min(95, $delayProbability));
    if ($isProjectOverdue) {
        $delayProbability = max(75, $delayProbability);
    } elseif ($overdueTasks > 0) {
        $delayProbability = max(55, $delayProbability);
    }
    $successProbability = max(5, min(95, 100 - $delayProbability + (int) round(($planningReadiness - 50) * 0.12)));

    $riskLevel = $delayProbability >= 70 ? 'High' : ($delayProbability >= 40 ? 'Medium' : 'Low');

    if ($riskLevel === 'High') {
        $summary = 'Delivery risk is high. Revalidate the scope, close planning gaps, and add a realistic schedule buffer before handoff.';
    } elseif ($riskLevel === 'Medium') {
        $summary = 'Delivery risk is moderate. Confirm capacity, dependencies, and milestone dates to improve schedule confidence.';
    } else {
        $summary = 'Delivery outlook is healthy. Maintain clear ownership, milestone reviews, and timely documentation to keep the project on track.';
    }

    $tips = [];
    if ($featureCount === 0) {
        $tips[] = 'Break the requested scope into clear feature tasks so the team can estimate and assign work earlier.';
    }
    if ($scheduleDays === null) {
        $tips[] = 'Set both project start and end dates before submission so the team can compare capacity against the promised timeline.';
    }
    if ($techCount === 0) {
        $tips[] = 'Finalize the technology stack now to avoid late changes in infrastructure, licensing, or integration work.';
    }
    if ($allocationCount === 0 || $totalResources === 0) {
        $tips[] = 'Complete the developer allocation plan so SUL can assign the right people without creating a staffing gap.';
    }
    if ($documentCount === 0) {
        $tips[] = 'Upload architecture diagrams and workflow documents before handoff to reduce clarification delays during development.';
    }
    if ($tasksWithoutDeadline > 0) {
        $tips[] = $tasksWithoutDeadline . ' task(s) still have no deadline. Add target dates so slippage becomes visible early.';
    }
    if ($resourceGap > 0) {
        $tips[] = 'Current load needs about ' . $resourceGap . ' more person(s) or roughly ' . max(1, $extraDaysNeeded) . ' extra day(s) to keep the plan realistic.';
    } elseif ($extraDaysNeeded > 0) {
        $tips[] = 'The current timeline is tighter than the estimated workload. Add at least ' . $extraDaysNeeded . ' buffer day(s) or rebalance scope.';
    }
    if ($complexity === 'complex') {
        $tips[] = 'For a complex project, add milestone reviews and design checkpoints so issues are caught before they become schedule blockers.';
    }
    if ($tips === []) {
        $tips[] = 'Keep weekly design and delivery checkpoints so small risks are corrected before they become deadline issues.';
    }

    return [
        'delay_probability' => $delayProbability,
        'success_probability' => $successProbability,
        'risk_level' => $riskLevel,
        'summary' => $summary,
        'tips' => array_slice($tips, 0, 4),
    ];
}

function delayInsightsBuildAfterMetrics(array $project, array $issueRows): array
{
    $status = (string) ($project['status'] ?? '');
    $timelineEnd = (string) ($project['timeline_end'] ?? '');
    $overdueTasks = (int) ($project['overdue_tasks'] ?? 0);
    $blockedTasks = (int) ($project['blocked_tasks'] ?? 0);
    $reviewTasks = (int) ($project['review_tasks'] ?? 0);
    $unassignedTasks = (int) ($project['unassigned_tasks'] ?? 0);
    $lateCompletedTasks = (int) ($project['late_completed_tasks'] ?? 0);

    $today = strtotime(date('Y-m-d'));
    $endTs = $timelineEnd !== '' ? strtotime($timelineEnd) : false;
    $timelineSlipDays = ($endTs !== false && $today > $endTs && $status !== 'completed') ? (int) floor(($today - $endTs) / 86400) : 0;

    $reasons = [];
    if ($timelineSlipDays > 0) {
        $reasons[] = 'Project is already ' . $timelineSlipDays . ' day(s) past the planned end date.';
    }
    if ($overdueTasks > 0) {
        $reasons[] = $overdueTasks . ' active task(s) are already past their deadlines.';
    }
    if ($blockedTasks > 0) {
        $reasons[] = $blockedTasks . ' task(s) are blocked or marked overdue.';
    }
    if ($reviewTasks > 0) {
        $reasons[] = $reviewTasks . ' task(s) are waiting for review or approval.';
    }
    if ($unassignedTasks > 0) {
        $reasons[] = $unassignedTasks . ' task(s) still do not have an owner.';
    }
    if ($lateCompletedTasks > 0) {
        $reasons[] = $lateCompletedTasks . ' completed task(s) already finished later than their deadline.';
    }

    $ownerCounts = [];
    foreach ($issueRows as $row) {
        $name = trim((string) ($row['full_name'] ?? ''));
        $role = trim((string) ($row['role'] ?? ''));
        if ($name === '') {
            continue;
        }

        $key = strtolower($name . '|' . $role);
        if (!isset($ownerCounts[$key])) {
            $ownerCounts[$key] = [
                'label' => $name . ($role !== '' ? ' (' . getRoleDisplayName($role) . ')' : ''),
                'count' => 0,
            ];
        }
        $ownerCounts[$key]['count']++;
    }

    if ($unassignedTasks > 0) {
        $fallbackOwnerName = trim((string) ($project['sul_name'] ?? '')) !== ''
            ? trim((string) $project['sul_name']) . ' (Service Unit Leader)'
            : (trim((string) ($project['architect_name'] ?? '')) !== ''
                ? trim((string) $project['architect_name']) . ' (Architect)'
                : trim((string) ($project['created_by_name'] ?? '')) . ' (Project Owner)');

        if ($fallbackOwnerName !== ' (Project Owner)' && $fallbackOwnerName !== '') {
            $key = strtolower($fallbackOwnerName);
            if (!isset($ownerCounts[$key])) {
                $ownerCounts[$key] = ['label' => $fallbackOwnerName, 'count' => 0];
            }
            $ownerCounts[$key]['count'] += $unassignedTasks;
        }
    }

    usort($ownerCounts, static function (array $left, array $right): int {
        return $right['count'] <=> $left['count'];
    });

    $owners = [];
    foreach (array_slice($ownerCounts, 0, 4) as $owner) {
        $owners[] = $owner['label'] . ' - ' . $owner['count'] . ' issue(s)';
    }

    if ($timelineSlipDays > 0) {
        $summary = 'The project is currently delayed. The main causes below show where the schedule is slipping and who should be checked first.';
    } elseif ($reasons !== []) {
        $summary = 'The project is not fully delayed yet, but these signals already explain where delay can happen or has already happened inside the delivery work.';
    } else {
        $summary = 'No strong delay signal is visible from the current task data. Keep owners and reviews active so the project stays on schedule.';
    }

    if ($status === 'completed' && $lateCompletedTasks > 0) {
        $note = 'Historical task deadlines show where lateness happened, even though the project is already marked completed.';
    } elseif ($status === 'completed') {
        $note = 'The project is completed and current task history does not show an active delay pattern.';
    } elseif ($timelineSlipDays > 0) {
        $note = 'Prioritize the overdue, blocked, and unassigned work first because those are the fastest ways to cut further delay.';
    } else {
        $note = 'Use these signals as an early warning so owners can act before the final project deadline is missed.';
    }

    return [
        'summary' => $summary,
        'reasons' => array_slice($reasons, 0, 5),
        'owners' => $owners,
        'note' => $note,
    ];
}

function delayInsightsScheduleDays(string $timelineStart, string $timelineEnd): ?int
{
    if ($timelineStart === '' || $timelineEnd === '') {
        return null;
    }

    $startTs = strtotime($timelineStart);
    $endTs = strtotime($timelineEnd);
    if ($startTs === false || $endTs === false || $endTs < $startTs) {
        return null;
    }

    return (int) floor(($endTs - $startTs) / 86400) + 1;
}

function delayInsightsTimelineLabel(string $timelineStart, string $timelineEnd): string
{
    if ($timelineStart === '' || $timelineEnd === '') {
        return 'Timeline not set';
    }

    $startTs = strtotime($timelineStart);
    $endTs = strtotime($timelineEnd);
    if ($startTs === false || $endTs === false) {
        return 'Timeline not set';
    }

    return date('M d, Y', $startTs) . ' - ' . date('M d, Y', $endTs);
}
