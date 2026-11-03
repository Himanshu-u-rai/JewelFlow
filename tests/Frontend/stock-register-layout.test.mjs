import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import postcss from 'postcss';

const css = postcss.parse(readFileSync(new URL('../../resources/css/app.css', import.meta.url), 'utf8'));
const value = (selector, prop, media) => {
    let result;
    css.walkRules(rule => {
        if (rule.selectors.includes(selector) && (rule.parent.type === 'atrule' ? rule.parent.params : undefined) === media) {
            for (const node of rule.nodes) if (node.prop === prop) result = node.value;
        }
    });
    return result;
};

test('stock popup keeps full-width search and native controls outside scrolling records', () => {
    assert.equal(value('.inventory-items-page .items-register-filters', 'display'), 'grid');
    assert.equal(value('.inventory-items-page .items-register-search', 'grid-column'), '1 / -1');
    assert.equal(value('.inventory-items-page .items-register-search', 'min-width'), '0');
    assert.equal(value('.inventory-items-page .items-stock-register .items-register-frame', 'overflow'), 'visible');
    assert.equal(value('.inventory-items-page .items-register-header', 'z-index'), '3');
});

test('phone popup filters use one readable column and 44px controls without body overflow locks', () => {
    const phone = '(max-width: 768px)';
    assert.equal(value('.inventory-items-page .items-register-filters', 'grid-template-columns', phone), 'minmax(0, 1fr)');
    assert.equal(value('.inventory-items-page .items-register-search', 'grid-column'), '1 / -1');
    assert.equal(value('.inventory-items-page .items-register-search', 'min-width'), '0');
    assert.equal(value('.inventory-items-page .items-register-control', 'min-height'), '44px');
    assert.equal(value('.inventory-items-page .items-register-control', 'font-size', phone), '16px');
});

test('wide stock header aligns title, filter button and metrics without an inline filter form', () => {
    const wide = '(min-width: 1280px)';
    assert.equal(value('.inventory-items-page .items-register-header', 'display', wide), 'flex');
    assert.equal(value('.inventory-items-page .items-register-header', 'flex-wrap', wide), 'wrap');
    assert.equal(value('.inventory-items-page .items-register-header', 'align-items', wide), 'center');
    assert.equal(value('.inventory-items-page .items-register-heading', 'flex', wide), '0 1 auto');
    assert.equal(value('.inventory-items-page .items-register-filters', 'flex', wide), undefined);
});

test('only desktop moves stock navigation to the header and keeps shrinking/wrapping controls reachable', () => {
    const desktop = '(min-width: 1024px)';
    assert.equal(value('.inventory-items-header .items-header-nav', 'display'), 'none');
    assert.equal(value('.inventory-items-header .items-header-nav', 'display', desktop), 'flex');
    assert.equal(value('.inventory-items-page .items-body-nav', 'display', desktop), 'none');
    assert.equal(value('.inventory-items-header .items-header-nav', 'flex-wrap', desktop), 'wrap');
    assert.equal(value('.inventory-items-header .page-actions', 'min-width', desktop), '0');
    assert.equal(value('.inventory-items-header .items-header-nav', 'min-width', desktop), '0');
});

test('sticky stock headings are confined below filter menus instead of painting over Category options', () => {
    const scroll = '.inventory-items-page .items-register-frame .items-stock-table-shell';
    assert.equal(value(scroll, 'position'), 'relative');
    assert.equal(value(scroll, 'z-index'), '0');
    assert.ok(Number(value('.inventory-items-page .items-register-header', 'z-index')) > Number(value(scroll, 'z-index')));
});

