import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';
import test from 'node:test';

const index = readFileSync(new URL('../../resources/views/inventory/purchases/index.blade.php', import.meta.url), 'utf8');
const partialPath = new URL('../../resources/views/inventory/purchases/partials/confirm-draft-action.blade.php', import.meta.url);
const attr = (source, name) => {
    const value = source.match(new RegExp(`${name}="([^"]*)"`))?.[1];
    assert.ok(value, `Missing ${name} handler`);
    return value;
};

test('opening another draft replaces the panel data without submitting; Cancel and cache navigation close it', () => {
    const button = readFileSync(partialPath, 'utf8');
    const dialog = index.slice(index.indexOf('<dialog id="purchase-confirm-dialog"'));
    let opened = 0;
    let closed = 0;
    const state = { draft: null, confirming: true, $el: { dataset: { number: 'PUR-A', confirmUrl: '/a/confirm' } },
        $refs: { confirmDraft: { showModal: () => opened++, close: () => closed++ } } };
    runInNewContext(attr(button, '@click'), state);
    assert.equal(opened, 1);
    assert.equal(state.draft.number, 'PUR-A');
    assert.equal(state.confirming, false);
    state.$el.dataset = { number: 'PUR-B', confirmUrl: '/b/confirm' };
    runInNewContext(attr(button, '@click'), state);
    assert.equal(state.draft.confirmUrl, '/b/confirm');
    const cancel = dialog.match(/<button[^]*?autofocus[^]*?<\/button>/)?.[0];
    assert.ok(cancel);
    runInNewContext(attr(cancel, '@click'), state);
    assert.equal(closed, 1);
    runInNewContext(attr(dialog, '@close'), state);
    assert.equal(state.draft, null);
    runInNewContext(attr(index, '@turbo:before-cache.document'), state);
    assert.equal(closed, 2);
    assert.equal(state.confirming, false);
});

test('the confirmation form targets only the selected draft and suppresses repeated submissions', () => {
    const dialog = index.slice(index.indexOf('<dialog id="purchase-confirm-dialog"'));
    let prevented = 0;
    const state = { draft: { confirmUrl: '/purchases/42/confirm' }, confirming: false,
        $event: { preventDefault: () => prevented++ } };
    assert.equal(runInNewContext(attr(dialog, ':action'), state), '/purchases/42/confirm');
    const submit = attr(dialog, '@submit');
    runInNewContext(submit, state);
    assert.equal(state.confirming, true);
    assert.equal(prevented, 0);
    runInNewContext(submit, state);
    assert.equal(prevented, 1);
    state.draft = null;
    state.confirming = false;
    runInNewContext(submit, state);
    assert.equal(prevented, 2);
});

test('Escape can cancel a review but cannot dismiss an in-flight confirmation', () => {
    const dialog = index.slice(index.indexOf('<dialog id="purchase-confirm-dialog"'));
    let closed = 0;
    const state = { confirming: false, $el: { close: () => closed++ } };
    const cancel = attr(dialog, '@cancel.prevent');
    runInNewContext(cancel, state);
    assert.equal(closed, 1);
    state.confirming = true;
    runInNewContext(cancel, state);
    assert.equal(closed, 1);
});
