import assert from 'node:assert/strict';
import { existsSync, readFileSync } from 'node:fs';
import test from 'node:test';
import postcss from 'postcss';

const view = readFileSync(new URL('../../resources/views/job-orders/index.blade.php', import.meta.url), 'utf8');
const path = new URL('../../resources/css/job-orders-directory.css', import.meta.url);
const css = postcss.parse(existsSync(path) ? readFileSync(path, 'utf8') : '');
const value = (selector, prop, media) => {
    let result;
    css.walkRules(rule => {
        if (rule.selector === selector && (rule.parent.type === 'atrule' ? rule.parent.params : undefined) === media) {
            rule.walkDecls(prop, decl => { result = decl.value; });
        }
    });
    return result;
};

test('register reuses the list viewport and mobile filter sheet without duplicate bespoke filter code', () => {
    assert.match(view, /<x-responsive-filter-panel id="job-orders-filter-panel"/);
    assert.match(view, /data-list-workspace/);
    assert.match(view, /data-list-frame/);
    assert.match(view, /data-list-scroll tabindex="0" role="region"/);
    assert.doesNotMatch(view, /<style|mobileFilterOpen|jo-mobile-filter-drawer|jobwork-kpi-grid/);
    assert.match(readFileSync(new URL('../../resources/css/app.css', import.meta.url), 'utf8'), /@import "\.\/job-orders-directory.css"/);
    assert.equal(value('.job-orders-index-page .jo-register', 'overflow'), 'visible');
    assert.equal(value('.job-orders-index-page .jo-register-head', 'flex-wrap'), 'wrap');
    assert.equal(value('.job-orders-index-page .jo-metric', 'flex-wrap'), 'wrap');
    assert.equal(value('.job-orders-index-page .jo-metric', 'max-inline-size'), '100%');
});

test('only one Apply/Clear action is visible and changing any filter restores Apply', () => {
    const form = view.match(/<form\b[^>]*class="jo-filters"[\s\S]*?<\/form>/)?.[0];
    assert.ok(form);
    const apply = form.match(/<button[^>]*data-job-filter-apply[^>]*>/)?.[0];
    const clear = form.match(/<a[^>]*data-job-filter-clear[^>]*>/)?.[0];
    for (const hasFilters of [true, false]) {
        const state = { dirty: false, hasFilters };
        const visible = tag => new Function('dirty', 'hasFilters', `return (${tag.match(/x-show="([^"]+)"/)[1]});`)(state.dirty, state.hasFilters);
        assert.equal(visible(apply), !hasFilters);
        assert.equal(visible(clear), hasFilters);
        for (const event of ['input', 'change']) {
            state.dirty = false;
            new Function(`with(this) { ${form.match(new RegExp(`@${event}="([^"]+)"`))[1]} }`).call(state);
            assert.equal(visible(apply), true);
            assert.equal(visible(clear), false);
        }
    }
});

test('phone records wrap, touch actions stay usable and filters align without hard-coded heights', () => {
    assert.equal(value('.job-orders-index-page .jo-filters', 'align-items'), 'end');
    assert.equal(value('.job-orders-index-page .jo-mobile-meta dd', 'overflow-wrap', 'screen and (max-width: 1023px)'), 'anywhere');
    assert.equal(value('.job-orders-index-page .jf-row-action', 'min-height'), '44px');
    assert.equal(value('.job-orders-index-page .jo-filter-input', 'font-size', 'screen and (max-width: 768px)'), '16px');
    assert.equal(value('.job-orders-index-page .jo-filters', 'grid-template-columns', 'screen and (max-width: 768px)'), 'minmax(0, 1fr)');
    css.walkDecls('height', decl => {
        if (['.content-inner.job-orders-index-page', '.job-orders-index-page .jo-register', '.job-orders-index-page .jo-records'].includes(decl.parent.selector)) {
            assert.fail(`List height must remain responsive, not ${decl.value}`);
        }
    });
    assert.doesNotMatch(css.toString(), /body\s*\{|overflow:\s*hidden/);
});
