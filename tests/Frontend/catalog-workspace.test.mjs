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

test('Message editor uses native modal focus and cleanup; message style is a real dropdown', () => {
    assert.match(view, /<dialog id="wa-custom-modal"[^>]*aria-labelledby="modal-title"/);
    assert.match(view, /modal.showModal\(\)/);
    assert.match(view, /modal.close\(\)/);
    assert.match(view, /turbo:before-cache/);
    assert.match(view, /<select id="wa-message-style"/);
    assert.doesNotMatch(view, /wa-template-chip|Catalog sharing|wa-sharing-tools/);
    assert.match(view, /templateSelect\?\.addEventListener\('change'/);
    assert.match(view, /state.template = templateSelect.value/);
    assert.match(view, /aria-controls="wa-item-actions-/);
    assert.match(view, /id="wa-preview-product-link"/);
    assert.match(view, /previewLink.href = shareLink/);
});

test('Custom message is last style option and opens the editor without applying an unfinished draft', () => {
    const options = [...view.matchAll(/<option value="(premium|quick|newdrop|custom)">/g)].map(m => m[1]);
    assert.deepEqual(options, ['premium', 'quick', 'newdrop', 'custom']);
    assert.match(view, /id="modal-apply-style"/);
    assert.match(view, /state.customMessage = modalBodyInput.value.trim\(\)/);
    assert.match(view, /templateSelect.value = state.template/);
    assert.match(view, /modalBodyInput.reportValidity\(\)/);
});

test('Custom editor accepts plain writing, automatically adds product/collection details and never interprets codes', () => {
    const source = view.match(/const buildCustomMessage = ([\s\S]*?);\n\n\s*const buildMessage/)?.[1];
    assert.ok(source);
    const build = new Function('shopName', 'money', 'normalizeLines', `return ${source}`)(
        'Review Shop', new Intl.NumberFormat('en-IN'), text => text.trim(),
    );
    const item = build({ design: 'Silver ring', price: '1200', purity: '925' }, 'Hello! <b>Plain text</b> and {ordinary braces}.', '', '9000000111', '/product/1');
    assert.match(item, /Silver ring/);
    assert.match(item, /Price: ₹1,200/);
    assert.match(item, /<b>Plain text<\/b>/);
    assert.match(item, /\{ordinary braces\}/);
    assert.match(item, /View Product: \/product\/1/);
    const collection = build({ count: 3, collection: true }, 'My favourite picks.', '', '', '/collection/1');
    assert.match(collection, /My favourite picks\./);
    assert.match(collection, /3 selected products/);
    assert.match(collection, /View collection: \/collection\/1/);
    assert.match(view, /<label for="modal-custom-body">Your message<\/label>/);
    assert.doesNotMatch(view, /modal-custom-header|modal-custom-footer|Message body template|Placeholders:|\{share_url_line\}/);
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
