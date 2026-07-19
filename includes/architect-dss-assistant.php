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
.architect-dss-source-controls,
.architect-dss-source-card { display: none !important; }
.chat-bot-speech.evortix-architect-dss {
    width: min(380px, calc(100vw - 190px));
    min-width: 300px;
    padding: 1rem 1.05rem;
    pointer-events: auto;
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
}
.chat-bot-speech.evortix-architect-dss .evortix-dss-points {
    margin: .55rem 0 0;
    padding-left: 1.1rem;
    font-size: .76rem;
    line-height: 1.4;
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
    .chat-bot-speech.evortix-architect-dss { width: min(280px, calc(100vw - 135px)); min-width: 225px; }
    .chat-bot-speech.evortix-architect-dss .evortix-dss-actions { grid-template-columns: 1fr; }
    .evortix-dss-replay-trigger { right: 5.3rem; bottom: 1.1rem; }
    .evortix-dss-replay-trigger span { display: none; }
}
</style>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const config = <?php echo json_encode($architectDssAssistantConfig, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
    const allItems = Array.isArray(config.items) ? config.items : [];
    const stateKey = config.stateKey || `evortix.dss.v2:${location.pathname}:${new URLSearchParams(location.search).get('id') || ''}:${config.title || 'review'}`;
    let savedDecisions = {};
    try { savedDecisions = JSON.parse(localStorage.getItem(stateKey) || '{}') || {}; } catch (error) { savedDecisions = {}; }
    const itemDecisionKey = (item) => [item?.selector, item?.label, item?.value ?? '', item?.count ?? ''].join('|');
    const items = allItems.filter((item) => !savedDecisions[itemDecisionKey(item)]);
    let itemIndex = 0;
    let acceptedCount = 0;
    let skippedCount = 0;
    const replayTrigger = document.querySelector('[data-evortix-dss-replay]');
    if (replayTrigger) replayTrigger.hidden = false;

    const escapeDss = (value) => {
        const node = document.createElement('div');
        node.textContent = String(value || '');
        return node.innerHTML;
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
        window.chatBotFloatRobot?.wake?.();
        window.chatBotFloatRobot?.setEmotion?.('thinking', 0);

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
            </div>`;
        bot.hidden = false;
        bot.setAttribute('aria-hidden', 'false');
        bot.classList.remove('is-returning-bed', 'is-preparing-speech');
        bot.classList.add('is-speaking');
        window.chatBotFloatRobot?.wake?.();
        window.chatBotFloatRobot?.setEmotion?.('thinking', 0);
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

    document.addEventListener('click', (event) => {
        const replay = event.target.closest('[data-evortix-dss-replay]');
        if (replay) {
            event.preventDefault();
            try { localStorage.removeItem(stateKey); } catch (error) {}
            location.reload();
            return;
        }
        const choice = event.target.closest('[data-evortix-dss-choice]');
        if (!choice) return;
        event.preventDefault();
        event.stopPropagation();
        const accepted = choice.dataset.evortixDssChoice === 'accept';
        const item = items[itemIndex] || null;
        applyItemDecision(item, accepted);
        if (item) {
            savedDecisions[itemDecisionKey(item)] = accepted ? 'accepted' : 'skipped';
            try { localStorage.setItem(stateKey, JSON.stringify(savedDecisions)); } catch (error) {}
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
        const heading = config.completedTitle || 'DSS review complete';
        const message = `I reviewed ${items.length} suggestion${items.length === 1 ? '' : 's'} with you: ${acceptedCount} accepted and ${skippedCount} skipped. Review your selections before continuing.`;
        if (replayTrigger) replayTrigger.hidden = false;
        window.evortixAnnounceNotification?.(heading, message, '');
    }, true);

    if (config.title && config.message && items.length > 0) window.setTimeout(showDecision, 650);
});
</script>
