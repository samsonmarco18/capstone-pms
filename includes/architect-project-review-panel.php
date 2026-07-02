<?php
$architectReviewProjectId = (int) ($architectReviewProjectId ?? $projectId ?? $selectedProjectId ?? ($_GET['id'] ?? $_GET['project_id'] ?? 0));
$architectReviewProject = null;

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
    border: 1px solid var(--gray-200, #e9ecef);
    border-radius: 16px;
    box-shadow: var(--shadow-sm, 0 2px 4px rgba(0,0,0,0.04));
    margin-bottom: 1.5rem;
    overflow: hidden;
}
.architect-review-panel__head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    padding: 1rem 1.25rem;
    background: var(--gray-50, #f8f9fa);
    border-bottom: 1px solid var(--gray-200, #e9ecef);
}
.architect-review-panel__head h5 {
    margin: 0;
    color: var(--gray-900, #212529);
    font-size: 0.98rem;
    font-weight: 700;
}
.architect-review-panel__toggle {
    border: 0;
    background: transparent;
    color: var(--theme-primary, #1f6392);
    font-size: 0.82rem;
    font-weight: 700;
}
.architect-review-panel.is-minimized .architect-review-panel__body {
    display: none;
}
.architect-review-panel__body {
    display: grid;
    gap: 1rem;
    padding: 1.25rem;
}
.architect-review-panel__grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 0.75rem;
}
.architect-review-panel__item,
.architect-review-panel__section {
    border: 1px solid var(--gray-200, #e9ecef);
    border-radius: 12px;
    background: #ffffff;
    padding: 0.85rem;
}
.architect-review-panel__item span,
.architect-review-panel__section span {
    display: block;
    color: var(--gray-600, #6c757d);
    font-size: 0.72rem;
    font-weight: 700;
    text-transform: uppercase;
}
.architect-review-panel__item strong,
.architect-review-panel__section div {
    color: var(--gray-900, #212529);
    font-size: 0.9rem;
    overflow-wrap: anywhere;
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
@media (max-width: 992px) {
    .architect-review-panel__grid {
        grid-template-columns: 1fr;
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
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .architect-review-panel__head h5,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .architect-review-panel__item strong,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .architect-review-panel__section div {
    color: #d7e4f2;
}
</style>
<section class="architect-review-panel" data-architect-review-panel>
    <div class="architect-review-panel__head">
        <h5><i class="fas fa-clipboard-check me-2"></i>Project Review Details</h5>
        <button type="button" class="architect-review-panel__toggle" data-architect-review-toggle>Minimize</button>
    </div>
    <div class="architect-review-panel__body">
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
                        <a class="d-inline-block me-3" href="<?php echo BASE_URL . htmlspecialchars((string) ($attachment['path'] ?? '')); ?>" target="_blank" rel="noopener">
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
        panel.classList.toggle('is-minimized');
        button.textContent = panel.classList.contains('is-minimized') ? 'Expand' : 'Minimize';
    });
});
</script>
<?php endif; ?>
