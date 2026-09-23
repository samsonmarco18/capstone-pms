(() => {
    const review = document.getElementById('evortixClassificationReview');
    if (!review) return;
    const rows = [...document.querySelectorAll('#developerAllocationDetails .allocation-input-row')];
    const locked = review.dataset.locked === '1';
    const signature = JSON.stringify(rows.map(row => [row.dataset.department, row.dataset.recommendedLevel, row.dataset.recommendationReason]));
    const key = `evortix.classification.${review.dataset.expertId}.${review.dataset.projectId}`;
    let reviewed = locked;
    try { reviewed ||= sessionStorage.getItem(key) === signature; } catch (_) {}
    const allocationToggle = document.querySelector('[aria-controls="developerAllocationDetails"]');
    const featureToggle = document.querySelector('[aria-controls="featureEstimationProgressDetails"]');
    const complete = allocationToggle?.querySelector('.estimation-section-complete');
    const status = review.querySelector('[data-classification-status]');
    const ready = () => locked || (rows.length > 0 && reviewed);
    window.evortixDeveloperClassificationReady = projectId => String(projectId) === review.dataset.projectId && ready();

    function update() {
        if (complete) complete.hidden = !ready();
        review.hidden = locked;
        review.querySelectorAll('button').forEach(button => { button.disabled = locked || rows.length === 0; });
        if (status) status.textContent = ready() ? 'Developer classification reviewed. Continue to feature estimation.'
            : rows.length ? 'Review the recommendations, then apply them or confirm your selections.'
                : 'Roadmap developer headcount is required before feature estimation.';
        featureToggle?.setAttribute('aria-disabled', ready() ? 'false' : 'true');
    }

    function showRecommendations() {
        if (allocationToggle?.getAttribute('aria-expanded') !== 'true') allocationToggle?.click();
        review.scrollIntoView({ behavior: 'smooth', block: 'center' });
        const bot = document.getElementById('chat-bot-float');
        const speech = bot?.querySelector('.chat-bot-speech');
        if (!speech || locked) return;
        const escape = value => { const node = document.createElement('span'); node.textContent = value; return node.innerHTML; };
        speech.className = 'chat-bot-speech evortix-project-notification evortix-architect-dss evortix-classification-panel';
        speech.innerHTML = `<strong>Developer classification recommendations</strong>
            <p>Review these developer levels before feature estimation.</p>
            <div class="evortix-classification-list">${rows.map((row, index) => `<p><strong>${escape(row.dataset.department)} · Position ${index + 1}: ${escape(row.dataset.recommendedLevel)}</strong><span>${escape(row.dataset.recommendationReason)}</span></p>`).join('') || '<p>Enter the Roadmap developer headcount first.</p>'}</div>
            ${rows.length ? '<div class="evortix-classification-actions"><button type="button" class="btn btn-primary btn-sm" data-classification-choice="apply">Apply and continue</button><button type="button" class="btn btn-light btn-sm" data-classification-choice="keep">Keep my selections</button></div>' : ''}`;
        speech.dataset.classificationReview = 'true';
        speech.hidden = false;
        speech.setAttribute('aria-hidden', 'false');
        speech.style.pointerEvents = 'auto';
        bot.hidden = false;
        bot.setAttribute('aria-hidden', 'false');
        bot.classList.add('is-speaking');
        window.chatBotFloatRobot?.setMode?.('standing');
    }

    // Capture the transition before the accordion or feature assistant can advance.
    featureToggle?.addEventListener('click', event => {
        if (ready()) return;
        event.preventDefault();
        event.stopImmediatePropagation();
        showRecommendations();
    }, true);
    document.getElementById('projectCostEstimationForm')?.addEventListener('submit', event => {
        if (ready()) return;
        event.preventDefault();
        event.stopImmediatePropagation();
        showRecommendations();
    }, true);
    document.addEventListener('click', event => {
        const button = event.target.closest('[data-classification-choice]');
        if (!button || locked || !rows.length) return;
        if (button.dataset.classificationChoice === 'apply') {
            rows.forEach(row => {
                const select = row.querySelector('.developer-type-input');
                select.value = row.dataset.recommendedLevel;
                select.dispatchEvent(new Event('change', { bubbles: true }));
            });
        }
        reviewed = true;
        try { sessionStorage.setItem(key, signature); } catch (_) {}
        update();
        const speech = document.querySelector('[data-classification-review="true"]');
        if (speech) {
            speech.hidden = true;
            speech.setAttribute('aria-hidden', 'true');
            delete speech.dataset.classificationReview;
        }
        if (featureToggle?.getAttribute('aria-expanded') !== 'true') featureToggle?.click();
        document.getElementById('featureEstimationProgressDetails')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
        window.scheduleEvortixFeatureReview?.(review.dataset.projectId);
    });
    update();
    document.addEventListener('DOMContentLoaded', () => {
        if (!ready()) showRecommendations();
    });
})();
