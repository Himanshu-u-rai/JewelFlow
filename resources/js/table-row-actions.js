const initialized = new WeakSet();

// One label outside the scrolling table; the control keeps its own accessible name.
export function initializeRowActionLabels(doc = document, win = window) {
    if (initialized.has(doc)) return;
    initialized.add(doc);
    let action = null;
    let label = null;
    let dismissed = null;
    let frame = 0;
    let leaveTimer = 0;

    const clearLeave = () => { win.clearTimeout(leaveTimer); leaveTimer = 0; };
    const hide = () => {
        clearLeave();
        win.cancelAnimationFrame(frame);
        frame = 0;
        label?.remove();
        label = action = null;
    };
    const place = () => {
        frame = 0;
        if (!action?.isConnected) return hide();
        const box = action.getBoundingClientRect();
        if (box.bottom <= 0 || box.top >= win.innerHeight || box.right <= 0 || box.left >= win.innerWidth) return hide();
        if (doc.elementFromPoint && !action.contains(doc.elementFromPoint(box.left + box.width / 2, box.top + box.height / 2))) return hide();
        const size = label.getBoundingClientRect();
        const left = Math.max(8, Math.min(box.left + box.width / 2 - size.width / 2, win.innerWidth - size.width - 8));
        const above = box.top - size.height - 6;
        const top = Math.max(8, Math.min(above >= 8 ? above : box.bottom + 6, win.innerHeight - size.height - 8));
        label.style.left = `${left}px`;
        label.style.top = `${top}px`;
        label.style.visibility = 'visible';
    };
    const queuePlace = () => { if (!frame) frame = win.requestAnimationFrame(place); };
    const show = (next) => {
        if (!next?.dataset.tip || next.disabled || next === dismissed) return;
        clearLeave();
        if (next === action) return queuePlace();
        hide();
        action = next;
        label = doc.createElement('div');
        label.className = 'jf-row-action-label';
        label.setAttribute('aria-hidden', 'true');
        label.setAttribute('popover', 'manual');
        label.textContent = action.dataset.tip;
        label.style.visibility = 'hidden';
        doc.body.appendChild(label);
        try { label.showPopover?.(); } catch { label.removeAttribute('popover'); }
        queuePlace();
    };
    const control = (target) => target?.closest?.('.jf-row-action[data-tip]');
    const focused = () => action === doc.activeElement && action?.matches(':focus-visible');

    doc.addEventListener('focusin', (event) => {
        const next = control(event.target);
        if (next !== dismissed) dismissed = null;
        if (next?.matches(':focus-visible')) show(next);
    });
    doc.addEventListener('focusout', () => { dismissed = null; hide(); });
    doc.addEventListener('pointerover', (event) => {
        if (label?.contains(event.target)) return clearLeave();
        if (event.pointerType === 'mouse' && win.matchMedia('(hover: hover) and (pointer: fine)').matches) show(control(event.target));
    });
    doc.addEventListener('pointerout', (event) => {
        if (dismissed?.contains(event.target) && !dismissed.contains(event.relatedTarget)) dismissed = null;
        if (!action || action.contains(event.relatedTarget) || label?.contains(event.relatedTarget) || focused()) return;
        clearLeave();
        leaveTimer = win.setTimeout(hide, 120);
    });
    doc.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') { dismissed = action; hide(); }
    });
    doc.addEventListener('click', hide);
    doc.addEventListener('scroll', () => { if (action) focused() ? queuePlace() : hide(); }, { capture: true, passive: true });
    win.addEventListener('resize', () => { if (action) queuePlace(); });
    for (const event of ['turbo:before-cache', 'turbo:before-render']) {
        doc.addEventListener(event, () => { dismissed = null; hide(); });
    }
}
