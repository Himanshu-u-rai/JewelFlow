import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// UI audit 2026-11-12, F01: on Invoice Details the summary tiles split amounts such as "₹0.00" across two lines,
// because four fixed columns were squeezed into the narrower main column and the value was allowed to break
// anywhere. Tiles now fit as many per row as have room, and an amount never breaks inside itself.
const css = readFileSync(new URL('../../resources/css/app.css', import.meta.url), 'utf8');
const rule = (selector) => {
    const at = css.indexOf(`${selector} {`);
    assert.notEqual(at, -1, `${selector} exists`);
    return css.slice(at, css.indexOf('}', at));
};

test('summary tiles fit as many per row as have room', () => {
    const box = rule('.invoice-show-totals-box');
    assert.match(box, /grid-template-columns:\s*repeat\(auto-fill,\s*minmax\(min\(100%,\s*\d+px\),\s*1fr\)\)/);
    assert.doesNotMatch(box, /repeat\(4,/);
});

test('an amount never breaks inside itself', () => {
    const value = rule('.invoice-show-totals-box strong,\n.invoice-show-payment-footer strong');
    assert.match(value, /white-space:\s*nowrap/);
    assert.doesNotMatch(value, /overflow-wrap:\s*anywhere/);
});

test('the grand total still spans two tiles where two fit, and the whole row on phones', () => {
    assert.match(rule('.invoice-show-totals-box .invoice-show-total-row'), /grid-column:\s*span 2/);
});
