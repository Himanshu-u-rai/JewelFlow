<?php

namespace App\Support;

/**
 * GST time limit for credit notes (CGST Act s.34(2), as amended by the Finance Act 2022): a credit note for a
 * supply may be issued only up to 30 November following the end of the financial year of that supply. (The Act
 * also ends it earlier if the annual return is filed first; the app does not know that date, so the owner's
 * accountant covers that case.) After the deadline a returned piece is bought back as old gold instead.
 */
final class CreditNoteDeadline
{
    /** The last day ('Y-m-d') a credit note may be issued for a bill of this financial year. */
    public static function for(FinancialYear $billYear): string
    {
        return substr($billYear->next()->startDate(), 0, 4) . '-11-30';
    }

    public static function allows(FinancialYear $billYear, string $onDate): bool
    {
        return $onDate <= self::for($billYear);
    }

    /**
     * Refuse a credit note today for a bill of $billFiscalYear ('2025-26') once its deadline has passed. Called
     * where a return starts (so staff hear it up front) and where a credit note is written (the backstop).
     */
    public static function assertOpen(?string $billFiscalYear): void
    {
        if ($billFiscalYear === null || $billFiscalYear === '') {
            return; // a bill without a recorded year predates financial-year numbering: judged by its date elsewhere
        }
        $year = FinancialYear::fromLabel($billFiscalYear);
        $today = \Carbon\CarbonImmutable::now(FinancialYear::TIMEZONE)->toDateString();
        if (! self::allows($year, $today)) {
            throw new \LogicException(self::refusal($year));
        }
    }

    public static function refusal(FinancialYear $billYear): string
    {
        $deadline = \Carbon\CarbonImmutable::parse(self::for($billYear))->format('j F Y');

        return "This bill is from financial year {$billYear->label()}. GST allowed a credit note for it only until {$deadline}. "
            . 'Record the returned piece as an old-gold purchase instead.';
    }
}
