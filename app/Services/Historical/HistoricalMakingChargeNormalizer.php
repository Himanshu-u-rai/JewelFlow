<?php

namespace App\Services\Historical;

use App\Support\Historical\HistoricalMakingCharge;
use App\Support\Historical\HistoricalMessages;
use App\Support\Historical\HistoricalMoney;
use App\Support\Historical\HistoricalParseException;

/**
 * Turns "VA 12%" or "Labour 450/gm" into an amount — or, far more often than the
 * spec writers expect, into an honest "we cannot compute this".
 *
 * WHAT IS PRESERVED, ALWAYS: the original column label, the original printed
 * value, the confirmed category, the confirmed basis. Those four survive whether
 * or not a number comes out the other end, because they are what the paper said.
 *
 * WHAT IS NEVER DONE: inventing a base. `12%` without a metal value is not
 * "12% of the grand total"; `450/gm` without a weight is not "450". Both are
 * recorded with a normalized amount of NULL and a warning, because a fabricated
 * making charge silently changes what the shop believes its margins were.
 */
class HistoricalMakingChargeNormalizer
{
    public const CODE_BASIS_UNKNOWN   = 'making_basis_unknown';
    public const CODE_BASE_MISSING    = 'making_base_missing';
    public const CODE_UNREADABLE      = 'making_value_unreadable';
    public const CODE_INCLUDED        = 'making_included_in_total';
    public const CODE_INFORMATIONAL   = 'making_informational';
    public const CODE_DRIFT           = 'making_reconciliation_drift';

    /**
     * @param  array{quantity?: ?float, net_weight?: ?float, base_amount?: ?float,
     *               declared_amount?: ?float}  $context
     * @return array{label_original: ?string, value_original: ?string, category: ?string,
     *               basis: ?string, amount: ?float, base_kind: ?string, base_used: ?float,
     *               difference: ?float}
     */
    public function normalize(
        ?string $label,
        ?string $printedValue,
        ?string $category,
        ?string $basis,
        array $context,
        HistoricalMessages $messages,
        string $decimalSeparator = '.',
        ?string $thousandsSeparator = ',',
    ): array {
        $result = [
            'label_original' => $label,
            'value_original' => $printedValue,
            'category'       => HistoricalMakingCharge::isCategory($category) ? $category : null,
            'basis'          => HistoricalMakingCharge::isBasis($basis) ? $basis : HistoricalMakingCharge::BASIS_UNKNOWN,
            'amount'         => null,
            'base_kind'      => null,
            'base_used'      => null,
            'difference'     => null,
        ];

        if ($printedValue === null || trim($printedValue) === '') {
            return $result;
        }

        // The numeric part of "12%", "450/gm", "₹1,200". The suffix is the basis,
        // which the operator has already confirmed — it is not re-derived here.
        try {
            $figure = HistoricalMoney::parse(
                self::stripBasisSuffix($printedValue),
                $decimalSeparator,
                $thousandsSeparator
            );
        } catch (HistoricalParseException $e) {
            $messages->warning(
                self::CODE_UNREADABLE,
                sprintf(
                    'The making/labour value "%s" is not a number, so no amount could be derived from it. '
                    . 'The original text is preserved on the record.',
                    $printedValue
                ),
                'making_value'
            );

            return $result;
        }

        switch ($result['basis']) {
            case HistoricalMakingCharge::BASIS_FIXED_INVOICE:
            case HistoricalMakingCharge::BASIS_FIXED_LINE:
                $result['amount'] = round($figure, 2);
                break;

            case HistoricalMakingCharge::BASIS_PER_ITEM:
                $result['base_kind'] = 'quantity';
                $result['base_used'] = $context['quantity'] ?? null;
                $result['amount']    = $result['base_used'] === null
                    ? null
                    : round($figure * $result['base_used'], 2);
                break;

            case HistoricalMakingCharge::BASIS_PER_GRAM:
                // Net weight, never gross. Making is charged on the metal that
                // was worked, and falling back to gross would quietly bill the
                // customer for their own stones.
                $result['base_kind'] = 'net_weight';
                $result['base_used'] = $context['net_weight'] ?? null;
                $result['amount']    = $result['base_used'] === null
                    ? null
                    : round($figure * $result['base_used'], 2);
                break;

            case HistoricalMakingCharge::BASIS_PERCENT:
                // Percentage making is charged on metal value in the trade. If the
                // metal value was not mapped there is nothing to take a percentage
                // OF, and the grand total is emphatically not a substitute.
                $result['base_kind'] = 'metal_value';
                $result['base_used'] = $context['base_amount'] ?? null;
                $result['amount']    = $result['base_used'] === null
                    ? null
                    : round($figure * $result['base_used'] / 100, 2);
                break;

            case HistoricalMakingCharge::BASIS_INCLUDED:
                $messages->info(
                    self::CODE_INCLUDED,
                    'The making/labour charge is already inside the printed total and is not added again.',
                    'making_value'
                );
                $result['amount'] = round($figure, 2);
                break;

            case HistoricalMakingCharge::BASIS_INFORMATIONAL:
                $messages->info(
                    self::CODE_INFORMATIONAL,
                    'The making/labour figure is recorded for information only and contributes nothing to the total.',
                    'making_value'
                );
                break;

            default:
                $messages->warning(
                    self::CODE_BASIS_UNKNOWN,
                    sprintf(
                        'It is not recorded how "%s" (%s) is calculated, so no amount is derived from it. '
                        . 'Confirm the calculation basis on the mapping screen.',
                        $printedValue,
                        $label ?? 'making / labour'
                    ),
                    'making_basis'
                );
        }

        if (HistoricalMakingCharge::needsBase($result['basis']) && $result['base_used'] === null) {
            $messages->warning(
                self::CODE_BASE_MISSING,
                sprintf(
                    '"%s" is charged %s, but the %s it applies to is not in this data. '
                    . 'The printed value is preserved and the amount stays unknown.',
                    $printedValue,
                    str_replace('_', ' ', (string) $result['basis']),
                    str_replace('_', ' ', (string) $result['base_kind'])
                ),
                'making_value'
            );
        }

        // When the source ALSO printed a computed making amount, our
        // interpretation is checkable against it. A drift means the basis is
        // wrong, and the operator is the only one who can say which.
        $declared = $context['declared_amount'] ?? null;

        if ($declared !== null && $result['amount'] !== null) {
            $result['difference'] = HistoricalMoney::differsBy($result['amount'], $declared);

            if ($result['difference'] > 0.005) {
                $messages->warning(
                    self::CODE_DRIFT,
                    sprintf(
                        'Interpreting "%s" as %s gives %s, but the source states %s — a difference of %s. '
                        . 'Check the calculation basis.',
                        $printedValue,
                        str_replace('_', ' ', (string) $result['basis']),
                        number_format($result['amount'], 2),
                        number_format($declared, 2),
                        number_format($result['difference'], 2)
                    ),
                    'making_value'
                );
            }
        }

        return $result;
    }

    /**
     * Removes the trailing unit a printed making value carries: `%`, `/gm`,
     * `per gram`, `/pc`. Only the SUFFIX — an embedded separator is part of the
     * number and belongs to HistoricalMoney.
     */
    private static function stripBasisSuffix(string $value): string
    {
        $clean = trim($value);
        $clean = (string) preg_replace('/\s*%\s*$/u', '', $clean);
        $clean = (string) preg_replace('/\s*(\/|per\s+)\s*(gm|gms|gram|grams|g|pc|pcs|piece|pieces|item)\.?\s*$/iu', '', $clean);

        return $clean;
    }
}
