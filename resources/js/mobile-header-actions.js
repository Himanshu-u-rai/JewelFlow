// Phone and tablet page headers stay one row (owner, 2026-11-15):
//   - Back, Cancel and parent links ("All reports", "← Vault") are hidden: the phone and the browser have their own
//     back. A record page (its path ends in an id) keeps one as an arrow, since it is usually reached by saving a
//     form and the phone's back would return to that form.
//   - The main action (Add, New, Create, Issue, Enroll, Start) and the search toggle stay, as icons.
//   - Every other action moves into the page's floating button: the page's own one when it has one.
// Markup decides over these label rules: data-mobile-action="back|primary|fab" on an action, and
// data-mobile-back="keep|hide" on the header. data-mobile-header="manual" leaves a header alone.
// The header keeps the real buttons (hidden on phones); the floating button holds stand-ins that forward the click.

// Phones and tablets (owner extended it to tablets, 2026-11-15); the app's tablet layout ends at 1024px.
const PHONE = '(max-width: 1024px)';
const PAGE_FAB_NAVS = '.customers-show-mobile-fab-nav, .dash-mobile-fab-nav, .invoice-emi-mobile-fab-nav, .pos-mobile-fab-nav';
const BACK = /^(←|back\b|cancel\b|all\s)/i;
const PRIMARY = /^(\+\s*)?(add|new|create|issue|enroll|start)\b/i;
const SEARCH = /search/i;
const ARROW = '<svg class="jf-header-back-arrow" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m12 19-7-7 7-7"/><path d="M19 12H5"/></svg>';

export function isRecordPage(path) {
    return /\/(\d+|[0-9A-HJKMNP-TV-Z]{26})\/?$/.test(path);
}

// Phones and tablets: only a sentence that describes the page waits behind the info button beside the title.
// Information stays in sight (owner, 2026-11-15: "be logical before you move a thing in i button"):
//   - a page about a record (an id anywhere in its path) shows that record's details ("Karigar: … · DC: …");
//   - a subtitle with a number in it is a date, a period or an amount ("01 Nov 2026 – 30 Nov 2026 · Detailed").
// data-mobile-subtitle="keep|info|hide" on the header decides instead; "hide" is for a page that shows the same
// information elsewhere on phones (the dashboard's date sits in its shop profile there).
export function subtitleMode(path, data = {}, text = '') {
    if (['keep', 'info', 'hide'].includes(data.mobileSubtitle)) return data.mobileSubtitle;
    if (/\/(\d+|[0-9A-HJKMNP-TV-Z]{26})(\/|$)/.test(path)) return 'keep';
    return /\d/.test(text) ? 'keep' : 'info';
}

const INFO = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>';
const CLOSE = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>';
let infoIds = 0;

