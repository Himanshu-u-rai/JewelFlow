import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';

const source = readFileSync(new URL('../../resources/views/cashbook/_entry-form.blade.php', import.meta.url), 'utf8');
const script = source.match(/<script>([\s\S]*?)<\/script>/)[1];

function formState() {
    const classes = () => {
        const values = new Set();
        return { add: x => values.add(x), remove: x => values.delete(x), contains: x => values.has(x),
            toggle: (x, on) => on ? values.add(x) : values.delete(x) };
    };
    const select = (rows, value = '') => {
        const node = { value, options: [], name: '', required: false, disabled: false,
            get selectedOptions() { return this.options.filter(o => o.value === this.value); } };
        node.options = rows.map(([value, kind]) => ({ value, dataset: { kind }, disabled: false, hidden: false,
            get selected() { return node.value === value; }, getAttribute: name => name === 'data-dir' ? kind : null }));
        return node;
    };
    const nodes = {
        source_type: select([['', null], ['customer_payment', 'in'], ['rent', 'out'], ['custom', 'both']]),
        custom_source: { name: '', required: false, classList: classes() },
        source_hint: { textContent: '' },
        payment_mode: { value: 'cash' },
        account_field: { classList: classes() },
        payment_method_id: select([['', null], ['1', 'upi'], ['2', 'upi'], ['3', 'bank']]),
    };
    const context = { document: { readyState: 'complete', getElementById: id => nodes[id],
        querySelector: () => ({ querySelector: () => ({ value: 'in' }) }), addEventListener() {} } };
    context.window = context;
    runInNewContext(script, context);
    return { nodes, context };
}

test('switching direction clears an incompatible reason and keeps a custom reason usable', () => {
    const { nodes, context } = formState();
    nodes.source_type.value = 'rent';
    context.handleTypeChange('in');
    assert.equal(nodes.source_type.value, '');
    assert.equal(nodes.source_type.options.find(o => o.value === 'rent').disabled, true);
    nodes.source_type.value = 'custom';
    context.handleSourceChange(nodes.source_type);
    assert.equal(nodes.custom_source.name, 'source_type');
    assert.equal(nodes.custom_source.required, true);
    assert.equal(nodes.source_type.name, '');
    context.handleTypeChange('out');
    assert.equal(nodes.source_type.value, 'custom');
    nodes.source_type.value = 'rent';
    context.handleSourceChange(nodes.source_type);
    assert.equal(nodes.source_type.name, 'source_type');
    assert.equal(nodes.custom_source.name, '');
    assert.equal(nodes.custom_source.required, false);
    assert.equal(nodes.custom_source.classList.contains('cbf-hidden'), true);
});

test('account selection follows mode without sending stale accounts or guessing among multiple accounts', () => {
    const { nodes, context } = formState();
    const account = nodes.payment_method_id;
    assert.equal(account.disabled, true);
    assert.equal(account.required, false);
    nodes.payment_mode.value = 'bank';
    context.handleModeChange();
    assert.equal(account.value, '3');
    assert.equal(account.required, true);
    assert.equal(account.disabled, false);
    nodes.payment_mode.value = 'upi';
    context.handleModeChange();
    assert.equal(account.value, '');
    assert.equal(account.options.find(o => o.value === '3').disabled, true);
    account.value = '2';
    context.handleModeChange();
    assert.equal(account.value, '2');
    nodes.payment_mode.value = 'wallet';
    context.handleModeChange();
    assert.equal(account.disabled, true);
    assert.equal(account.required, false);
    assert.equal(account.value, '');
    assert.equal(nodes.account_field.classList.contains('cbf-hidden'), true);
});
