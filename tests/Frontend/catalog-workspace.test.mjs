import assert from 'node:assert/strict';
import { existsSync, readFileSync } from 'node:fs';
import test from 'node:test';
import postcss from 'postcss';

const read = path => {
    const url = new URL(`../../${path}`, import.meta.url);
    return existsSync(url) ? readFileSync(url, 'utf8') : '';
};
const view = read('resources/views/retailer-reports/whatsapp.blade.php');

test('Catalog uses shared filter sheet, one search form and one Apply/Clear slot', () => {
    assert.match(view, /x-responsive-filter-panel id="catalog-filter-panel" label="Catalog filters"/);
    assert.equal([...view.matchAll(/name="search"/g)].length, 1);
    assert.match(view, /x-show="dirty \|\| !hasFilters"/);
    assert.match(view, /x-show="!dirty && hasFilters"/);
    assert.doesNotMatch(view, /onchange=".*this.form.submit/);
    assert.match(view, /<dl class="wa-summary"/);
    assert.match(view, /\$item->purity_label/);
    assert.doesNotMatch(view, /<style>/);
});

test('Select All collection payload contains off-page selections, not just rendered cards', () => {
    const source = view.match(/const itemIds = ([\s\S]*?);\n\n\s*const response = await fetch/)?.[1];
    assert.ok(source);
    const ids = new Function('selectedIds', `return ${source};`)(new Set(['1', '2', '23', '42']));
    assert.deepEqual(ids, [1, 2, 23, 42]);
});

test('Message editor uses native modal focus and cleanup; template state is announced', () => {
    assert.match(view, /<dialog id="wa-custom-modal"[^>]*aria-labelledby="modal-title"/);
    assert.match(view, /modal.showModal\(\)/);
    assert.match(view, /modal.close\(\)/);
    assert.match(view, /turbo:before-cache/);
    assert.match(view, /setAttribute\('aria-pressed'/);
    assert.match(view, /aria-controls="wa-item-actions-/);
});

test('Failed collection creation keeps its recovery message after button state resets', async () => {
    const handler = view.match(/createCollectionBtn\?\.addEventListener\('click', async \(event\) => \{([\s\S]*?)\n            \}\);/)?.[1];
    assert.ok(handler);
    let note = '';
    const button = { disabled: false, textContent: 'Create Collection' };
    const run = new Function('currentSelectionKey', 'latestCollectionLink', 'latestCollectionKey', 'createOrReuseCollectionLink', 'setBulkNote', 'selectedIds', 'updateSelectionUI', 'createCollectionBtn', `return async event => {${handler}}`)(
        () => '1', '', '', async () => { throw new Error('Network unavailable. Try again.'); },
        message => { note = message; }, new Set(['1']), () => { note = 'Normal selection hint'; }, button,
    );
    await run({ currentTarget: button });
    assert.equal(note, 'Network unavailable. Try again.');
    assert.equal(button.disabled, false);
});

test('Catalog styles remain scoped, use theme surfaces and readable phone controls', () => {
    const source = read('resources/css/catalog-workspace.css');
    assert.ok(source);
    const css = postcss.parse(source);
    css.walkRules(rule => {
        assert.ok(rule.selectors.every(s => s.includes('.wa-catalog-root') || s.includes('.catalog-page-header')), rule.selector);
    });
    assert.match(source, /var\(--jf-surface\)/);
    assert.match(source, /font-size: 16px/);
    assert.match(source, /min-height: 44px/);
    assert.doesNotMatch(source, /overflow-x: hidden|(?:^|\n)\s*body\s*\{/);
});
