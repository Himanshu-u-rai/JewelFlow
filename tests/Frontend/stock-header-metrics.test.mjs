import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import postcss from 'postcss';

const source = readFileSync(new URL('../../resources/views/inventory/items/index.blade.php', import.meta.url), 'utf8');
const css = postcss.parse(readFileSync(new URL('../../resources/css/app.css', import.meta.url), 'utf8'));
const value = (selector, prop, media) => {
    let result;
    css.walkRules(rule => {
        if (rule.selectors.includes(selector) && (rule.parent.type === 'atrule' ? rule.parent.params : undefined) === media) {
            rule.walkDecls(prop, decl => { result = decl.value; });
        }
    });
    return result;
};

test('header metrics wrap content-sized counts without reserving a full row', () => {
    assert.equal(value('.inventory-items-page .items-register-metrics', 'flex'), '0 1 auto');
    assert.equal(value('.inventory-items-page .items-register-metrics', 'display'), 'flex');
    assert.equal(value('.inventory-items-page .items-register-metrics', 'flex-wrap'), 'wrap');
    assert.equal(value('.inventory-items-page .items-register-metrics', 'gap'), '8px');
    assert.equal(value('.inventory-items-page .items-register-metric dd', 'font-size'), '16px');
    assert.equal(value('.inventory-items-page .items-register-holdings', 'flex'), '0 1 240px');
    assert.equal(value('.inventory-items-page .items-register-metrics', 'padding-top'), '12px');
    assert.equal(value('.inventory-items-page .items-register-metrics', 'border-top'), '1px solid var(--jf-border)');
    assert.equal(value('.inventory-items-page .items-register-metrics', 'min-width'), '0');
    assert.equal(value('.inventory-items-page .items-register-metrics', 'height'), undefined);
    assert.doesNotMatch(source, /Metal Holdings|Net weight in stock|items-register-metal-list/);
    assert.equal(value('.inventory-items-page .items-register-metric dd', 'overflow-wrap'), 'anywhere');
});

test('Stock count tiles match Vault with intrinsic sizing and untruncated values', () => {
    const vaultCss = postcss.parse(readFileSync(new URL('../../resources/css/vault-workspace.css', import.meta.url), 'utf8'));
    const tile = '.inventory-items-page .items-register-metric';
    for (const prop of ['display', 'flex', 'flex-wrap', 'align-items', 'gap', 'min-width', 'max-inline-size', 'padding', 'border', 'border-radius', 'background']) {
        let expected;
        vaultCss.walkRules(rule => {
            if (rule.selector === '.vault-page .vault-position-strip .vault-summary div') {
                rule.walkDecls(prop, decl => { expected = decl.value; });
            }
        });
        assert.notEqual(expected, undefined, prop);
        assert.equal(value(tile, prop), expected, prop);
    }
    assert.equal(value(tile, 'width'), undefined);
    assert.equal(value(tile, 'height'), undefined);
    assert.equal(value('.inventory-items-page .items-register-metric dd', 'margin'), '0');
    assert.equal(value('.inventory-items-page .items-register-metric dd', 'min-width'), '0');
    assert.equal(value('.inventory-items-page .items-register-metric dt', 'overflow-wrap'), 'anywhere');
});

test('header search and filter tools stay grouped on the right, with usable phone input sizing', () => {
    assert.equal(value('.inventory-items-page .items-register-tools', 'margin-inline-start'), 'auto');
    assert.equal(value('.inventory-items-page .items-register-tools', 'display'), 'flex');
    assert.equal(value('.inventory-items-page .items-register-tools', 'min-width'), '0');
    assert.equal(value('.inventory-items-page .items-register-header-search', 'flex'), '1 1 240px');
    assert.equal(value('.inventory-items-page .items-register-header-search .items-register-control', 'padding-inline-end'), '48px');
    assert.equal(value('.inventory-items-page .items-register-tools', 'width', '(max-width: 768px)'), '100%');
});

test('wide header separates filters from totals with a thin vertical divider without forcing another row', () => {
    const wide = '(min-width: 1280px)';
    const metrics = '.inventory-items-page .items-register-metrics';
    assert.equal(value(metrics, 'border-top', wide), '0');
    assert.equal(value(metrics, 'padding-top', wide), '0');
    assert.equal(value(metrics, 'border-inline-start', wide), '1px solid var(--jf-border)');
    assert.equal(value(metrics, 'padding-inline-start', wide), '16px');
    assert.equal(value(metrics, 'flex', wide), undefined, 'Keep the existing flexible basis rather than reserving a full row.');
});

test('phone metrics reflow without narrow holdings or clipping the eye control focus', () => {
    const phone = '(max-width: 768px)';
    assert.equal(value('.inventory-items-page .items-register-metrics', 'grid-template-columns', phone), 'repeat(3, minmax(0, 1fr))');
    assert.equal(value('.inventory-items-page .items-register-holdings', 'grid-column', phone), '1 / -1');
    assert.equal(value('.inventory-items-page .items-register-eye', 'min-height'), '44px');
    assert.equal(value('.inventory-items-page .items-register-eye:focus-visible', 'outline-offset'), '2px');
    assert.notEqual(value('.inventory-items-page .items-register-metrics', 'overflow'), 'hidden');
});

test('remaining manufacturer fine-gold control still masks, reveals and auto-hides after three seconds', () => {
    const models = [...source.matchAll(/x-data="(\{ show: false, timer: null, toggle\(\)[^"]+)"/g)];
    assert.equal(models.length, 1);
    for (const [, expression] of models) {
        const pending = new Map();
        let id = 0;
        const model = new Function('setTimeout', 'clearTimeout', `return (${expression});`)(
            (callback, delay) => { assert.equal(delay, 3000); pending.set(++id, callback); return id; },
            timer => pending.delete(timer),
        );
        assert.equal(model.show, false);
        model.toggle();
        assert.equal(model.show, true);
        assert.equal(pending.size, 1);
        model.toggle();
        assert.equal(model.show, false);
        assert.equal(pending.size, 0);
        model.toggle();
        pending.get(model.timer)();
        assert.equal(model.show, false);
    }
});
