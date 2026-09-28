(() => {
    const form = document.getElementById('phaseOrderForm');
    const cards = [...document.querySelectorAll('[data-phase-card-id]')];
    if (!form || cards.length < 2) return;
    let dragged = null;
    let saving = false;
    let preview = null;
    const phaseName = card => card.querySelector('h5')?.textContent.trim() || 'Phase';
    const ids = () => cards.map(card => card.dataset.phaseCardId);
    function save(order) {
        if (saving || order.join(',') === ids().join(',')) return;
        saving = true;
        order.forEach(id => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'phase_order[]';
            input.value = id;
            form.appendChild(input);
        });
        cards.forEach(card => card.querySelector('.phase-drag-handle')?.setAttribute('disabled', ''));
        window.appShowLoading?.('Saving phase order', 'Please wait while the roadmap is updated.');
        // Let the loading state paint before starting navigation.
        window.requestAnimationFrame(() => window.requestAnimationFrame(() => form.requestSubmit()));
    }
    function clear() {
        cards.forEach(card => card.classList.remove('is-dragging', 'is-drop-target', 'drop-before', 'drop-after'));
        preview?.remove();
        preview = null;
        dragged = null;
    }
    cards.forEach(card => {
        const handle = card.querySelector('.phase-drag-handle');
        handle?.addEventListener('dragstart', event => {
            if (saving) { event.preventDefault(); return; }
            dragged = card;
            event.dataTransfer.effectAllowed = 'move';
            event.dataTransfer.setData('text/plain', card.dataset.phaseCardId);
            preview = document.createElement('div');
            preview.className = 'phase-drag-preview';
            preview.setAttribute('role', 'status');
            const label = document.createElement('small');
            label.textContent = 'Moving phase';
            const title = document.createElement('strong');
            title.textContent = phaseName(card);
            preview.append(label, title);
            document.body.appendChild(preview);
            event.dataTransfer.setDragImage(preview, 24, 24);
            card.classList.add('is-dragging');
            event.stopPropagation();
        });
        handle?.addEventListener('dragend', clear);
        handle?.addEventListener('keydown', event => {
            if (!['ArrowUp', 'ArrowDown'].includes(event.key)) return;
            event.preventDefault();
            event.stopPropagation();
            const order = ids();
            const index = order.indexOf(card.dataset.phaseCardId);
            const target = index + (event.key === 'ArrowUp' ? -1 : 1);
            if (target < 0 || target >= order.length) return;
            [order[index], order[target]] = [order[target], order[index]];
            save(order);
        });
        card.addEventListener('dragover', event => {
            if (!dragged || card === dragged) return;
            event.preventDefault();
            event.dataTransfer.dropEffect = 'move';
            const rect = card.getBoundingClientRect();
            const after = event.clientY > rect.top + rect.height / 2;
            cards.forEach(other => {
                other.classList.toggle('is-drop-target', other === card);
                other.classList.toggle('drop-before', other === card && !after);
                other.classList.toggle('drop-after', other === card && after);
            });
            const label = preview?.querySelector('small');
            if (label) label.textContent = `Drop ${after ? 'after' : 'before'} ${phaseName(card)}`;
        });
        card.addEventListener('drop', event => {
            if (!dragged || dragged === card) return;
            event.preventDefault();
            event.stopPropagation();
            const movedId = dragged.dataset.phaseCardId;
            const order = ids().filter(id => id !== movedId);
            const rect = card.getBoundingClientRect();
            const after = event.clientY > rect.top + rect.height / 2;
            order.splice(order.indexOf(card.dataset.phaseCardId) + (after ? 1 : 0), 0, movedId);
            clear();
            save(order);
        });
    });
    window.addEventListener('pageshow', () => {
        clear();
        saving = false;
        form.querySelectorAll('[name="phase_order[]"]').forEach(input => input.remove());
        cards.forEach(card => card.querySelector('.phase-drag-handle')?.removeAttribute('disabled'));
        window.appHideLoading?.();
    });
})();
