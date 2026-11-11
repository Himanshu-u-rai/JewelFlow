// Local to Repairs: keep the original form nodes (including files and drafts).
export function createRepairIntake(root, doc = document, win = window) {
    const details = root.querySelector('[data-repair-intake]');
    if (!details) return () => {};
    const summary = details.querySelector('summary');
    const form = details.querySelector('form');
    const body = details.querySelector('[data-repair-intake-body]');
    const panes = [...root.querySelectorAll('[data-repair-rows]')];
    let shell = root.parentElement;
    while (shell && shell !== doc.body && !/auto|scroll/.test(win.getComputedStyle(shell).overflowY)) shell = shell.parentElement;
    if (shell === doc.body) shell = win;
    const position = () => shell === win ? win.scrollY : shell.scrollTop;
    const scrollTo = top => shell.scrollTo({ top, behavior: 'instant' });
    let frame = 0, active = true, upwardIntent = false, upwardPane = null, touchY = 0;
    let animation = null, expanded = details.open;

    const stopMotion = () => {
        if (animation) { animation.onfinish = null; animation.cancel(); animation = null; }
        details.removeAttribute('data-intake-animating');
        details.removeAttribute('data-intake-closing');
        form.inert = false;
    };

    const resetFit = () => {
        root.removeAttribute('data-repair-fit');
        root.removeAttribute('data-repair-table-fit');
        root.removeAttribute('data-repair-intake-pin');
        root.style.removeProperty('--repair-workspace-height');
        root.style.removeProperty('--repair-table-height');
        root.style.removeProperty('--repair-intake-top');
    };
    const fit = () => {
        resetFit();
        if (win.innerHeight < 560) return;
        const bottom = shell === win ? win.innerHeight : Math.min(win.innerHeight, shell.getBoundingClientRect().bottom);
        const top = root.getBoundingClientRect().top + position();
        const available = Math.floor(bottom - top + 1);
        const padding = win.getComputedStyle(root);
        const gap = win.getComputedStyle(root.querySelector('.repairs-layout'));
        const border = win.getComputedStyle(details);
        const number = value => Number.parseFloat(value) || 0;
        const collapsedIntake = summary.getBoundingClientRect().height
            + number(border.borderTopWidth) + number(border.borderBottomWidth);
        // Budget against the collapsed heading once, even while the form is open.
        const height = available - number(padding.paddingTop) - number(padding.paddingBottom)
            - number(gap.rowGap) - collapsedIntake;
        root.style.setProperty('--repair-table-height', `${height}px`);
        root.setAttribute('data-repair-table-fit', '');
        const pane = panes.find(p => p.getClientRects().length);
        if (!pane || pane.clientHeight < 180) return resetFit();
        // Keep a fully visible intake still during the scroll preceding collapse.
        if (details.getBoundingClientRect().height <= available - number(padding.paddingTop) - number(padding.paddingBottom)) {
            const origin = shell === win ? 0 : shell.getBoundingClientRect().top;
            root.style.setProperty('--repair-intake-top', `${top + number(padding.paddingTop) - origin}px`);
            root.setAttribute('data-repair-intake-pin', '');
        }
        if (!details.open) {
            // One pixel retains the native scroll-back-to-top path.
            root.style.setProperty('--repair-workspace-height', `${available}px`);
            root.setAttribute('data-repair-fit', '');
        }
    };
    const change = (next, immediate = false) => {
        const from = details.open ? body.getBoundingClientRect().height : 0;
        stopMotion();
        expanded = next;
        upwardIntent = false;
        details.open = true;
        root.removeAttribute('data-repair-fit');
        if (next) for (const pane of panes) pane.scrollTop = 0;
        scrollTo(0);
        const finish = () => {
            details.open = next;
            fit();
            if (!next) scrollTo(0);
        };
        if (immediate || !body.animate || win.matchMedia?.('(prefers-reduced-motion: reduce)')?.matches) return finish();
        const to = next ? body.getBoundingClientRect().height : 0;
        details.setAttribute('data-intake-animating', '');
        if (!next) details.setAttribute('data-intake-closing', '');
        form.inert = !next;
        // The heading stays outside the reveal, including its border and padding.
        animation = body.animate([{ height: `${from}px` }, { height: `${to}px` }], {
            duration: next ? 260 : 220, easing: 'cubic-bezier(0.16, 1, 0.3, 1)', fill: 'both',
        });
        animation.onfinish = () => { stopMotion(); finish(); };
    };
    const open = () => change(true);
    const close = () => change(false);
    const revealInvalid = () => change(true, true);
    const measure = () => {
        frame = 0;
        if (!active || !root.isConnected || animation) return;
        const current = position();
        if (details.open && current > 24 && !form.contains(doc.activeElement) && root.dataset.intakeErrors !== 'true') close();
        else if (!details.open && current <= 1 && upwardIntent && (!upwardPane || upwardPane.scrollTop === 0)) open();
    };
    const schedule = () => { if (active && !frame) frame = win.requestAnimationFrame(measure); };
    const toggle = event => { event.preventDefault(); expanded ? close() : open(); };
    const resize = () => { if (animation) change(expanded, true); else fit(); };
    // Focus/scrollIntoView can move the one-pixel return path before a summary click.
    // Only a browsing gesture may auto-open; otherwise that click would close it again.
    const intent = (up, event) => {
        upwardIntent = up;
        upwardPane = event.target?.closest?.('[data-repair-rows]') ?? null;
        if (up) schedule();
    };
    const gestures = [
        ['wheel', e => intent(e.deltaY < 0, e)],
        ['keydown', e => intent(['ArrowUp', 'PageUp', 'Home'].includes(e.key), e)],
        ['touchstart', e => { touchY = e.touches[0]?.clientY ?? 0; upwardIntent = false; }],
        ['touchmove', e => { const y = e.touches[0]?.clientY ?? touchY; intent(y > touchY, e); touchY = y; }],
        ['pointerdown', e => intent(e.target === shell, e)],
    ];
    for (const [type, handler] of gestures) shell.addEventListener(type, handler, { passive: true });
    shell.addEventListener('scroll', schedule, { passive: true });
    summary.addEventListener('click', toggle);
    form.addEventListener('invalid', revealInvalid, true);
    win.addEventListener('resize', resize);
    // Ignore our own in-flight layout changes; a real viewport resize still settles motion.
    const observer = win.ResizeObserver ? new win.ResizeObserver(() => { if (!animation) fit(); }) : null;
    for (const el of [...root.querySelectorAll('[data-repair-measure], summary'), body]) observer?.observe(el);
    fit();
    schedule();
    return () => {
        active = false;
        win.cancelAnimationFrame(frame);
        stopMotion();
        shell.removeEventListener('scroll', schedule);
        for (const [type, handler] of gestures) shell.removeEventListener(type, handler);
        summary.removeEventListener('click', toggle);
        form.removeEventListener('invalid', revealInvalid, true);
        win.removeEventListener('resize', resize);
        observer?.disconnect();
        details.open = true;
        resetFit();
    };
}

const initialized = new WeakSet();
export function initializeRepairIntakes(doc = document, win = window) {
    if (initialized.has(doc)) return;
    initialized.add(doc);
    let dispose = [];
    const clear = () => { dispose.forEach(fn => fn()); dispose = []; };
    const mount = () => {
        clear();
        dispose = [...doc.querySelectorAll('[data-repair-intake-workspace]')].map(root => createRepairIntake(root, doc, win));
    };
    doc.addEventListener('turbo:load', mount);
    doc.addEventListener('turbo:before-cache', clear);
    doc.addEventListener('turbo:before-render', clear);
    if (doc.readyState === 'loading') doc.addEventListener('DOMContentLoaded', mount, { once: true });
    else mount();
}
