import assert from 'node:assert/strict';
import { readFileSync, existsSync } from 'node:fs';
import { runInNewContext } from 'node:vm';
import test from 'node:test';
import postcss from 'postcss';

const source = readFileSync(new URL('../../resources/views/karigar-invoices/_form.blade.php', import.meta.url), 'utf8');
const cssPath = new URL('../../resources/css/karigar-invoices.css', import.meta.url);
const css = postcss.parse(existsSync(cssPath) ? readFileSync(cssPath, 'utf8') : '');
function state() {
    const script = source.match(/<script>([\s\S]*?)<\/script>/)[1]
        .replace(/{{ \(\$invoice->is_interstate \?\? false\) \? 'true' : 'false' }}/, 'false')
        .replace(/{{ \(float\) \(\$invoice->cgst_rate \?\? 1.5\) }}/, '1.5')
        .replace(/{{ \(float\) \(\$invoice->sgst_rate \?\? 1.5\) }}/, '1.5')
        .replace(/{{ \(float\) \(\$invoice->igst_rate \?\? 0\) }}/, '0');
    const fn = runInNewContext(`${script}; invoiceForm`, {});
    return fn([{ description: 'Synthetic ring', gross_weight: 12, stone_weight: 2, net_weight: 0, rate_per_gram: 1000, metal_amount: 0, making_charge: 0, wastage_charge: 0, extra_amount: 50 }], 'purchase', { 1: [{ id: 7, amount: 500 }, { id: 8, amount: 1000 }] }, '1', 'Synthetic workshop', 'Purchase');
}
function decl(selector, prop, media) {
    let found;
    css.walkRules(rule => {
        if (rule.selectors.includes(selector) && (rule.parent.type === 'atrule' ? rule.parent.params : undefined) === media) rule.walkDecls(prop, d => { found = d.value; });
    });
    return found;
}

test('real invoice calculations retain net/rate, manual metal, tax and mode-switch values', () => {
    const form = state();
    form.recompute(0);
    assert.equal(form.lines[0].net_weight, 10);
    assert.equal(form.lines[0].metal_amount, 10000);
    assert.equal(form.subtotal, 10050);
    assert.equal(form.tax, 301.5);
    assert.equal(form.grandTotal, 10351.5);
    form.lines[0].metal_amount = 11000;
    form.recomputeTotal(0);
    assert.equal(form.subtotal, 11050);
    form.isInterstate = true;
    form.igstRate = 3;
    assert.equal(form.tax, 331.5);
    form.onModeSelect('job_work', 'Job Work');
    assert.equal(form.lines[0].metal_amount, 11000, 'mode switching must not silently discard entered amounts');
    Object.assign(form.lines[0], { metal_amount: 0, making_charge: 200, wastage_charge: 30, extra_amount: 20 });
    assert.equal(form.subtotal, 250);
    assert.equal(form.grandTotal, 257.5);
    form.addLine();
    Object.assign(form.lines[1], { metal_amount: 100, net_weight: 2 });
    assert.equal(form.totalNet, 12);
    assert.equal(form.subtotal, 350);
    form.removeLine(1);
    assert.equal(form.subtotal, 250);
});

test('advance selection remains explicit and resets when the selected workshop changes', () => {
    const form = state();
    assert.equal(form.selectedAdvanceTotal, 0);
    form.toggleAdvance(7);
    form.toggleAdvance(8);
    assert.equal(form.selectedAdvanceTotal, 1500);
    form.toggleAdvance(7);
    assert.equal(form.selectedAdvanceTotal, 1000);
    form.onKarigarSelect('2', 'Another synthetic workshop');
    assert.equal(form.selectedAdvanceTotal, 0);
    assert.equal(form.checkedAdvanceIds.length, 0);
    form.onKarigarSelect('1', 'Synthetic workshop');
    assert.equal(form.selectedAdvanceTotal, 0, 'changing back must not automatically apply payments');
});

test('invoice summary and fields use shrinkable layouts without trapping short or mobile windows', () => {
    assert.equal(decl('.karigar-invoice-form-page .ki-form-grid', 'min-width'), '0');
    assert.equal(decl('.karigar-invoice-form-page .ki-summary', 'position'), 'static');
    assert.equal(decl('.karigar-invoice-form-page .ki-summary', 'position', 'screen and (min-width: 1200px) and (min-height: 700px)'), 'sticky');
    assert.equal(decl('.karigar-invoice-form-page .ki-control', 'min-height'), '44px');
    assert.equal(decl('.karigar-invoice-form-page .ki-control', 'font-size', 'screen and (max-width: 768px)'), '16px');
    assert.equal(decl('.karigar-invoices-index-page .ki-records', 'position'), 'relative');
});
