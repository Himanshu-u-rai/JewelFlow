import assert from 'node:assert/strict';
import test from 'node:test';

const module = await import('../../resources/js/settings-unsaved.js').catch(error => {
    if (error.code !== 'ERR_MODULE_NOT_FOUND') throw error;
    return {};
});
const { initializeSettingsUnsavedChanges } = module;
const tick = () => new Promise(resolve => setImmediate(resolve));

function fixture() {
    assert.equal(typeof initializeSettingsUnsavedChanges, 'function', 'Settings needs an unsaved-changes guard');
    const doc = new EventTarget();
    const win = new EventTarget();
    const forms = [];
    const buttons = new Map(['keep', 'discard'].map(name => [name, new EventTarget()]));
    const dialog = Object.assign(new EventTarget(), {
        open: false, returnValue: '',
        showModal() { this.open = true; this.shown = (this.shown || 0) + 1; },
        close(value = '') { this.returnValue = value; this.open = false; this.dispatchEvent(new Event('close')); },
        querySelector: selector => buttons.get(selector.includes('discard') ? 'discard' : 'keep'),
    });
    doc.readyState = 'complete';
    doc.activeElement = { focus() {} };
    doc.querySelector = selector => selector.includes('dialog') ? dialog : (forms.length ? {} : null);
    doc.querySelectorAll = () => forms;
    win.location = { href: 'http://local.test/settings?tab=shop' };
    win.history = { state: { turbo: { restorationIndex: 2 } }, steps: [], go(delta) { this.steps.push(delta); } };
    win.FormData = class {
        constructor(form) { return form.data; }
    };
    win.queueMicrotask = queueMicrotask;
    win.Turbo = { visits: [], cache: { exemptPageFromCache() { this.exempted = true; } }, visit(url) { this.visits.push(url); } };
    const form = (method = 'post') => {
        const item = { method, id: `form-${forms.length}`, action: `http://local.test/save/${forms.length}`, isConnected: true, data: new FormData(),
            querySelector: () => ({}), closest: () => ({}),
            requestSubmit(submitter) { this.resubmitted = submitter || true; },
        };
        item.data.set('name', 'Original');
        forms.push(item);
        return item;
    };
    const first = form();
    const dispatch = (name, props = {}, target = doc) => {
        const event = new Event(name, { cancelable: true });
        if (name === 'beforeunload') Object.defineProperty(event, 'returnValue', { writable: true, value: '' });
        for (const [key, value] of Object.entries(props)) Object.defineProperty(event, key, { value });
        target.dispatchEvent(event);
        return event;
    };
    const mount = () => initializeSettingsUnsavedChanges(doc, win);
    const unload = () => dispatch('beforeunload', {}, win);
    const navigate = () => dispatch('turbo:before-visit', { detail: { url: 'http://local.test/dashboard' } });
    return { doc, win, forms, form, first, mount, dispatch, unload, navigate, dialog, buttons };
}

test('only actual changed values warn; restoring the original and CSRF renewal stay clean', () => {
    const f = fixture(); f.mount();
    assert.equal(f.unload().defaultPrevented, false);
    f.first.data.set('name', 'Changed');
    assert.equal(f.unload().defaultPrevented, true);
    f.first.data.set('name', 'Original');
    f.first.data.set('_token', 'new token');
    assert.equal(f.unload().defaultPrevented, false);
});

test('checkbox, repeated select values and file replacements participate in dirty detection', () => {
    const f = fixture();
    f.first.data.append('categories[]', 'gold');
    f.first.data.append('categories[]', 'silver');
    f.mount();
    f.first.data.set('categories[]', 'gold');
    assert.equal(f.unload().defaultPrevented, true);
    f.first.data.append('categories[]', 'silver');
    assert.equal(f.unload().defaultPrevented, false);
    f.first.data.set('logo', new Blob(['a'], { type: 'image/png' }), 'logo.png');
    assert.equal(f.unload().defaultPrevented, true);
});

