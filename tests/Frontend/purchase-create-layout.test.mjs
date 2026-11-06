import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { runInNewContext } from 'node:vm';
import postcss from 'postcss';

const blade = readFileSync(new URL('../../resources/views/inventory/purchases/create.blade.php', import.meta.url), 'utf8');
const css = postcss.parse(readFileSync(new URL('../../resources/css/app.css', import.meta.url), 'utf8'));
function value(selector, prop, media) {
    let result;
    css.walkRules(rule => {
        if (rule.selectors.includes(selector) && (rule.parent.type === 'atrule' ? rule.parent.params : undefined) === media) {
            for (const node of rule.nodes) if (node.prop === prop) result = node.value;
        }
    });
    return result;
}

test('compact purchase form is scoped, uses content-sized fields and keeps phone controls full-width and navigation in flow', () => {
    assert.equal(value('.purchase-entry-page', 'max-width'), 'none');
    assert.equal(value('.purchase-entry-page .purchase-field-grid', 'display'), 'flex');
    assert.equal(value('.purchase-entry-page .purchase-field-grid', 'flex-wrap'), 'wrap');
    assert.equal(value('.purchase-entry-page .purchase-field', 'flex'), '0 1 160px');
    assert.equal(value('.purchase-entry-page .purchase-field--weight', 'flex-basis'), '112px');
    assert.equal(value('.purchase-entry-page .purchase-field--amount', 'flex-basis'), '140px');
    assert.equal(value('.purchase-entry-page .purchase-nav', 'position'), 'static');
    assert.equal(value('.purchase-entry-page .purchase-field-grid > div', 'flex', '(max-width: 768px)'), '1 1 100%');
    assert.equal(value('.purchase-entry-page :is(.purchase-input, .purchase-select, .purchase-textarea)', 'font-size', '(max-width: 768px)'), '16px');
    assert.equal(value('.purchase-entry-page .purchase-select', 'appearance'), 'none');
});

function formState(fields = {}) {
    let source = blade.match(/<script>([^]*?)<\/script>/)[1];
    source = source.replace(/const IS_EDIT = [^;]*;/, 'const IS_EDIT = false;')
        .replace(/@json\([^)]*\)/g, "'vendor'").replace(/{{[^]*?}}/g, '0');
    const saved = new Map();
    const state = runInNewContext(`${source}; purchaseForm({}, [])`, {
        document: { getElementById: () => ({ querySelector: selector => fields[selector.match(/name="([^"]*)"/)?.[1]] }) },
        localStorage: { setItem: (key, value) => saved.set(key, value), getItem: key => saved.get(key), removeItem: key => saved.delete(key) },
        setTimeout, clearTimeout,
    });
    state.$nextTick = fn => fn();
    state.$refs = {};
    state.scheduleSave = () => {};
    return { state, saved };
}

test('item and tax calculations remain usable through compact controls, including manual net weight', () => {
    const { state } = formState();
    state.addLine();
    const line = state.lines[0];
    Object.assign(line, { gross_weight: '10', stone_weight: '2', purchase_rate_per_gram: '100', making_charges: '50', stone_charges: '20', hallmark_charges: '10', rhodium_charges: '5', other_charges: '15' });
    state.recalcLine(line);
    assert.equal(line.net_metal_weight, '8.000');
    assert.equal(line.purchase_line_amount, '900.00');
    line.net_metal_weight = '7';
    state.recalcLineFromNet(line);
    assert.equal(line.purchase_line_amount, '800.00');
    Object.assign(state, { labourDiscount: '100', cgstRate: '1.5', sgstRate: '1.5', tcsAmount: '10' });
    assert.equal(state.grandTotal, 731);
    state.igstRate = '3';
    assert.equal(state.hasTaxConflict, true);
});

test('a purchase rate the user typed survives purity and metal changes; an auto-filled one follows them', () => {
    const { state } = formState();
    state.resolvedRates = { gold: { '22.00': { rate_per_gram: 6600 }, '18.00': { rate_per_gram: 5400 } } };

    // the supplier's own price, typed before the purity
    state.addLine();
    const typed = state.lines[0];
    Object.assign(typed, { metal_type: 'gold', gross_weight: '8', purchase_rate_per_gram: '6000' });
    typed.purity = '22';
    state.prefillRate(typed);
    assert.equal(String(typed.purchase_rate_per_gram), '6000');

    // an empty rate is filled from today's rate and follows a later purity change
    state.addLine();
    const auto = state.lines[1];
    Object.assign(auto, { metal_type: 'gold', gross_weight: '8', purchase_rate_per_gram: '' });
    auto.purity = '22';
    state.prefillRate(auto);
    assert.equal(String(auto.purchase_rate_per_gram), '6600');
    auto.purity = '18';
    state.prefillRate(auto);
    assert.equal(String(auto.purchase_rate_per_gram), '5400');

    // once the user changes an auto-filled rate, it is theirs
    auto.purchase_rate_per_gram = '5300';
    auto.purity = '22';
    state.prefillRate(auto);
    assert.equal(String(auto.purchase_rate_per_gram), '5300');
});

test('details, step navigation and local draft state remain intact', () => {
    const { state, saved } = formState();
    state.addLine();
    state.toggleLineDetails(state.lines[0]);
    assert.equal(state.lines[0].detailsOpen, true);
    state.goToStep(3);
    assert.equal(state.currentStep, 3);
    assert.equal(JSON.parse([...saved.values()][0]).currentStep, 3);
    state.prevStep();
    assert.equal(state.currentStep, 2);
    state.nextStep();
    assert.equal(state.currentStep, 3);
    state.removeLine(0);
    assert.equal(state.lines.length, 0);
});

test('only New Supplier submits automatic vendor creation; switching back ignores stale supplier input', () => {
    const tag = name => blade.match(new RegExp(`<(?:input|select)[^]*?\\bname="${name}"[^]*?>`))?.[0].split(/(?=<(?:input|select)\b)/).at(-1);
    const disabled = name => {
        const binding = tag(name)?.match(/:disabled="([^"]*)"/)?.[1];
        assert.ok(binding, `${name} must follow the chosen supplier mode`);
        return mode => runInNewContext(binding, { supplierMode: mode });
    };
    for (const mode of ['vendor', 'other']) {
        assert.equal(disabled('save_as_vendor')(mode), mode !== 'other');
        assert.equal(disabled('supplier_name')(mode), mode !== 'other');
        assert.equal(disabled('vendor_id')(mode), mode !== 'vendor');
    }
});

test('an older saved draft cannot turn automatic supplier saving off', () => {
    const flag = { type: 'hidden', value: '1' };
    const { state, saved } = formState({ save_as_vendor: flag });
    saved.set('jf_purchase_draft_0', JSON.stringify({ supplierMode: 'other', inputs: { save_as_vendor: false } }));
    state.init();
    assert.equal(state.supplierMode, 'other');
    assert.equal(flag.value, '1');
    state.saveDraft();
    assert.equal(Object.hasOwn(JSON.parse([...saved.values()][0]).inputs, 'save_as_vendor'), false);
});
