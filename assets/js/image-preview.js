(() => {
    'use strict';
    const imageExtension = /\.(?:png|jpe?g|gif|webp|svg|bmp|avif|ico)$/i;
    let dialog, picture, caption, status, returnFocus, previousOverflow;

    function imageUrl(value, explicit = false) {
        try {
            const url = new URL(value, document.baseURI);
            if (!['http:', 'https:', 'blob:'].includes(url.protocol)) return null;
            const path = url.searchParams.get('path') || decodeURIComponent(url.pathname);
            return explicit || imageExtension.test(path) ? url.href : null;
        } catch (_) {
            return null;
        }
    }

    function createPreview() {
        dialog = document.createElement('dialog');
        dialog.className = 'app-image-preview';
        dialog.setAttribute('aria-label', 'Image preview');
        dialog.innerHTML = '<div class="app-image-preview__bar"><button type="button" autofocus aria-label="Close image preview and go back">← Back</button><span></span></div><div class="app-image-preview__body"><p role="status">Loading image…</p><img alt=""></div>';
        document.body.append(dialog);
        picture = dialog.querySelector('img');
        caption = dialog.querySelector('span');
        status = dialog.querySelector('[role="status"]');
        dialog.querySelector('button').addEventListener('click', () => dialog.close());
        dialog.addEventListener('click', event => {
            if (event.target === dialog || event.target.classList.contains('app-image-preview__body')) dialog.close();
        });
        dialog.addEventListener('close', () => {
            picture.removeAttribute('src');
            document.body.style.overflow = previousOverflow;
            if (returnFocus?.isConnected) returnFocus.focus({ preventScroll: true });
        });
        // Keep a parent Bootstrap modal's focus trap from stealing preview focus.
        dialog.addEventListener('focusin', event => event.stopPropagation());
        picture.addEventListener('load', () => { status.hidden = true; picture.hidden = false; });
        picture.addEventListener('error', () => {
            status.textContent = 'Unable to load this image. Close the preview and try again.';
            status.hidden = false;
            picture.hidden = true;
        });
    }

    document.addEventListener('click', event => {
        if (event.defaultPrevented || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
        const target = event.target instanceof Element ? event.target : null;
        if (!target || target.closest('.app-image-preview')) return;
        const link = target.closest('a[href]');
        if (!link || link.hasAttribute('download') || link.hasAttribute('data-no-image-preview')) return;
        const url = imageUrl(link.href, link.hasAttribute('data-image-preview'));
        if (!url) return;
        event.preventDefault();
        event.stopImmediatePropagation();
        if (!dialog) createPreview();
        returnFocus = link;
        previousOverflow = document.body.style.overflow;
        caption.textContent = link.dataset.imageTitle || link.querySelector('img')?.alt || link.textContent.trim() || 'Image preview';
        picture.alt = caption.textContent;
        picture.hidden = true;
        status.hidden = false;
        status.textContent = 'Loading image…';
        dialog.showModal();
        document.body.style.overflow = 'hidden';
        picture.src = url;
    }, true);
})();
