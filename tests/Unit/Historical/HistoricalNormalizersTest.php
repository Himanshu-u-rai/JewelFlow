<?php

namespace Tests\Unit\Historical;

use App\Models\Historical\HistoricalSalesDocument;
use App\Services\Historical\HistoricalDateParser;
use App\Services\Historical\HistoricalMakingChargeNormalizer;
use App\Services\Historical\HistoricalSourceFileReader;
use App\Services\Historical\HistoricalTaxNormalizer;
use App\Support\Historical\HistoricalMakingCharge;
use App\Support\Historical\HistoricalMessages;
use App\Support\Historical\HistoricalParseException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Gate 9 — targeted unit coverage for the three pure normalizers that carry
 * Batch 2's money-safety rules. No DB, no HTTP: these classes take plain
 * scalars/arrays in and out, so a fast unit test proves each rule directly
 * against the source (Phase 4/10/11), instead of only indirectly through the
 * end-to-end closure-audit fixtures.
 */
class HistoricalNormalizersTest extends TestCase
{
    // =========================================================== date parser

    public function test_same_slash_string_means_different_dates_under_dmy_vs_mdy(): void
    {
        $parser = new HistoricalDateParser();

        $dmy = $parser->parse('04/05/2024', HistoricalDateParser::FORMAT_DMY);
        $mdy = $parser->parse('04/05/2024', HistoricalDateParser::FORMAT_MDY);

        $this->assertSame('2024-05-04', $dmy->toDateString()); // 4 May
        $this->assertSame('2024-04-05', $mdy->toDateString()); // 5 April
    }

    public function test_ambiguous_date_flag_is_true_only_when_both_readings_are_valid_calendar_dates(): void
    {
        $this->assertTrue(HistoricalDateParser::isAmbiguous('04/05/2024'));
        $this->assertFalse(HistoricalDateParser::isAmbiguous('25/05/2024')); // 25 cannot be a month
    }

    public function test_excel_serial_resolves_to_the_documented_calendar_date(): void
    {
        $parser = new HistoricalDateParser();

        $this->assertSame('2024-05-04', $parser->parse('45416', HistoricalDateParser::FORMAT_EXCEL_SERIAL)->toDateString());
    }

    public function test_excel_serial_60_is_rejected_not_silently_resolved(): void
    {
        $parser = new HistoricalDateParser();

        $this->expectException(HistoricalParseException::class);
        $parser->parse('60', HistoricalDateParser::FORMAT_EXCEL_SERIAL);
    }

    public function test_impossible_calendar_date_is_rejected_not_rolled_forward(): void
    {
        $parser = new HistoricalDateParser();

        $this->expectException(HistoricalParseException::class);
        $parser->parse('31/02/2024', HistoricalDateParser::FORMAT_DMY);
    }

    // ============================================================ tax normalizer

    public function test_missing_tax_is_recorded_unknown_never_zero(): void
    {
        $normalizer = new HistoricalTaxNormalizer();
        $messages   = new HistoricalMessages();

        $result = $normalizer->normalize(
            ['grand_total' => 1000.0],
            HistoricalSalesDocument::TAX_MODE_UNKNOWN,
            zeroTaxConfirmed: false,
            messages: $messages,
        );

        $this->assertSame(HistoricalSalesDocument::TAX_UNKNOWN, $result['completeness']);
        $this->assertNull($result['tax_total']);
        $this->assertTrue($messages->has(HistoricalTaxNormalizer::CODE_TAX_UNKNOWN));
    }

    public function test_explicit_zero_tax_requires_confirmation_before_becoming_not_applicable(): void
    {
        $normalizer = new HistoricalTaxNormalizer();

        $unconfirmed = new HistoricalMessages();
        $result = $normalizer->normalize(
            ['grand_total' => 1000.0, 'tax_total' => 0.0],
            HistoricalSalesDocument::TAX_MODE_NOT_APPLICABLE,
            zeroTaxConfirmed: false,
            messages: $unconfirmed,
        );
        $this->assertSame(HistoricalSalesDocument::TAX_SUMMARY_ONLY, $result['completeness']);
        $this->assertTrue($unconfirmed->has(HistoricalTaxNormalizer::CODE_TAX_ZERO_UNCONFIRMED));

        $confirmed = new HistoricalMessages();
        $result = $normalizer->normalize(
            ['grand_total' => 1000.0, 'tax_total' => 0.0],
            HistoricalSalesDocument::TAX_MODE_NOT_APPLICABLE,
            zeroTaxConfirmed: true,
            messages: $confirmed,
        );
        $this->assertSame(HistoricalSalesDocument::TAX_NOT_APPLICABLE, $result['completeness']);
    }

