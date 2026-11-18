import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = path => readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const ledger = read('resources/views/vault/ledger.blade.php');
const lot = read('resources/views/vault/create-lot.blade.php');

test('ledger has one accessible record list with shared filters and a separate footer', () => {
    assert.doesNotMatch(ledger, /<style|vault-ledger-mobile/);
    assert.equal(ledger.split('@forelse($movements as $mv)').length - 1, 1);
    assert.match(ledger, /data-list-workspace/);
    assert.match(ledger, /data-list-frame/);
    assert.match(ledger, /data-list-scroll tabindex="0" role="region"/);
    assert.match(ledger, /<x-responsive-filter-panel id="vault-ledger-filters"/);
    assert.match(ledger, /x-show="dirty \|\| !hasFilters"/);
    assert.match(ledger, /x-show="!dirty && hasFilters"/);
    for (const field of ['When', 'Type', 'From lot', 'To lot', 'Fine weight', 'Reference', 'By']) {
        assert.ok(ledger.includes(`data-label="${field}"`), field);
    }
    assert.match(ledger, /<footer[\s\S]*?\$movements->links\(\)/);
});

test('bullion form retains native posting, old input and the authority math', () => {
    assert.doesNotMatch(lot, /<style|#[0-9a-fA-F]{6}/);
    assert.match(lot, /method="POST" action="{{ route\('vault.lots.store'\) }}"/);
    assert.match(lot, /@csrf/);
    for (const field of ['metal_type', 'source', 'gross_weight', 'purity', 'cost_per_gram', 'payment_mode', 'vendor_id', 'notes']) {
        assert.ok(lot.includes(`name="${field}"`), field);
        assert.ok(lot.includes(`old('${field}'`), `${field} survives validation`);
    }
    assert.match(lot, /this.gross \* \(this.purity \/ 1000\)/);
    assert.match(lot, /this.gross \* \(this.purity \/ 24\)/);
    assert.match(lot, /this.gross \* this.costPerGram/);
    assert.match(lot, /aria-invalid=/);
    assert.match(lot, /@error\('gross_weight'\)/);
    assert.match(lot, /x-ref="puritySelect"/);
    assert.match(lot, /\$nextTick/);
    assert.match(lot, /Number\(gross \|\| 0\)\.toFixed\(3\)/);
});

test('actual preview getters preserve karat/fineness formulas and handle empty inputs', () => {
    const getters = lot.slice(lot.indexOf('get availablePurities()'), lot.indexOf('onMetalChange(val)')).trim().replace(/,$/, '');
    const calculator = new Function(`return ({gross: 10, costPerGram: 100, metalType: 'gold', purityProfiles: [], purityChoice: '22', customPurity: 0, ${getters}})`)();
    assert.equal(calculator.fine.toFixed(3), '9.167');
    assert.equal(calculator.total, 1000);
    calculator.metalType = 'silver';
    calculator.purityChoice = '925';
    assert.equal(calculator.fine, 9.25);
    calculator.purityChoice = 'custom';
    calculator.customPurity = 958;
    assert.equal(calculator.fine, 9.58);
    calculator.gross = '';
    calculator.costPerGram = '';
    assert.equal(calculator.fine, 0);
    assert.equal(calculator.total, 0);
});
