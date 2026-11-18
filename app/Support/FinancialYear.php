<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * The Indian financial year: 1 April – 31 March, judged in Indian time (every shop runs on Asia/Kolkata; see
 * ShopPricingService::pricingTimezone). The one rule for numbering, locks, closings and year reports.
 * The database mirrors it in fiscal_year_of() — tests/Feature/FinancialYear keep the two in step.
 */
final class FinancialYear
{
    public const TIMEZONE = 'Asia/Kolkata';

    private function __construct(private readonly int $startYear) {}

    public static function forInstant(CarbonInterface $instant): self
    {
        $local = CarbonImmutable::instance($instant)->setTimezone(self::TIMEZONE);

        return new self($local->month >= 4 ? $local->year : $local->year - 1);
    }

    /** A calendar date ('Y-m-d') as written on a document: no zone to convert. */
    public static function forDate(string $date): self
    {
        if (! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            throw new InvalidArgumentException("Not a date: {$date}");
        }

        return new self((int) $m[2] >= 4 ? (int) $m[1] : (int) $m[1] - 1);
    }

    public static function current(): self
    {
        return self::forInstant(CarbonImmutable::now());
    }

    /** '2026-27' only: the second part must be the next year's last two digits. */
    public static function fromLabel(string $label): self
    {
        if (! preg_match('/^(\d{4})-(\d{2})$/', $label, $m)) {
            throw new InvalidArgumentException("Not a financial year: '{$label}'");
        }
        $start = (int) $m[1];
        if ((int) $m[2] !== ($start + 1) % 100) {
            throw new InvalidArgumentException("Not a financial year: '{$label}'");
        }

        return new self($start);
    }

    public function label(): string
    {
        return $this->startYear . '-' . sprintf('%02d', ($this->startYear + 1) % 100);
    }

    /** '26-27', the form used inside document numbers ({FY}). */
    public function short(): string
    {
        return sprintf('%02d-%02d', $this->startYear % 100, ($this->startYear + 1) % 100);
    }

    public function startDate(): string
    {
        return sprintf('%04d-04-01', $this->startYear);
    }

    public function endDate(): string
    {
        return sprintf('%04d-03-31', $this->startYear + 1);
    }

    public function contains(string $date): bool
    {
        return $date >= $this->startDate() && $date <= $this->endDate();
    }

    public function next(): self
    {
        return new self($this->startYear + 1);
    }

    public function previous(): self
    {
        return new self($this->startYear - 1);
    }

    public function isBefore(self $other): bool
    {
        return $this->startYear < $other->startYear;
    }

    public function equals(self $other): bool
    {
        return $this->startYear === $other->startYear;
    }

    public function __toString(): string
    {
        return $this->label();
    }
}
