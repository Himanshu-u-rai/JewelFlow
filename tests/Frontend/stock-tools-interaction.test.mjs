import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { createContext, runInContext } from 'node:vm';

const source = readFileSync(new URL('../../resources/views/tags/index.blade.php', import.meta.url), 'utf8');
const script = source.match(/<script>([\s\S]*?)<\/script>/)[1];
function fixture(saved = {}) {
    const values = new Map(Object.entries(saved));
    const context = createContext({
        localStorage: { getItem: key => values.get(key) ?? null, setItem: (key, value) => values.set(key, value), removeItem: key => values.delete(key) },
        document: { createElement: () => ({}) },
    });
    const load = () => runInContext(script, context);
    load();
    return { context, load, values };
}

test('tag selection survives repeat Turbo script evaluation without redeclaration errors', () => {
    const f = fixture({ synthetic_shop: '["1","21","999"]' });
    assert.doesNotThrow(f.load);
    const state = f.context.tagPrint({ storeKey: 'synthetic_shop', allMatchingIds: Array.from({ length: 22 }, (_, i) => String(i + 1)) });
    assert.deepEqual(Array.from(state.selected), ['1', '21']);
    const other = f.context.tagPrint({ storeKey: 'other_shop', allMatchingIds: ['1', '21'] });
    assert.deepEqual(Array.from(other.selected), []);
});

test('cross-page selections and saved options remain intact and repeated print submits do not duplicate hidden IDs', () => {
    const f = fixture({ synthetic_shop: '["1","21"]', tag_print_options: '{"codes":"qr","flatSize":"38x25"}' });
    const state = f.context.tagPrint({ storeKey: 'synthetic_shop', allMatchingIds: ['1', '2', '21'] });
    const watchers = new Map();
    state.$watch = (key, fn) => watchers.set(key, fn);
    state.init();
    assert.equal(state.codes, 'qr');
    assert.equal(state.flatSize, '38x25');
    state.toggle('2'); watchers.get('selected')(state.selected);
    f.load();
    assert.deepEqual(Array.from(f.context.tagPrint({ storeKey: 'synthetic_shop', allMatchingIds: ['1', '2', '21'] }).selected), ['1', '21', '2']);
    const children = [];
    const form = {
        querySelectorAll: () => [...children],
        appendChild(node) { node.remove = () => children.splice(children.indexOf(node), 1); children.push(node); },
    };
    state.injectHiddenInputs({ target: form });
    state.injectHiddenInputs({ target: form });
    assert.deepEqual(children.map(node => [node.name, node.value]), [['item_ids[]', '1'], ['item_ids[]', '21'], ['item_ids[]', '2']]);
    state.clearAll();
    let prevented = false;
    state.injectHiddenInputs({ target: form, preventDefault() { prevented = true; } });
    assert.equal(prevented, true);
    assert.equal(f.values.has('synthetic_shop'), false);
});
