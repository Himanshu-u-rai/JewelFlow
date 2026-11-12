import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const source = readFileSync(new URL('../../resources/views/categories/index.blade.php', import.meta.url), 'utf8');
const start = source.indexOf('function showCategoryModal');
const end = source.indexOf('// ---- end dialog helpers ----');

function makeDialog(id, nameInput) {
    const classes = new Set();
    const d = {
        id, open: false, onclose: null, isConnected: true,
        showModalCalls: 0,
        showModal() { this.showModalCalls++; this.open = true; },
        close() { if (!this.open) return; this.open = false; if (this.onclose) this.onclose(); },
        querySelector: () => nameInput,
        classList: { add: c => classes.add(c), remove: c => classes.delete(c), contains: c => classes.has(c) },
    };
    return d;
}

function fixture() {
    const focused = [];
    const mk = () => ({ value: '', textContent: '', action: '', focus() { focused.push(this); }, select() { this.selected = true; }, isConnected: true });
    const els = {};
    const dialogs = {};
    for (const id of ['addCategoryModal', 'addSubCategoryModal', 'editCategoryModal', 'editSubCategoryModal']) {
        const input = mk();
        dialogs[id] = makeDialog(id, input);
        dialogs[id].input = input;
        els[id] = dialogs[id];
    }
    for (const id of ['subCategoryCategoryId', 'parentCategoryName', 'editCategoryForm', 'editCategoryIdInput', 'editCategoryName', 'editSubCategoryForm', 'editSubIdInput', 'editSubCategoryName']) els[id] = mk();
    const bodyClasses = new Set();
    const document = {
        activeElement: null,
        body: { classList: { add: c => bodyClasses.add(c), remove: c => bodyClasses.delete(c) } },
        getElementById: id => els[id] || null,
        querySelector: sel => Object.values(dialogs).find(d => d.open) || null,
    };
    const window = { setTimeout: fn => fn() };
    const ctx = vm.createContext({ document, window, categoryBaseUrl: '/categories', subBaseUrl: '/sub-categories' });
    assert.ok(start > 0 && end > start, 'dialog helper block markers exist in the Blade source');
    vm.runInContext(source.slice(start, end), ctx);
    return { ctx, document, dialogs, els, focused, bodyClasses, window };
}

test('opening uses showModal, focuses the first field and locks body scroll', () => {
    const f = fixture();
    const opener = { focus() { f.focused.push(this); }, isConnected: true };
    f.document.activeElement = opener;
    f.ctx.openAddCategoryModal();
    const d = f.dialogs.addCategoryModal;
    assert.equal(d.showModalCalls, 1);
    assert.equal(d.open, true);
    assert.equal(f.focused.at(-1), d.input);
    assert.equal(f.bodyClasses.has('categories-modal-open'), true);
});

test('closing (Cancel, Escape and backdrop all end in close) returns focus to the opener', () => {
    const f = fixture();
    const opener = { focus() { f.focused.push(this); }, isConnected: true };
    f.document.activeElement = opener;
    f.ctx.openAddCategoryModal();
    f.document.activeElement = f.dialogs.addCategoryModal.input;
    f.ctx.closeAddCategoryModal();
    assert.equal(f.dialogs.addCategoryModal.open, false);
    assert.equal(f.focused.at(-1), opener);
    assert.equal(f.bodyClasses.has('categories-modal-open'), false);

    // Escape: the browser closes the dialog itself and fires the close event.
    f.document.activeElement = opener;
    f.ctx.openEditCategoryModal(7, 'Rings');
    f.focused.length = 0;
    f.dialogs.editCategoryModal.close();
    assert.equal(f.focused.at(-1), opener);
});

test('edit dialogs prefill exactly as before', () => {
    const f = fixture();
    f.ctx.openEditCategoryModal(7, 'Rings');
    assert.equal(f.els.editCategoryForm.action, '/categories/7');
    assert.equal(f.els.editCategoryIdInput.value, 7);
    assert.equal(f.els.editCategoryName.value, 'Rings');
    f.ctx.openEditSubCategoryModal(9, 'Bridal');
    assert.equal(f.els.editSubCategoryForm.action, '/sub-categories/9');
    assert.equal(f.els.editSubIdInput.value, 9);
    assert.equal(f.els.editSubCategoryName.value, 'Bridal');
    f.ctx.openAddSubCategoryModal(3, 'Gold');
    assert.equal(f.els.subCategoryCategoryId.value, 3);
    assert.equal(f.els.parentCategoryName.textContent, 'Gold');
});

test('a stale opener that left the page is not focused', () => {
    const f = fixture();
    const opener = { focus() { f.focused.push(this); }, isConnected: false };
    f.document.activeElement = opener;
    f.ctx.openAddCategoryModal();
    f.focused.length = 0;
    f.ctx.closeAddCategoryModal();
    assert.equal(f.focused.includes(opener), false);
});
