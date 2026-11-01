import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import postcss from 'postcss';

const css = postcss.parse(readFileSync(new URL('../../resources/css/app.css', import.meta.url), 'utf8'));

// Cascade contract for the header's known competing selectors, not a layout engine
// or a substitute for rendered-browser checks. Include the older phone overrides.
const selectors = {
    header: ['.content-header', '.customers-page-header', '.customers-directory-header', '.content-header.customers-directory-header'],
    actions: ['.page-actions', '.content-header .page-actions', '.customers-page-header .page-actions', '.customers-directory-header .page-actions', '.content-header > :nth-child(n+3)', '.customers-page-header > :nth-child(n+3)', '.content-header.customers-directory-header > .page-actions'],
    bell: ['.notice-bell', '.content-header > .notice-bell', '.content-header.customers-directory-header > .notice-bell'],
    bellWithAdd: ['.content-header.customers-directory-header > .notice-bell', '.content-header.customers-directory-header:has(.customers-add-btn) > .notice-bell'],
    add: ['.customers-page-header .customers-add-btn', '.customers-directory-header .customers-add-btn', '.content-header.customers-directory-header .customers-add-btn'],
    addLabel: ['.content-header.customers-directory-header .customers-add-label'],
    addIcon: ['.customers-page-header .customers-add-btn svg', '.content-header.customers-directory-header .customers-add-btn svg'],
    tabs: ['.customers-directory-header .customers-view-toggle', '.content-header.customers-directory-header .customers-view-toggle'],
    directoryTrigger: ['.ui-filter-select-trigger', '.customers-index-page .customers-directory-status .ui-filter-select-trigger'],
    emiTrigger: ['.ui-filter-select-trigger', '.customers-index-page .customers-register-inline-form .ui-filter-select-trigger'],
    directoryMenu: ['.ui-filter-select-menu', '.customers-index-page .customers-directory-status .ui-filter-select-menu'],
    emiMenu: ['.ui-filter-select-menu', '.customers-index-page .customers-register-inline-form .ui-filter-select-menu'],
    directoryChevron: ['.ui-filter-select-chevron', '.customers-index-page .customers-directory-status .ui-filter-select-chevron'],
    emiChevron: ['.ui-filter-select-chevron', '.customers-index-page .customers-register-inline-form .ui-filter-select-chevron'],
    directoryStatus: ['.customers-directory-status'],
    directoryStatusFocus: ['.customers-directory-status', '.customers-directory-status:has(:focus-visible)'],
    directoryMenuScrollbar: ['.customers-index-page .customers-directory-status .ui-filter-select-menu::-webkit-scrollbar'],
    invoiceToolbar: ['.invoices-index-page .invoices-register-toolbar'],
    invoiceTrigger: ['.ui-filter-select-trigger', '.invoices-index-page .customers-directory-status .ui-filter-select-trigger'],
    invoiceMenu: ['.ui-filter-select-menu', '.invoices-index-page .customers-directory-status .ui-filter-select-menu'],
    invoiceMenuScrollbar: ['.invoices-index-page .customers-directory-status .ui-filter-select-menu::-webkit-scrollbar'],
    invoiceDates: ['.invoices-index-page .invoices-date-range'],
    invoiceDateField: ['.invoices-index-page .invoices-filter-field'],
    filterPanel: ['.jf-filter-panel', '.jf-filter-panel[data-ready]'],
    filterPanelOpen: ['.jf-filter-panel', '.jf-filter-panel[data-ready]', '.jf-filter-panel[data-ready]:modal'],
    filterShell: ['.jf-filter-shell'],
};

function cascade(part, width) {
    const values = new Map();
    css.walkRules(rule => {
        for (let parent = rule.parent; parent?.type !== 'root'; parent = parent.parent) {
            if (parent.type !== 'atrule') continue;
            if (parent.name !== 'media' || !/^\(max-width: \d+px\)$/.test(parent.params)) return;
            if (width > Number(parent.params.match(/\d+/)[0])) return;
        }
        for (const selector of rule.selectors) {
            if (!selectors[part].includes(selector)) continue;
            const specificity = (selector.match(/\.[\w-]+|:[\w-]+/g) || []).length;
            rule.walkDecls(declaration => {
                const rank = specificity + (declaration.important ? 100 : 0);
                if (rank >= (values.get(declaration.prop)?.rank ?? -1)) {
                    values.set(declaration.prop, { rank, value: declaration.value });
                }
            });
        }
    });
    return Object.fromEntries([...values].map(([property, declaration]) => [property, declaration.value]));
}

