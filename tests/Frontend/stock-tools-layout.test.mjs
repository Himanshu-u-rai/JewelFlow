import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import postcss from 'postcss';

const css = postcss.parse(readFileSync(new URL('../../resources/css/app.css', import.meta.url), 'utf8'));
function value(selector, property, media) {
    let found;
    css.walkRules(rule => {
        if (rule.selectors.includes(selector) && (rule.parent.type === 'atrule' ? rule.parent.params : undefined) === media) {
            for (const node of rule.nodes) if (node.prop === property) found = node.value;
        }
    });
    return found;
}

test('stock tools use contained table scrolling and wrapping filter controls without fixed page heights', () => {
    assert.equal(value('.stock-tool-page .stock-tool-scroll', 'overflow'), 'auto');
    assert.equal(value('.stock-tool-page .stock-tool-scroll', 'min-width'), '0');
    assert.equal(value('.stock-tool-page .stock-tool-header', 'flex-wrap'), 'wrap');
    assert.equal(value('.stock-tool-page .stock-tool-filters', 'flex-wrap'), 'wrap');
    assert.equal(value('.stock-tool-page .stock-tool-header', 'position'), undefined);
    assert.equal(value('.stock-tool-page', 'height'), undefined);
    assert.equal(value('.stock-tool-page .categories-sub-list ul', 'grid-template-columns', '(max-width: 768px)'), 'minmax(0, 1fr)');
});

test('tag form nesting can shrink inside an enhanced list workspace and reorder styles live in compiled CSS', () => {
    assert.equal(value('.tags-index-page[data-list-fit] #print-form', 'min-height', 'screen and (min-width: 1024px) and (min-height: 800px)'), '0');
    assert.equal(value('.tags-index-page[data-list-fit] .stock-tool-table-frame', 'flex', 'screen and (min-width: 1024px) and (min-height: 800px)'), '1 1 auto');
    const reorder = readFileSync(new URL('../../resources/views/reorder/index.blade.php', import.meta.url), 'utf8');
    assert.doesNotMatch(reorder, /<style|reorder-mobile-list/);
});

test('tag print toolbar is compact on desktop without squeezing code options or touch targets', () => {
    assert.equal(value('.tags-index-page .stock-tool-print-settings', 'padding'), '10px 12px');
    assert.equal(value('.tags-index-page .stock-tool-print-settings button:not(.ui-filter-select-option)', 'min-height'), '36px');
    assert.equal(value('.tags-index-page .tags-print-codes .ui-filter-select-host', 'width'), '220px');
    assert.equal(value('.tags-index-page .stock-tool-print-settings .ui-filter-select-option', 'white-space'), 'normal');
    assert.equal(value('.tags-index-page .stock-tool-print-settings .ui-filter-select-option', 'min-height'), '40px');
    assert.equal(value('.tags-index-page .stock-tool-print-settings button:not(.ui-filter-select-option)', 'min-height', '(max-width: 768px), (pointer: coarse)'), '44px');
});

test('tag search reserves icon space after compact padding and retains touch sizing', () => {
    assert.equal(value('.tags-index-page .stock-tool-filters .stock-tool-input', 'padding'), '6px 10px');
    // Three classes match the specificity of the shared :is() input rule.
    assert.equal(value('.tags-index-page .stock-tool-search .stock-tool-search-input', 'padding-left'), '36px');
    assert.equal(value('.tags-index-page .stock-tool-filters .stock-tool-input', 'min-height'), '36px');
    assert.equal(value('.tags-index-page .stock-tool-filters .ui-filter-select-trigger', 'min-height'), '36px');
    assert.equal(value('.tags-index-page .stock-tool-filters .stock-tool-input', 'min-height', '(max-width: 768px), (pointer: coarse)'), '44px');
    assert.equal(value('.tags-index-page .stock-tool-search .stock-tool-search-input', 'padding-left', '(max-width: 768px), (pointer: coarse)'), '36px');
});
