import assert from 'node:assert/strict';
import { existsSync, readFileSync } from 'node:fs';
import test from 'node:test';
import { runInNewContext } from 'node:vm';
import postcss from 'postcss';

const view = readFileSync(new URL('../../resources/views/job-orders/create.blade.php', import.meta.url), 'utf8');
const path = new URL('../../resources/css/job-order-create.css', import.meta.url);
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
function form() {
    const script = view.match(/<script>([\s\S]*?)<\/script>/)[1].replace('@js($karigarHeld)', JSON.stringify({
        7: [{ metal_type: 'gold', purity: 22, fine: 50 }],
    }));
    return runInNewContext(`${script}; jobOrderForm()`, {});
}

test('schedule shares one compact row when space allows, with wrapping instead of clipped fields', () => {
    assert.match(view, /class="job-field-grid job-schedule-grid"/);
    assert.equal(value('.job-create-shell .job-schedule-grid', 'display'), 'flex');
    assert.equal(value('.job-create-shell .job-schedule-grid', 'flex-wrap'), 'wrap');
    assert.equal(value('.job-create-shell .job-schedule-grid', 'align-items'), 'end');
    assert.equal(value('.job-create-shell .job-schedule-karigar', 'flex'), '1 1 180px');
    assert.equal(value('.job-create-shell .job-schedule-karigar', 'max-width'), '280px');
    assert.equal(value('.job-create-shell .job-schedule-wastage .job-control', 'width'), '96px');
    assert.equal(value('.job-create-shell .job-schedule-grid', 'display', 'screen and (max-width: 768px)'), 'grid');
});

test('summary sticks beside a shrinkable form only on tall desktops, never over phone or short-window controls', () => {
    assert.doesNotMatch(view, /<style\b/);
    assert.equal(value('.job-create-shell .job-create-grid', 'grid-template-columns'), 'minmax(0, 1fr)');
    const desktop = 'screen and (min-width: 1200px) and (min-height: 700px)';
    assert.equal(value('.job-create-shell .job-create-grid', 'grid-template-columns', desktop), 'minmax(0, 1fr) 300px');
    assert.equal(value('.job-create-shell .job-preview', 'position', desktop), 'sticky');
    assert.equal(value('.job-create-shell .job-preview', 'max-height', desktop), 'calc(100dvh - 116px)');
    assert.equal(value('.job-create-shell .job-preview', 'overflow-y', desktop), 'auto');
    assert.equal(value('.job-create-shell .job-card', 'min-width'), '0');
    assert.equal(value('.job-create-shell .job-card', 'overflow'), 'visible');
    assert.equal(value('.job-create-shell .job-preview-value', 'overflow-wrap'), 'anywhere');
    assert.equal(value('.job-create-shell .job-control', 'min-height'), '44px');
    assert.equal(value('.job-create-shell .job-remove-line', 'min-height'), '44px');
    assert.equal(value('.job-create-shell .job-control', 'font-size', 'screen and (max-width: 768px)'), '16px');
    assert.ok(view.indexOf('Challan Summary') < view.indexOf('Issue &amp; Print Challan') || view.indexOf('Challan Summary') < view.indexOf('Issue & Print Challan'), 'summary precedes submit in mobile reading order');
});

test('gold and silver lot calculations, add/remove and source totals stay unchanged', () => {
    const state = form();
    state.onLotSelect(0, '1', 'Synthetic lot', 22, 100);
    state.lines[0].fine_weight = 22;
    state.recomputeGross(0);
    assert.equal(state.totalGross, 24);
    assert.equal(state.totalFine, 22);
    assert.equal(state.expectedReturn, 21.56);
    state.addLine();
    state.lines[1].fine_weight = 11;
    state.recomputeGross(1);
    assert.equal(state.totalGross, 36);
    state.removeLine(1);
    state.onMetalTypeSelect('silver');
    state.lines[0].purity = 925;
    state.lines[0].fine_weight = 9.25;
    state.recomputeGross(0);
    assert.equal(state.totalGross, 10);
    state.setSource('customer_advance'); state.custFine = 20;
    assert.equal(state.totalGross, 0);
    assert.equal(state.totalFine, 20);
    state.onKarigarSelect('7', 'Synthetic artisan', 5);
    state.setSource('karigar_held'); state.heldFine = 30;
    state.pickHeldBucket(state.heldBuckets[0]);
    assert.equal(state.heldRemaining, 20);
    assert.equal(state.expectedReturn, 28.5);
    state.setSource('none');
    assert.equal(state.totalFine, 0);
    assert.equal(state.expectedReturn, 0);
});

test('mode changes preserve entered amounts and Escape/Turbo close every open dropdown', () => {
    const state = form();
    state.lines[0].fine_weight = '12.345';
    state.onCustomerSelect('9', 'Synthetic customer'); state.custFine = '4.5';
    state.onAdvanceModeSelect('upi', 'UPI');
    state.onPaymentMethodSelect('3', 'Synthetic UPI account');
    for (const source of ['none', 'karigar_held', 'customer_advance', 'vault']) state.setSource(source);
    assert.equal(state.lines[0].fine_weight, '12.345');
    assert.equal(state.custFine, '4.5');
    assert.equal(state.customerId, '9');
    assert.equal(state.advanceMode, 'upi');
    assert.equal(state.paymentMethodId, '3');
    for (const key of ['karigarOpen', 'metalTypeOpen', 'advanceModeOpen', 'paymentMethodOpen', 'customerOpen']) state[key] = true;
    state.lines[0].lotOpen = true;
    state.closeDropdowns();
    assert.equal(state.lines[0].lotOpen, false);
    for (const key of ['karigarOpen', 'metalTypeOpen', 'advanceModeOpen', 'paymentMethodOpen', 'customerOpen']) assert.equal(state[key], false);
});
