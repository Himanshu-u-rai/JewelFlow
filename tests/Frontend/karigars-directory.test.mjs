import assert from 'node:assert/strict';
import { readFileSync, existsSync } from 'node:fs';
import test from 'node:test';
import postcss from 'postcss';

const view = readFileSync(new URL('../../resources/views/karigars/index.blade.php', import.meta.url), 'utf8');
const path = new URL('../../resources/css/karigars-directory.css', import.meta.url);
const css = postcss.parse(existsSync(path) ? readFileSync(path, 'utf8') : '');
function value(selector, prop, media) {
    let result;
    css.walkRules(rule => {
        if (rule.selectors.includes(selector) && (rule.parent.type === 'atrule' ? rule.parent.params : undefined) === media) {
            rule.walkDecls(prop, decl => { result = decl.value; });
        }
    });
    return result;
}

test('Karigar metrics use content-sized Vault-style tiles and compiled scoped styles', () => {
    assert.match(readFileSync(new URL('../../resources/css/app.css', import.meta.url), 'utf8'), /@import "\.\/karigars-directory.css"/);
    const tile = '.karigars-index-page .karigars-metric';
    assert.equal(value(tile, 'border'), '1px solid var(--jf-border)');
    assert.equal(value(tile, 'border-radius'), '8px');
    assert.equal(value(tile, 'padding'), '6px 10px');
    assert.equal(value(tile, 'flex-wrap'), 'wrap');
    assert.equal(value(tile, 'max-inline-size'), '100%');
    assert.equal(value(tile, 'width'), undefined);
    assert.equal(value('.karigars-index-page .karigars-metric dd', 'overflow-wrap'), 'anywhere');
    assert.doesNotMatch(view, /<style|karigars-kpi-grid|karigars-filter-tabs/);
});

test('directory reuses shared mobile bottom-sheet filters and contained desktop records', () => {
    assert.match(view, /<x-responsive-filter-panel id="karigars-filter-panel"/);
    assert.match(view, /data-enhance-selects-variant="standard" data-enhance-selects-placement="viewport"/);
    assert.match(view, /data-list-workspace/);
    assert.match(view, /data-list-frame/);
    assert.match(view, /data-list-scroll tabindex="0" role="region"/);
    assert.equal(value('.karigars-index-page .karigars-register', 'overflow'), 'visible');
    assert.equal(value('.karigars-index-page .karigars-register-head', 'flex-wrap'), 'wrap');
    assert.equal(value('.karigars-index-page .karigars-filters', 'flex-wrap'), 'wrap');
});

test('phone cards wrap long fields, actions retain touch targets and header actions stay in flow', () => {
    const phone = 'screen and (max-width: 768px)';
    assert.equal(value('.karigars-index-page .karigars-mobile-meta dd', 'overflow-wrap'), 'anywhere');
    assert.equal(value('.karigars-index-page .jf-row-action', 'min-height'), '44px');
    assert.equal(value('.karigars-index-page .karigars-filter-input', 'font-size', phone), '16px');
    assert.equal(value('body.app-shell .karigars-page-header > .notice-bell', 'position', phone), 'relative');
    assert.equal(value('body.app-shell .content-header.karigars-page-header', 'grid-template-columns', phone), '44px minmax(0, 1fr) auto 44px');
});