function tuckSubtitle(header) {
    const title = header.querySelector('.page-title');
    const subtitle = header.querySelector('.page-subtitle');
    if (!title || !subtitle || !subtitle.textContent.trim()) return;

    const mode = subtitleMode(window.location.pathname, header.dataset, subtitle.textContent);
    if (mode === 'keep') return;
    if (mode === 'hide') {
        subtitle.classList.add('jf-subtitle-tucked');
        subtitle.style.setProperty('display', 'none', 'important');
        return;
    }

    // The description opens as a floating card under the header, with its own close button (owner, 2026-11-15):
    // the header never grows. Inline and important: page CSS shows some subtitles with !important.
    subtitle.classList.add('jf-subtitle-tucked');
    subtitle.style.setProperty('display', 'none', 'important');

    infoIds += 1;
    const card = document.createElement('div');
    card.className = 'jf-page-about';
    card.id = `jf-page-about-${infoIds}`;
    card.hidden = true;
    card.setAttribute('role', 'dialog');
    card.setAttribute('aria-label', 'About this page');
    const text = document.createElement('p');
    text.className = 'jf-page-about-text';
    text.textContent = subtitle.textContent.replace(/\s+/g, ' ').trim();
    const close = document.createElement('button');
    close.type = 'button';
    close.className = 'jf-page-about-close';
    close.setAttribute('aria-label', 'Close');
    close.insertAdjacentHTML('afterbegin', CLOSE);
    card.append(text, close);
    document.body.appendChild(card);

    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'jf-header-info';
    button.setAttribute('aria-label', 'About this page');
    button.setAttribute('aria-expanded', 'false');
    button.setAttribute('aria-controls', card.id);
    button.insertAdjacentHTML('afterbegin', INFO);

    const onOutside = (event) => {
        if (!card.contains(event.target) && !button.contains(event.target)) setOpen(false);
    };
    const onKey = (event) => {
        if (event.key === 'Escape') setOpen(false, true);
    };
    function setOpen(open, returnFocus = false) {
        button.setAttribute('aria-expanded', open ? 'true' : 'false');
        card.hidden = !open;
        if (open) {
            // Under the header, wherever the page has scrolled it (the header is sticky on most pages).
            card.style.top = `${Math.max(8, Math.round(header.getBoundingClientRect().bottom) + 8)}px`;
            document.addEventListener('pointerdown', onOutside, true);
            document.addEventListener('keydown', onKey);
            close.focus({ preventScroll: true });
        } else {
            document.removeEventListener('pointerdown', onOutside, true);
            document.removeEventListener('keydown', onKey);
            if (returnFocus) button.focus({ preventScroll: true });
        }
    }
    button.addEventListener('click', () => setOpen(button.getAttribute('aria-expanded') !== 'true'));
    close.addEventListener('click', () => setOpen(false, true));
    card.jfClose = () => setOpen(false);
    title.appendChild(button);
}

// A link up to a parent of the current path ("Job Orders" on /job-orders/1) is a Back, whatever its label.
export function isParentLink(href, path, origin = 'http://local.invalid') {
    if (!href) return false;
    let url;
    try {
        url = new URL(href, origin);
    } catch {
        return false;
    }
    if (url.origin !== new URL(origin).origin) return false;
    const parent = url.pathname.replace(/\/+$/, '');
    const current = path.replace(/\/+$/, '');
    return parent !== '' && parent !== current && current.startsWith(parent + '/');
}

// actions: [{ label, data, parent }] in header order. Returns the actions to keep in the header, to move to the floating
// button, to hide (back-like), and the one back-like action kept as an arrow (or null).
export function classifyHeaderActions(actions, { recordPage, backPolicy = null }) {
    const keep = [];
    const fab = [];
    const back = [];
    let keepBack = null;
    let primaryKept = false;
    const keepsBack = backPolicy ? backPolicy === 'keep' : recordPage;

    for (const action of actions) {
        const role = action.data?.mobileAction || null;
        const label = (action.label || '').trim();

        if (role === 'back' || (!role && (action.parent || BACK.test(label)))) {
            if (keepsBack && !keepBack) keepBack = action;
            else back.push(action);
        } else if (role === 'primary') {
            keep.push(action);
            primaryKept = true;
        } else if (role === 'fab') {
            fab.push(action);
        } else if (SEARCH.test(label)) {
            keep.push(action);
        } else if (!primaryKept && PRIMARY.test(label)) {
            keep.push(action);
            primaryKept = true;
        } else {
            fab.push(action);
        }
    }

    return { keep, fab, back, keepBack };
}

function headerActionLabel(node) {
    if (node.tagName.toLowerCase() === 'form') {
        const submitter = node.querySelector('button[type="submit"], button:not([type]), input[type="submit"]');
        const text = (submitter?.textContent || submitter?.value || '').replace(/\s+/g, ' ').trim();
        if (text) return text;
    }

    return (node.getAttribute('aria-label') || node.getAttribute('title') || node.textContent || '')
        .replace(/\s+/g, ' ')
        .trim();
}

function isDanger(node, label) {
    return /delete|archive/i.test(label) || /danger/i.test(node.className || '');
}

