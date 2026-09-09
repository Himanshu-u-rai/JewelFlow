/**
 * Executes the real payment getters from resources/js/historical-manual.js.
 *
 * The repo ships no JS test runner and one pair of getters does not justify
 * adding vitest plus a DOM shim, so this is a plain node script: stub the two
 * globals the factory touches, capture the Alpine data factory, and read the
 * getters. tests/Feature/Historical/HistoricalPaymentStatusJsTest.php runs it
 * inside the normal PHPUnit suite.
 *
 * ponytail: node + assert, no framework. If JS logic ever grows past a handful
 * of pure getters, move this to vitest and delete the shim below.
 */
import assert from 'node:assert/strict';
import { registerHistoricalManual } from '../../resources/js/historical-manual.js';

globalThis.window = { matchMedia: () => ({ matches: false, addEventListener() {}, removeEventListener() {} }) };

const factories = {};
registerHistoricalManual({ data: (name, factory) => { factories[name] = factory; } });

/** A form whose grand total is exactly `grandTotal` and whose payments sum to `paid`. */
function formWith(grandTotal, paid) {
    const form = factories.historicalManualForm({ documentTotals: {}, documentModes: {} });
    form.documentTotals = { grand_total: grandTotal };
    form.payments = paid === null ? [] : [{ mode: 'cash', amount: paid }];

    return form;
}

const cases = [
    // grand_total, paid, expected label, expected excess, why this case exists
    ['0', '100', 'Overpaid', 100, 'known zero total, money tendered -> the whole payment is excess'],
    ['0.00', '100', 'Overpaid', 100, 'a formatted zero is still a known zero'],
    ['', '100', 'Partially paid', 0, 'BLANK total is unknown, not zero -> assert no excess'],
    [null, '100', 'Partially paid', 0, 'missing total behaves like blank'],
    ['0', null, 'Unpaid', 0, 'zero total, nothing paid'],
    ['93000', '93000', 'Fully paid', 0, 'exact payment'],
    ['93000', '95500', 'Overpaid', 2500, 'ordinary overpayment'],
    ['93000', '50000', 'Partially paid', 0, 'ordinary short payment'],
    ['93000', null, 'Unpaid', 0, 'nothing paid'],
];

let failures = 0;
for (const [grand, paid, label, excess, why] of cases) {
    const form = formWith(grand, paid);
    const actual = { label: form.paymentStatusLabel, excess: form.paymentExcess };

    try {
        assert.equal(actual.label, label);
        assert.equal(actual.excess, excess);
        console.log(`  ok   grand=${JSON.stringify(grand)} paid=${JSON.stringify(paid)} -> ${actual.label} / excess ${actual.excess}   (${why})`);
    } catch {
        failures += 1;
        console.log(`  FAIL grand=${JSON.stringify(grand)} paid=${JSON.stringify(paid)} -> ${actual.label} / excess ${actual.excess}; expected ${label} / ${excess}   (${why})`);
    }
}

// The pill and the figure are rendered by two separate bindings; they must never
// disagree about whether an overpayment happened.
for (const [grand, paid] of cases) {
    const form = formWith(grand, paid);
    if ((form.paymentStatusLabel === 'Overpaid') !== (form.paymentExcess > 0)) {
        failures += 1;
        console.log(`  FAIL pill/excess disagree at grand=${JSON.stringify(grand)} paid=${JSON.stringify(paid)}`);
    }
}

console.log(failures === 0 ? 'payment-status.check: OK' : `payment-status.check: ${failures} FAILED`);
process.exit(failures === 0 ? 0 : 1);
