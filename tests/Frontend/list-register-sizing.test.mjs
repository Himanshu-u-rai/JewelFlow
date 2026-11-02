import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import postcss from 'postcss';

const app = postcss.parse(readFileSync(new URL('../../resources/css/app.css', import.meta.url), 'utf8'));
const tables = postcss.parse(readFileSync(new URL('../../resources/css/tables.css', import.meta.url), 'utf8'));
const value = (css, selector, property, media) => {
    let result;
    css.walkRules(rule => {
        if (!rule.selectors.includes(selector)) return;
        if ((rule.parent.type === 'atrule' ? rule.parent.params : undefined) !== media) return;
        for (const decl of rule.nodes) if (decl.prop === property) result = decl.value;
    });
    return result;
};

test('opted-in list pages use the same full-width shrinkable workspace rather than independent width caps', () => {
    assert.equal(value(tables, '.content-inner[data-list-workspace]', 'width'), '100%');
    assert.equal(value(tables, '.content-inner[data-list-workspace]', 'max-width'), 'none');
    assert.equal(value(tables, '.content-inner[data-list-workspace]', 'min-width'), '0');
});

test('invoice register heading spacing matches Customers on desktop and phones', () => {
    const invoice = '.invoices-index-page .invoices-register-head';
    const customer = '.customers-index-page .customers-register-head';
    assert.equal(value(app, invoice, 'padding'), value(app, customer, 'padding'));
    assert.equal(value(app, invoice, 'gap'), value(app, customer, 'gap'));
    assert.equal(value(app, invoice, 'padding', '(max-width: 768px)'), value(app, customer, 'padding', '(max-width: 768px)'));
});

test('sticky invoice actions keep their pinning without a cast shadow', () => {
    const selector = '.jf-table--sticky-end > tbody > tr > td:last-child';
    assert.equal(value(tables, selector, 'position'), 'sticky');
    assert.equal(value(tables, selector, 'right'), '0');
    assert.equal(value(tables, selector, 'box-shadow'), 'none');
    assert.equal(value(tables, selector, 'border-inline-start'), '1px solid var(--jf-t-head-line)');
});
