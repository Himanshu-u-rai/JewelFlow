<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/** Pure date rules for locking the books. "Today" is always India's calendar day (FinancialYear::TIMEZONE). */
final class LockDateRules
{
    public const MIN_REASON_LENGTH = 10;

    /** The latest date a lock may reach: yesterday in India. Today and the future stay open. */
    public static function latestLockable(CarbonInterface $now): string
    {
        return CarbonImmutable::instance($now)->setTimezone(FinancialYear::TIMEZONE)->subDay()->toDateString();
    }

    /** A real calendar date written Y-m-d, or an InvalidArgumentException. */
    public static function parse(string $date): string
    {
        if (! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            throw new InvalidArgumentException('Enter a valid date.');
        }

        return $date;
    }

    /** 31 March at the end of the last completed financial year. */
    public static function lastFinancialYearEnd(CarbonInterface $now): string
    {
        return FinancialYear::forInstant($now)->previous()->endDate();
    }

    public static function reasonIsEnough(string $reason): bool
    {
        return mb_strlen(trim($reason)) >= self::MIN_REASON_LENGTH;
    }
}