test('GET filters and action-only forms do not count as unsaved settings', () => {
    const f = fixture(); const filter = f.form('get'); const action = f.form();
    action.querySelector = () => null;
    f.mount(); filter.data.set('name', 'Search'); action.data.set('name', 'Action metadata');
    assert.equal(f.unload().defaultPrevented, false);
});

test('Keep editing and Escape retain edits; Discard resumes the original Turbo visit once', async () => {
    const f = fixture(); f.mount(); f.first.data.set('name', 'Edited');
    assert.equal(f.navigate().defaultPrevented, true);
    f.dialog.close('keep'); await tick();
    assert.equal(f.win.Turbo.visits.length, 0);
    assert.equal(f.unload().defaultPrevented, true);
    f.navigate(); f.dispatch('cancel', {}, f.dialog); await tick();
    assert.equal(f.win.Turbo.visits.length, 0);
    f.navigate(); f.buttons.get('discard').dispatchEvent(new Event('click')); await tick();
    assert.deepEqual(f.win.Turbo.visits, ['http://local.test/dashboard']);
    assert.equal(f.win.Turbo.cache.exempted, true, 'Discarded edits must not reappear from the Back cache');
    assert.equal(f.unload().defaultPrevented, false);
});

test('Settings links pause before frame navigation; modified clicks and downloads do not warn', async () => {
    const f = fixture(); f.mount(); f.first.data.set('name', 'Edited');
    const link = { href: 'http://local.test/settings?tab=preferences', target: '',
        hasAttribute: () => false, getAttribute: () => null, click() { this.clicked = true; } };
    const target = { closest: () => link };
    assert.equal(f.dispatch('click', { target, button: 0, ctrlKey: true }).defaultPrevented, false);
    assert.equal(f.dispatch('click', { target, button: 0 }).defaultPrevented, true);
    f.buttons.get('discard').dispatchEvent(new Event('click')); await tick();
    assert.equal(link.clicked, true);
});

test('an own-form save is allowed without clearing edits after a failed save', async () => {
    const f = fixture(); f.mount(); f.first.data.set('name', 'Edited');
    assert.equal(f.dispatch('submit', { target: f.first }).defaultPrevented, false);
    await tick();
    assert.equal(f.unload().defaultPrevented, false);
    f.dispatch('turbo:submit-end', { target: f.first, detail: { success: false } });
    assert.equal(f.unload().defaultPrevented, true);
});

test('cancelled submission and browser validation do not turn off protection', async () => {
    const f = fixture(); f.mount(); f.first.data.set('name', 'Edited');
    const event = f.dispatch('submit', { target: f.first }); event.preventDefault(); await tick();
    assert.equal(f.unload().defaultPrevented, true);
});

test('a validation-error Turbo body retains the submitted form baseline instead of treating rejected values as saved', () => {
    const f = fixture(); f.mount(); f.first.data.set('name', 'Rejected edit');
    f.dispatch('submit', { target: f.first });
    f.dispatch('turbo:before-render', { detail: { newBody: { querySelector: () => ({}) } } });
    f.forms.shift(); f.first.isConnected = false;
    const replacement = f.form(); replacement.data.set('name', 'Rejected edit');
    f.dispatch('turbo:render');
    assert.equal(f.unload().defaultPrevented, true);
    replacement.data.set('name', 'Original');
    assert.equal(f.unload().defaultPrevented, false);
});

test('validation errors distinguish anonymous independent forms posting to the same endpoint', () => {
    const f = fixture(); const second = f.form();
    f.first.id = second.id = ''; second.action = f.first.action;
    second.data.set('name', 'Second original');
    f.mount(); second.data.set('name', 'Rejected edit');
    f.dispatch('submit', { target: second });
    f.dispatch('turbo:before-render', { detail: { newBody: { querySelector: () => ({}) } } });
    f.forms.splice(0); f.first.isConnected = second.isConnected = false;
    const firstReplacement = f.form(); const secondReplacement = f.form();
    firstReplacement.id = secondReplacement.id = '';
    secondReplacement.action = firstReplacement.action;
    secondReplacement.data.set('name', 'Rejected edit');
    f.dispatch('turbo:render');
    secondReplacement.data.set('name', 'Second original');
    assert.equal(f.unload().defaultPrevented, false);
});

