import assert from 'node:assert/strict';
import { readFileSync, existsSync } from 'node:fs';
import test from 'node:test';
import postcss from 'postcss';

const view = readFileSync(new URL('../../resources/views/vault/index.blade.php', import.meta.url), 'utf8');
const path = new URL('../../resources/css/vault-workspace.css', import.meta.url);
const css = postcss.parse(existsSync(path) ? readFileSync(path, 'utf8') : '');
function value(selector, property, media) {
    let found;
    css.walkRules(rule => {
        if (rule.selectors.includes(selector) && (rule.parent.type === 'atrule' ? rule.parent.params : undefined) === media) {
            for (const node of rule.nodes) if (node.prop === property) found = node.value;
        }
    });
    return found;
}

test('vault uses one set of records and compiled scoped styles, not separate breakpoint copies', () => {
    assert.doesNotMatch(view, /<style|vault-table-view--|vault-mobile-fab|mobileOverviewOpen/);
    for (const collection of ['lots', 'openJobs', 'recentMovements']) {
        assert.equal(view.split(`@foreach($${collection} as `).length - 1, 1);
    }
    assert.match(readFileSync(new URL('../../resources/css/app.css', import.meta.url), 'utf8'), /@import "\.\/vault-workspace\.css"/);
});

test('growing purity lists scroll only on tall desktops and preserve sticky headings', () => {
    const tall = 'screen and (min-width: 1024px) and (min-height: 800px)';
    assert.equal(value('.vault-page .vault-scroll', 'overflow'), 'auto');
    assert.equal(value('.vault-page .vault-balance-scroll', 'max-block-size', tall), 'min(32dvh, 26rem)');
    assert.equal(value('.vault-page .vault-record-scroll', 'max-block-size', tall), 'min(48dvh, 38rem)');
    assert.equal(value('.vault-page .vault-scroll', 'max-block-size'), undefined);
    assert.equal(value('.vault-page .vault-table > thead > tr > th', 'position'), 'sticky');
    assert.equal(value('.vault-page .vault-table > thead > tr > th', 'top'), '0');
    assert.doesNotMatch(css.toString(), /(^|\n)body\s*\{|overflow:\s*hidden/);
    assert.equal(value('.vault-page .vault-scroll', 'height'), undefined);
});

test('phone records retain all cells and headers with readable wrapping and touch actions', () => {
    const phone = 'screen and (max-width: 768px)';
    assert.equal(value('.vault-page .vault-table > tbody > tr', 'grid-template-columns', phone), 'repeat(2, minmax(0, 1fr))');
    assert.equal(value('.vault-page .vault-table > thead', 'clip-path', phone), 'inset(50%)');
    assert.equal(value('.vault-page .vault-table > tbody > tr > td', 'overflow-wrap', phone), 'anywhere');
    assert.equal(value('.vault-page .vault-switch button', 'min-height'), '44px');
    assert.match(view, /aria-pressed=/);
    assert.doesNotMatch(view, /role="tablist"|onclick=/);
});

test('current position keeps heading, inline metrics and actions in one wrapping strip', () => {
    const strip = view.match(/<section class="vault-surface vault-position-strip"[\s\S]*?<\/section>/)?.[0] || '';
    assert.match(strip, /<header[\s\S]*?<h2[\s\S]*?<dl[\s\S]*?<\/dl>[\s\S]*?class="vault-actions"[\s\S]*?<\/header>/);
    assert.equal(value('.vault-page .vault-position-strip .vault-summary', 'padding'), '0');
    assert.equal(value('.vault-page .vault-position-strip .vault-summary div', 'display'), 'flex');
    assert.equal(value('.vault-page .vault-position-strip .vault-summary div', 'align-items'), 'baseline');
    assert.equal(value('.vault-page .vault-position-strip .vault-summary dd', 'margin'), '0');
    assert.equal(value('.vault-page .vault-position-strip .vault-actions', 'margin-inline-start'), 'auto');
    assert.equal(value('.vault-page .vault-position-strip .vault-summary', 'grid-template-columns', 'screen and (max-width: 768px)'), 'repeat(2, minmax(0, 1fr))');
});

test('summary metric tiles grow with their content without fixed widths or clipped values', () => {
    const tile = '.vault-page .vault-position-strip .vault-summary div';
    assert.equal(value(tile, 'border'), '1px solid var(--jf-border)');
    assert.equal(value(tile, 'border-radius'), '8px');
    assert.equal(value(tile, 'padding'), '6px 10px');
    assert.equal(value(tile, 'background'), 'var(--jf-surface-soft)');
    assert.equal(value(tile, 'flex'), '0 1 auto');
    assert.equal(value(tile, 'max-inline-size'), '100%');
    assert.equal(value(tile, 'min-width'), '0');
    assert.equal(value(tile, 'width'), undefined);
    assert.equal(value(tile, 'height'), undefined);
    assert.equal(value(tile, 'flex-wrap'), 'wrap');
    const metric = '.vault-page .vault-position-strip .vault-summary dd';
    assert.equal(value(metric, 'overflow-wrap'), 'anywhere');
    assert.equal(value(metric, 'min-width'), '0');
    assert.equal(value('.vault-page .vault-position-strip .vault-summary dt', 'overflow-wrap'), 'anywhere');
});
