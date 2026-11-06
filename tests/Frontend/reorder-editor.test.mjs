import assert from 'node:assert/strict';
import { existsSync, readFileSync } from 'node:fs';
import test from 'node:test';
import postcss from 'postcss';

const path = new URL('../../resources/js/reorder-editor.js', import.meta.url);
const { createReorderEditor } = existsSync(path) ? await import(path) : {};
function fixture(config = {}) {
    assert.equal(typeof createReorderEditor, 'function');
    const state = createReorderEditor({ createUrl: '/reorder', subcategories: { Rings: ['Bands'] }, ...config });
    let opened = 0, closed = 0, focused = 0;
    // showModal throws on a detached dialog, as browsers do (InvalidStateError)
    const dialog = { open: false, isConnected: true, dataset: {}, showModal() { if (!this.isConnected) throw new Error('InvalidStateError: not in a Document'); this.open = true; opened++; }, close() { this.open = false; closed++; }, querySelector: () => ({ focus() { focused++; } }) };
    state.$refs = { ruleDialog: dialog };
    state.$nextTick = fn => fn();
    return { state, dialog, counts: () => ({ opened, closed, focused }) };
}

test('create and edit reuse one dialog; cancelling cannot mutate the original rule', () => {
    const f = fixture();
    const rule = { id: 3, action: '/reorder/3', category: 'Rings', sub_category: 'Bands', min_stock_threshold: 5, vendor_id: '', is_active: false };
    f.state.open(rule);
    assert.equal(f.state.form.action, '/reorder/3');
    assert.equal(f.state.editing, true);
    assert.deepEqual(f.state.subCategories, ['Bands']);
    f.state.form.min_stock_threshold = 99;
    f.state.close();
    assert.equal(rule.min_stock_threshold, 5);
    assert.equal(f.dialog.open, false);
    f.state.open();
    assert.equal(f.state.form.action, '/reorder');
    assert.equal(f.state.form.category, '');
    assert.equal(f.state.editing, false);
    assert.equal(f.state.form.is_active, true);
});

test('a dialog left behind by a Turbo re-render ignores the window open event instead of throwing', () => {
    const stale = fixture();
    stale.dialog.isConnected = false;
    assert.doesNotThrow(() => stale.state.open({ id: 5, action: '/reorder/5' }));
    assert.equal(stale.counts().opened, 0);
    const live = fixture();
    live.state.open();
    assert.equal(live.dialog.open, true);
});

test('validation reopens the correct edit with attempted values, but not a cached error dialog', () => {
    const config = { hasErrors: true, initialRule: { id: 4, action: '/reorder/4', min_stock_threshold: '0', is_active: false } };
    const f = fixture(config);
    f.state.init();
    assert.equal(f.dialog.open, true);
    assert.equal(f.state.form.min_stock_threshold, '0');
    assert.equal(f.state.form.is_active, false);
    assert.equal(f.state.showErrors, true);
    f.state.cleanup();
    f.state.init();
    assert.equal(f.dialog.open, false);
    f.state.open();
    assert.equal(f.state.showErrors, false);
});

test('unknown category maps are safe and native submission cannot be closed while pending', () => {
    const f = fixture();
    f.state.open({ category: 'constructor' });
    assert.deepEqual(f.state.subCategories, []);
    f.state.submitting = true;
    f.state.close();
    assert.equal(f.dialog.open, true);
    f.state.submitting = false;
    f.state.close();
    assert.equal(f.dialog.open, false);
});

test('unavailable saved choices stay visible instead of silently becoming All categories or None', () => {
    const f = fixture({ categories: ['Rings'], vendorIds: ['2'] });
    f.state.open({ category: 'Legacy group', sub_category: 'Legacy detail', vendor_id: 99 });
    assert.equal(f.state.form.category, 'Legacy group');
    assert.equal(f.state.form.sub_category, 'Legacy detail');
    assert.equal(f.state.form.vendor_id, 99);
    assert.deepEqual(f.state.categories, ['Rings']);
    assert.deepEqual(f.state.vendorIds, ['2']);
    const source = readFileSync(new URL('../../resources/views/reorder/partials/rule-dialog.blade.php', import.meta.url), 'utf8');
    for (const field of ['category', 'sub_category', 'vendor_id']) assert.match(source, new RegExp(`:value="form\\.${field}" :selected="true"`));
});

test('modal uses native forms and dialog semantics with cache cleanup, never an AJAX mutation retry', () => {
    const file = new URL('../../resources/views/reorder/partials/rule-dialog.blade.php', import.meta.url);
    const html = existsSync(file) ? readFileSync(file, 'utf8') : '';
    assert.match(html, /<dialog[^>]*id="reorder-rule-dialog"/);
    assert.match(html, /aria-labelledby="reorder-rule-title"/);
    assert.match(html, /data-turbo-frame="_top"/);
    assert.match(html, /@submit="submitting = true"/);
    assert.match(html, /@turbo:before-cache.document="cleanup\(\)"/);
    assert.match(html, /@turbo:submit-end.document="submitting = false"/);
    assert.doesNotMatch(html, /data-enhance-selects|fetch\(/);
    const cssPath = new URL('../../resources/css/reorder-editor.css', import.meta.url);
    const css = postcss.parse(existsSync(cssPath) ? readFileSync(cssPath, 'utf8') : '');
    const values = {};
    css.walkDecls(decl => { values[decl.prop] = decl.value; });
    assert.match(values['max-height'] || '', /100dvh/);
    assert.equal(values['overflow-y'], 'auto');
    assert.equal(values['font-size'], '16px');
    const app = readFileSync(new URL('../../resources/js/app.js', import.meta.url), 'utf8');
    assert.match(app, /Alpine.data\('reorderRuleEditor', createReorderEditor\);[\s\S]*Alpine.start\(\)/);
});

test('normal Add clicks open the dialog; modified clicks and unsupported browsers keep fallback links', () => {
    const source = readFileSync(new URL('../../resources/views/reorder/index.blade.php', import.meta.url), 'utf8');
    const handler = source.match(/data-reorder-open @click="([^"]+)"/)?.[1];
    assert.ok(handler);
    const run = new Function('$event', '$dispatch', 'HTMLDialogElement', handler);
    let prevented = 0, dispatched = 0;
    const event = { button: 0, preventDefault() { prevented++; } };
    const supported = { prototype: { showModal() {} } };
    run({ ...event, ctrlKey: true }, () => dispatched++, supported);
    run(event, () => dispatched++, undefined);
    assert.equal(prevented, 0);
    run(event, () => dispatched++, supported);
    assert.equal(prevented, 1);
    assert.equal(dispatched, 1);
    assert.match(source, /aria-haspopup="dialog" aria-controls="reorder-rule-dialog"/);
});
