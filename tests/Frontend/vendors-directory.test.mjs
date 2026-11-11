import assert from 'node:assert/strict';
import { readFileSync, existsSync } from 'node:fs';
import test from 'node:test';
import postcss from 'postcss';

const view = readFileSync(new URL('../../resources/views/vendors/index.blade.php', import.meta.url), 'utf8');
const path = new URL('../../resources/css/vendors-directory.css', import.meta.url);
const css = postcss.parse(existsSync(path) ? readFileSync(path, 'utf8') : '');
const value = (selector, prop, media) => {
    let found;
    css.walkRules(rule => {
        if (rule.selectors.includes(selector) && (rule.parent.type === 'atrule' ? rule.parent.params : undefined) === media) {
            rule.walkDecls(prop, decl => { found = decl.value; });
        }
    });
    return found;
};

test('Vendor directory replaces separate KPI cards with wrapping Vault-style header metrics', () => {
    assert.doesNotMatch(view, /<style|vendors-stats-grid|vendors-stat-card/);
    assert.match(view, /<header[^>]*data-vendor-register-head/);
    assert.match(view, /<dl[^>]*aria-label="Vendor summary"/);
    assert.match(readFileSync(new URL('../../resources/css/app.css', import.meta.url), 'utf8'), /@import "\.\/vendors-directory.css"/);
    assert.equal(value('.vendor-directory .vendors-metric', 'flex-wrap'), 'wrap');
    assert.equal(value('.vendor-directory .vendors-metric', 'padding'), '6px 10px');
    assert.equal(value('.vendor-directory .vendors-metric', 'border-radius'), '8px');
    assert.equal(value('.vendor-directory .vendors-metric dd', 'overflow-wrap'), 'anywhere');
});

test('Vendor filters use a small always-visible search field, bottom sheet and viewport dropdown', () => {
    assert.match(view, /<x-responsive-filter-panel id="vendors-filter-panel" label="Vendor filters">/);
    assert.match(view, /data-enhance-selects-placement="viewport"/);
    assert.doesNotMatch(view, /searchOpen|vendors-search-toggle|vendors-search-panel|\$refs.search/);
    assert.match(view, /class="vendors-search-icon" aria-hidden="true"/);
    assert.match(view, /data-suggest="vendors"/);
    const phone = 'screen and (max-width: 768px)';
    assert.equal(value('.vendor-directory .vendors-search-field', 'width'), '220px');
    assert.equal(value('.vendor-directory .vendors-search-field', 'width', phone), '100%');
    assert.equal(value('.vendor-directory .vendors-search-icon', 'pointer-events'), 'none');
    assert.equal(value('.vendor-directory .vendors-filter-form', 'grid-template-columns', phone), 'minmax(0, 1fr)');
    assert.equal(value('.vendor-directory .vendors-filter-input', 'font-size', phone), '16px');
});

test('Apply and Clear share a single action slot, with edits restoring Apply', () => {
    const form = view.match(/<form\b[^>]*class="vendors-filter-form"[\s\S]*?<\/form>/)?.[0];
    assert.ok(form);
    const apply = form.match(/<button\b[^>]*data-vendor-filter-apply[^>]*>/)?.[0];
    const clear = form.match(/<a\b[^>]*data-vendor-filter-clear[^>]*>/)?.[0];
    assert.ok(apply); assert.ok(clear);
    assert.match(clear, /x-cloak/);
    const visible = (tag, state) => new Function('dirty', 'hasFilters', `return (${tag.match(/x-show="([^"]+)"/)[1]});`)(state.dirty, state.hasFilters);
    for (const hasFilters of [false, true]) {
        const state = { dirty: false, hasFilters };
        assert.equal(visible(apply, state), !hasFilters);
        assert.equal(visible(clear, state), hasFilters);
        for (const event of ['input', 'change']) {
            state.dirty = false;
            new Function(`with (this) { ${form.match(new RegExp(`@${event}="([^"]+)"`))[1]} }`).call(state);
            assert.equal(visible(apply, state), true);
            assert.equal(visible(clear, state), false);
        }
    }
});

test('Records retain shared scrolling, mobile wrapping and visible touch-friendly actions', () => {
    assert.match(view, /data-list-workspace/);
    assert.match(view, /data-list-frame/);
    assert.match(view, /data-list-scroll tabindex="0" role="region" aria-label="Vendor records"/);
    assert.match(view, /<footer class="vendors-pagination"/);
    assert.equal(value('.vendor-directory .vendors-list-card', 'overflow'), 'visible');
    assert.equal(value('.vendor-directory .vendors-table-wrap', 'overflow'), 'auto');
    const phone = 'screen and (max-width: 768px)';
    assert.equal(value('.vendor-directory .vendor-mobile-grid dd', 'overflow-wrap', phone), 'anywhere');
    assert.equal(value('.vendor-directory .jf-row-action', 'min-height', phone), '44px');
    assert.equal(value('body.app-shell .content-header.vendors-page-header', 'grid-template-columns', phone), '44px minmax(0, 1fr) auto 44px');
});
