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
    let animation = null, expanded = details.open, steady = null, motionFrame = 0, needsFit = false;

    const offset = (value, syncScroll = false) => {
        if (!steady) return;
        // Match native scrollTop's pixel rounding so the heading cannot shimmer.
        steady.offset = Math.max(0, Math.min(steady.height, Math.round(value)));
        root.style.setProperty('--repair-scroll-offset', `${steady.offset}px`);
        if (steady.offset) root.setAttribute('data-repair-scrolled', '');
        else root.removeAttribute('data-repair-scrolled');
        // Until the intake has fully collapsed, the rows behind it stay still (CSS), so the wheel collapses the form
        // first instead of scrolling a table that is mostly hidden behind it.
        if (steady.offset >= steady.height) root.setAttribute('data-repair-collapsed', '');
        else root.removeAttribute('data-repair-collapsed');
        if (syncScroll) scrollTo(steady.offset);
    };

    const stopMotion = () => {
        win.cancelAnimationFrame(motionFrame);
        motionFrame = 0;
        if (animation) { animation.onfinish = null; animation.cancel(); animation = null; }
        details.removeAttribute('data-intake-animating');
        details.removeAttribute('data-intake-closing');
        form.inert = false;
    };

    const resetFit = () => {
        root.removeAttribute('data-repair-fit');
        root.removeAttribute('data-repair-table-fit');
        root.removeAttribute('data-repair-intake-pin');
        root.removeAttribute('data-repair-steady');
        root.removeAttribute('data-repair-scrolled');
        root.removeAttribute('data-repair-collapsed');
        root.style.removeProperty('--repair-workspace-height');
        root.style.removeProperty('--repair-table-height');
        root.style.removeProperty('--repair-intake-top');
        root.style.removeProperty('--repair-scroll-range');
        root.style.removeProperty('--repair-base-padding');
        root.style.removeProperty('--repair-scroll-offset');
    };
    const fit = () => {
        const previous = steady;
        const current = position();
        resetFit();
        steady = null;
        if (win.innerHeight < 560) return;
        const bottom = shell === win ? win.innerHeight : Math.min(win.innerHeight, shell.getBoundingClientRect().bottom);
        const top = root.getBoundingClientRect().top + position();
        const available = Math.floor(bottom - top);
        const padding = win.getComputedStyle(root);
        const gap = win.getComputedStyle(root.querySelector('.repairs-layout'));
        const border = win.getComputedStyle(details);
        const number = value => Number.parseFloat(value) || 0;
        const collapsedIntake = summary.getBoundingClientRect().height
            + number(border.borderTopWidth) + number(border.borderBottomWidth);
        const wasOpen = details.open;
        details.open = true;
        const fullHeight = details.getBoundingClientRect().height;
        const bodyHeight = body.getBoundingClientRect().height;
        details.open = wasOpen;
        // Budget against the collapsed heading once, even while the form is open.
        const height = available - number(padding.paddingTop) - number(padding.paddingBottom)
            - number(gap.rowGap) - collapsedIntake;
        root.style.setProperty('--repair-table-height', `${height}px`);
        root.setAttribute('data-repair-table-fit', '');
        const pane = panes.find(p => p.getClientRects().length);
        if (!pane || pane.clientHeight < 180) return resetFit();
        // Keep a fully visible intake still during the scroll preceding collapse.
        const intakeFits = fullHeight <= available - number(padding.paddingTop) - number(padding.paddingBottom);
        if (intakeFits) {
            const origin = shell === win ? 0 : shell.getBoundingClientRect().top;
            root.style.setProperty('--repair-intake-top', `${top + number(padding.paddingTop) - origin}px`);
            root.setAttribute('data-repair-intake-pin', '');
        }
        if (win.innerWidth >= 1024 && intakeFits && bodyHeight > 0
            && root.dataset.intakeErrors !== 'true' && !form.contains(doc.activeElement)) {
            // One stable native track: padding replaces the revealed body as it closes.
            steady = { height: bodyHeight, offset: previous ? current : expanded ? 0 : bodyHeight };
            root.style.setProperty('--repair-workspace-height', `${available}px`);
            root.style.setProperty('--repair-scroll-range', `${bodyHeight}px`);
            root.style.setProperty('--repair-base-padding', `${number(padding.paddingTop)}px`);
            root.setAttribute('data-repair-steady', '');
            offset(steady.offset, true);
            details.open = steady.offset < steady.height;
            return;
        }
        if (!details.open) {
            root.style.setProperty('--repair-workspace-height', `${available}px`);
            root.setAttribute('data-repair-fit', '');
        }
    };
    const change = (next, immediate = false) => {
        const from = steady ? steady.height - Math.min(steady.height, Math.max(0, position()))
            : details.open ? body.getBoundingClientRect().height : 0;
        stopMotion();
        expanded = next;
        upwardIntent = false;
        details.open = true;
        root.removeAttribute('data-repair-fit');
        if (next) for (const pane of panes) pane.scrollTop = 0;
        if (steady) offset(steady.height - from);
        else scrollTo(0);
        const finish = () => {
            if (steady) offset(next ? 0 : steady.height, true);
            stopMotion();
            details.open = next;
            fit();
            if (!steady && !next) scrollTo(0);
        };
        if (immediate || !body.animate || win.matchMedia?.('(prefers-reduced-motion: reduce)')?.matches) return finish();
        const to = next ? steady?.height ?? body.getBoundingClientRect().height : 0;
        if (Math.abs(to - from) < 1) return finish();
        details.setAttribute('data-intake-animating', '');
        if (!next) details.setAttribute('data-intake-closing', '');
        form.inert = !next;
        // The heading stays outside the reveal, including its border and padding.
        animation = body.animate([{ height: `${from}px` }, { height: `${to}px` }], {
            duration: next ? 260 : 220, easing: 'cubic-bezier(0.16, 1, 0.3, 1)', fill: 'both',
        });
        animation.onfinish = finish;
        if (steady) {
            const follow = () => {
                if (!active || !animation || !steady) return;
                offset(steady.height - body.getBoundingClientRect().height, true);
                motionFrame = win.requestAnimationFrame(follow);
            };
            motionFrame = win.requestAnimationFrame(follow);
        }
    };
    const open = () => change(true);
    const close = () => change(false);
    const revealInvalid = () => change(true, true);
    const measure = () => {
        frame = 0;
        if (!active || !root.isConnected || animation) return;
        if (needsFit) { needsFit = false; fit(); }
        const current = position();
        if (steady) offset(current);
        if (details.open && current > 24 && !form.contains(doc.activeElement) && root.dataset.intakeErrors !== 'true') close();
        else if (steady && !details.open && current < steady.height - 1) {
            // Scrolling back up reopens the intake only once the rows are back at row one; from mid-list the page
            // stays collapsed and the list keeps its place (never a jump back to row one).
            if (panes.every(p => p.scrollTop <= 0)) open();
            else offset(steady.height, true);
        }
        else if (!details.open && current <= 1 && upwardIntent && (!upwardPane || upwardPane.scrollTop === 0)) open();
    };
    const schedule = () => { if (active && !frame) frame = win.requestAnimationFrame(measure); };
    const toggle = event => { event.preventDefault(); expanded ? close() : open(); };
    const resize = () => { if (animation) change(expanded, true); else fit(); };
    const refit = () => { needsFit = true; schedule(); };
    // Focus/scrollIntoView can move the page before a summary click.
    // Only a browsing gesture may auto-open; otherwise that click would close it again.
    const intent = (up, event) => {
        upwardIntent = up;
        upwardPane = event.target?.closest?.('[data-repair-rows]') ?? null;
        if (up) schedule();
    };
    // Collapsed, the browser would move the page by a whole wheel step before measure() runs a frame later, so the
    // heading dropped and snapped back on every frame (a shake). The controller takes the upward wheel itself: rows
    // first, then the reveal. While the form animates, wheel steps cannot fight the reveal's own scroll.
    const wheelStep = (e, pane) => e.deltaY * (e.deltaMode === 1 ? 40 : e.deltaMode === 2 ? pane.clientHeight : 1);
    // Zoom, the page header (notice panel) and any other scroller inside the workspace (a dropdown) keep their wheel.
    const ownScroller = target => {
        for (let n = target; n && n !== root; n = n.parentElement) {
            if (!panes.includes(n) && n.scrollHeight > n.clientHeight
                && /auto|scroll/.test(win.getComputedStyle(n).overflowY)) return true;
        }
        return false;
    };
    const wheel = e => {
        const mine = steady && !e.ctrlKey && !e.metaKey && root.contains(e.target) && !ownScroller(e.target);
        if (mine && animation) return e.preventDefault();
        if (mine && !details.open && e.deltaY < 0) {
            const pane = panes.find(p => p.getClientRects().length);
            if (!pane || pane.scrollTop <= 0) { e.preventDefault(); return open(); }
            if (e.target?.closest?.('[data-repair-rows]') !== pane) {
                e.preventDefault();
                pane.scrollTop = Math.max(0, pane.scrollTop + wheelStep(e, pane));
            }
        }
        intent(e.deltaY < 0, e);
    };
    const gestures = [
        ['wheel', wheel],
        ['keydown', e => intent(['ArrowUp', 'PageUp', 'Home'].includes(e.key), e)],
        ['touchstart', e => { touchY = e.touches[0]?.clientY ?? 0; upwardIntent = false; }],
        ['touchmove', e => { const y = e.touches[0]?.clientY ?? touchY; intent(y > touchY, e); touchY = y; }],
        ['pointerdown', e => intent(e.target === shell, e)],
    ];
    for (const [type, handler] of gestures) shell.addEventListener(type, handler, { passive: type !== 'wheel' });
    shell.addEventListener('scroll', schedule, { passive: true });
    summary.addEventListener('click', toggle);
    form.addEventListener('invalid', revealInvalid, true);
    form.addEventListener('focusin', refit);
    form.addEventListener('focusout', refit);
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
        form.removeEventListener('focusin', refit);
        form.removeEventListener('focusout', refit);
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
