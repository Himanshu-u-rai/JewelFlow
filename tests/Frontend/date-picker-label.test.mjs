import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const source = readFileSync(new URL('../../resources/js/app.js', import.meta.url), 'utf8');
const start = source.indexOf('function styleDatePickerInput(');
assert.ok(start > -1, 'styleDatePickerInput not found');
const end = source.indexOf('\nfunction ', start + 10);
const fnSource = source.slice(start, end > -1 ? end : undefined);

function el(attrs = {}, extra = {}) {
    const a = { ...attrs };
    return {
        className: '', placeholder: '', readOnly: false, textContent: '',
        get id() { return a.id || ''; },
        set id(v) { a.id = v; },
        classList: { add() {} },
        getAttribute: (n) => (n in a ? a[n] : null),
        setAttribute: (n, v) => { a[n] = String(v); },
        hasAttribute: (n) => n in a,
        ...extra,
    };
}

function run(nativeAttrs, label) {
    const native = el(nativeAttrs);
    const alt = el();
    const doc = {
        querySelector: (sel) => (label && sel === `label[for="${nativeAttrs.id}"]` ? label : null),
    };
    const ctx = { document: doc, CSS: { escape: (s) => s } };
    vm.createContext(ctx);
    vm.runInContext(`${fnSource}; this.fn = styleDatePickerInput;`, ctx);
    ctx.fn(native, { altInput: alt, config: { allowInput: false } });
    return alt;
}

test('copies explicit aria-label', () => {
    const alt = run({ 'aria-label': 'Due date' });
    assert.equal(alt.getAttribute('aria-label'), 'Due date');
});

test('copies aria-labelledby', () => {
    const alt = run({ 'aria-labelledby': 'cap-1' });
    assert.equal(alt.getAttribute('aria-labelledby'), 'cap-1');
});

test('derives name from label[for]', () => {
    const label = el({}, { textContent: '  Start date \n' });
    const alt = run({ id: 'start' }, label);
    const named = alt.getAttribute('aria-label') === 'Start date' || alt.getAttribute('aria-labelledby');
    assert.ok(named, 'altInput has no accessible name');
});
