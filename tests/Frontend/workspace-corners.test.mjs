import assert from 'node:assert/strict';
import { existsSync, readFileSync } from 'node:fs';
import test from 'node:test';
import postcss from 'postcss';

const source = new URL('../../resources/css/workspace-corners.css', import.meta.url);
const css = postcss.parse(existsSync(source) ? readFileSync(source, 'utf8') : '');
const rules = [];
css.walkRules(rule => rules.push(rule));

test('workspace corner corrections ship through Vite and do not clip menus or change geometry', () => {
    const app = readFileSync(new URL('../../resources/css/app.css', import.meta.url), 'utf8');
    assert.match(app, /@import "\.\/workspace-corners\.css";/);
    assert.ok(rules.length > 0);
    for (const rule of rules) {
        assert.equal(rule.parent.name, 'media');
        assert.equal(rule.parent.params, 'screen');
        for (const node of rule.nodes) {
            if (node.type !== 'decl') continue;
            assert.ok(['border-radius', 'border-top-left-radius', 'border-top-right-radius', 'border-bottom-left-radius', 'border-bottom-right-radius'].includes(node.prop), node.prop);
            assert.equal(node.value, 'inherit', 'match the actual parent radius, not an unrelated fixed radius');
        }
    }
});

test('painted register and form headers inherit top corners including fieldsets with hidden legends', () => {
    const headers = rules.find(rule => rule.selector.includes('.customers-table-card-header'));
    assert.ok(headers, 'shared header correction exists');
    for (const name of ['.invoices-register-head', '.qb-register__head', '.repairs-card-header', '.schemes-card-head', '[data-historical-card-header]']) {
        assert.ok(headers.selector.includes(name), name);
    }
    assert.ok(headers.nodes.some(node => node.prop === 'border-top-left-radius' && node.value === 'inherit'));
    assert.ok(headers.nodes.some(node => node.prop === 'border-top-right-radius' && node.value === 'inherit'));
});

test('tag form wrappers pass the board radius through to the bottom pagination surface', () => {
    assert.ok(rules.some(rule => rule.selector.includes('#print-form') && rule.nodes.some(node => node.prop === 'border-radius')));
    assert.ok(rules.some(rule => rule.selector.includes('.stock-tool-footer') && rule.nodes.some(node => node.prop === 'border-bottom-left-radius')));
});
