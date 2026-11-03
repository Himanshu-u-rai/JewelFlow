import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const source = readFileSync(new URL('../../resources/views/inventory/items/index.blade.php', import.meta.url), 'utf8');

test('stock filter action switches from Apply to Clear after applying and back after editing', () => {
    const form = source.match(/<form\b[^>]*data-stock-filters[\s\S]*?<\/form>/)?.[0];
    assert.ok(form);
    const apply = form.match(/<button\b[^>]*data-stock-filter-apply[^>]*>/)?.[0];
    const clear = form.match(/<a\b[^>]*data-stock-filter-clear[^>]*>/)?.[0];
    assert.ok(apply, 'Apply must remain a normal form submit');
    assert.ok(clear, 'Clear must remain a normal navigation');
    const visible = (tag, state) => new Function('dirty', 'hasFilters', `return (${tag.match(/x-show="([^"]+)"/)[1]});`)(state.dirty, state.hasFilters);
    const state = { dirty: false, hasFilters: false };
    assert.equal(visible(apply, state), true);
    assert.equal(visible(clear, state), false);
    state.hasFilters = true; // state delivered by the filtered GET response
    assert.equal(visible(apply, state), false);
    assert.equal(visible(clear, state), true);
    for (const event of ['input', 'change']) {
        state.dirty = false;
        new Function(`with (this) { ${form.match(new RegExp(`@${event}="([^"]+)"`))[1]} }`).call(state);
        assert.equal(visible(apply, state), true);
        assert.equal(visible(clear, state), false);
    }
});
