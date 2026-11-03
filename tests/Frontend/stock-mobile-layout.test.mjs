import assert from 'node:assert/strict';
import { existsSync, readFileSync } from 'node:fs';
import test from 'node:test';
import postcss from 'postcss';

const path = new URL('../../resources/css/stock-mobile.css', import.meta.url);
const css = postcss.parse(existsSync(path) ? readFileSync(path, 'utf8') : '');
const prefix = 'body.app-shell ';
const value = (selector, prop) => {
    let found;
    css.walkRules(rule => {
        if (rule.selectors.includes(prefix + selector)) rule.walkDecls(prop, decl => { found = decl.value; });
    });
    return found;
};

test('Stock mobile overrides are bundled and screen/phone scoped, not desktop or print changes', () => {
    const app = readFileSync(new URL('../../resources/css/app.css', import.meta.url), 'utf8');
    assert.match(app, /@import "\.\/stock-mobile\.css";/);
    assert.ok(css.nodes.length);
    for (const node of css.nodes.filter(node => node.type !== 'comment')) {
        assert.equal(node.type, 'atrule');
        assert.equal(node.name, 'media');
        assert.equal(node.params, 'screen and (max-width: 768px)');
    }
    css.walkRules(rule => { for (const selector of rule.selectors) assert.ok(selector.startsWith(prefix)); });
});

test('Stock filter dialog is a bottom sheet with scrolling and safe-area space, not a centered phone modal', () => {
    const sheet = '.inventory-items-page .items-register-filter-dialog[data-ready]';
    assert.equal(value(sheet, 'inset'), 'auto 0 0');
    assert.equal(value(sheet, 'margin'), '0');
    assert.equal(value(sheet, 'width'), '100%');
    assert.equal(value(sheet, 'border-radius'), '16px 16px 0 0');
    assert.match(value(sheet, 'max-height') || '', /100dvh/);
    assert.equal(value(sheet, 'padding-bottom'), 'env(safe-area-inset-bottom, 0px)');
    assert.equal(value(sheet, 'overflow-y'), 'auto');
    assert.equal(value('.inventory-items-page .items-register-filter-head', 'position'), 'sticky');
});

test('Stock record actions have full touch height and long barcodes can wrap', () => {
    assert.equal(value('.inventory-items-page .items-stock-mobile-action', 'min-height'), '44px');
    assert.equal(value('.inventory-items-page .items-stock-mobile-barcode', 'overflow-wrap'), 'anywhere');
});

test('opening phone filters does not force the search keyboard; desktop keeps search focus', () => {
    const source = readFileSync(new URL('../../resources/views/inventory/items/index.blade.php', import.meta.url), 'utf8');
    const button = source.match(/<button\b[^>]*data-stock-filter-open[^>]*>/)?.[0];
    const handler = button?.match(/@click="([^"]+)"/)?.[1];
    assert.ok(handler);
    let focused = 0, opened = 0;
    const refs = { stockFilters: { close() {}, showModal() { opened++; }, querySelector: () => ({ focus() { focused++; } }) } };
    const click = new Function('$refs', 'window', handler);
    click(refs, { matchMedia: () => ({ matches: false }) });
    assert.equal(opened, 1);
    assert.equal(focused, 0);
    click(refs, { matchMedia: () => ({ matches: true }) });
    assert.equal(opened, 2);
    assert.equal(focused, 1);
});

test('Stock Aging buckets use labelled metrics rather than narrow fixed numeric columns', () => {
    assert.equal(value('.inventory-items-page .items-age-key-head', 'display'), 'none');
    assert.equal(value('.inventory-items-page .items-age-key-row', 'grid-template-columns'), 'repeat(3, minmax(0, 1fr))');
    assert.equal(value('.inventory-items-page .items-age-key-row dt', 'grid-column'), '1 / -1');
    assert.equal(value('.inventory-items-page .items-age-key-row .jf-sr-only', 'position'), 'static');
    const aging = readFileSync(new URL('../../resources/views/inventory/items/partials/aging-overview.blade.php', import.meta.url), 'utf8');
    assert.match(aging, /<span class="jf-sr-only">Share <\/span>/);
});

test('Sell Trend and Aging mobile cards use readable figures and wrapping disclosure headings', () => {
    assert.equal(value('.inventory-items-page .items-seller-mobile-metrics strong', 'font-size'), '14px');
    assert.equal(value('.inventory-items-page .items-aging-mobile-metrics span', 'font-size'), '12px');
    assert.equal(value('.inventory-items-page .items-analytics-details summary', 'flex-wrap'), 'wrap');
});

test('Category sheet forms fit the dynamic viewport and avoid small-input phone zoom', () => {
    assert.equal(value('.categories-modal .categories-input', 'font-size'), '16px');
    assert.equal(value('.categories-modal .categories-input', 'min-height'), '44px');
    assert.match(value('.categories-modal .categories-modal-card', 'max-height') || '', /100dvh/);
    assert.equal(value('.categories-modal .categories-modal-body', 'min-height'), '0');
});

test('Tag Printing options fit full-width rows and custom dimensions keep units visible', () => {
    assert.equal(value('.tags-index-page .tags-print-size', 'grid-template-columns'), '56px minmax(0, 1fr)');
    assert.equal(value('.tags-index-page .tags-print-codes .ui-filter-select-host', 'width'), '100%');
    assert.equal(value('.tags-index-page .tags-print-custom label', 'grid-template-columns'), 'minmax(0, 1fr) 80px auto');
    const tags = readFileSync(new URL('../../resources/views/tags/index.blade.php', import.meta.url), 'utf8');
    assert.match(tags, /class="tags-print-custom /);
});

test('Reorder rules become labelled phone rows with visible full-width actions', () => {
    assert.equal(value('.reorder-index-page .reorder-rules-table', 'min-width'), '0');
    assert.equal(value('.reorder-index-page .reorder-rules-table tbody tr', 'grid-template-columns'), 'repeat(2, minmax(0, 1fr))');
    assert.equal(value('.reorder-index-page .reorder-rules-table td[data-label="Actions"]', 'grid-column'), '1 / -1');
    assert.equal(value('.reorder-index-page .reorder-rules-table .jf-row-action', 'min-height'), '44px');
});
