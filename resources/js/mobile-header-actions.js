// Phone page headers stay one row (owner, 2026-11-15):
//   - Back, Cancel and parent links ("All reports", "← Vault") are hidden: the phone and the browser have their own
//     back. A record page (its path ends in an id) keeps one as an arrow, since it is usually reached by saving a
//     form and the phone's back would return to that form.
//   - The main action (Add, New, Create, Issue, Enroll, Start) and the search toggle stay, as icons.
//   - Every other action moves into the page's floating button: the page's own one when it has one.
// Markup decides over these label rules: data-mobile-action="back|primary|fab" on an action, and
// data-mobile-back="keep|hide" on the header. data-mobile-header="manual" leaves a header alone.
// The header keeps the real buttons (hidden on phones); the floating button holds stand-ins that forward the click.

const PHONE = '(max-width: 768px)';
const PAGE_FAB_NAVS = '.customers-show-mobile-fab-nav, .dash-mobile-fab-nav, .invoice-emi-mobile-fab-nav, .pos-mobile-fab-nav';
const BACK = /^(←|back\b|cancel\b|all\s)/i;
const PRIMARY = /^(\+\s*)?(add|new|create|issue|enroll|start)\b/i;
const SEARCH = /search/i;
const ARROW = '<svg class="jf-header-back-arrow" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m12 19-7-7 7-7"/><path d="M19 12H5"/></svg>';

export function isRecordPage(path) {
    return /\/(\d+|[0-9A-HJKMNP-TV-Z]{26})\/?$/.test(path);
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
    document.querySelectorAll('.jf-header-mobile-fab, .jf-header-fab-proxy, .jf-header-back-arrow').forEach((node) => node.remove());
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
        if (header.closest('.admin-shell') || header.classList.contains('dh-page-header')) return;
        if (header.dataset.mobileHeader === 'manual') return;

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
        if (!entries.length) return;

        const plan = classifyHeaderActions(entries, { recordPage, backPolicy: header.dataset.mobileBack || null });

        // A header with no family class of its own takes the shared compact phone layout.
        if (Array.from(header.classList).every((name) => name === 'content-header')) header.classList.add('jf-header-auto-mobile');

        actions.classList.add('jf-header-fab-source');
        const groups = shown.filter((node) => !isAction(node));
        groups.forEach((node) => node.classList.add('jf-header-fab-pinned'));
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
