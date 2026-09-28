<?php
$architectDssAssistantConfig = is_array($architectDssAssistantConfig ?? null) ? $architectDssAssistantConfig : [];
?>
<?php if (!empty($architectDssAssistantConfig['items'])): ?>
<button type="button" class="evortix-dss-replay-trigger" data-evortix-dss-replay hidden>
    <i class="fas fa-rotate-left" aria-hidden="true"></i>
    <span>Ask Evortix again</span>
</button>
<?php endif; ?>
<style>
<?php if (empty($architectDssAssistantConfig['showSourceControls'])): ?>
.architect-dss-source-controls,
.architect-dss-source-card { display: none !important; }
<?php endif; ?>
.chat-bot-speech.evortix-architect-dss {
    top: auto;
    bottom: 18%;
    right: calc(100% + 18px);
    width: min(380px, calc(100vw - 190px));
    min-width: 300px;
    max-height: min(520px, calc(100vh - 110px));
    overflow-x: hidden;
    overflow-y: auto;
    overflow-wrap: anywhere;
    padding: 1rem 1.05rem;
    border-radius: 22px 22px 8px 22px;
    pointer-events: auto;
    box-shadow: 0 18px 42px rgba(15, 91, 137, .22);
}
.chat-bot-speech.evortix-architect-dss .evortix-dss-heading {
    display: block;
    padding-left: .8rem;
    font-size: .95rem;
    line-height: 1.3;
}
.chat-bot-speech.evortix-architect-dss .evortix-dss-copy {
    display: block;
    margin-top: .6rem;
    font-size: .81rem;
    line-height: 1.45;
    white-space: normal;
}
.chat-bot-speech.evortix-architect-dss .evortix-dss-points {
    margin: .55rem 0 0;
    padding-left: 1.1rem;
    font-size: .76rem;
    line-height: 1.4;
    white-space: normal;
}
.chat-bot-speech.evortix-architect-dss .evortix-dss-points li + li { margin-top: .22rem; }
.chat-bot-speech.evortix-architect-dss .evortix-dss-actions {
    position: relative;
    z-index: 2;
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: .55rem;
    margin-top: .85rem;
}
.chat-bot-speech.evortix-architect-dss .evortix-dss-actions .btn {
    min-height: 40px;
    border-radius: 11px;
    font-size: .75rem;
    font-weight: 800;
}
.evortix-dss-replay-trigger {
    position: fixed;
    right: 6.7rem;
    bottom: 1.6rem;
    z-index: 1040;
    display: inline-flex;
    align-items: center;
    gap: .45rem;
    min-height: 40px;
    padding: .55rem .8rem;
    border: 1px solid #9bd8f3;
    border-radius: 999px;
    background: #fff;
    color: #12689a;
    box-shadow: 0 8px 22px rgba(22, 104, 154, .16);
    font-size: .75rem;
    font-weight: 800;
}
.evortix-dss-replay-trigger[hidden] { display: none !important; }
.evortix-dss-replay-trigger:hover { background: #eef9ff; }
@media (max-width: 600px) {
    .chat-bot-speech.evortix-architect-dss { right: calc(100% + 9px); bottom: 12%; width: min(280px, calc(100vw - 135px)); min-width: 225px; max-height: calc(100vh - 90px); }
    .chat-bot-speech.evortix-architect-dss .evortix-dss-actions { grid-template-columns: 1fr; }
    .evortix-dss-replay-trigger { right: 5.3rem; bottom: 1.1rem; }
    .evortix-dss-replay-trigger span { display: none; }
}
.evortix-dss-accept-all { grid-column: 1 / -1; }
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .evortix-dss-replay-trigger,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .chat-bot-speech.evortix-architect-dss .btn-light {
    background: #102f45;
    color: #c5eaff;
    border: 1px solid #32627e;
}
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .evortix-dss-replay-trigger:hover,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .chat-bot-speech.evortix-architect-dss .btn-light:hover {
    background: #194560;
}
</style>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const config = <?php echo json_encode($architectDssAssistantConfig, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
    const allItems = Array.isArray(config.items) ? config.items : [];
    const persistDecisions = config.persistDecisions !== false;
    const autoStart = config.autoStart !== false;
    const stateKey = config.stateKey || `evortix.dss.v2:${location.pathname}:${new URLSearchParams(location.search).get('id') || ''}:${config.title || 'review'}`;
    let savedDecisions = {};
    if (persistDecisions) {
        try { savedDecisions = JSON.parse(localStorage.getItem(stateKey) || '{}') || {}; } catch (error) { savedDecisions = {}; }
    }
    const itemDecisionKey = (item) => [item?.selector, item?.label, item?.value ?? '', item?.count ?? ''].join('|');
    let items = allItems.filter((item) => !savedDecisions[itemDecisionKey(item)]);
    let itemIndex = 0;
    let acceptedCount = 0;
    let skippedCount = 0;
    let reviewCompleted = !autoStart;
    const replayTrigger = document.querySelector('[data-evortix-dss-replay]');
    const hideSourceControls = () => {
        document.querySelectorAll('.architect-dss-source-controls').forEach((controls) => {
            controls.hidden = true;
        });
    };
    if (replayTrigger) replayTrigger.hidden = false;
    if (items.length === 0) {
        reviewCompleted = true;
        hideSourceControls();
    }

    document.addEventListener('evortix:dss-bulk-decision', (event) => {
        const accepted = event.detail?.accepted === true;
        reviewCompleted = true;
        itemIndex = items.length;
        acceptedCount = accepted ? items.length : 0;
        skippedCount = accepted ? 0 : items.length;
        items.forEach((item) => {
            savedDecisions[itemDecisionKey(item)] = accepted ? 'accepted' : 'skipped';
        });
        if (persistDecisions) {
            try { localStorage.setItem(stateKey, JSON.stringify(savedDecisions)); } catch (error) {}
        }
        hideSourceControls();
        if (replayTrigger) replayTrigger.hidden = false;

        const bot = document.getElementById('chat-bot-float');
        const speech = bot?.querySelector('.chat-bot-speech.evortix-architect-dss');
        if (speech) {
            speech.hidden = true;
            speech.setAttribute('aria-hidden', 'true');
        }
        bot?.classList.remove('is-speaking');
        window.chatBotFloatRobot?.setEmotion?.(accepted ? 'happy' : 'sad', 900);
    });

    const escapeDss = (value) => {
        const node = document.createElement('div');
        node.textContent = String(value || '');
        return node.innerHTML;
    };

    const showMessagePose = () => {
        const robot = window.chatBotFloatRobot;
        if (config.pointAtMessage && typeof robot?.point === 'function') {
            robot.point(config.pointSide === 'right' ? 'right' : 'left', 0);
            return;
        }
        robot?.setEmotion?.('thinking', 0);
    };

    window.evortixConfirm = (options = {}) => new Promise((resolve) => {
        const bot = document.getElementById('chat-bot-float');
        const speech = bot?.querySelector('.chat-bot-speech');
        if (!bot || !speech) {
            resolve(false);
            return;
        }

        const title = options.title || 'Please confirm';
        const message = options.message || 'Would you like to continue?';
        const cancelLabel = options.cancelLabel || 'Keep current';
        const confirmLabel = options.confirmLabel || 'Continue';
        const resumeDelay = Math.max(240, Number(options.resumeDelay || 240));
        speech.className = 'chat-bot-speech evortix-project-notification evortix-architect-dss';
        speech.hidden = false;
        speech.setAttribute('aria-hidden', 'false');
        speech.style.pointerEvents = 'auto';
        speech.innerHTML = `
            <strong class="evortix-dss-heading"><i class="fas fa-robot me-1"></i>${escapeDss(title)}</strong>
            <span class="evortix-dss-copy">${escapeDss(message)}</span>
            <div class="evortix-dss-actions">
                <button type="button" class="btn btn-light btn-sm" data-evortix-confirm-choice="cancel">${escapeDss(cancelLabel)}</button>
                <button type="button" class="btn btn-primary btn-sm" data-evortix-confirm-choice="confirm">${escapeDss(confirmLabel)}</button>
            </div>`;
        bot.hidden = false;
        bot.setAttribute('aria-hidden', 'false');
        bot.classList.remove('is-returning-bed', 'is-preparing-speech');
        bot.classList.add('is-speaking');
        window.chatBotWidgetRobot?.setMode?.('empty-bed');
        window.chatBotFloatRobot?.setMode?.('standing');
        showMessagePose();

        const handleConfirmation = (event) => {
            const choice = event.target.closest('[data-evortix-confirm-choice]');
            if (!choice) return;
            event.preventDefault();
            event.stopPropagation();
            document.removeEventListener('click', handleConfirmation, true);
            const confirmed = choice.dataset.evortixConfirmChoice === 'confirm';
            window.chatBotFloatRobot?.setEmotion?.(confirmed ? 'happy' : 'thinking', 700);
            resolve(confirmed);
            if (itemIndex < items.length) window.setTimeout(showDecision, resumeDelay);
        };
        document.addEventListener('click', handleConfirmation, true);
    });

    const showDecision = () => {
        if (reviewCompleted) return;
        const bot = document.getElementById('chat-bot-float');
        const speech = bot?.querySelector('.chat-bot-speech');
        if (!bot || !speech) return;
        speech.className = 'chat-bot-speech evortix-project-notification evortix-architect-dss';
        speech.hidden = false;
        speech.setAttribute('aria-hidden', 'false');
        speech.style.pointerEvents = 'auto';
        const item = items[itemIndex] || null;
        const heading = item ? `Suggestion ${itemIndex + 1} of ${items.length}: ${item.label}` : config.title;
        const copy = item?.detail || config.message;
        const points = Array.isArray(item?.points) ? item.points.filter(Boolean) : [];
        speech.innerHTML = `
            <strong class="evortix-dss-heading"><i class="fas fa-lightbulb me-1"></i>${escapeDss(heading)}</strong>
            <span class="evortix-dss-copy">${escapeDss(copy)}</span>
            ${points.length ? `<ul class="evortix-dss-points">${points.map((point) => `<li>${escapeDss(point)}</li>`).join('')}</ul>` : ''}
            <div class="evortix-dss-actions">
                <button type="button" class="btn btn-light btn-sm" data-evortix-dss-choice="skip">Skip this</button>
                <button type="button" class="btn btn-primary btn-sm" data-evortix-dss-choice="accept">Accept this</button>
                ${config.allowAcceptAll ? '<button type="button" class="btn btn-primary btn-sm evortix-dss-accept-all" data-evortix-dss-choice="accept-all">Accept all</button>' : ''}
            </div>`;
        bot.hidden = false;
        bot.setAttribute('aria-hidden', 'false');
        bot.classList.remove('is-returning-bed', 'is-preparing-speech');
        bot.classList.add('is-speaking');
        window.chatBotWidgetRobot?.setMode?.('empty-bed');
        window.chatBotFloatRobot?.setMode?.('standing');
        showMessagePose();
    };

    const applyItemDecision = (item, accepted) => {
        if (!item?.selector) return;
        const field = document.querySelector(item.selector);
        if (!field) return;
        const countField = item.countSelector ? document.querySelector(item.countSelector) : null;

        // Set every related value before firing change events. This prevents an
        // accepted allocation from briefly looking like a reduction to zero.
        field.dataset.evortixDecision = '1';
        if (countField) countField.dataset.evortixDecision = '1';
        if (field.type === 'checkbox') field.checked = accepted;
        if (accepted && item.value !== undefined) field.value = String(item.value);
        if (countField) countField.value = accepted ? String(item.count || 0) : '0';
        field.dispatchEvent(new Event('change', { bubbles: true }));
        if (countField) countField.dispatchEvent(new Event('change', { bubbles: true }));
        delete field.dataset.evortixDecision;
        if (countField) delete countField.dataset.evortixDecision;
    };

    const showCompletion = () => {
        const heading = config.completedTitle || 'Review complete';
        const message = `${acceptedCount} accepted and ${skippedCount} skipped. Your selections have been applied. Save the form to keep your changes.`;
        const speech = document.querySelector('#chat-bot-float .chat-bot-speech');
        if (speech) {
            speech.hidden = false;
            speech.setAttribute('aria-hidden', 'false');
            speech.setAttribute('role', 'status');
            speech.innerHTML = `<strong class="evortix-dss-heading"><i class="fas fa-circle-check me-1"></i>${escapeDss(heading)}</strong><span class="evortix-dss-copy">${escapeDss(message)}</span>`;
        }
        window.appToast?.(heading, message, 'success');
        document.dispatchEvent(new CustomEvent('evortix:dss-completed', { detail: { accepted: acceptedCount, skipped: skippedCount } }));
    };

    document.addEventListener('click', (event) => {
        const replay = event.target.closest('[data-evortix-dss-replay]');
        if (replay) {
            event.preventDefault();
            if (!autoStart) {
                items = [...allItems];
                itemIndex = 0;
                acceptedCount = 0;
                skippedCount = 0;
                reviewCompleted = items.length === 0;
                if (!reviewCompleted) showDecision();
                return;
            }
            if (persistDecisions) try { localStorage.removeItem(stateKey); } catch (error) {}
            location.reload();
            return;
        }
        const choice = event.target.closest('[data-evortix-dss-choice]');
        if (!choice || reviewCompleted) return;
        event.preventDefault();
        event.stopPropagation();
        if (choice.dataset.evortixDssChoice === 'accept-all' && config.allowAcceptAll) {
            allItems.forEach(item => {
                applyItemDecision(item, true);
                savedDecisions[itemDecisionKey(item)] = 'accepted';
            });
            if (persistDecisions) try { localStorage.setItem(stateKey, JSON.stringify(savedDecisions)); } catch (error) {}
            acceptedCount = allItems.length;
            skippedCount = 0;
            itemIndex = items.length;
            reviewCompleted = true;
            hideSourceControls();
            const speech = document.querySelector('#chat-bot-float .chat-bot-speech');
            if (speech) { speech.hidden = true; speech.setAttribute('aria-hidden', 'true'); }
            window.chatBotFloatRobot?.setEmotion?.('happy', 900);
            showCompletion();
            return;
        }
        const accepted = choice.dataset.evortixDssChoice === 'accept';
        const item = items[itemIndex] || null;
        applyItemDecision(item, accepted);
        if (item) {
            savedDecisions[itemDecisionKey(item)] = accepted ? 'accepted' : 'skipped';
            if (persistDecisions) try { localStorage.setItem(stateKey, JSON.stringify(savedDecisions)); } catch (error) {}
        }
        accepted ? acceptedCount++ : skippedCount++;
        itemIndex++;
        window.chatBotFloatRobot?.setEmotion?.(accepted ? 'happy' : 'sad', 900);
        if (accepted && item?.submitSelector) {
            const form = document.querySelector(item.submitSelector);
            if (form?.requestSubmit) form.requestSubmit();
            return;
        }
        if (itemIndex < items.length) {
            window.setTimeout(showDecision, 260);
            return;
        }
        reviewCompleted = true;
        hideSourceControls();
        if (replayTrigger) replayTrigger.hidden = false;
        showCompletion();
    }, true);

    if (autoStart && config.title && config.message && items.length > 0) {
        window.setTimeout(showDecision, Math.max(0, Number(config.startDelay ?? 650)));
    }
});
</script>
