<?php

namespace Tests\Unit\Historical;

use App\Services\Historical\HistoricalCalculationSuggester;
use App\Services\MetalRegistry;
use Tests\TestCase;

/**
 * THE BROWSER AND THE SERVER MUST AGREE ABOUT METAL.
 *
 * Fine weight and metal value are computed twice: once in
 * `resources/js/historical-manual.js` (`calculateLine` / `fineMultiplier`), so
 * the operator sees a live figure while typing an old bill, and once in
 * `HistoricalCalculationSuggester`, which is what actually gets stored. The two
 * implementations are hand-mirrored — the JS hardcodes `purity/24` and
 * `purity/1000`, the PHP delegates to `MetalRegistry::fineWeightMultiplier()`.
 *
 * Before this test the fine-weight leg had no executing test on either side.
 * A change to one formula would have shipped green, and the number the operator
 * approved would quietly stop being the number in the archive — the one class of
 * bug a historical-records module cannot tolerate, because there is no live
 * document to reconcile against afterwards.
 *
 * Deliberately in tests/Unit and deliberately WITHOUT RefreshDatabase: both
 * implementations are pure functions of their arguments, so touching the
 * database here would only add a way for the test to fail for unrelated reasons.
 */
class HistoricalFineWeightParityTest extends TestCase
{
    /**
     * Each case is the scalar input a single line carries.
     *
     * `line_billable_weight_mode` is 'manual' throughout so the billable weight
     * under test is exactly the one named here — 'auto' would re-derive it from
     * gross/net and the case would stop testing what it says it tests.
     *
     * @return array<string, array{metal: ?string, purity: ?float, weight: float, rate: float, basis: string}>
     */
    private function cases(): array
    {
        return [
            // The worked example from HistoricalCalculationSuggester's own
            // docblock: a real 22K invoice printed 15g @ 6,200 = 93,000 metal.
            // Reading that rate as a 24K reference yields 85,250 — 7,750 short,
            // exactly the 22/24 gap. Both readings must survive the round trip.
            'gold 22K, rate as printed on the bill' => [
                'metal' => 'gold', 'purity' => 22.0, 'weight' => 15.0, 'rate' => 6200.0, 'basis' => 'as_printed',
            ],
            'gold 22K, rate quoted against pure 24K' => [
                'metal' => 'gold', 'purity' => 22.0, 'weight' => 15.0, 'rate' => 6200.0, 'basis' => 'pure_reference',
            ],
            'silver 925, rate as printed' => [
                'metal' => 'silver', 'purity' => 925.0, 'weight' => 100.0, 'rate' => 80.0, 'basis' => 'as_printed',
            ],
            'silver 999, rate quoted against pure' => [
                'metal' => 'silver', 'purity' => 999.0, 'weight' => 250.0, 'rate' => 76.5, 'basis' => 'pure_reference',
            ],
            // Purity is accounting truth for gold/silver, so a missing one is
            // unanswerable — NOT silently 24K/999 pure.
            'gold with no purity stated' => [
                'metal' => 'gold', 'purity' => null, 'weight' => 15.0, 'rate' => 6200.0, 'basis' => 'as_printed',
            ],
            // Platinum is supported but its purity is not accounting truth, so
            // billable weight passes through unscaled.
            'platinum 950 passes through unscaled' => [
                'metal' => 'platinum', 'purity' => 950.0, 'weight' => 10.0, 'rate' => 3000.0, 'basis' => 'as_printed',
            ],
            'a metal the registry has never heard of' => [
                'metal' => 'unobtainium', 'purity' => null, 'weight' => 10.0, 'rate' => 100.0, 'basis' => 'as_printed',
            ],
            'no metal chosen at all' => [
                'metal' => null, 'purity' => null, 'weight' => 10.0, 'rate' => 100.0, 'basis' => 'as_printed',
            ],
            // Fractional purity (a hallmark grade like 91.6% expressed in karat)
            // is where a rounding difference between the two languages shows up.
            'gold 91.6 expressed as a fractional karat' => [
                'metal' => 'gold', 'purity' => 21.984, 'weight' => 12.345, 'rate' => 6187.5, 'basis' => 'pure_reference',
            ],
        ];
    }

    public function test_the_browser_and_the_server_compute_the_same_metal_value(): void
    {
        $js = $this->runTheBrowserCalculator();
        $suggester = new HistoricalCalculationSuggester;

        foreach (array_keys($this->cases()) as $index => $label) {
            $case = $this->cases()[$label];

            $expected = $suggester->suggestMetalValue(
                $case['metal'],
                $case['purity'],
                $case['weight'],
                $case['rate'],
                $case['basis'],
            );

            $actual = $js[$index]['metal_value'];

            if ($expected === null) {
                $this->assertSame('', $actual, "[{$label}] the server cannot suggest a value, so the browser must show nothing — not 0, not an unscaled figure.");

                continue;
            }

            $this->assertEqualsWithDelta(
                $expected,
                (float) $actual,
                0.01,
                "[{$label}] the browser showed {$actual} but the server would store {$expected}."
            );
        }
    }

