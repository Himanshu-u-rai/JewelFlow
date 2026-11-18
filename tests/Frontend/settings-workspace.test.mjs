import assert from 'node:assert/strict';
import test from 'node:test';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';
import { bindSettingsLogo, initializeSettingsWorkspace } from '../../resources/js/settings-workspace.js';
import * as settingsWorkspace from '../../resources/js/settings-workspace.js';

test('Settings validation toasts show once per rendered source, including Turbo frame visits', async () => {
    const source = { dataset: { message: 'The email field must be lowercase.' } };
    const handlers = {};
    const shown = [];
    const doc = { readyState: 'complete', getElementById: () => null,
        querySelector: selector => selector.includes('data-settings-validation') ? source :
            selector === '.settings-shell' ? {} : { dataset: { settingsTab: 'profile' } },
        querySelectorAll: () => [], addEventListener: (name, fn) => { handlers[name] = fn; } };
    const win = { location: { href: 'http://localhost/settings?tab=profile' }, addEventListener() {},
        showToast: (...args) => shown.push(args) };
    initializeSettingsWorkspace(doc, win);
    await new Promise(resolve => queueMicrotask(resolve));
    handlers['turbo:frame-load']({ target: { id: 'settings-content' } });
    handlers['turbo:load']();
    await new Promise(resolve => queueMicrotask(resolve));
    assert.deepEqual(shown, [['The email field must be lowercase.', 'error', 8000]]);
});

test('failed-submit body renders show validation feedback even without a Turbo load event', async () => {
    let source = null;
    const handlers = {}, shown = [];
    const doc = { readyState: 'complete', getElementById: () => null,
        querySelector: selector => selector.includes('data-settings-validation') ? source :
            selector === '.settings-shell' ? {} : { dataset: { settingsTab: 'profile' } },
        querySelectorAll: () => [], addEventListener: (name, fn) => { handlers[name] = fn; } };
    const win = { location: { href: 'http://localhost/settings?tab=profile' }, addEventListener() {}, showToast: (...args) => shown.push(args) };
    initializeSettingsWorkspace(doc, win);
    source = { dataset: { message: 'The email field must be lowercase.' } };
    handlers['turbo:render']?.();
    await new Promise(resolve => queueMicrotask(resolve));
    assert.deepEqual(shown, [['The email field must be lowercase.', 'error', 8000]]);
});

test('Settings toast placement floats below the header without reserving page space', () => {
    assert.equal(typeof settingsWorkspace.placeSettingsToast, 'function');
    const removed = [], positions = [];
    const doc = { documentElement: { style: { removeProperty: name => removed.push(name) } },
        querySelector: selector => selector === '.settings-shell' ? {} : { getBoundingClientRect: () => ({ bottom: 80 }) } };
    const toast = { style: { setProperty: (...args) => positions.push(args) } };
    assert.equal(settingsWorkspace.placeSettingsToast(doc, toast), true);
    assert.deepEqual(removed, ['--toast-space']);
    assert.deepEqual(positions, [['--toast-top', '88px']]);
    assert.equal(settingsWorkspace.placeSettingsToast({ querySelector: () => null }, toast), false);
});

test('Turbo snapshot cleanup does not hide live Settings Alpine controls during promoted frame navigation', () => {
    const source = readFileSync(new URL('../../resources/js/app.js', import.meta.url), 'utf8');
    assert.ok(source.includes("querySelectorAll('[x-data]:not(.settings-shell [x-data])')"));
    assert.ok(source.includes("querySelectorAll('[x-show]:not(.settings-shell [x-show])')"));
});

test('Website tab scripts can execute again after a Turbo revisit', () => {
    const blade = readFileSync(new URL('../../resources/views/partials/settings/website-tab.blade.php', import.meta.url), 'utf8');
    const script = [...blade.matchAll(/<script>([\s\S]*?)<\/script>/g)].at(-1)[1];
    const context = vm.createContext({ document: { getElementById: () => null } });
    context.window = context;
    assert.doesNotThrow(() => {
        vm.runInContext(script, context);
        vm.runInContext(script, context);
    });
});