for (const width of [320, 390, 427, 640, 768]) {
    test(`customer header places Add beside the bell and tabs below at ${width}px despite legacy overrides`, () => {
        const header = cascade('header', width);
        const actions = cascade('actions', width);
        const bell = cascade('bell', width);
        assert.equal(header.display, 'grid', 'a nowrap header must not squeeze the title beside the complete tab group');
        assert.equal(actions.display, 'contents', 'each action needs its own place in the header grid');
        assert.equal(cascade('tabs', width)['grid-column'], '1 / -1');
        assert.equal(cascade('tabs', width)['grid-row'], '2');
        assert.equal(cascade('tabs', width).width, '100%');
        assert.equal(bell.position, 'relative', 'the notice bell must reserve its own column instead of overlaying tabs');
        assert.equal(bell['grid-column'], '3');
        assert.equal(bell['grid-row'], '1');
        assert.equal(cascade('bellWithAdd', width)['grid-column'], '4');
        const add = cascade('add', width);
        assert.equal(add['grid-column'], '3');
        assert.equal(add['grid-row'], '1');
        assert.equal(add.width, '44px');
        assert.equal(add['min-height'], '44px');
        assert.equal(cascade('addLabel', width).display, 'none');
        assert.equal(cascade('addIcon', width).margin, '0');
        assert.equal(header.position, 'static', 'a multi-row phone header must scroll away on short or zoomed screens');
    });
}

test('desktop keeps the compact flex header and sticky page context', () => {
    const header = cascade('header', 1280);
    assert.equal(header.display, 'flex');
    assert.equal(header.position, 'sticky');
    assert.equal(cascade('actions', 1280)['grid-row'], undefined);
    assert.equal(cascade('addLabel', 1280).display, undefined, 'desktop retains the full Add Customer label');
});

for (const part of ['Trigger', 'Menu', 'Chevron']) {
    test(`Directory Status ${part.toLowerCase()} shares the EMI filter treatment`, () => {
        for (const width of [427, 1280]) {
            const directory = cascade('directory' + part, width);
            // The owner requested a hidden scrollbar only for Directory Status.
            if (part === 'Menu') delete directory['scrollbar-width'];
            assert.deepEqual(directory, cascade('emi' + part, width));
        }
    });
}

test('Directory Status stays content-sized and capped instead of inheriting wide EMI form columns', () => {
    for (const width of [320, 427, 1280]) {
        const status = cascade('directoryStatus', width);
        assert.equal(status.width, 'max-content');
        assert.equal(status['min-width'], '8rem');
        assert.equal(status['max-width'], '10rem');
        assert.equal(status.flex, '0 1 auto');
    }
});

test('Directory wrapper does not add a second focus ring around the shared EMI trigger', () => {
    assert.equal(cascade('directoryStatusFocus', 1280).outline, undefined);
});

test('Directory menu hides the scrollbar without disabling its scroll container', () => {
    for (const width of [427, 1280]) {
        const menu = cascade('directoryMenu', width);
        assert.equal(menu['scrollbar-width'], 'none');
        assert.equal(menu.overflow, 'auto', 'hiding the scrollbar must not become overflow:hidden');
        assert.equal(cascade('directoryMenuScrollbar', width).display, 'none');
    }
});

test('Invoice filters remain available in the single wrapping form at desktop and phone widths', () => {
    for (const width of [320, 427, 1280]) {
        assert.equal(cascade('invoiceToolbar', width).display, 'flex');
        assert.equal(cascade('invoiceToolbar', width)['flex-wrap'], 'wrap');
    }
});

test('Invoice dropdowns reuse Directory treatment including hidden scrollbar and retained scrolling', () => {
    for (const width of [427, 1280]) {
        assert.deepEqual(cascade('invoiceTrigger', width), cascade('directoryTrigger', width));
        assert.deepEqual(cascade('invoiceMenu', width), cascade('directoryMenu', width));
        assert.equal(cascade('invoiceMenuScrollbar', width).display, 'none');
    }
});

test('Invoice dates use two shrinkable columns together on narrow screens', () => {
    for (const width of [320, 427]) {
        assert.equal(cascade('invoiceDates', width).display, 'grid');
        assert.equal(cascade('invoiceDates', width)['grid-template-columns'], 'repeat(2, minmax(0, 1fr))');
        assert.equal(cascade('invoiceDateField', width).width, '100%');
    }
});

test('Shared filters stay inline on desktop and become a bounded bottom sheet on phones', () => {
    assert.equal(cascade('filterPanel', 1280).position, 'static');
    for (const width of [320, 427]) {
        assert.equal(cascade('filterPanel', width).display, 'none');
        assert.equal(cascade('filterPanelOpen', width).display, 'block');
        assert.equal(cascade('filterPanelOpen', width).position, 'fixed');
        assert.equal(cascade('filterPanelOpen', width).bottom, '0');
        assert.equal(cascade('filterPanelOpen', width)['overflow-y'], 'auto');
    }
});

test('Shared filter scope does not introduce an extra layout box into register headers', () => {
    for (const width of [427, 1280]) assert.equal(cascade('filterShell', width).display, 'contents');
});
