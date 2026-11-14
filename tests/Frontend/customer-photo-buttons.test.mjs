import assert from 'node:assert/strict';
import test from 'node:test';

// Audit N11: the save/remove action disabled every button and its `finally` enabled every button again, including
// ones that were disabled for another reason (capture while the camera warms up). It now restores each button's
// own prior state.
const { initializeCustomerPhotos } = await import(new URL('../../resources/js/customer-photo.js', import.meta.url));

function build() {
    const mk = (extra = {}) => ({ hidden: false, disabled: false, textContent: '', dataset: {}, attrs: {}, setAttribute(k, v) { this.attrs[k] = v; }, removeAttribute() {}, focus() {}, getClientRects: () => [1], isConnected: true, ...extra });
    const action = (name) => mk({ dataset: { photoAction: name }, closest() { return this; } });
    const actions = Object.fromEntries(['camera', 'upload', 'change', 'remove', 'capture', 'switch', 'retake', 'confirm', 'cancel'].map((n) => [n, action(n)]));
    const image = mk();
    const initials = mk();
    const avatar = mk({ querySelector: (s) => (s.includes('image') ? image : initials), getAttribute: () => null });
    const dialog = mk({ open: false, showModal() { this.open = true; }, close() { this.open = false; }, addEventListener() {}, querySelector: () => mk(), querySelectorAll: () => [] });
    const named = { '[data-photo-dialog]': dialog, '[data-photo-video]': mk({ play: async () => {} }), '[data-photo-picker]': mk({ addEventListener() {}, click() {} }), '[data-photo-preview]': mk(), '[data-photo-error]': mk(), '[data-photo-help]': mk(), '[data-photo-status]': mk(), '[data-customer-avatar]': avatar, '[data-photo-action="confirm"]': actions.confirm };
    avatar.getAttribute = () => null;
    image.getAttribute = () => null;
    let click;
    const root = {
        dataset: { photoUrl: '/photo', photoVersion: '1', photoMode: 'live' },
        querySelector: (s) => named[s] ?? null,
        querySelectorAll: (s) => (s === 'button' ? Object.values(actions) : (s.match(/data-photo-action="(\w+)"/) ? [actions[s.match(/data-photo-action="(\w+)"/)[1]]] : [])),
        addEventListener(type, fn) { if (type === 'click') click = fn; },
    };
    return { root, actions, click: (name) => click({ target: actions[name] }) };
}

function install(f, fetchImpl) {
    const doc = { listeners: {}, readyState: 'complete', addEventListener(t, fn) { this.listeners[t] = fn; }, querySelectorAll: (s) => (s === '[data-customer-photo]' ? [f.root] : []), querySelector: () => ({ content: 'tok' }) };
    const win = { addEventListener() {} };
    Object.assign(globalThis, { document: doc, window: win, fetch: fetchImpl, FormData: class { set() {} } });
    f.root.isConnected = true;
    initializeCustomerPhotos();
}

test('finishing a photo action leaves buttons that were already disabled disabled', async () => {
    const f = build();
    install(f, async () => ({ ok: true, json: async () => ({ photo: null, message: 'Removed' }) }));
    f.actions.capture.disabled = true;
    f.actions.switch.disabled = true;
    await f.click('remove');
    await f.click('confirm');
    assert.equal(f.actions.confirm.disabled, false, 'the confirm button is usable again');
    assert.equal(f.actions.camera.disabled, false);
    assert.equal(f.actions.capture.disabled, true, 'capture was disabled before and still is');
    assert.equal(f.actions.switch.disabled, true);
});

test('the same holds when the save fails', async () => {
    const f = build();
    install(f, async (url, init) => (init ? { ok: false, json: async () => ({ message: 'No' }) } : { ok: true, json: async () => ({ photo: null }) }));
    f.actions.capture.disabled = true;
    await f.click('remove');
    await f.click('confirm');
    assert.equal(f.actions.confirm.disabled, false);
    assert.equal(f.actions.capture.disabled, true);
});
