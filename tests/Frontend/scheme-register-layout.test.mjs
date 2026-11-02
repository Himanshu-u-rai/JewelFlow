import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import postcss from 'postcss';

const blade = readFileSync(new URL('../../resources/views/schemes/index.blade.php', import.meta.url), 'utf8');
const css = postcss.parse(readFileSync(new URL('../../resources/css/app.css', import.meta.url), 'utf8'));

test('Schemes reuses the Customers header and filter controls without a second mobile form or body scroll lock', () => {
    for (const name of ['customers-directory-header', 'customers-directory-head', 'customers-directory-summary', 'customers-directory-controls', 'customers-directory-status']) {
        assert.ok(blade.includes(name), `missing shared ${name}`);
    }
    assert.equal([...blade.matchAll(/<form\b/g)].length, 1);
    assert.ok(blade.includes('<x-responsive-filter-panel id="schemes-filter-panel"'));
    assert.doesNotMatch(blade, /schemesFiltersOpen|schemes-filter-sheet|schemes-mobile-search|overflow-hidden',/);
});

test('phone title and Filters share a row while all three summary values use the full row below', () => {
    const phone = css.nodes.find(node => node.type === 'atrule' && node.params === '(max-width: 768px)'
        && node.nodes.some(rule => rule.selector === '.schemes-index-page .schemes-register-head .jf-filter-toggle'));
    assert.ok(phone);
    const value = (selector, property) => phone.nodes.find(rule => rule.selector === selector)
        ?.nodes.find(decl => decl.prop === property)?.value;
    assert.equal(value('.schemes-index-page .schemes-register-head > .customers-register-titleblock h2', 'grid-row'), '1');
    assert.equal(value('.schemes-index-page .schemes-register-head .jf-filter-toggle', 'grid-row'), '1');
    assert.equal(value('.schemes-index-page .schemes-register-head .customers-directory-summary', 'grid-column'), '1 / -1');
    assert.equal(value('.schemes-index-page .schemes-register-head .customers-directory-summary', 'grid-template-columns'), 'repeat(3, minmax(0, 1fr))');
});