    public function test_cgst_sgst_and_igst_both_positive_is_a_blocking_conflict(): void
    {
        $normalizer = new HistoricalTaxNormalizer();
        $messages   = new HistoricalMessages();

        $normalizer->normalize(
            ['grand_total' => 1180.0, 'cgst' => 90.0, 'sgst' => 90.0, 'igst' => 180.0],
            HistoricalSalesDocument::TAX_MODE_EXCLUSIVE,
            zeroTaxConfirmed: false,
            messages: $messages,
        );

        $this->assertTrue($messages->has(HistoricalTaxNormalizer::CODE_TAX_SPLIT_CONFLICT));
        $this->assertTrue($messages->hasBlocking());
    }

    public function test_rounding_tolerance_boundary_between_warning_and_blocking(): void
    {
        $normalizer = new HistoricalTaxNormalizer();

        // Off by exactly the ₹1 tolerance -> warning, not blocking.
        $withinTolerance = new HistoricalMessages();
        $normalizer->normalize(
            ['grand_total' => 1001.0, 'taxable_amount' => 1000.0],
            HistoricalSalesDocument::TAX_MODE_NOT_APPLICABLE,
            zeroTaxConfirmed: true,
            messages: $withinTolerance,
        );
        $this->assertTrue($withinTolerance->has(HistoricalTaxNormalizer::CODE_TOTAL_ROUNDING));
        $this->assertFalse($withinTolerance->hasBlocking());

        // Off by more than ₹1 -> blocking, and the printed total is untouched.
        $overTolerance = new HistoricalMessages();
        $normalizer->normalize(
            ['grand_total' => 1005.0, 'taxable_amount' => 1000.0],
            HistoricalSalesDocument::TAX_MODE_NOT_APPLICABLE,
            zeroTaxConfirmed: true,
            messages: $overTolerance,
        );
        $this->assertTrue($overTolerance->has(HistoricalTaxNormalizer::CODE_TOTAL_MISMATCH));
        $this->assertTrue($overTolerance->hasBlocking());
    }

    public function test_paid_plus_outstanding_partial_is_preserved_incomplete_not_derived(): void
    {
        $normalizer = new HistoricalTaxNormalizer();
        $messages   = new HistoricalMessages();

        $normalizer->normalize(
            ['grand_total' => 1000.0, 'paid_amount' => 400.0],
            HistoricalSalesDocument::TAX_MODE_NOT_APPLICABLE,
            zeroTaxConfirmed: true,
            messages: $messages,
        );

        $this->assertTrue($messages->has(HistoricalTaxNormalizer::CODE_SETTLEMENT_PARTIAL));
        $this->assertFalse($messages->hasBlocking()); // incompleteness warns, never blocks
    }

    // ================================================= making/labour normalizer

    public function test_percentage_basis_without_a_base_amount_stays_unknown_not_fabricated(): void
    {
        $normalizer = new HistoricalMakingChargeNormalizer();
        $messages   = new HistoricalMessages();

        $result = $normalizer->normalize(
            'VA', '12%', HistoricalMakingCharge::CATEGORY_VALUE_ADDITION, HistoricalMakingCharge::BASIS_PERCENT,
            [], $messages,
        );

        $this->assertNull($result['amount']);
        $this->assertTrue($messages->has(HistoricalMakingChargeNormalizer::CODE_BASE_MISSING));
    }

    public function test_percentage_basis_with_metal_value_computes_the_documented_amount(): void
    {
        $normalizer = new HistoricalMakingChargeNormalizer();
        $messages   = new HistoricalMessages();

        $result = $normalizer->normalize(
            'VA', '12%', HistoricalMakingCharge::CATEGORY_VALUE_ADDITION, HistoricalMakingCharge::BASIS_PERCENT,
            ['base_amount' => 50000.0], $messages,
        );

        $this->assertSame(6000.0, $result['amount']); // 12% of 50000
        $this->assertFalse($messages->hasBlocking());
    }

    public function test_per_gram_basis_uses_net_weight_never_gross(): void
    {
        $normalizer = new HistoricalMakingChargeNormalizer();
        $messages   = new HistoricalMessages();

        $result = $normalizer->normalize(
            'Labour', '450', HistoricalMakingCharge::CATEGORY_LABOUR, HistoricalMakingCharge::BASIS_PER_GRAM,
            ['net_weight' => 9.5], $messages,
        );

        $this->assertSame(4275.0, $result['amount']); // 450 * 9.5
        $this->assertSame('net_weight', $result['base_kind']);
    }