test('Audit event delegation mounts only once when its tab is revisited', () => {
    const blade = readFileSync(new URL('../../resources/views/settings.blade.php', import.meta.url), 'utf8');
    const script = [...blade.matchAll(/<script>([\s\S]*?)<\/script>/g)].find(m => m[1].includes('function auditDecode'))[1]
        .replace(/\{\{[\s\S]*?\}\}/g, 'translated');
    const listeners = [];
    const context = vm.createContext({ document: { addEventListener: name => listeners.push(name) } });
    context.window = context;
    vm.runInContext(script, context);
    vm.runInContext(script, context);
    assert.deepEqual(listeners, ['click', 'keydown']);
});

test('Settings navigation follows rendered tabs and lifecycle handlers mount once', () => {
    let tab = 'shop';
    const link = name => ({ href: `http://localhost/settings?tab=${name}`, attrs: {}, active: false,
        classList: { toggle(_, value) { this.owner.active = value; } },
        setAttribute(key, value) { this.attrs[key] = value; }, removeAttribute(key) { delete this.attrs[key]; } });
    const links = ['shop', 'pricing'].map(name => { const item = link(name); item.classList.owner = item; return item; });
    const handlers = {};
    const doc = { readyState: 'complete', getElementById: () => null,
        querySelector: selector => selector === '.settings-shell' ? {} : { dataset: { settingsTab: tab } },
        querySelectorAll: () => links, addEventListener: (name, fn) => (handlers[name] ??= []).push(fn) };
    const win = { location: { href: 'http://localhost/settings' }, matchMedia: () => ({ matches: false }), addEventListener() {} };
    initializeSettingsWorkspace(doc, win);
    initializeSettingsWorkspace(doc, win);
    assert.equal(links[0].active, true);
    assert.equal(handlers['turbo:frame-load'].length, 1);
    tab = 'pricing';
    handlers['turbo:frame-load'][0]({ target: { id: 'settings-content' } });
    assert.equal(links[0].active, false);
    assert.equal(links[1].attrs['aria-current'], 'page');
    tab = 'shop';
    handlers['turbo:load'][0]();
    assert.equal(links[0].active, true);
});

test('Shop logo binds each new Turbo DOM once and one browse click opens one picker', () => {
    const node = () => ({ events: {}, style: {}, textContent: '', value: '', files: [],
        classList: { add() {}, remove() {}, toggle() {} }, getAttribute: () => '', closest: () => null,
        addEventListener(name, fn) { (this.events[name] ??= []).push(fn); } });
    const makeNodes = () => new Map(['shop-logo-dropzone', 'shop-logo-browse', 'shop-logo-input', 'shop-logo-preview',
        'shop-logo-placeholder', 'shop-logo-state-text', 'shop-logo-file-name'].map(id => [id, node()]));
    let nodes = makeNodes();
    const doc = { getElementById: id => nodes.get(id) };
    bindSettingsLogo(doc);
    bindSettingsLogo(doc);
    assert.equal(nodes.get('shop-logo-input').events.change.length, 1);
    let opened = 0, stopped = false;
    const input = nodes.get('shop-logo-input');
    input.click = () => opened++;
    nodes.get('shop-logo-browse').events.click[0]({ preventDefault() {}, stopPropagation() { stopped = true; } });
    assert.equal(opened, 1);
    assert.equal(stopped, true);
    nodes.get('shop-logo-dropzone').events.click[0]({ target: input });
    assert.equal(opened, 1);
    input.events.change[0]({ target: { files: [{ type: 'image/png', size: 3 * 1024 * 1024 }] } });
    assert.equal(nodes.get('shop-logo-state-text').textContent, 'File too large (max 2 MB)');
    nodes = makeNodes();
    bindSettingsLogo(doc);
    assert.equal(nodes.get('shop-logo-input').events.change.length, 1);
});
