/**
 * Executes the REAL line calculator from resources/js/historical-manual.js and
 * prints what it produced, so PHP can compare it against the backend formula.
 *
 * WHY THIS EXISTS: fine weight and metal value are computed twice in this
 * codebase — once in the browser (`calculateLine`/`fineMultiplier` here) and
 * once on the server (`HistoricalCalculationSuggester`). Neither side had an
 * executing test for the fine-weight leg, so a change to either could silently
 * make the figure the operator approves differ from the figure that is stored.
 * A jeweller reconciling old bills would have no way to see that.
 *
 * This script is NOT the assertion. It is the JS half of a parity check; the
 * cases and every expectation live in
 * tests/Unit/Historical/HistoricalFineWeightParityTest.php, which runs this
 * inside the normal PHPUnit suite and compares the two languages' answers.
 *
 * Usage: node fine-weight.check.mjs '<json array of cases>'
 * Emits: one JSON array of {fine, metal_value} on stdout, nothing else.
 *
 * ponytail: node + argv, no framework and no fixture file. If the JS
 * calculation grows past this, move to vitest and delete the shim below.
 */
import { registerHistoricalManual } from '../../resources/js/historical-manual.js';

globalThis.window = { matchMedia: () => ({ matches: false, addEventListener() {}, removeEventListener() {} }) };

const factories = {};
registerHistoricalManual({ data: (name, factory) => { factories[name] = factory; } });

const cases = JSON.parse(process.argv[2] ?? '[]');

const results = cases.map((testCase) => {
    const form = factories.historicalManualForm({ documentTotals: {}, documentModes: {} });
    const line = { ...form.blankLine(), ...testCase };

    form.calculateLine(line);

    return {
        fine: line.line_fine_weight,
        metal_value: line.line_metal_value,
    };
});

process.stdout.write(JSON.stringify(results));
