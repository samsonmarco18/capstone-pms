<?php
$architectReviewProjectId = (int) ($architectReviewProjectId ?? $projectId ?? $selectedProjectId ?? ($_GET['id'] ?? $_GET['project_id'] ?? 0));
$architectReviewProject = null;
$architectReviewPanelExpanded = isset($architectReviewPanelExpanded)
    ? (bool) $architectReviewPanelExpanded
    : basename((string) ($_SERVER['PHP_SELF'] ?? '')) === 'project-review.php';
$architectReviewPanelBodyId = 'architectReviewPanelBody' . max(0, $architectReviewProjectId);

if ($architectReviewProjectId > 0) {
    $architectReviewResult = $conn->query("
        SELECT p.*, c.name AS client_name, c.contact_person, c.email AS client_email, c.phone AS client_phone, c.address AS client_address, c.industry AS client_industry
        FROM projects p
        INNER JOIN clients c ON c.id = p.client_id
        WHERE p.id = {$architectReviewProjectId}
        LIMIT 1
    ");
    $architectReviewProject = $architectReviewResult ? $architectReviewResult->fetch_assoc() : null;
}

if ($architectReviewProject):
    $reviewRequirementsText = trim((string) ($architectReviewProject['requested_features'] ?? ''));
    $reviewAdditionalText = '';
    if (preg_match('/Additional Requirements:\s*(.*?)(?=\R+\s*Budget\s*:|\z)/is', $reviewRequirementsText, $reviewAdditionalMatches)) {
        $reviewAdditionalText = trim((string) ($reviewAdditionalMatches[1] ?? ''));
    }
    $reviewFeatures = [];
    $reviewFeatureSeen = [];
    foreach ((preg_split('/\R+/', $reviewRequirementsText) ?: []) as $reviewFeatureLine) {
        $reviewFeatureLine = trim((string) $reviewFeatureLine);
        if ($reviewFeatureLine === '') {
            continue;
        }
        if (preg_match('/^(additional requirements|budget)\s*:/i', $reviewFeatureLine)) {
            break;
        }
        $reviewFeatureLine = trim((string) preg_replace('/^\s*(?:[-*]|\d+[\.)])\s*/', '', $reviewFeatureLine));
        if ($reviewFeatureLine === '') {
            continue;
        }
        $reviewFeatureKey = strtolower((string) preg_replace('/\s+/', ' ', $reviewFeatureLine));
        if (isset($reviewFeatureSeen[$reviewFeatureKey])) {
            continue;
        }
        $reviewFeatureSeen[$reviewFeatureKey] = true;
        $reviewFeatures[] = $reviewFeatureLine;
    }
    $reviewBudgetAmount = null;
    if (isset($architectReviewProject['sales_budget']) && is_numeric($architectReviewProject['sales_budget']) && (float) $architectReviewProject['sales_budget'] > 0) {
        $reviewBudgetAmount = (float) $architectReviewProject['sales_budget'];
    } elseif (preg_match('/(?:^|\R)Budget:\s*(?:\R\s*)?([^\r\n]+)/i', $reviewRequirementsText, $reviewBudgetMatches)) {
        $reviewBudgetRaw = preg_replace('/[^0-9.]/', '', (string) ($reviewBudgetMatches[1] ?? ''));
        if (is_numeric($reviewBudgetRaw) && (float) $reviewBudgetRaw > 0) {
            $reviewBudgetAmount = (float) $reviewBudgetRaw;
        }
    }
    $reviewTimelineStart = !empty($architectReviewProject['timeline_start']) ? strtotime((string) $architectReviewProject['timeline_start']) : false;
    $reviewTimelineEnd = !empty($architectReviewProject['timeline_end']) ? strtotime((string) $architectReviewProject['timeline_end']) : false;
    if ($reviewTimelineStart && $reviewTimelineEnd) {
        $reviewTimelineLabel = date('M d, Y', $reviewTimelineStart) . ' - ' . date('M d, Y', $reviewTimelineEnd);
    } elseif ($reviewTimelineStart) {
        $reviewTimelineLabel = 'Starts ' . date('M d, Y', $reviewTimelineStart);
    } elseif ($reviewTimelineEnd) {
        $reviewTimelineLabel = 'Due ' . date('M d, Y', $reviewTimelineEnd);
    } else {
        $reviewTimelineLabel = 'Not set';
    }
    $reviewAttachments = [];
    if (!empty($architectReviewProject['sales_attachments'])) {
        $decodedReviewAttachments = json_decode((string) $architectReviewProject['sales_attachments'], true);
        $reviewAttachments = is_array($decodedReviewAttachments) ? $decodedReviewAttachments : [];
    }
?>
<style>
.architect-review-panel {
    background: #ffffff;
    border: 1px solid #dce5ee;
    border-radius: 14px;
    box-shadow: 0 4px 14px rgba(30, 64, 95, 0.07);
    margin-bottom: 1.5rem;
    overflow: hidden;
}

.architect-review-panel__head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    min-height: 60px;
    padding: 0.65rem 1rem;
    background: #ffffff;
}

