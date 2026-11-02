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
    assert.equal(declarations('.qb-compact-form .qb-customer-fields', 1280)['grid-template-columns'], 'minmax(0, 1fr) 11rem');
    assert.match(declarations('.qb-compact-form .qb-mobile-product-grid', 1280)['grid-template-columns'], /4\.5rem$/);
    assert.equal(declarations('.qb-compact-form .qb-weight-field', 1280).flex, '0 1 7rem');
    assert.equal(declarations('.qb-compact-form .qb-making-field', 1280).flex, '0 1 14rem');
    assert.equal(declarations('.qb-compact-form .qb-wastage-field', 1280).flex, '0 1 5.5rem');
    assert.equal(declarations('.qb-compact-form .qb-weight-panel', 390).display, undefined);
    assert.equal(declarations('.qb-compact-form .qb-weight-panel', 1280).display, 'contents');
    assert.equal(declarations('.qb-compact-form .qb-review-summary', 768)['grid-template-columns'], 'minmax(0, 1fr)');
    assert.equal(declarations('.qb-compact-form .qb-item-extras', 768)['grid-template-columns'], 'minmax(0, 1fr)');
    assert.match(declarations('.qb-compact-form .qb-review-summary', 1280)['grid-template-columns'], /auto$/);
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
