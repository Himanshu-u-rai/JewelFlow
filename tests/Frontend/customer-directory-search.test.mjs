import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { runInNewContext } from 'node:vm';

const blade = readFileSync(new URL('../../resources/views/customers/index.blade.php', import.meta.url), 'utf8');

// Run the actual Alpine handlers; DOM focus/nextTick are the only boundaries.
function fixture(index) {
    const button = [...blade.matchAll(/<button\s+type="button"\s+data-customer-search-toggle\b[^]*?<\/button>/g)][index]?.[0];
    assert.ok(button, 'Customer search needs a named, non-submitting disclosure button.');
    const click = button.match(/@click="([^"]*)"/)?.[1];
    const escape = [...blade.matchAll(/@keydown\.escape\.stop\.prevent="([^"]*)"/g)][index]?.[1];
    assert.ok(click);
    assert.ok(escape);
    const pending = [];
    const state = {
        searchOpen: false, inputFocus: 0, toggleFocus: 0,
        $refs: {}, $nextTick: fn => pending.push(fn),
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

for (const [index, name] of ['Directory', 'Loyalty'].entries()) {
    test(`${name}: opening search focuses its field after it becomes visible without submitting the GET form`, () => {
        const f = fixture(index);
        f.click();
        assert.equal(f.state.searchOpen, true);
        assert.equal(f.state.inputFocus, 0);
        f.flush();
        assert.equal(f.state.inputFocus, 1);
        assert.equal(f.state.$refs.searchInput.value, 'Unsaved customer query');
    });

    test(`${name}: Escape closes search and restores the icon focus without discarding entered text`, () => {
        const f = fixture(index);
        f.click(); f.flush(); f.escape();
        assert.equal(f.state.searchOpen, false);
        assert.equal(f.state.toggleFocus, 1);
        assert.equal(f.state.$refs.searchInput.value, 'Unsaved customer query');
    });

    test(`${name}: repeated icon toggles preserve the query and only refocus when reopening`, () => {
        const f = fixture(index);
        f.click(); f.flush(); f.click(); f.flush();
        assert.equal(f.state.searchOpen, false);
        assert.equal(f.state.inputFocus, 1);
        f.click(); f.flush();
        assert.equal(f.state.searchOpen, true);
        assert.equal(f.state.inputFocus, 2);
        assert.equal(f.state.$refs.searchInput.value, 'Unsaved customer query');
    });
}