// A stand-in for a header action, for the floating button: a link copies the href; a button or form forwards the
// click to the real one (which keeps its own confirm, Alpine handler or submission).
function buildStandIn(node, label, className, onDone) {
    const tag = node.tagName.toLowerCase();
    const standIn = document.createElement(tag === 'a' ? 'a' : 'button');
    standIn.className = `${className} jf-header-fab-proxy`;

    const icon = node.querySelector('svg, img');
    if (icon) standIn.appendChild(icon.cloneNode(true));
    const text = document.createElement('span');
    text.className = 'jf-header-mobile-fab-label';
    text.textContent = label;
    standIn.appendChild(text);
    if (isDanger(node, label)) standIn.classList.add('is-danger');

    if (tag === 'a') {
        standIn.href = node.getAttribute('href') || '#';
        ['target', 'rel', 'data-turbo-frame', 'data-turbo'].forEach((name) => {
            if (node.hasAttribute(name)) standIn.setAttribute(name, node.getAttribute(name));
        });
        standIn.addEventListener('click', onDone);
        return standIn;
    }

    standIn.type = 'button';
    standIn.addEventListener('click', (event) => {
        event.preventDefault();
        onDone();
        if (tag === 'button') {
            node.click();
            return;
        }
        const submitter = node.querySelector('button[type="submit"], button:not([type]), input[type="submit"]');
        if (submitter) submitter.click();
        else if (typeof node.requestSubmit === 'function') node.requestSubmit();
        else node.submit();
    });
    return standIn;
}

function pageFabNav() {
    return Array.from(document.querySelectorAll(PAGE_FAB_NAVS)).find((nav) => {
        const root = nav.closest('[class*="mobile-fab"]:not([class*="-nav"])') || nav;
        return getComputedStyle(root).display !== 'none';
    }) || null;
}

