<?php

namespace App\Services\Reporting\Filters;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Resolves FY-first presets to explicit, timezone-correct, stamped date ranges
 * (frozen §17). The financial year starts on `reporting.fy_start_month` (India:
 * April = 4). Presets resolve to concrete dates which are then printed on the
 * document, so the panel's "This FY" and the document's "1 Apr 2025 – 31 Mar 2026"
 * never disagree.
 *
 * The Branch dimension (frozen §3.2) is intentionally NOT resolved here — it is a
 * reserved hook with no UI until a multi-location model exists.
 */
class FilterResolver
{
    private int $fyStartMonth;

    public function __construct(?int $fyStartMonth = null)
    {
        $this->fyStartMonth = $fyStartMonth ?? (int) config('reporting.fy_start_month', 4);
    }

    public function resolve(
        DatePreset $preset,
        ?CarbonImmutable $customFrom = null,
        ?CarbonImmutable $customTo = null,
        ?string $fyName = null,
        ?CarbonImmutable $now = null,
    ): ResolvedPeriod {
        $now = ($now ?? CarbonImmutable::now())->startOfDay();
        $fyStartYear = $this->fyStartYearFor($now);

        return match ($preset) {
            DatePreset::Today => $this->range($now, $now),
            DatePreset::ThisMonth => $this->range($now->startOfMonth(), $now->endOfMonth()),
            DatePreset::LastMonth => $this->range($now->subMonthNoOverflow()->startOfMonth(), $now->subMonthNoOverflow()->endOfMonth()),
            DatePreset::ThisQuarter => $this->quarter($now, 0),
            DatePreset::LastQuarter => $this->quarter($now, -1),
            DatePreset::ThisFy => $this->fy($fyStartYear),
            DatePreset::LastFy => $this->fy($fyStartYear - 1),
            DatePreset::FyToDate => $this->range($this->fyStart($fyStartYear), $now->endOfDay(), $this->fyLabel($fyStartYear) . ' to date'),
            DatePreset::NamedFy => $this->namedFy($fyName),
            DatePreset::Custom => $this->custom($customFrom, $customTo),
        };
    }

    /** Parse a named FY string like "2024-25" or "2024-2025" → its explicit range. */
    public function namedFy(?string $fyName): ResolvedPeriod
    {
        if ($fyName === null || ! preg_match('/^(\d{4})\s*[-\/]\s*(\d{2,4})$/', trim($fyName), $m)) {
            throw new InvalidArgumentException("Invalid financial year [{$fyName}] (expected e.g. 2024-25).");
        }
        return $this->fy((int) $m[1]);
    }

    private function custom(?CarbonImmutable $from, ?CarbonImmutable $to): ResolvedPeriod
    {
        if ($from === null || $to === null) {
            throw new InvalidArgumentException('Custom period requires both from and to dates.');
        }
        if ($from->gt($to)) {
            throw new InvalidArgumentException('Custom period "from" must not be after "to".');
        }
        return $this->range($from->startOfDay(), $to->endOfDay());
    }

    private function fy(int $startYear): ResolvedPeriod
    {
        return new ResolvedPeriod(
            $this->fyStart($startYear),
            $this->fyStart($startYear)->addYear()->subDay()->endOfDay(),
            $this->fyLabel($startYear),
            $this->fyName($startYear),
        );
    }

    private function quarter(CarbonImmutable $now, int $offsetQuarters): ResolvedPeriod
    {
        $fyStart = $this->fyStart($this->fyStartYearFor($now));
        $monthsIntoFy = $fyStart->diffInMonths($now->startOfMonth());
        $quarterIndex = intdiv($monthsIntoFy, 3) + $offsetQuarters;

        $start = $fyStart->addMonthsNoOverflow($quarterIndex * 3)->startOfMonth();
        $end = $start->addMonthsNoOverflow(3)->subDay()->endOfDay();

        return $this->range($start, $end);
    }

    private function range(CarbonImmutable $from, CarbonImmutable $to, ?string $label = null): ResolvedPeriod
    {
        $from = $from->startOfDay();
        $to = $to->endOfDay();
        return new ResolvedPeriod($from, $to, $label ?? $this->rangeLabel($from, $to));
    }

    private function fyStartYearFor(CarbonImmutable $now): int
    {
        return $now->month >= $this->fyStartMonth ? $now->year : $now->year - 1;
    }

    private function fyStart(int $startYear): CarbonImmutable
    {
        return CarbonImmutable::create($startYear, $this->fyStartMonth, 1)->startOfDay();
    }

    private function fyLabel(int $startYear): string
    {
        return 'FY ' . $this->fyName($startYear);
    }

    private function fyName(int $startYear): string
    {
        return $startYear . '-' . str_pad((string) (($startYear + 1) % 100), 2, '0', STR_PAD_LEFT);
    }

    private function rangeLabel(CarbonImmutable $from, CarbonImmutable $to): string
    {
        if ($from->isSameDay($to)) {
            return $from->format('d M Y');
        }
        return $from->format('d M Y') . ' – ' . $to->format('d M Y');
    }
}
