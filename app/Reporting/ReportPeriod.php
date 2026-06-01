<?php

namespace App\Reporting;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * Canonical reporting period — the single place that parses, validates, and
 * clamps a [start, end] window for every report.
 *
 * Kills the per-controller `preg_match('/^\d{4}-\d{2}-\d{2}$/', ...)` date
 * validation copy-paste (audit §6/C1) and guarantees that "this month" or
 * "this day" means exactly the same boundaries in every report.
 *
 * All boundaries are inclusive: start is start-of-day, end is end-of-day.
 */
final class ReportPeriod
{
    public const GRANULARITY_DAY   = 'day';
    public const GRANULARITY_MONTH = 'month';
    public const GRANULARITY_RANGE = 'range';

    private function __construct(
        private readonly CarbonImmutable $start,
        private readonly CarbonImmutable $end,
        private readonly string $granularity,
    ) {}

    /** A single calendar day. */
    public static function day(?string $date = null): self
    {
        $d = self::safeDate($date, CarbonImmutable::now()->toDateString());

        return new self($d->startOfDay(), $d->endOfDay(), self::GRANULARITY_DAY);
    }

    /** A calendar month, clamped to sane bounds. */
    public static function month(int|string|null $year = null, int|string|null $month = null): self
    {
        $y = is_numeric($year) ? (int) $year : (int) CarbonImmutable::now()->year;
        $m = is_numeric($month) ? (int) $month : (int) CarbonImmutable::now()->month;

        $y = max(2000, min(2100, $y));
        $m = max(1, min(12, $m));

        $anchor = CarbonImmutable::create($y, $m, 1);

        return new self($anchor->startOfMonth(), $anchor->endOfMonth(), self::GRANULARITY_MONTH);
    }

    /** An arbitrary [from, to] range. Falls back to the current month on bad input. */
    public static function range(?string $from = null, ?string $to = null): self
    {
        $start = self::safeDate($from, CarbonImmutable::now()->startOfMonth()->toDateString())->startOfDay();
        $end   = self::safeDate($to, CarbonImmutable::now()->toDateString())->endOfDay();

        // Guard inverted ranges — never let end precede start.
        if ($end->lessThan($start)) {
            $end = $start->endOfDay();
        }

        return new self($start, $end, self::GRANULARITY_RANGE);
    }

    /**
     * Resolve a period from common request shapes:
     *   ?month=&year=        → month
     *   ?from=&to=           → range
     *   ?date=               → day
     * Defaults to the current month.
     */
    public static function fromRequest(Request $request): self
    {
        if ($request->filled('month') || $request->filled('year')) {
            return self::month($request->input('year'), $request->input('month'));
        }

        if ($request->filled('from') || $request->filled('to')) {
            return self::range($request->input('from'), $request->input('to'));
        }

        if ($request->filled('date')) {
            return self::day($request->input('date'));
        }

        return self::month();
    }

    public function start(): CarbonImmutable
    {
        return $this->start;
    }

    public function end(): CarbonImmutable
    {
        return $this->end;
    }

    public function granularity(): string
    {
        return $this->granularity;
    }

    /** [start, end] pair for `whereBetween`. */
    public function bounds(): array
    {
        return [$this->start, $this->end];
    }

    public function label(): string
    {
        return match ($this->granularity) {
            self::GRANULARITY_DAY   => $this->start->format('d M Y'),
            self::GRANULARITY_MONTH => $this->start->format('F Y'),
            default                 => $this->start->format('d M Y') . ' – ' . $this->end->format('d M Y'),
        };
    }

    /** Parse a YYYY-MM-DD string; return the fallback on anything invalid. */
    private static function safeDate(?string $value, string $fallback): CarbonImmutable
    {
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            try {
                return CarbonImmutable::createFromFormat('Y-m-d', $value)->startOfDay();
            } catch (\Throwable) {
                // fall through to fallback
            }
        }

        return CarbonImmutable::createFromFormat('Y-m-d', $fallback)->startOfDay();
    }
}