test('stock filters open a native modal, focus search and close before Turbo caches or replaces it', () => {
    const source = readFileSync(new URL('../../resources/views/inventory/items/index.blade.php', import.meta.url), 'utf8');
    const button = source.match(/<button\b[^>]*data-stock-filter-open[^>]*>/)?.[0];
    const dialog = source.match(/<dialog\b[^>]*id="stock-filter-dialog"[^>]*>/)?.[0];
    assert.ok(button);
    assert.ok(dialog);
    let opened = 0, closed = 0, focused = 0;
    const element = { dataset: {}, close: () => closed++, showModal: () => opened++, querySelector: selector => {
        assert.equal(selector, '[name=search]'); return { focus: () => focused++ };
    } };
    const run = (markup, attr) => {
        const expression = markup.match(new RegExp(`${attr}="([^"]+)"`))?.[1];
        assert.ok(expression, attr);
        new Function('$refs', '$el', 'window', expression)({ stockFilters: element }, element, { matchMedia: () => ({ matches: true }) });
    };
    run(dialog, 'x-init');
    assert.equal(element.dataset.ready, 'true');
    run(button, '@click');
    assert.equal(opened, 1);
    assert.equal(focused, 1);
    run(dialog, '@turbo:before-cache.document');
    run(dialog, '@turbo:before-render.document');
    assert.equal(closed, 4);
    const close = source.match(/<button\b[^>]*aria-label="Close stock filters"[^>]*>/)?.[0];
    assert.ok(close);
    run(close, '@click');
    assert.equal(closed, 5);
    assert.doesNotMatch(dialog, /@cancel.prevent|@keydown.tab/);
    assert.equal(value('.inventory-items-page .items-register-filter-dialog[data-ready]:not([open])', 'display'), 'none');
    assert.equal(value('.inventory-items-page .items-register-filter-dialog[data-ready]', 'overflow-y'), 'auto');
});

test('expanding one sell-trend disclosure does not stretch the closed neighbouring card', () => {
    assert.equal(value('.inventory-items-page .items-sellers-tables-grid', 'align-items'), 'start');
});

test('shared stock navigation wraps one tab strip and moves out of phone headers without clipped controls', () => {
    assert.equal(value('.stock-workspace-nav .items-view-toggle', 'flex-wrap'), 'wrap');
    assert.equal(value('.stock-workspace-nav .items-view-toggle', 'overflow'), 'visible');
    assert.equal(value('.stock-workspace-nav.items-body-nav', 'display', '(min-width: 1024px)'), 'none');
    assert.equal(value('.stock-workspace-nav .items-view-toggle', 'grid-template-columns', '(max-width: 768px)'), 'repeat(2, minmax(0, 1fr))');
    assert.equal(value('.stock-workspace-nav :is(.items-view-tab, .items-page-link)', 'min-height', '(max-width: 768px)'), '44px');
    assert.equal(value('.stock-workspace-nav :is(.items-view-tab, .items-page-link)', 'white-space', '(max-width: 768px)'), 'normal');
});

test('stock tabs keep a stable desktop origin independently of each page action width', () => {
    const desktop = '(min-width: 1024px)';
    assert.equal(value('.inventory-items-header', 'grid-template-columns', desktop), '160px minmax(0, 1fr) 40px');
    assert.equal(value('.inventory-items-header .page-actions', 'display', desktop), 'grid');
    assert.equal(value('.inventory-items-header .page-actions', 'grid-template-columns', desktop), 'minmax(0, 1fr) 200px');
    assert.equal(value('.inventory-items-header .page-actions', 'width', desktop), '100%');
    assert.equal(value('.inventory-items-header .items-header-nav', 'justify-content', desktop), 'flex-start');
    assert.equal(value('.inventory-items-header .stock-header-actions', 'min-height'), '44px');
    assert.equal(value('.inventory-items-header .stock-header-title', 'min-height'), '52px');
});

test('narrow stock headers reserve the same action row even when a tool has no action', () => {
    const narrow = '(max-width: 1023px)';
    assert.equal(value('.content-header.inventory-items-header', 'display', narrow), 'grid');
    assert.equal(value('.inventory-items-header .page-actions', 'grid-row', narrow), '2');
    assert.equal(value('.inventory-items-header .page-actions', 'grid-column', narrow), '1 / -1');
    assert.equal(value('.inventory-items-header .stock-header-actions', 'min-height'), '44px');
});

test('moving stock actions into their group preserves the pricing bell icon geometry and focus styling', () => {
    const bell = '.inventory-items-header .stock-header-actions > #pricing-alert-bell';
    assert.equal(value(bell, 'width'), '40px');
    assert.equal(value(bell, 'padding'), '0');
    assert.equal(value(bell + ' > svg', 'width'), '20px');
    assert.equal(value(bell + ':focus-visible', 'outline-offset'), '2px');
});

test('stock navigation uses real links rather than client-only tabs or custom history code', () => {
    const nav = readFileSync(new URL('../../resources/views/inventory/items/partials/navigation.blade.php', import.meta.url), 'utf8');
    assert.doesNotMatch(nav, /@click|role="tab|pushState|replaceState/);
    assert.match(nav, /inventory\.stock-aging/);
    assert.match(nav, /inventory\.sell-trend/);
});
