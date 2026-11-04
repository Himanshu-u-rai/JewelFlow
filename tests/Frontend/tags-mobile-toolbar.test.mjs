import assert from 'node:assert/strict';
import { existsSync, readFileSync } from 'node:fs';
import test from 'node:test';
import postcss from 'postcss';

const source = readFileSync(new URL('../../resources/views/tags/index.blade.php', import.meta.url), 'utf8');
const path = new URL('../../resources/css/tags-toolbar.css', import.meta.url);
const css = postcss.parse(existsSync(path) ? readFileSync(path, 'utf8') : '');
function value(selector, prop, media = 'screen and (max-width: 768px)') {
    let found;
    css.walkRules(rule => {
        if (rule.selectors.includes('body.app-shell ' + selector) && rule.parent.params === media) rule.walkDecls(prop, decl => { found = decl.value; });
    });
    return found;
}

test('Tag filters reuse the shared bottom sheet without duplicating the GET form', () => {
    assert.match(source, /<x-responsive-filter-panel id="tags-filter-panel" label="Tag filters">/);
    assert.equal((source.match(/class="stock-tool-filters"/g) || []).length, 1);
    assert.equal((source.match(/id="tags-search"/g) || []).length, 1);
    assert.equal((source.match(/id="tags-category"/g) || []).length, 1);
    assert.equal(value('.tags-index-page .stock-tool-header', 'grid-template-columns'), 'minmax(0, 1fr) auto');
    assert.equal(value('.tags-index-page .jf-filter-panel .stock-tool-filters', 'display'), 'grid');
});

test('print settings start collapsed on phones, expanded on desktop, and reveal invalid inputs', () => {
    const details = source.match(/<details class="tags-print-options"[\s\S]*?<\/details>/)?.[0];
    assert.ok(details);
    const init = details.match(/x-init="([^"]+)"/)?.[1];
    const resize = details.match(/@resize.window="([^"]+)"/)?.[1];
    const invalid = details.match(/@invalid.capture="([^"]+)"/)?.[1];
    assert.ok(init); assert.ok(resize); assert.ok(invalid);
    const el = { open: true };
    new Function('$el', 'window', init)(el, { innerWidth: 427 });
    assert.equal(el.open, false);
    new Function('$el', invalid)(el);
    assert.equal(el.open, true);
    el.open = false;
    new Function('$el', 'window', resize)(el, { innerWidth: 1280 });
    assert.equal(el.open, true);
    el.open = false;
    new Function('$el', 'window', resize)(el, { innerWidth: 427 });
    assert.equal(el.open, false, 'Keyboard/phone resizing does not change user disclosure state.');
});

test('compact toolbar keeps desktop layout and readable phone targets rather than shrinking fields', () => {
    assert.equal(value('.tags-index-page .tags-print-options', 'display', 'screen'), 'block');
    for (const selector of ['.tags-index-page .tags-print-options-body', '.tags-index-page .tags-print-actions']) {
        assert.equal(value(selector, 'display', 'screen'), 'flex', 'The real contents container must lay out controls, not rely on flattening native details.');
        assert.equal(value(selector, 'flex-wrap', 'screen'), 'wrap');
        assert.equal(value(selector, 'align-items', 'screen'), 'center');
        assert.equal(value(selector, 'gap', 'screen'), '8px 12px');
    }
    assert.equal(value('.tags-index-page .tags-print-options > summary', 'display', 'screen'), 'none');
    assert.equal(value('.tags-index-page .stock-tool-header .jf-filter-panel', 'flex', 'screen and (min-width: 769px)'), '0 1 560px');
    assert.equal(value('.tags-index-page .tags-print-options > summary', 'display'), 'flex');
    assert.equal(value('.tags-index-page .tags-print-options > summary', 'min-height'), '44px');
    assert.equal(value('.tags-index-page .tags-print-options:not([open]) .tags-print-options-body', 'display'), 'none');
    assert.equal(value('.tags-index-page .tags-print-actions', 'display'), 'flex');
    assert.equal(value('.tags-index-page .tags-print-actions', 'flex-wrap'), 'wrap');
    const app = readFileSync(new URL('../../resources/css/app.css', import.meta.url), 'utf8');
    assert.match(app, /@import "\.\/tags-toolbar\.css";/);
    assert.doesNotMatch(css.toString(), /overflow:\s*hidden|height:\s*\d+vh/);
});
