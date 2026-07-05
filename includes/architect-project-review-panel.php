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
    $reviewFeatureText = trim((string) ($architectReviewProject['requested_features'] ?? ''));
    $reviewAdditionalText = '';
    if (str_contains($reviewFeatureText, 'Additional Requirements:')) {
        [$reviewFeatureText, $reviewAdditionalText] = array_pad(explode('Additional Requirements:', $reviewFeatureText, 2), 2, '');
    }
    $reviewFeatures = array_values(array_filter(array_map('trim', preg_split('/\R+/', $reviewFeatureText) ?: [])));
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
    min-height: 72px;
    padding: 0.9rem 1rem;
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
    height: 38px;
    justify-content: center;
    width: 38px;
}

.architect-review-panel__head h5 {
    margin: 0;
    color: var(--gray-900, #212529);
    font-size: 0.95rem;
    font-weight: 700;
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
    display: inline-flex;
    flex: 0 0 auto;
    font-size: 0.82rem;
    font-weight: 700;
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
    display: grid;
    gap: 1rem;
    padding: 1rem;
    background: #f7fafc;
}

.architect-review-panel__grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 0.75rem;
}

.architect-review-panel__item,
.architect-review-panel__section {
    border: 1px solid #e1e9f0;
    border-radius: 10px;
    background: #ffffff;
    padding: 0.9rem;
}

.architect-review-panel__item span,
.architect-review-panel__section span {
    display: block;
    color: #718399;
    font-size: 0.69rem;
    font-weight: 700;
    letter-spacing: 0.035em;
    text-transform: uppercase;
}

.architect-review-panel__item strong,
.architect-review-panel__section div {
    color: #1e344a;
    font-size: 0.9rem;
    overflow-wrap: anywhere;
}

.architect-review-panel__item strong {
    display: block;
    margin-top: 0.3rem;
}

.architect-review-panel__section div {
    margin-top: 0.35rem;
    white-space: pre-wrap;
}

.architect-review-panel__features {
    margin: 0.45rem 0 0;
    padding-left: 1.1rem;
}

.architect-review-panel__features li {
    margin-bottom: 0.25rem;
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
    .architect-review-panel__grid {
        grid-template-columns: 1fr;
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
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .architect-review-panel,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .architect-review-panel__item,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .architect-review-panel__section {
    background: #071321;
    border-color: rgba(31, 99, 146, 0.24);
    color: #d7e4f2;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .architect-review-panel__head {
    background: #0a1b2c;
    border-color: rgba(31, 99, 146, 0.24);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .architect-review-panel__body {
    background: #091624;
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
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .architect-review-panel__item strong,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .architect-review-panel__section div {
    color: #d7e4f2;
}
</style>
<section class="architect-review-panel<?php echo $architectReviewPanelExpanded ? '' : ' is-collapsed'; ?>" data-architect-review-panel>
    <div class="architect-review-panel__head">
        <div class="architect-review-panel__intro">
            <span class="architect-review-panel__icon"><i class="fas fa-clipboard-check"></i></span>
            <div>
                <h5>Project Review Details</h5>
                <p>Reference the approved client requirements and sales context.</p>
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
        <div class="architect-review-panel__grid">
            <div class="architect-review-panel__item"><span>Project</span><strong><?php echo htmlspecialchars((string) $architectReviewProject['name']); ?></strong></div>
            <div class="architect-review-panel__item"><span>Client</span><strong><?php echo htmlspecialchars((string) $architectReviewProject['client_name']); ?></strong></div>
            <div class="architect-review-panel__item"><span>Timeline</span><strong><?php echo htmlspecialchars(trim((string) ($architectReviewProject['timeline_start'] ?? 'Not set') . ' to ' . (string) ($architectReviewProject['timeline_end'] ?? 'Not set'))); ?></strong></div>
            <div class="architect-review-panel__item"><span>Contact</span><strong><?php echo htmlspecialchars((string) ($architectReviewProject['contact_person'] ?? 'N/A')); ?></strong></div>
            <div class="architect-review-panel__item"><span>Email</span><strong><?php echo htmlspecialchars((string) ($architectReviewProject['client_email'] ?? 'N/A')); ?></strong></div>
            <div class="architect-review-panel__item"><span>Priority</span><strong><?php echo htmlspecialchars(ucfirst((string) ($architectReviewProject['priority'] ?? 'medium'))); ?></strong></div>
        </div>
        <div class="architect-review-panel__section">
            <span>Description</span>
            <div><?php echo htmlspecialchars((string) ($architectReviewProject['description'] ?: 'No description provided.')); ?></div>
        </div>
        <div class="architect-review-panel__section">
            <span>Requested Features</span>
            <?php if ($reviewFeatures): ?>
                <ul class="architect-review-panel__features">
                    <?php foreach ($reviewFeatures as $reviewFeature): ?>
                        <li><?php echo htmlspecialchars($reviewFeature); ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <div>No requested features provided.</div>
            <?php endif; ?>
            <?php if (trim($reviewAdditionalText) !== ''): ?>
                <div><strong>Additional Requirements:</strong> <?php echo htmlspecialchars(trim($reviewAdditionalText)); ?></div>
            <?php endif; ?>
        </div>
        <div class="architect-review-panel__grid">
            <div class="architect-review-panel__section"><span>Meeting Notes</span><div><?php echo htmlspecialchars((string) ($architectReviewProject['meeting_notes'] ?: 'No meeting notes provided.')); ?></div></div>
            <div class="architect-review-panel__section"><span>MOM</span><div><?php echo htmlspecialchars((string) ($architectReviewProject['mom'] ?: 'No MOM provided.')); ?></div></div>
            <div class="architect-review-panel__section"><span>Sales Notes</span><div><?php echo htmlspecialchars((string) (($architectReviewProject['sales_notes'] ?? '') ?: 'No sales notes provided.')); ?></div></div>
        </div>
        <div class="architect-review-panel__section">
            <span>Attachments</span>
            <div>
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
