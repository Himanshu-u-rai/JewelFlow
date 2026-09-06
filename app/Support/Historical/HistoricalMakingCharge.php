<?php

namespace App\Support\Historical;

/**
 * The vocabulary of a jeweller's making/labour column.
 *
 * THE CENTRAL RULE OF THIS FILE: `MC`, `VA`, `Labour`, `Job Work` and `Wastage`
 * are NOT synonyms. They are five different commercial arrangements that
 * different shops compute differently, and a shop that charges 12% VA and a shop
 * that charges ₹450/gm labour have nothing in common except a column position.
 * Every method here SUGGESTS. Nothing here decides. The operator confirms the
 * category and the basis on the mapping screen, and the preview shows the
 * interpretation before anything is published.
 */
final class HistoricalMakingCharge
{
    /**
     * Standard display wording for a manual entry that never asked the
     * operator for a custom label. File-import keeps its own printed label
     * (that's the whole point of the mapping screen) — this constant is for
     * the manual-entry-only path, which has no label input at all.
     */
    public const DEFAULT_LABEL = 'Making charges';

    // ------------------------------------------------------------- categories
    // What the charge IS. Deliberately not collapsed: see the class docblock.

    public const CATEGORY_MAKING          = 'making';
    public const CATEGORY_LABOUR          = 'labour';
    public const CATEGORY_JOB_WORK        = 'job_work';
    public const CATEGORY_VALUE_ADDITION  = 'value_addition';
    public const CATEGORY_WASTAGE         = 'wastage';
    public const CATEGORY_HALLMARKING     = 'hallmarking';
    public const CATEGORY_OTHER           = 'other';
    public const CATEGORY_UNKNOWN         = 'unknown';

    public const CATEGORIES = [
        self::CATEGORY_MAKING,
        self::CATEGORY_LABOUR,
        self::CATEGORY_JOB_WORK,
        self::CATEGORY_VALUE_ADDITION,
        self::CATEGORY_WASTAGE,
        self::CATEGORY_HALLMARKING,
        self::CATEGORY_OTHER,
        self::CATEGORY_UNKNOWN,
    ];

    // ------------------------------------------------------------------ bases
    // How the printed figure turns into money.

    /** A flat amount charged once for the whole bill. */
    public const BASIS_FIXED_INVOICE = 'fixed_invoice';
    /** A flat amount charged once for this line. */
    public const BASIS_FIXED_LINE    = 'fixed_line';
    /** An amount per piece: multiply by quantity. */
    public const BASIS_PER_ITEM      = 'per_item';
    /** An amount per gram: multiply by a weight. */
    public const BASIS_PER_GRAM      = 'per_gram';
    /** A percentage of a base amount. */
    public const BASIS_PERCENT       = 'percent';
    /** Already inside the printed total. Adding it again double-counts. */
    public const BASIS_INCLUDED      = 'included';
    /** Printed on the bill for information; contributes nothing to the total. */
    public const BASIS_INFORMATIONAL = 'informational';
    /** The source did not say. Preserved as-is; never guessed into a number. */
    public const BASIS_UNKNOWN       = 'unknown';

    public const BASES = [
        self::BASIS_FIXED_INVOICE,
        self::BASIS_FIXED_LINE,
        self::BASIS_PER_ITEM,
        self::BASIS_PER_GRAM,
        self::BASIS_PERCENT,
        self::BASIS_INCLUDED,
        self::BASIS_INFORMATIONAL,
        self::BASIS_UNKNOWN,
    ];

    /** Bases that cannot produce an amount without something to multiply. */
    public const BASES_NEEDING_BASE = [
        self::BASIS_PER_ITEM,
        self::BASIS_PER_GRAM,
        self::BASIS_PERCENT,
    ];

    /**
     * Label -> a SUGGESTED category, for the mapping screen's prefill only.
     *
     * Keys are compared after HistoricalDocumentIdentity's loose fold, so
     * `M.C.`, `mc` and `ＭＣ` all reach the same key. Absence is not an error —
     * an unrecognised label simply arrives at the operator uncategorised, which
     * is the honest outcome.
     */
    private const CATEGORY_HINTS = [
        'MAKINGCHARGES'  => self::CATEGORY_MAKING,
        'MAKINGCHARGE'   => self::CATEGORY_MAKING,
        'MAKING'         => self::CATEGORY_MAKING,
        'MKG'            => self::CATEGORY_MAKING,
        'MC'             => self::CATEGORY_MAKING,
        'LABOUR'         => self::CATEGORY_LABOUR,
        'LABOR'          => self::CATEGORY_LABOUR,
        'LABOURCHARGES'  => self::CATEGORY_LABOUR,
        'LABOURCHARGE'   => self::CATEGORY_LABOUR,
        'MAJURI'         => self::CATEGORY_LABOUR,
        'MAZURI'         => self::CATEGORY_LABOUR,
        'JOBWORK'        => self::CATEGORY_JOB_WORK,
        'JOBCHARGES'     => self::CATEGORY_JOB_WORK,
        'VA'             => self::CATEGORY_VALUE_ADDITION,
        'VALUEADDITION'  => self::CATEGORY_VALUE_ADDITION,
        'WASTAGE'        => self::CATEGORY_WASTAGE,
        'WASTE'          => self::CATEGORY_WASTAGE,
        'WSTG'           => self::CATEGORY_WASTAGE,
        'HALLMARK'       => self::CATEGORY_HALLMARKING,
        'HALLMARKING'    => self::CATEGORY_HALLMARKING,
        'HUID'           => self::CATEGORY_HALLMARKING,
    ];

    public static function isCategory(?string $value): bool
    {
        return $value !== null && in_array($value, self::CATEGORIES, true);
    }

    public static function isBasis(?string $value): bool
    {
        return $value !== null && in_array($value, self::BASES, true);
    }

    public static function needsBase(?string $basis): bool
    {
        return $basis !== null && in_array($basis, self::BASES_NEEDING_BASE, true);
    }

    /**
     * A suggestion for the mapping screen. Returns null rather than
     * CATEGORY_OTHER when nothing matches: "I don't know" and "it's an other
     * charge" are different statements and only the operator can pick.
     */
    public static function suggestCategory(?string $label): ?string
    {
        if ($label === null) {
            return null;
        }

        $key = HistoricalDocumentIdentity::comparableLabel($label);

        return self::CATEGORY_HINTS[$key] ?? null;
    }

    /**
     * A suggestion for the basis, read off the printed VALUE rather than the
     * label — `12%` and `450/gm` say what they are. Everything else is
     * deliberately unknown: a bare `5400` could be a flat charge on the bill, a
     * flat charge on the line, or a per-piece rate, and picking one silently is
     * how a total drifts.
     */
    public static function suggestBasis(?string $printedValue): ?string
    {
        if ($printedValue === null) {
            return null;
        }

        $value = HistoricalDocumentIdentity::comparableLabel($printedValue);

        if ($value === '') {
            return null;
        }

        return match (true) {
            str_contains($printedValue, '%')                        => self::BASIS_PERCENT,
            (bool) preg_match('/(PERGM|PERGRAM|GM|GRAM|G)$/', $value) => self::BASIS_PER_GRAM,
            (bool) preg_match('/(PERPC|PERPCS|PERPIECE|PC|PCS)$/', $value) => self::BASIS_PER_ITEM,
            default                                                 => null,
        };
    }
}
