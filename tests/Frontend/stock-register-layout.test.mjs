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
    assert.equal(value('.inventory-items-page .items-register-search', 'flex'), '0 1 320px');
    assert.equal(value('.inventory-items-page .items-register-search', 'min-width'), '240px');
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
