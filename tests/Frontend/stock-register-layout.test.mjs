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
