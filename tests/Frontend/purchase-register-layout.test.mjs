import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import postcss from 'postcss';

const blade = readFileSync(new URL('../../resources/views/inventory/purchases/index.blade.php', import.meta.url), 'utf8');
const css = postcss.parse(readFileSync(new URL('../../resources/css/app.css', import.meta.url), 'utf8'));

test('stock purchases uses one shared header filter panel instead of an inline stylesheet, separate filter section or drawer', () => {
    assert.doesNotMatch(blade, /<style\b|Filter stock purchases|sp-mobile-filter-open|mobileFilterOpen|MutationObserver/);
    assert.ok(blade.includes('<x-responsive-filter-panel id="purchases-filter-panel"'));
    assert.equal([...blade.matchAll(/<form method="GET"/g)].length, 1);
    for (const name of ['customers-directory-header', 'customers-directory-head', 'customers-directory-summary', 'customers-directory-controls', 'jf-table-actions']) {
        assert.ok(blade.includes(name));
    }
});

test('phone purchase summaries wrap two-up below the heading/filter row and dates keep two shrinkable columns', () => {
    const phone = css.nodes.find(node => node.type === 'atrule' && node.params === '(max-width: 768px)'
        && node.nodes.some(rule => rule.selector === '.inventory-purchases-page .sp-register-head .jf-filter-toggle'));
    const value = (selector, property) => phone?.nodes.find(rule => rule.selector === selector)?.nodes.find(decl => decl.prop === property)?.value;
    assert.equal(value('.inventory-purchases-page .sp-register-head .jf-filter-toggle', 'grid-row'), '1');
    assert.equal(value('.inventory-purchases-page .sp-register-head .customers-directory-summary', 'grid-column'), '1 / -1');
    assert.equal(value('.inventory-purchases-page .sp-register-head .customers-directory-summary', 'grid-template-columns'), 'repeat(2, minmax(0, 1fr))');
    assert.equal(value('.inventory-purchases-page .sp-date-range', 'grid-template-columns'), 'repeat(2, minmax(0, 1fr))');
    assert.equal(value('.inventory-purchases-page .sp-date-field input', 'font-size'), '16px');
    assert.equal(value('.inventory-purchases-page .sp-mobile-card .btn', 'min-height'), '44px');
});