function createFab() {
    const fab = document.createElement('div');
    fab.className = 'jf-header-mobile-fab';
    fab.innerHTML = `
        <div class="jf-header-mobile-fab-shell">
            <nav class="jf-header-mobile-fab-nav" aria-label="Page actions"></nav>
            <button type="button" class="jf-header-mobile-fab-toggle" aria-expanded="false" aria-label="Toggle page actions">
                <span class="jf-header-mobile-fab-bars" aria-hidden="true"><span></span><span></span><span></span></span>
            </button>
        </div>`;
    const shell = fab.querySelector('.jf-header-mobile-fab-shell');
    const toggle = fab.querySelector('.jf-header-mobile-fab-toggle');
    const close = () => {
        shell.classList.remove('is-open');
        toggle.setAttribute('aria-expanded', 'false');
    };
    toggle.addEventListener('click', () => {
        const open = !shell.classList.contains('is-open');
        shell.classList.toggle('is-open', open);
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
    return { fab, nav: fab.querySelector('nav'), close };
}

function makeIconOnly(node, label, isBack) {
    if (!node.querySelector('svg')) {
        if (!isBack) return;
        node.insertAdjacentHTML('afterbegin', ARROW);
    }
    node.classList.add('jf-header-icon-only');
    if (!node.hasAttribute('aria-label')) {
        node.setAttribute('aria-label', label);
        node.dataset.jfAriaAdded = 'true';
    }
}

export function cleanupMobileHeaderActionFabs() {
    document.querySelectorAll('.jf-page-about').forEach((card) => {
        if (typeof card.jfClose === 'function') card.jfClose();
        card.remove();
    });
    document.querySelectorAll('.jf-header-mobile-fab, .jf-header-fab-proxy, .jf-header-back-arrow, .jf-header-info').forEach((node) => node.remove());
    document.querySelectorAll('.jf-subtitle-tucked').forEach((node) => {
        node.classList.remove('jf-subtitle-tucked');
        node.style.removeProperty('display');
    });
    document.querySelectorAll('[data-jf-header-hidden]').forEach((node) => {
        node.style.removeProperty('display');
        delete node.dataset.jfHeaderHidden;
    });
    document.querySelectorAll('[data-jf-aria-added]').forEach((node) => {
        node.removeAttribute('aria-label');
        delete node.dataset.jfAriaAdded;
    });
    document.querySelectorAll('.jf-header-fab-source').forEach((node) => node.classList.remove('jf-header-fab-source'));
    document.querySelectorAll('.jf-header-fab-pinned, .jf-header-icon-only').forEach((node) => node.classList.remove('jf-header-fab-pinned', 'jf-header-icon-only'));
    document.querySelectorAll('.content-header.jf-header-auto-mobile, .content-header.jf-header-compact').forEach((node) => node.classList.remove('jf-header-auto-mobile', 'jf-header-compact'));
}

export function initMobileHeaderActionFabs() {
    cleanupMobileHeaderActionFabs();
    if (!window.matchMedia(PHONE).matches) return;

    let fabFilled = false;
    const recordPage = isRecordPage(window.location.pathname);

    document.querySelectorAll('.content-header').forEach((header) => {
        if (header.closest('.admin-shell')) return;
        if (header.dataset.mobileHeader === 'manual') return;

        tuckSubtitle(header);

        const actions = header.querySelector(':scope > .page-actions');
        if (!actions || actions.querySelector('input:not([type="hidden"]), select, textarea')) return;

        // Only what the page shows on phones: anything its own CSS already hides stays hidden.
        const shown = Array.from(actions.children).filter((node) => node.getClientRects().length > 0);
        const isAction = (node) => node.matches('a, button, form');
        const entries = shown.filter(isAction).map((node) => ({
            node,
            label: headerActionLabel(node),
            data: node.dataset,
            parent: node.matches('a') && isParentLink(node.getAttribute('href'), window.location.pathname, window.location.origin),
        }));
        // A header holding only a status badge still takes the one-row layout below.
        if (!entries.length && !shown.some((node) => node.matches('.header-badge') || !node.querySelector('a, button, nav, form, select, input'))) return;

        const plan = classifyHeaderActions(entries, { recordPage, backPolicy: header.dataset.mobileBack || null });

        // A header with no family class of its own takes the shared compact phone layout.
        if (Array.from(header.classList).every((name) => name === 'content-header')) header.classList.add('jf-header-auto-mobile');

        actions.classList.add('jf-header-fab-source');
        // A badge or status chip (nothing to press inside) sits in the one-row layout; a tab strip does not.
        const isChip = (node) => node.matches('.header-badge') || !node.querySelector('a, button, nav, form, select, input');
        const groups = shown.filter((node) => !isAction(node) && !isChip(node));
        shown.filter((node) => !isAction(node)).forEach((node) => node.classList.add('jf-header-fab-pinned'));
        // Only icons left: one row, whatever grid the page gave its header for full-width buttons. A header that
        // still holds a group (a tab strip, a status) keeps its own layout.
        if (!groups.length) header.classList.add('jf-header-compact');
        // Inline and important: some pages force their header buttons visible with !important rules of their own.
        [...plan.back, ...plan.fab].forEach((entry) => {
            entry.node.style.setProperty('display', 'none', 'important');
            entry.node.dataset.jfHeaderHidden = 'true';
        });
        plan.keep.forEach((entry) => {
            entry.node.classList.add('jf-header-fab-pinned');
            makeIconOnly(entry.node, entry.label, false);
        });
        if (plan.keepBack) {
            plan.keepBack.node.classList.add('jf-header-fab-pinned');
            makeIconOnly(plan.keepBack.node, plan.keepBack.label, true);
        }

        if (!plan.fab.length || fabFilled) return;
        fabFilled = true;

        const ownNav = pageFabNav();
        if (ownNav) {
            const sample = ownNav.querySelector('a, button');
            const className = sample ? sample.className : 'jf-header-mobile-fab-link';
            const has = (entry) => Array.from(ownNav.querySelectorAll('a, button')).some((item) => {
                const href = entry.node.getAttribute('href');
                return (href && item.getAttribute('href') === href)
                    || item.textContent.replace(/\s+/g, ' ').trim().toLowerCase() === entry.label.toLowerCase();
            });
            plan.fab.filter((entry) => !has(entry)).forEach((entry) => {
                ownNav.appendChild(buildStandIn(entry.node, entry.label, className, () => {}));
            });
            return;
        }

        const { fab, nav, close } = createFab();
        plan.fab.forEach((entry) => nav.appendChild(buildStandIn(entry.node, entry.label, 'jf-header-mobile-fab-link', close)));
        document.body.appendChild(fab);
    });
}