    public function test_informational_basis_never_contributes_an_amount(): void
    {
        $normalizer = new HistoricalMakingChargeNormalizer();
        $messages   = new HistoricalMessages();

        $result = $normalizer->normalize(
            'Wastage', '2%', HistoricalMakingCharge::CATEGORY_WASTAGE, HistoricalMakingCharge::BASIS_INFORMATIONAL,
            ['base_amount' => 50000.0], $messages,
        );

        $this->assertNull($result['amount']);
        $this->assertTrue($messages->has(HistoricalMakingChargeNormalizer::CODE_INFORMATIONAL));
    }

    public function test_two_differently_labelled_charges_are_never_silently_treated_as_equivalent(): void
    {
        // "MC" and "Labour Charges" must each keep their own preserved label and
        // basis — Phase 11's core rule. Confirmed here by checking neither
        // normalization infers the other's basis from the label text alone.
        $normalizer = new HistoricalMakingChargeNormalizer();

        $mc = $normalizer->normalize(
            'MC', '500', null, null, [], new HistoricalMessages(),
        );
        $labour = $normalizer->normalize(
            'Labour Charges', '500', null, null, [], new HistoricalMessages(),
        );

        $this->assertSame(HistoricalMakingCharge::BASIS_UNKNOWN, $mc['basis']);
        $this->assertSame(HistoricalMakingCharge::BASIS_UNKNOWN, $labour['basis']);
        $this->assertSame('MC', $mc['label_original']);
        $this->assertSame('Labour Charges', $labour['label_original']);
    }

    // ================================================= csv-injection detection

    /**
     * A negative amount is a number, not a formula.
     *
     * The guard used to match on the leading character alone, so every `-500`
     * in a discount or rounding column told the operator their own valid input
     * "looks like a spreadsheet formula". Excel renders `-500` as a number; it
     * only evaluates a leading sign when what follows is an expression.
     */
    #[DataProvider('notFormulas')]
    public function test_a_plain_signed_number_is_not_flagged_as_a_formula(string $value): void
    {
        $this->assertFalse(
            HistoricalSourceFileReader::looksLikeFormula($value),
            "\"{$value}\" is a number, not a formula",
        );
    }

    #[DataProvider('formulas')]
    public function test_a_real_formula_is_still_flagged(string $value): void
    {
        $this->assertTrue(
            HistoricalSourceFileReader::looksLikeFormula($value),
            "\"{$value}\" would be evaluated by a spreadsheet",
        );
    }

    /** @return array<string, array{0: string}> */
    public static function notFormulas(): array
    {
        return self::named([
            '-500',            // the reported false positive
            '+1200.50',
            '-1,25,000.00',    // indian grouping
            '-1 250,75',       // space grouping, comma decimal
            '- 500',           // sign detached from the digits
            '+0',
        ]);
    }

    /** @return array<string, array{0: string}> */
    public static function formulas(): array
    {
        return self::named([
            '=SUM(A1:A9)',
            '@SUM(A1)',
            '+SUM(A1)',
            '-1+1',            // signed, but an expression rather than a number
            '=1+1',
            '-A1',
            "\t-500",          // tab lead: Excel strips it, then evaluates
            "\r=cmd|'/c calc'!A0",
        ]);
    }

    /**
     * Name each dataset after the value it carries, without letting PHP turn
     * the name into an integer.
     *
     * A bare array_combine($values, ...) looks right and even keeps all the
     * datasets, but PHP casts a canonical integer string used as an array key:
     * '-500' becomes int(-500). PHPUnit then renders integer-keyed datasets
     * positionally, so '-500' and '+0' both came out as "data set #0" — two
     * tests with one identity. Every runner handles that differently, which is
     * exactly the confusion it caused: phpunit and the JUnit log counted 2297
     * tests while `php artisan test` reported 2289 passing, and chasing the
     * missing one cost a full suite run.
     *
     * The `= ` prefix cannot be cast to an int, so the key stays a string and
     * every dataset keeps a distinct, readable name. Control characters are
     * escaped so a tab- or CR-led value is legible in test output instead of
     * silently eating the rest of the line.
     *
     * @param  array<int, string>  $values
     * @return array<string, array{0: string}>
     */
    private static function named(array $values): array
    {
        $keys = array_map(
            static fn (string $v): string => '= ' . addcslashes($v, "\0..\37"),
            $values,
        );

        $named = array_combine($keys, array_map(static fn ($v) => [$v], $values));

        // A collision here would silently drop a case from the suite.
        self::assertCount(count($values), $named, 'dataset names collided');

        return $named;
    }
}
