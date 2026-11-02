import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { runInNewContext } from 'node:vm';

const blade = readFileSync(new URL('../../resources/views/customers/index.blade.php', import.meta.url), 'utf8');
const invoices = readFileSync(new URL('../../resources/views/invoices/index.blade.php', import.meta.url), 'utf8');
const schemes = readFileSync(new URL('../../resources/views/schemes/index.blade.php', import.meta.url), 'utf8');
const purchases = readFileSync(new URL('../../resources/views/inventory/purchases/index.blade.php', import.meta.url), 'utf8');

// Run the actual Alpine handlers; DOM focus/nextTick are the only boundaries.
function fixture(source, index) {
    const button = [...source.matchAll(/<button\s+type="button"\s+data-(?:customer|invoice|scheme|purchase)-search-toggle\b[^]*?<\/button>/g)][index]?.[0];
    assert.ok(button, 'Register search needs a named, non-submitting disclosure button.');
    const click = button.match(/@click="([^"]*)"/)?.[1];
    const escape = [...source.matchAll(/@keydown\.escape\.stop\.prevent="([^"]*)"/g)][index]?.[1];
    assert.ok(click);
    assert.ok(escape);
    const pending = [];
    const state = {
        searchOpen: false, inputFocus: 0, toggleFocus: 0,
        $refs: {}, $nextTick: fn => pending.push(fn),
        $el: { closest: () => null },
    };
    state.$refs.searchInput = { value: 'Unsaved customer query', focus: () => state.inputFocus++ };
    state.$refs.searchToggle = { focus: () => state.toggleFocus++ };
    return {
        state,
        click: () => runInNewContext(click, state),
        escape: () => runInNewContext(escape, state),
        flush: () => pending.splice(0).forEach(fn => fn()),
    };
}

for (const [source, index, name] of [[blade, 0, 'Directory'], [blade, 1, 'Loyalty'], [invoices, 0, 'Invoices'], [schemes, 0, 'Schemes'], [purchases, 0, 'Purchases']]) {
    test(`${name}: search Escape closes the native mobile filter dialog without discarding the query`, () => {
        const f = fixture(source, index);
        let closed = 0;
        f.state.$el.closest = () => ({ matches: selector => selector === ':modal', close: () => closed++ });
        f.escape();
        assert.equal(closed, 1);
        assert.equal(f.state.$refs.searchInput.value, 'Unsaved customer query');
        assert.equal(f.state.toggleFocus, 0, 'the hidden phone search icon must not receive restored focus');
    });
}

test('Shared filter initialization, resizing and Turbo cleanup leave the appropriate dialog state', () => {
    const component = readFileSync(new URL('../../resources/views/components/responsive-filter-panel.blade.php', import.meta.url), 'utf8');
    const panel = component.slice(component.indexOf('<dialog'), component.indexOf('<div class="jf-filter-panel-head">'));
    const attr = name => panel.match(new RegExp(name + '="([^\"]*)"'))?.[1];
    let modal = false;
    const state = {
        window: { innerWidth: 427 },
        $el: { dataset: {}, open: true, matches: () => modal, close() { this.open = false; modal = false; } },
    };
    runInNewContext(attr('x-init'), state);
    assert.equal(state.$el.dataset.ready, 'true');
    assert.equal(state.$el.open, false);
    state.$el.open = modal = true;
    state.window.innerWidth = 1280;
    runInNewContext(attr('@resize.window'), state);
    assert.equal(modal, false, 'desktop must not retain a modal background');
    assert.equal(state.$el.open, true, 'desktop filters stay available inline');
    state.window.innerWidth = 427;
    runInNewContext(attr('@resize.window'), state);
    assert.equal(state.$el.open, false);
    state.$el.open = modal = true;
    runInNewContext(attr('@turbo:before-cache.document'), state);
    assert.equal(state.$el.open, false, 'a cached page must not restore an open modal');
});

for (const [source, index, name] of [[blade, 0, 'Directory'], [blade, 1, 'Loyalty'], [invoices, 0, 'Invoices'], [schemes, 0, 'Schemes'], [purchases, 0, 'Purchases']]) {
    test(`${name}: opening search focuses its field after it becomes visible without submitting the GET form`, () => {
        const f = fixture(source, index);
        f.click();
        assert.equal(f.state.searchOpen, true);
        assert.equal(f.state.inputFocus, 0);
        f.flush();
        assert.equal(f.state.inputFocus, 1);
        assert.equal(f.state.$refs.searchInput.value, 'Unsaved customer query');
    });

    test(`${name}: Escape closes search and restores the icon focus without discarding entered text`, () => {
        const f = fixture(source, index);
        f.click(); f.flush(); f.escape();
        assert.equal(f.state.searchOpen, false);
        assert.equal(f.state.toggleFocus, 1);
        assert.equal(f.state.$refs.searchInput.value, 'Unsaved customer query');
    });

    test(`${name}: repeated icon toggles preserve the query and only refocus when reopening`, () => {
        const f = fixture(source, index);
        f.click(); f.flush(); f.click(); f.flush();
        assert.equal(f.state.searchOpen, false);
        assert.equal(f.state.inputFocus, 1);
        f.click(); f.flush();
        assert.equal(f.state.searchOpen, true);
        assert.equal(f.state.inputFocus, 2);
        assert.equal(f.state.$refs.searchInput.value, 'Unsaved customer query');
    });
}
