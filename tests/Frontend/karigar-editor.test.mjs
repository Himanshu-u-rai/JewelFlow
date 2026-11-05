import assert from 'node:assert/strict';
import { existsSync, readFileSync } from 'node:fs';
import test from 'node:test';
import postcss from 'postcss';

const path = new URL('../../resources/js/karigar-editor.js', import.meta.url);
const { createKarigarEditor } = existsSync(path) ? await import(path) : {};
function fixture(config = {}) {
    assert.equal(typeof createKarigarEditor, 'function');
    const state = createKarigarEditor(config);
    let focus = 0, clears = 0;
    const frame = { src: null, removeAttribute() { this.src = null; }, replaceChildren() { clears++; }, querySelector: () => ({ focus() { focus++; } }) };
    const dialog = { open: false, dataset: {}, showModal() { this.open = true; }, close() { this.open = false; } };
    const opener = { isConnected: true, focus() { focus++; } };
    state.$refs = { editor: dialog, frame };
    state.$nextTick = fn => fn();
    return { state, frame, dialog, opener, counts: () => ({ focus, clears }) };
}

test('opens server-rendered create/edit forms; cancel clears pending reads and restores focus', () => {
    const f = fixture();
    f.state.open('/karigars/create', 'Add Karigar', f.opener);
    assert.equal(f.dialog.open, true);
    assert.equal(f.frame.src, '/karigars/create');
    assert.equal(f.state.loading, true);
    f.state.loaded({ target: f.frame });
    assert.equal(f.state.loading, false);
    f.state.close();
    f.state.dismissed();
    assert.equal(f.frame.src, null);
    assert.equal(f.dialog.open, false);
    assert.equal(f.counts().focus, 2);
    f.state.open('/karigars/4/edit', 'Edit Karigar', f.opener);
    assert.equal(f.frame.src, '/karigars/4/edit');
    assert.equal(f.state.title, 'Edit Karigar');
});

test('pending submission cannot close or switch forms; Turbo cleanup prevents cached reopening', () => {
    const f = fixture({ restore: true, title: 'Edit Karigar' });
    f.state.init();
    assert.equal(f.dialog.open, true);
    f.state.submitting = true;
    f.state.close();
    f.state.open('/karigars/create', 'Add Karigar', f.opener);
    assert.equal(f.dialog.open, true);
    assert.equal(f.frame.src, null);
    f.state.cleanup();
    assert.equal(f.dialog.open, false);
    assert.equal(f.dialog.dataset.reopen, 'false');
    f.state.init();
    assert.equal(f.dialog.open, false);
});

test('failed GET remains recoverable without submission or an automatic retry', () => {
    const f = fixture();
    f.state.open('/karigars/4/edit', 'Edit Karigar', f.opener);
    let prevented = 0;
    f.state.failed({ target: f.frame, preventDefault() { prevented++; } });
    assert.equal(prevented, 1);
    assert.equal(f.state.loading, false);
    assert.match(f.state.error, /could not load/i);
    assert.equal(f.state.url, '/karigars/4/edit');
});

test('normal clicks open a native popup; modified clicks and unsupported browsers keep real links', () => {
    const source = readFileSync(new URL('../../resources/views/karigars/index.blade.php', import.meta.url), 'utf8');
    const handler = source.match(/data-karigar-open @click="([^"]+)"/)?.[1];
    assert.ok(handler);
    const run = new Function('$event', '$dispatch', '$el', 'HTMLDialogElement', handler);
    let opened = 0, prevented = 0;
    const event = { button: 0, preventDefault() { prevented++; } };
    const element = { href: '/karigars/create', dataset: { editorTitle: 'Add Karigar' } };
    const supported = { prototype: { showModal() {} } };
    run({ ...event, ctrlKey: true }, () => opened++, element, supported);
    run(event, () => opened++, element, undefined);
    assert.equal(opened, 0);
    run(event, () => opened++, element, supported);
    assert.equal(opened, 1);
    assert.equal(prevented, 1);
});

test('popup retains native form submission and navigation cleanup, with a scrollable phone sheet', () => {
    const source = readFileSync(new URL('../../resources/views/karigars/_editor.blade.php', import.meta.url), 'utf8');
    const form = readFileSync(new URL('../../resources/views/karigars/_editor-form.blade.php', import.meta.url), 'utf8');
    assert.match(source, /aria-labelledby="karigar-editor-title"/);
    assert.match(source, /@cancel="if \(submitting\) \$event.preventDefault\(\)"/);
    assert.match(source, /@close="dismissed\(\)"/);
    for (const event of ['turbo:before-cache', 'turbo:before-render']) assert.ok(source.includes(`@${event}.document="cleanup()"`));
    assert.match(form, /data-turbo-frame="_top"/);
    assert.match(form, /@include\('karigars\._form'\)/);
    assert.match(form, /@csrf/);
    assert.doesNotMatch(readFileSync(path, 'utf8'), /fetch\(|\.submit\(|requestSubmit\(/);
    const app = readFileSync(new URL('../../resources/js/app.js', import.meta.url), 'utf8');
    assert.match(app, /Alpine.data\('karigarEditor', createKarigarEditor\);[\s\S]*Alpine.start\(\)/);
    const css = postcss.parse(readFileSync(new URL('../../resources/css/karigars-directory.css', import.meta.url), 'utf8'));
    const values = {};
    css.walkRules(rule => {
        if (rule.selector === 'body.app-shell .karigar-editor.kf-shell') rule.walkDecls(decl => { values[decl.prop] = decl.value; });
    });
    assert.equal(values['overflow-y'], 'auto');
    assert.equal(values.width, '100%');
    assert.match(values['max-height'], /100dvh/);
    assert.equal(values['border-radius'], '16px 16px 0 0');
});
