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

test('stock header wraps compact filters but keeps a permanently expanded search with room for suggestions', () => {
    assert.equal(value('.inventory-items-page .items-register-filters', 'flex-wrap'), 'wrap');
    assert.equal(value('.inventory-items-page .items-register-category', 'flex-basis'), '180px');
    assert.equal(value('.inventory-items-page .items-register-purity', 'flex-basis'), '112px');
    assert.equal(value('.inventory-items-page .items-register-search', 'flex'), '0 1 256px');
    assert.equal(value('.inventory-items-page .items-register-search', 'min-width'), '192px');
    assert.equal(value('.inventory-items-page .items-stock-register .items-register-frame', 'overflow'), 'visible');
    assert.equal(value('.inventory-items-page .items-register-header', 'z-index'), '3');
});

test('phone filters use two compact columns, full-row search and 44px controls without body overflow locks', () => {
    const phone = '(max-width: 768px)';
    assert.equal(value('.inventory-items-page .items-register-filters', 'grid-template-columns', phone), 'repeat(2, minmax(0, 1fr))');
    assert.equal(value('.inventory-items-page .items-register-search', 'grid-column', phone), '1 / -1');
    assert.equal(value('.inventory-items-page .items-register-search', 'min-width', phone), '0');
    assert.equal(value('.inventory-items-page .items-register-control', 'min-height'), '44px');
    assert.equal(value('.inventory-items-page .items-register-control', 'font-size', phone), '16px');
});

test('wide stock header aligns the heading and filter form in one wrapping row rather than stacking them', () => {
    const wide = '(min-width: 1280px)';
    assert.equal(value('.inventory-items-page .items-register-header', 'display', wide), 'flex');
    assert.equal(value('.inventory-items-page .items-register-header', 'flex-wrap', wide), 'wrap');
    assert.equal(value('.inventory-items-page .items-register-header', 'align-items', wide), 'center');
    assert.equal(value('.inventory-items-page .items-register-heading', 'flex', wide), '0 1 auto');
    assert.equal(value('.inventory-items-page .items-register-filters', 'flex', wide), '1 1 620px');
    assert.equal(value('.inventory-items-page .items-register-filters', 'justify-content', wide), 'flex-end');
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

test('desktop Retail purity is square while phone and manufacturer filters retain their usable sizing', () => {
    const desktop = '(min-width: 769px)';
    assert.equal(value('.inventory-items-page .items-register-purity:has(> input)', 'flex', desktop), '0 0 44px');
    assert.equal(value('.inventory-items-page .items-register-purity > input', 'height', desktop), '44px');
    assert.equal(value('.inventory-items-page .items-register-purity > input', 'padding', desktop), '4px');
    assert.equal(value('.inventory-items-page .items-register-purity > input', 'height'), undefined);
    assert.equal(value('.inventory-items-page .items-register-purity', 'flex-basis'), '112px');
});

test('stock status is a compact pill toggle with a clear selected side and usable touch targets', () => {
    assert.equal(value('.inventory-items-page .items-register-status', 'flex-wrap'), 'nowrap');
    assert.equal(value('.inventory-items-page .items-register-status', 'border-radius'), '999px');
    assert.equal(value('.inventory-items-page .items-register-status-btn', 'min-height'), '28px');
    assert.equal(value('.inventory-items-page .items-register-status-btn', 'min-width'), '56px');
    assert.equal(value('.inventory-items-page .items-register-status', 'background'), 'var(--jf-surface-muted)');
    assert.equal(value('.inventory-items-page .items-register-status-btn.is-active', 'background'), 'var(--jf-surface)');
    assert.equal(value('.inventory-items-page .items-register-status-btn.is-active', 'color'), 'var(--jf-ink)');
    assert.equal(value('.inventory-items-page .items-register-status-btn.is-active', 'border-color'), 'var(--jf-border-strong)');
    assert.equal(value('.inventory-items-page .items-register-status-btn:focus-visible', 'outline'), '2px solid #b45309');
    assert.equal(value('.inventory-items-page .items-register-status-btn', 'min-height', '(pointer: coarse)'), '44px');
});

test('stock inner pill radius overrides the legacy important button-link radius', () => {
    const legacy = '.content-inner a[class*="btn"]';
    const scoped = '.content-inner.inventory-items-page .items-register-status-btn';
    const radius = selector => {
        let result;
        css.walkRules(rule => {
            if (rule.selectors.includes(selector)) {
                rule.walkDecls('border-radius', decl => { result = decl; });
            }
        });
        return result;
    };
    assert.ok(radius(legacy)?.important, 'legacy normalization is important and matches the btn class name');
    assert.equal(radius(scoped)?.value, '999px');
    assert.ok(radius(scoped)?.important, 'three-class scoped override must beat the two-class/attribute legacy selector');
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
