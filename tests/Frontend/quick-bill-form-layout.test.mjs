import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';
import test from 'node:test';
import postcss from 'postcss';

const blade = readFileSync(new URL('../../resources/views/quick-bills/form.blade.php', import.meta.url), 'utf8');
const css = postcss.parse(readFileSync(new URL('../../resources/css/app.css', import.meta.url), 'utf8'));
// Named-rule source contract only; browser layout and hitboxes are separate checks.
function declarations(selector, width) {
    const result = {};
    css.walkRules(rule => {
        if (!rule.selectors.includes(selector)) return;
        for (let parent = rule.parent; parent?.type !== 'root'; parent = parent.parent) {
            if (parent.type !== 'atrule') continue;
            const match = parent.params.match(/^\((min|max)-width: (\d+)px\)$/);
            if (parent.name !== 'media' || !match) return;
            if (match[1] === 'min' ? width < +match[2] : width > +match[2]) return;
        }
        rule.walkDecls(node => { result[node.prop] = node.value; });
    });
    return result;
}

test('entry has full-width work area, proportionate desktop fields and readable mobile fields', () => {
    assert.match(blade, /qb-compact-form/);
    for (const width of [320, 390, 768, 1280, 1600]) {
        assert.equal(declarations('.qb-compact-form .qb-entry-layout', width)['grid-template-columns'], 'minmax(0, 1fr)');
        assert.equal(declarations('.qb-compact-form .qb-top-grid', width)['align-items'], 'start');
    }
    assert.equal(declarations('.qb-compact-form .qb-customer-fields', 390)['grid-template-columns'], 'minmax(0, 1fr)');
    assert.equal(declarations('.qb-compact-form .qb-description-field', 390).width, undefined);
    assert.equal(declarations('.qb-compact-form .qb-description-field', 1280).width, undefined);
    assert.equal(declarations('.qb-compact-form .qb-description-weight-row', 1280).display, 'flex');
    assert.equal(declarations('.qb-compact-form .qb-description-weight-row', 1280)['flex-wrap'], 'wrap');
    assert.equal(declarations('.qb-compact-form .qb-metal-purity-fields', 1280)['grid-template-columns'], 'minmax(0, 7rem) minmax(0, 6rem)');
    assert.equal(declarations('.qb-compact-form .qb-metal-purity-fields', 390)['grid-template-columns'], 'repeat(2, minmax(0, 1fr))');
    assert.equal(declarations('.qb-compact-form .qb-description-weight-row', 390)['grid-template-columns'], 'minmax(0, 1fr)');
    assert.equal(declarations('.qb-compact-form .qb-customer-fields', 1280)['grid-template-columns'], 'minmax(12rem, 1fr) 10.5rem minmax(10rem, 1fr) 10.5rem');
    assert.match(declarations('.qb-compact-form .qb-mobile-product-grid', 1280)['grid-template-columns'], /4\.5rem$/);
    assert.equal(declarations('.qb-compact-form .qb-mobile-weight-grid', 1280)['grid-template-columns'], 'repeat(3, minmax(0, 7rem))');
    assert.equal(declarations('.qb-compact-form .qb-making-field', 1280).flex, '0 1 14rem');
    assert.equal(declarations('.qb-compact-form .qb-wastage-field', 1280).flex, '0 1 5.5rem');
    assert.equal(declarations('.qb-compact-form .qb-weight-panel', 390).display, undefined);
    assert.equal(declarations('.qb-compact-form .qb-weight-panel', 1280).display, undefined);
    assert.equal(declarations('.qb-compact-form .qb-review-summary', 768)['grid-template-columns'], 'minmax(0, 1fr)');
    assert.equal(declarations('.qb-compact-form .qb-item-extras', 768)['grid-template-columns'], 'minmax(0, 1fr)');
    assert.match(declarations('.qb-compact-form .qb-review-summary', 1280)['grid-template-columns'], /auto$/);
});

test('description and original weight controls share the first group in DOM/tab order', () => {
    const group = blade.slice(blade.indexOf('class="qb-description-weight-row"'), blade.indexOf('class="qb-mobile-product-grid"'));
    assert.match(group, /qb-description-field/);
    for (const field of ['gross_weight', 'stone_weight', 'net_weight']) {
        assert.ok(group.includes(`x-model.number="item.${field}"`), `${field} belongs beside description`);
        assert.equal(blade.split(`x-model.number="item.${field}"`).length - 1, 1, 'no duplicated weight inputs');
    }
    assert.ok(group.indexOf('item.gross_weight') < group.indexOf('item.stone_weight'));
    assert.ok(group.indexOf('item.stone_weight') < group.indexOf('item.net_weight'));
    assert.ok(group.includes('class="qb-metal-purity-fields"'));
    assert.ok(group.indexOf('item.net_weight') < group.indexOf('item.metal_type'));
    assert.ok(group.indexOf('item.metal_type') < group.indexOf('item.purity'));
});

