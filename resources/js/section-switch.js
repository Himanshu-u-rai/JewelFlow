// Section switch (owner, 2026-11-15): on phones a page whose sections were a sideways tab strip under the header
// (Settings, Customers, the Stock pages) shows the open section as its title with a down arrow; a tap opens that
// page's own section links or view buttons as a list under the header. The list is the page's markup, not a copy,
// so Turbo frame visits, page links and Alpine view buttons keep working as they did.
//
// Markup: a button[data-section-switch] with aria-controls naming the list; inside it a [data-section-label] (its
// text is the fallback when nothing is open); the list carries data-section-list. CSS decides where it shows.

const CURRENT = (item) => {
    const current = item.getAttribute('aria-current');
    return (current && current !== 'false')
        || item.getAttribute('aria-pressed') === 'true'
        || item.getAttribute('aria-selected') === 'true'
        || item.classList.contains('active')
        || item.classList.contains('is-active');
};

// A count or badge inside a section link ("Reorder Alerts 2") carries data-section-ignore: the title is the name.
const textOf = (item) => {
    if (typeof item.querySelector !== 'function' || !item.querySelector('[data-section-ignore]')) return item.textContent;
    const copy = item.cloneNode(true);
    copy.querySelectorAll('[data-section-ignore]').forEach((node) => node.remove());
    return copy.textContent;
};

export function sectionLabel(items, fallback = 'Settings') {
    const open = items.find(CURRENT);
    return open ? textOf(open).replace(/\s+/g, ' ').trim() : fallback;
}

// Desktop (owner, 2026-11-20): a strip that wraps onto a second row, or runs past its box, becomes the drop-down.
export function stripNeedsSwitch(tabs, list) {
    const shown = tabs.filter((t) => t.getClientRects().length);
    if (!shown.length) return false;
    const first = shown[0].getBoundingClientRect().top;
    return shown.some((t) => Math.abs(t.getBoundingClientRect().top - first) > 2) || list.scrollWidth > list.clientWidth + 1;
}

const bound = new WeakSet();

export function initializeSectionSwitch(doc = globalThis.document, win = globalThis.window) {
    if (!doc || !win || bound.has(doc)) return;
    bound.add(doc);

    const parts = () => Array.from(doc.querySelectorAll('[data-section-switch]')).map((button) => ({
        button,
        list: doc.getElementById(button.getAttribute('aria-controls')),
        label: button.querySelector('[data-section-label]'),
    })).filter((p) => p.list);

    const items = (list) => Array.from(list.querySelectorAll('a, button'));

    const setOpen = (p, open, returnFocus = false) => {
        p.button.setAttribute('aria-expanded', open ? 'true' : 'false');
        p.list.classList.toggle('is-open', open);
        if (open) {
            // Under the header, wherever the page has scrolled it.
            const header = p.button.closest('.content-header');
            const bottom = header ? header.getBoundingClientRect().bottom : 0;
            p.list.style.setProperty('--section-list-top', `${Math.max(8, Math.round(bottom) + 6)}px`);
            (items(p.list).find(CURRENT) || items(p.list)[0])?.focus({ preventScroll: true });
        } else if (returnFocus) {
            p.button.focus({ preventScroll: true });
        }
    };

    // Pages opt in with data-section-fit on the switch. CSS owns phones and tablets (<1024px); on desktop the strip
    // is measured as laid out (compact off) and the header swaps when it would wrap. Re-measured on resize and when
    // the header's width changes (the sidebar opening or closing).
    const widths = new WeakMap();
    const fit = (p) => {
        const header = p.button.hasAttribute('data-section-fit') && p.button.closest('.content-header');
        if (!header) return;
        header.removeAttribute('data-section-compact');
        if (win.innerWidth < 1024) return;
        if (stripNeedsSwitch(items(p.list), p.list)) header.setAttribute('data-section-compact', '');
    };
    const observer = win.ResizeObserver ? new win.ResizeObserver((entries) => entries.forEach((entry) => {
        const width = Math.round(entry.contentRect.width);
        if (widths.get(entry.target) === width) return; // our own swap changes only the height
        widths.set(entry.target, width);
        parts().filter((p) => p.button.closest('.content-header') === entry.target).forEach(fit);
    })) : null;

    const sync = () => parts().forEach((p) => {
        fit(p);
        const header = p.button.closest('.content-header');
        if (observer && header && p.button.hasAttribute('data-section-fit')) observer.observe(header);
        if (p.label) {
            p.label.dataset.fallback ??= p.label.textContent.trim();
            p.label.textContent = sectionLabel(items(p.list), p.label.dataset.fallback);
        }
        setOpen(p, false);
    });

    doc.addEventListener('click', (event) => {
        parts().forEach((p) => {
            if (p.button.contains(event.target)) {
                setOpen(p, p.button.getAttribute('aria-expanded') !== 'true');
                return;
            }
            if (!p.list.classList.contains('is-open')) return;
            if (p.list.contains(event.target)) {
                // A pick: close, and name the new section once the page has marked it (Alpine views switch in place).
                if (event.target.closest('a, button')) win.requestAnimationFrame(() => win.requestAnimationFrame(sync));
                return;
            }
            setOpen(p, false);
        });
    });
    doc.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape') return;
        parts().filter((p) => p.list.classList.contains('is-open')).forEach((p) => setOpen(p, false, true));
    });

    // Settings loads its sections into a Turbo frame; its own script marks the new link active first.
    doc.addEventListener('turbo:load', sync);
    doc.addEventListener('turbo:render', sync);
    doc.addEventListener('turbo:frame-load', () => win.requestAnimationFrame(sync));
    doc.addEventListener('turbo:before-cache', () => parts().forEach((p) => setOpen(p, false)));
    win.addEventListener('resize', () => parts().forEach((p) => { setOpen(p, false); fit(p); }));
    if (doc.readyState === 'loading') doc.addEventListener('DOMContentLoaded', sync, { once: true });
    else sync();
}
