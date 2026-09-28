        </div>
    </div>

    <?php if (empty($chatWidgetRendered)): ?>
        <?php include __DIR__ . '/chat-widget.php'; ?>
    <?php endif; ?>

    <div class="modal fade create-group-modal" id="createGroupModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <div>
                        <h5 class="modal-title">Create Chat Group</h5>
                        <p class="create-group-subtitle mb-0">Choose teammates with a simple tap or click.</p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Group Name</label>
                        <input type="text" id="groupName" class="form-control" placeholder="e.g. Project Launch Team">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea id="groupDescription" class="form-control" rows="2" placeholder="Optional short note for the group"></textarea>
                    </div>
                    <div class="mb-0">
                        <div class="d-flex align-items-center justify-content-between gap-2 mb-2">
                            <label class="form-label mb-0" for="groupMemberSearch">Members</label>
                            <span class="group-member-count" id="groupMemberCount">0 selected</span>
                        </div>
                        <div class="group-member-picker">
                            <div class="group-member-search">
                                <i class="fas fa-search"></i>
                                <input type="text" id="groupMemberSearch" class="form-control" placeholder="Search users">
                            </div>
                            <div id="groupMembers" class="group-member-list" aria-live="polite">
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
                    <button type="button" class="btn btn-primary" id="createGroupSubmitBtn" onclick="createGroup()">Create Group</button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade app-confirm-modal" id="appConfirmModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="appConfirmTitle">
                        <i class="fas fa-triangle-exclamation me-2"></i> Confirm Action
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="app-confirm-note mb-0">
                        <i class="fas fa-circle-info fa-lg" id="appConfirmIcon"></i>
                        <div>
                            <div class="fw-semibold mb-1" id="appConfirmSubject">Please confirm this action.</div>
                            <div class="small text-muted" id="appConfirmMessage">This action may affect the system workflow.</div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="app-confirm-cancel" data-bs-dismiss="modal" id="appConfirmCancelBtn">Cancel</button>
                    <button type="button" class="app-confirm-submit" id="appConfirmSubmitBtn">
                        <i class="fas fa-check me-1"></i> Continue
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div class="app-global-loading-overlay" id="appGlobalLoadingOverlay" aria-hidden="true">
        <div class="app-global-loading-content" role="status" aria-live="polite">
            <div class="spinner-border app-global-loading-spinner" role="presentation"></div>
            <div class="app-global-loading-title" id="appGlobalLoadingTitle">Working on it</div>
            <p class="app-global-loading-text" id="appGlobalLoadingText">Please wait while the system finishes this action.</p>
        </div>
    </div>

    <div class="sidebar-overlay"></div>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/image-preview.css?v=<?php echo filemtime(__DIR__ . '/../assets/css/image-preview.css'); ?>">
    <script src="<?php echo BASE_URL; ?>assets/js/image-preview.js?v=<?php echo filemtime(__DIR__ . '/../assets/js/image-preview.js'); ?>" defer></script>

    <?php if (empty($chatScriptLoaded)): ?>
        <script src="<?php echo BASE_URL; ?>assets/js/chat.js?v=<?php echo filemtime(__DIR__ . '/../assets/js/chat.js'); ?>" defer></script>
    <?php endif; ?>
</body>
</html>