test('HSN, pieces and rate follow metal and purity in the same wrapping entry row', () => {
    const row = blade.slice(blade.indexOf('class="qb-description-weight-row"'), blade.indexOf('class="qb-measures-grid"'));
    for (const model of ['item.hsn_code', 'item.pcs', 'item.rate']) {
        assert.ok(row.includes(`="${model}"`), `${model} stays in the entry row`);
    }
    assert.ok(row.indexOf('item.purity') < row.indexOf('item.hsn_code'));
    assert.ok(row.indexOf('item.hsn_code') < row.indexOf('item.pcs'));
    assert.ok(row.indexOf('item.pcs') < row.indexOf('item.rate'));
    assert.equal(blade.split('x-model.number="item.rate"').length - 1, 1);
    assert.equal(declarations('.qb-compact-form .qb-mobile-product-grid', 1280).flex, '0 1 12.25rem');
    assert.equal(declarations('.qb-compact-form .qb-rate-field', 1280).flex, '0 1 6rem');
    assert.equal(declarations('.qb-compact-form .qb-rate-field', 390).flex, undefined);
});

test('notes use native modal focus/Escape handling and selects have a consistent visible chevron', () => {
    assert.match(blade, /<dialog[^>]*x-ref="notesDialog"[^>]*@turbo:before-cache\.window="\$refs.notesDialog.close\(\)"/);
    assert.match(blade, /id="qb-notes"[^>]*autofocus/);
    assert.match(declarations('.qb-compact-form .qb-notes-dialog', 390).width, /min\(/);
    assert.equal(declarations('.qb-compact-form .qb-notes-dialog', 390)['max-height'], 'calc(100dvh - 32px)');
    const select = declarations('.content-inner.qb-compact-form.qb-edit-page select', 1280);
    assert.match(select['background-image'], /data:image\/svg\+xml/);
    assert.equal(select['background-position'], 'right 10px center');
    assert.equal(select['background-size'], '18px 18px');
    assert.doesNotMatch(blade, /class="qb-making-select-icon"/);
    const inline = postcss.parse(blade.match(/<style>([^]*?)<\/style>/)[1]);
    inline.walkRules(rule => {
        if (rule.selector.includes('.qb-edit-page') && rule.selector.includes('select')) {
            assert.ok(!rule.nodes.some(node => node.prop === 'background'), 'select arrows must survive normal, focused and disabled styles');
        }
    });
});

test('phone input text wins over the legacy important text-sm/text-xs shrink rules', () => {
    let font;
    css.walkRules(rule => {
        if (rule.parent.type === 'root' && rule.selectors.includes('.qb-compact-form.qb-edit-page input:not([type="hidden"])')) {
            font = rule.nodes.find(node => node.prop === 'font-size');
        }
    });
    assert.equal(font?.value, '16px');
    assert.equal(font?.important, true, 'otherwise global mobile typography still shrinks these fields to 11/12px');
});

function form(config = {}) {
    const context = { window: {} };
    runInNewContext(blade.match(/<script>([^]*?)<\/script>/)[1], context);
    const instance = context.window.quickBillForm(config);
    instance.$nextTick = fn => fn();
    return instance;
}

test('compact controls retain manual/automatic net weight and additional charge state', () => {
    const f = form({ items: [{ gross_weight: 10, stone_weight: 1, net_weight: 8, hallmark_charge: 40 }] });
    const item = f.items[0];
    assert.equal(item._chargesOpen, true);
    f.recalcNet(item);
    assert.equal(item.net_weight, 8);
    item.net_weight = 0;
    f.onNetInput(item);
    f.recalcNet(item);
    assert.equal(item.net_weight, 9);
    f.addItem();
    assert.equal(f.items.length, 2);
    assert.equal(f.openIndex, 1);
    f.removeItem(1);
    assert.equal(f.items[0].hallmark_charge, 40);
});

test('making, tax, discount and payment calculations still use the original fields', () => {
    const f = form({ pricingMode: 'gst_exclusive', gstRate: 3, discountType: 'fixed', discountValue: 100,
        items: [{ metal_type: 'Gold', purity: '24K', net_weight: 2, rate: 1000, making_charge_type: 'percentage', making_charge: 10 }],
        payments: [{ payment_mode: 'cash', amount: 500 }] });
    assert.equal(f.lineMaking(f.items[0]), 200);
    assert.equal(f.subtotal, 2200);
    assert.equal(f.totalAmount, 2163);
    assert.equal(f.dueAmount, 1663);
    f.items[0].making_charge_type = 'per_gram';
    assert.equal(f.lineMaking(f.items[0]), 20);
    f.openReview();
    assert.equal(f.reviewOpen, true);
    f.closeReview();
    assert.equal(f.reviewOpen, false);
});
