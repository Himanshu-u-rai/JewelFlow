<?php

namespace App\Services;

use App\Models\Invoice;
use App\Support\FinancialYear;
use Illuminate\Validation\ValidationException;

/**
 * When a shop's yearly invoice series starts and ends (shop_billing_settings.invoice_series_yearly_from/until,
 * financial-year labels; until is exclusive). A year's series never changes once it has a bill in it: a change
 * asked for after billing starts takes effect on 1 April. A yearly series needs {FY} in the number, or
 * each year would repeat the last one's numbers.
 */
final class InvoiceSeriesPlan
{
    /** @return array{from: ?string, until: ?string} */
    public static function apply(int $shopId, ?string $from, ?string $until, ?string $choice, string $affixes): array
    {
        $year = FinancialYear::current();
        $billed = Invoice::withoutGlobalScopes()->where('shop_id', $shopId)->where('fiscal_year', $year->label())->exists();

        try {
            return self::decide($year, $from, $until, $choice, $billed, str_contains($affixes, '{FY}'));
        } catch (\DomainException) {
            throw ValidationException::withMessages([
                'invoice_prefix' => __('A yearly series needs {FY} in the prefix or suffix (for example INV/{FY}/), so each year\'s numbers are different.'),
            ]);
        }
    }

    /** @return array{from: ?string, until: ?string} */
    public static function decide(FinancialYear $year, ?string $from, ?string $until, ?string $choice, bool $billedThisYear, bool $hasToken): array
    {
        $now = $year->label();
        $next = $year->next()->label();
        $running = $from !== null && $from <= $now && ($until === null || $now < $until);
        $planned = $from !== null && $from > $now;

        if ($choice === 'yearly') {
            if ($running) {
                $until = null;
            } elseif (! $planned) {
                $from = $billedThisYear ? $next : $now;
                $until = null;
            }
        } elseif ($choice === 'continuous') {
            if ($planned || ($running && $from === $now && ! $billedThisYear)) {
                $from = $until = null;
            } elseif ($running && $until === null) {
                $until = $billedThisYear ? $next : $now;
            }
        }

        $stillYearly = $from !== null && ($until === null || $until > $now);
        if ($stillYearly && ! $hasToken) {
            throw new \DomainException('A yearly invoice series needs {FY} in the prefix or suffix.');
        }

        return ['from' => $from, 'until' => $until];
    }
}
