import assert from 'node:assert/strict';
import { existsSync, readFileSync } from 'node:fs';
import test from 'node:test';
import { runInNewContext } from 'node:vm';
import postcss from 'postcss';

const receive = readFileSync(new URL('../../resources/views/job-orders/receive.blade.php', import.meta.url), 'utf8');
const show = readFileSync(new URL('../../resources/views/job-orders/show.blade.php', import.meta.url), 'utf8');
const path = new URL('../../resources/css/job-order-records.css', import.meta.url);
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
function form(purity = 24, metal = 'gold', issued = 100, returned = 80, allowed = 5) {
    const script = receive.match(/<script>([\s\S]*?)<\/script>/)[1]
        .replace('{{ (float) $jobOrder->issued_fine_weight }}', String(issued))
        .replace('{{ (float) $jobOrder->returned_fine_weight }}', String(returned))
        .replace('{{ (float) $jobOrder->allowed_wastage_fine }}', String(allowed));
    return runInNewContext(`${script}; receiveForm(${purity}, '${metal}')`, {});
}

test('receipt calculations preserve gold/silver formulas, multiple lines and editable net weights', () => {
    const gold = form(22);
    Object.assign(gold.lines[0], { gross_weight: '26', stone_weight: '2', pieces: '1000' });
    gold.recompute(0);
    assert.equal(gold.lines[0].net_weight, '24.000');
    assert.equal(gold.totalFine, 22);
    assert.equal(gold.totalPieces, 1000);
    gold.lines[0].net_weight = '12';
    assert.equal(gold.totalFine, 11, 'manual net correction must remain editable');
    gold.addLine();
    Object.assign(gold.lines[1], { gross_weight: '13', stone_weight: '1' });
    gold.recompute(1);
    assert.equal(gold.totalGross, 39);
    assert.equal(gold.totalNet, 24);
    assert.equal(gold.totalFine, 22);
    gold.removeLine(0);
    assert.equal(gold.totalFine, 11);
    const silver = form(925, 'silver');
    Object.assign(silver.lines[0], { gross_weight: 12, stone_weight: 2 });
    silver.recompute(0);
    assert.equal(silver.totalFine, 9.25);
    silver.lines[0].stone_weight = 20;
    silver.recompute(0);
    assert.equal(silver.lines[0].net_weight, '0.000');
});

test('tolerance warning retains the existing partial-return and rounding boundary', () => {
    const state = form();
    state.lines[0].net_weight = 10;
    assert.equal(state.exceedsTolerance, true);
    state.lines[0].net_weight = 15;
    assert.equal(state.exceedsTolerance, false);
    state.lines[0].net_weight = 14.9995;
    assert.equal(state.exceedsTolerance, false);
    state.lines[0].net_weight = 14.998;
    assert.equal(state.exceedsTolerance, true);
});

test('record tables scroll inside their surfaces and receipt inputs keep usable names and targets', () => {
    assert.doesNotMatch(show + receive, /<style\b/);
    assert.equal(value('.job-record-page .job-record-scroll', 'overflow-x'), 'auto');
    assert.equal(value('.job-record-page .job-record-scroll', 'position'), 'relative', 'Keep the absolute screen-reader-only Actions header inside the scroll container');
    assert.equal(value('.job-record-page .job-record-surface', 'overflow'), 'visible');
    assert.equal(value('.job-record-page .job-record-metric', 'min-width'), '0');
    assert.equal(value('.job-record-page .job-record-metric dd', 'overflow-wrap'), 'anywhere');
    assert.equal(value('.job-record-page .job-receive-grid input', 'min-height'), '44px');
    assert.equal(value('.job-record-page .job-receive-grid input', 'font-size', 'screen and (max-width: 768px)'), '16px');
    assert.match(receive, /class="[^\"]*jf-grid job-receive-grid/);
    assert.match(receive, /aria-label="Finished items received"/);
    for (const field of ['description', 'hsn_code', 'pieces', 'gross_weight', 'stone_weight', 'net_weight', 'purity']) {
        const tag = receive.match(new RegExp(`<input[^>]*x-model="line\\.${field}"[^>]*>`))?.[0];
        assert.ok(tag, field);
        assert.match(tag, /:aria-label=/, field);
        if (field === 'net_weight') assert.doesNotMatch(tag, /readonly|disabled/);
    }
    assert.match(receive, /:aria-label="'Remove item line '/);
    assert.doesNotMatch(css.toString(), /overflow:\s*hidden|body\s*\{/);
});
