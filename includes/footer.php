        </div>
    </div>

    <?php include __DIR__ . '/chat-widget.php'; ?>

    <div class="modal fade" id="createGroupModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Create Chat Group</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Group Name</label>
                        <input type="text" id="groupName" class="form-control">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea id="groupDescription" class="form-control" rows="3"></textarea>
                    </div>
                    <div class="mb-0">
                        <label class="form-label">Members</label>
                        <select id="groupMembers" class="form-select" multiple size="8"></select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" onclick="createGroup()">Create Group</button>
                </div>
            </div>
        </div>
    </div>

    <div class="sidebar-overlay"></div>

    <script src="<?php echo BASE_URL; ?>assets/js/chat.js" defer></script>
</body>
</html>