.architect-review-panel:not(.is-collapsed) .architect-review-panel__head {
    border-bottom: 1px solid #e5edf4;
}

.architect-review-panel__intro {
    align-items: center;
    display: flex;
    gap: 0.8rem;
    min-width: 0;
}

.architect-review-panel__icon {
    align-items: center;
    background: #eaf4fb;
    border-radius: 10px;
    color: var(--theme-primary, #1f6392);
    display: inline-flex;
    flex: 0 0 auto;
    height: 34px;
    justify-content: center;
    width: 34px;
}

.architect-review-panel__head h5 {
    margin: 0;
    color: var(--gray-900, #212529);
    font-size: 0.95rem;
    font-weight: 600;
}

.architect-review-panel__head p {
    color: #708399;
    font-size: 0.76rem;
    margin: 0.2rem 0 0;
}

.architect-review-panel__toggle {
    align-items: center;
    background: #ffffff;
    border: 1px solid #c8d8e6;
    border-radius: 9px;
    color: var(--theme-primary, #1f6392);
    cursor: pointer;
    display: inline-flex;
    flex: 0 0 auto;
    font-size: 0.82rem;
    font-weight: 600;
    gap: 0.5rem;
    min-height: 36px;
    padding: 0.45rem 0.75rem;
    transition: background-color 0.18s ease, border-color 0.18s ease, box-shadow 0.18s ease;
}

.architect-review-panel__toggle:hover,
.architect-review-panel__toggle:focus-visible {
    background: #eef7fc;
    border-color: var(--theme-primary, #1f6392);
    box-shadow: 0 0 0 3px rgba(31, 99, 146, 0.1);
    outline: 0;
}

.architect-review-panel__toggle i {
    transition: transform 0.18s ease;
}

.architect-review-panel:not(.is-collapsed) .architect-review-panel__toggle i {
    transform: rotate(180deg);
}

.architect-review-panel.is-collapsed .architect-review-panel__body {
    display: none;
}

.architect-review-panel__body {
    display: block;
    padding: 0;
    background: #ffffff;
}

.architect-review-panel__body > :last-child {
    border-bottom: 0;
}

.architect-review-panel__overview {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
}

.architect-review-panel__item {
    align-items: center;
    display: grid;
    gap: 0.75rem;
    grid-template-columns: minmax(90px, 0.34fr) minmax(0, 1fr);
    min-height: 52px;
    padding: 0.75rem 1rem;
    border-bottom: 1px solid #e5edf4;
}

.architect-review-panel__item:nth-child(odd) {
    border-right: 1px solid #e5edf4;
}

.architect-review-panel__label,
.architect-review-panel__section-title {
    display: block;
    color: #718399;
    font-size: 0.67rem;
    font-weight: 600;
    letter-spacing: 0.035em;
    text-transform: uppercase;
}

.architect-review-panel__value {
    color: #1e344a;
    font-size: 0.82rem;
    font-weight: 500;
    overflow-wrap: anywhere;
}

.architect-review-panel__row {
    align-items: start;
    display: grid;
    gap: 1rem;
    grid-template-columns: 150px minmax(0, 1fr);
    padding: 0.85rem 1rem;
    border-bottom: 1px solid #e5edf4;
}

.architect-review-panel__row .architect-review-panel__value {
    line-height: 1.55;
    white-space: pre-wrap;
}

.architect-review-panel__section {
    padding: 0.9rem 1rem 0.75rem;
    border-bottom: 1px solid #e5edf4;
}

.architect-review-panel__section-head {
    align-items: center;
    display: flex;
    gap: 0.75rem;
    justify-content: space-between;
    margin-bottom: 0.45rem;
}

.architect-review-panel__section-count {
    color: #718399;
    font-size: 0.7rem;
    font-weight: 500;
}

.architect-review-panel__features {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    margin: 0;
}

.architect-review-panel__feature {
    align-items: center;
    color: #1e344a;
    display: flex;
    font-size: 0.8rem;
    gap: 0.5rem;
    min-width: 0;
    padding: 0.55rem 0.65rem 0.55rem 0;
    border-bottom: 1px solid #edf2f6;
}

.architect-review-panel__feature i {
    color: var(--theme-primary, #1f6392);
    flex: 0 0 auto;
    font-size: 0.68rem;
}

.architect-review-panel__context-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    border-bottom: 1px solid #e5edf4;
}

.architect-review-panel__context-item {
    min-width: 0;
    padding: 0.85rem 1rem;
    border-right: 1px solid #e5edf4;
}

.architect-review-panel__context-item:last-child {
    border-right: 0;
}

.architect-review-panel__context-item .architect-review-panel__value {
    line-height: 1.5;
    margin-top: 0.35rem;
    white-space: pre-wrap;
}

.architect-review-panel__attachment {
    align-items: center;
    background: #eef7fc;
    border: 1px solid #d3e5f1;
    border-radius: 8px;
    color: var(--theme-primary, #1f6392);
    display: inline-flex;
    gap: 0.35rem;
    margin: 0.25rem 0.35rem 0.1rem 0;
    padding: 0.4rem 0.6rem;
    text-decoration: none;
}

.architect-review-panel__attachment:hover {
    background: #e2f1fa;
    color: var(--theme-dark, #184f73);
}

@media (max-width: 992px) {
    .architect-review-panel__features {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .architect-review-panel__context-grid {
        grid-template-columns: 1fr;
    }

    .architect-review-panel__context-item {
        border-right: 0;
        border-bottom: 1px solid #e5edf4;
    }

    .architect-review-panel__context-item:last-child {
        border-bottom: 0;
    }
}

@media (max-width: 576px) {
    .architect-review-panel__head {
        align-items: flex-start;
        flex-direction: column;
    }

    .architect-review-panel__toggle {
        justify-content: center;
        width: 100%;
    }

    .architect-review-panel__overview,
    .architect-review-panel__features {
        grid-template-columns: 1fr;
    }

    .architect-review-panel__item:nth-child(odd) {
        border-right: 0;
    }

    .architect-review-panel__row {
        grid-template-columns: 1fr;
        gap: 0.35rem;
    }
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .architect-review-panel {
    background: #071321;
    border-color: rgba(31, 99, 146, 0.24);
    color: #d7e4f2;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .architect-review-panel__head {
    background: #0a1b2c;
    border-color: rgba(31, 99, 146, 0.24);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .architect-review-panel__body {
    background: #071321;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .architect-review-panel__item,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .architect-review-panel__row,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .architect-review-panel__section,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .architect-review-panel__context-grid,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .architect-review-panel__context-item,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .architect-review-panel__feature {
    border-color: rgba(31, 99, 146, 0.24);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .architect-review-panel__icon,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .architect-review-panel__attachment {
    background: #102a40;
    border-color: #214764;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .architect-review-panel__toggle {
    background: #0a1b2c;
    border-color: #31536e;
    color: #8fc9ef;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .architect-review-panel__head h5,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .architect-review-panel__value,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .architect-review-panel__feature {
    color: #d7e4f2;
}
</style>
<section class="architect-review-panel<?php echo $architectReviewPanelExpanded ? '' : ' is-collapsed'; ?>" data-architect-review-panel>
    <div class="architect-review-panel__head">
        <div class="architect-review-panel__intro">
            <span class="architect-review-panel__icon"><i class="fas fa-clipboard-check"></i></span>
            <div>
                <h5>Project Review Details</h5>
            </div>
        </div>
        <button
            type="button"
            class="architect-review-panel__toggle"
            data-architect-review-toggle
            aria-expanded="<?php echo $architectReviewPanelExpanded ? 'true' : 'false'; ?>"
            aria-controls="<?php echo htmlspecialchars($architectReviewPanelBodyId); ?>"
        >
            <span data-architect-review-toggle-label><?php echo $architectReviewPanelExpanded ? 'Hide details' : 'View details'; ?></span>
            <i class="fas fa-chevron-down" aria-hidden="true"></i>
        </button>
    </div>
    <div class="architect-review-panel__body" id="<?php echo htmlspecialchars($architectReviewPanelBodyId); ?>" <?php echo $architectReviewPanelExpanded ? '' : 'hidden'; ?>>
        <div class="architect-review-panel__overview">
            <div class="architect-review-panel__item"><span class="architect-review-panel__label">Project</span><span class="architect-review-panel__value"><?php echo htmlspecialchars((string) $architectReviewProject['name']); ?></span></div>
            <div class="architect-review-panel__item"><span class="architect-review-panel__label">Client</span><span class="architect-review-panel__value"><?php echo htmlspecialchars((string) $architectReviewProject['client_name']); ?></span></div>
            <div class="architect-review-panel__item"><span class="architect-review-panel__label">Timeline</span><span class="architect-review-panel__value"><?php echo htmlspecialchars($reviewTimelineLabel); ?></span></div>
            <div class="architect-review-panel__item"><span class="architect-review-panel__label">Priority</span><span class="architect-review-panel__value"><?php echo htmlspecialchars(ucfirst((string) ($architectReviewProject['priority'] ?? 'medium'))); ?></span></div>
            <div class="architect-review-panel__item"><span class="architect-review-panel__label">Contact</span><span class="architect-review-panel__value"><?php echo htmlspecialchars((string) ($architectReviewProject['contact_person'] ?: 'Not provided')); ?></span></div>
            <div class="architect-review-panel__item"><span class="architect-review-panel__label">Email</span><span class="architect-review-panel__value"><?php echo htmlspecialchars((string) ($architectReviewProject['client_email'] ?: 'Not provided')); ?></span></div>
            <div class="architect-review-panel__item"><span class="architect-review-panel__label">Phone</span><span class="architect-review-panel__value"><?php echo htmlspecialchars((string) ($architectReviewProject['client_phone'] ?: 'Not provided')); ?></span></div>
            <div class="architect-review-panel__item"><span class="architect-review-panel__label">Budget</span><span class="architect-review-panel__value"><?php echo $reviewBudgetAmount !== null ? '&#8369;' . number_format($reviewBudgetAmount, 2) : 'Not provided'; ?></span></div>
        </div>
        <div class="architect-review-panel__row">
            <span class="architect-review-panel__label">Description</span>
            <div class="architect-review-panel__value"><?php echo htmlspecialchars((string) ($architectReviewProject['description'] ?: 'Not provided')); ?></div>
        </div>
        <div class="architect-review-panel__section">
            <div class="architect-review-panel__section-head">
                <span class="architect-review-panel__section-title">Requested Features</span>
                <span class="architect-review-panel__section-count"><?php echo count($reviewFeatures); ?> item<?php echo count($reviewFeatures) === 1 ? '' : 's'; ?></span>
            </div>
            <?php if ($reviewFeatures): ?>
                <div class="architect-review-panel__features">
                    <?php foreach ($reviewFeatures as $reviewFeature): ?>
                        <span class="architect-review-panel__feature"><i class="fas fa-check-circle" aria-hidden="true"></i><span><?php echo htmlspecialchars($reviewFeature); ?></span></span>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="architect-review-panel__value">Not provided</div>
            <?php endif; ?>
        </div>
        <?php if (trim($reviewAdditionalText) !== ''): ?>
            <div class="architect-review-panel__row">
                <span class="architect-review-panel__label">Additional Requirements</span>
                <div class="architect-review-panel__value"><?php echo htmlspecialchars(trim($reviewAdditionalText)); ?></div>
            </div>
        <?php endif; ?>
        <div class="architect-review-panel__context-grid">
            <div class="architect-review-panel__context-item"><span class="architect-review-panel__label">Meeting Notes</span><div class="architect-review-panel__value"><?php echo htmlspecialchars((string) ($architectReviewProject['meeting_notes'] ?: 'Not provided')); ?></div></div>
            <div class="architect-review-panel__context-item"><span class="architect-review-panel__label">MOM</span><div class="architect-review-panel__value"><?php echo htmlspecialchars((string) ($architectReviewProject['mom'] ?: 'Not provided')); ?></div></div>
            <div class="architect-review-panel__context-item"><span class="architect-review-panel__label">Sales Notes</span><div class="architect-review-panel__value"><?php echo htmlspecialchars((string) (($architectReviewProject['sales_notes'] ?? '') ?: 'Not provided')); ?></div></div>
        </div>
        <div class="architect-review-panel__row">
            <span class="architect-review-panel__label">Attachments</span>
            <div class="architect-review-panel__value">
                <?php if ($reviewAttachments): ?>
                    <?php foreach ($reviewAttachments as $attachment): ?>
                        <a class="architect-review-panel__attachment" href="<?php echo BASE_URL . htmlspecialchars((string) ($attachment['path'] ?? '')); ?>" target="_blank" rel="noopener">
                            <i class="fas fa-paperclip me-1"></i><?php echo htmlspecialchars((string) ($attachment['name'] ?? 'Attachment')); ?>
                        </a>
                    <?php endforeach; ?>
                <?php else: ?>
                    No attachments uploaded.
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>
<script>
document.querySelectorAll('[data-architect-review-toggle]').forEach((button) => {
    button.addEventListener('click', () => {
        const panel = button.closest('[data-architect-review-panel]');
        if (!panel) return;
        const bodyId = button.getAttribute('aria-controls');
        const body = bodyId ? document.getElementById(bodyId) : null;
        const isCollapsed = panel.classList.toggle('is-collapsed');
        const label = button.querySelector('[data-architect-review-toggle-label]');

        button.setAttribute('aria-expanded', isCollapsed ? 'false' : 'true');
        if (body) body.hidden = isCollapsed;
        if (label) label.textContent = isCollapsed ? 'View details' : 'Hide details';
    });
});
</script>
<?php endif; ?>