    public function test_the_browser_and_the_server_compute_the_same_fine_weight(): void
    {
        $js = $this->runTheBrowserCalculator();

        foreach (array_keys($this->cases()) as $index => $label) {
            $case = $this->cases()[$label];
            $actual = $js[$index]['fine'];

            $expected = $this->serverFineWeight($case);

            if ($expected === null) {
                $this->assertSame('', $actual, "[{$label}] fine weight is unknowable here; the browser must leave it blank rather than invent one.");

                continue;
            }

            $this->assertEqualsWithDelta(
                $expected,
                (float) $actual,
                0.001,
                "[{$label}] browser fine weight {$actual} disagrees with the registry's {$expected}."
            );
        }
    }

    /**
     * Fine weight is metal-content bookkeeping: it uses the TRUE purity
     * multiplier and never cares how the rate was quoted. Asserted separately
     * from the values above because it is the claim most likely to be broken by
     * someone "simplifying" the rate-basis branch.
     */
    public function test_fine_weight_ignores_the_rate_basis_while_metal_value_does_not(): void
    {
        $js = $this->runTheBrowserCalculator();
        $labels = array_keys($this->cases());

        $asPrinted = $js[array_search('gold 22K, rate as printed on the bill', $labels, true)];
        $pure = $js[array_search('gold 22K, rate quoted against pure 24K', $labels, true)];

        $this->assertSame(
            $asPrinted['fine'],
            $pure['fine'],
            'The same 15g of 22K gold holds the same fine gold regardless of how its rate was quoted.'
        );
        $this->assertEqualsWithDelta(13.75, (float) $asPrinted['fine'], 0.001, '15g x 22/24');

        $this->assertNotSame(
            $asPrinted['metal_value'],
            $pure['metal_value'],
            'The rate basis MUST change the price, or the as_printed/pure_reference distinction is decorative.'
        );
        $this->assertEqualsWithDelta(93000, (float) $asPrinted['metal_value'], 0.01);
        $this->assertEqualsWithDelta(85250, (float) $pure['metal_value'], 0.01);
    }

    /**
     * The server-side fine multiplier, read from the registry rather than
     * restated here — a second hand-written copy of `purity/24` in the test
     * would agree with a broken implementation just as happily as a correct one.
     */
    private function serverFineWeight(array $case): ?float
    {
        if ($case['metal'] === null || ! MetalRegistry::isSupported($case['metal'])) {
            return $case['weight']; // multiplier 1.0
        }

        if ($case['purity'] === null) {
            return MetalRegistry::purityIsAccountingTruth($case['metal']) ? null : $case['weight'];
        }

        $multiplier = MetalRegistry::fineWeightMultiplier($case['metal'], $case['purity']) ?? 1.0;

        return $case['weight'] * $multiplier;
    }

    /**
     * Runs the actual shipped JS. Not a PHP re-implementation of it — that would
     * prove only that this file agrees with itself.
     *
     * @return array<int, array{fine: string, metal_value: string}>
     */
    private function runTheBrowserCalculator(): array
    {
        $script = base_path('tests/js/fine-weight.check.mjs');
        $this->assertFileExists($script);

        exec('node --version 2>/dev/null', $probeOutput, $probe);
        if ($probe !== 0) {
            $this->markTestSkipped('node is not available; the browser half of this parity check cannot run here.');
        }

        $payload = json_encode(array_values(array_map(fn (array $case): array => [
            'line_metal_type'           => $case['metal'] ?? '',
            'line_purity_value'         => $case['purity'] === null ? '' : (string) $case['purity'],
            'line_billable_weight'      => (string) $case['weight'],
            'line_billable_weight_mode' => 'manual',
            'line_rate'                 => (string) $case['rate'],
            'line_rate_basis'           => $case['basis'],
        ], $this->cases())), JSON_THROW_ON_ERROR);

        exec('node '.escapeshellarg($script).' '.escapeshellarg($payload).' 2>&1', $output, $status);
        $raw = implode('', $output);

        $this->assertSame(0, $status, "The browser calculator failed to run:\n".$raw);

        $decoded = json_decode($raw, true);
        $this->assertIsArray($decoded, "Expected JSON from the browser calculator, got:\n".$raw);
        $this->assertCount(count($this->cases()), $decoded, 'Every case must come back answered.');

        return $decoded;
    }
}