test('saving an independent form warns about other edits, preserving the original submitter', async () => {
    const f = fixture(); const second = f.form(); f.mount();
    f.first.data.set('name', 'Edited'); const submitter = {};
    assert.equal(f.dispatch('submit', { target: second, submitter }).defaultPrevented, true);
    f.dialog.close('keep'); await tick(); assert.equal(second.resubmitted, undefined);
    f.dispatch('submit', { target: second, submitter });
    f.buttons.get('discard').dispatchEvent(new Event('click')); await tick();
    assert.equal(second.resubmitted, submitter);
});

test('resize/repeated initialization retain edits; replacement forms start with new server values', () => {
    const f = fixture(); f.mount(); f.first.data.set('name', 'Edited');
    f.mount(); f.dispatch('resize', {}, f.win);
    assert.equal(f.unload().defaultPrevented, true);
    f.first.isConnected = false; f.forms.shift(); f.form();
    f.dispatch('turbo:frame-load', { target: { id: 'settings-content' } });
    assert.equal(f.unload().defaultPrevented, false);
    assert.equal(f.dialog.shown || 0, 0);
});

// Audit M7 (2026-11-13): the guard kept its state in five loose flags (leaving, submitting, turboSubmitting,
// pending, rewind) that could be set in any combination. It now has one phase: editing, asking, submitting or
// leaving, and every handler moves it explicitly.
test('the guard has one explicit phase, not loose flags', async () => {
    const { readFileSync } = await import('node:fs');
    const src = readFileSync(new URL('../../resources/js/settings-unsaved.js', import.meta.url), 'utf8');
    assert.match(src, /const PHASES = \['editing', 'asking', 'submitting', 'leaving'\];/);
    for (const flag of ['leaving', 'submitting', 'turboSubmitting', 'pending']) {
        assert.doesNotMatch(src, new RegExp(`\\blet ${flag}\\b`), `no loose "${flag}" flag`);
    }
});

test('the phase follows the user: ask, keep, save, fail, discard', async () => {
    const f = fixture(); const second = f.form(); f.mount();
    const phase = () => f.doc.settingsUnsavedPhase;
    assert.equal(phase(), 'editing');
    f.first.data.set('name', 'Edited');
    f.navigate(); assert.equal(phase(), 'asking');
    f.dialog.close('keep'); await tick(); assert.equal(phase(), 'editing');
    f.dispatch('submit', { target: f.first }); assert.equal(phase(), 'submitting');
    f.dispatch('turbo:submit-end', { target: f.first, detail: { success: false } }); assert.equal(phase(), 'editing');
    f.navigate(); f.buttons.get('discard').dispatchEvent(new Event('click')); await tick();
    assert.equal(phase(), 'leaving');
    f.dispatch('turbo:render'); assert.equal(phase(), 'editing', 'a new page starts over');
    second.data.set('name', 'x');
});

test('back/forward are rewound before the prompt; cancel preserves URL position and discard retraverses', async () => {
    const f = fixture(); f.mount(); f.first.data.set('name', 'Edited');
    f.dispatch('popstate', { state: { turbo: { restorationIndex: 1 } } }, f.win);
    assert.deepEqual(f.win.history.steps, [1]);
    f.dispatch('popstate', { state: { turbo: { restorationIndex: 2 } } }, f.win);
    assert.equal(f.dialog.open, true);
    f.dialog.close('keep'); await tick();
    assert.deepEqual(f.win.history.steps, [1]);
    f.dispatch('popstate', { state: { turbo: { restorationIndex: 3 } } }, f.win);
    f.dispatch('popstate', { state: { turbo: { restorationIndex: 2 } } }, f.win);
    f.buttons.get('discard').dispatchEvent(new Event('click')); await tick();
    assert.deepEqual(f.win.history.steps, [1, -1, 1]);
});
