<?php

namespace App\Services;

use App\Models\ShopPreferences;
use App\Support\FinancialYear;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The owner's dashboard nudge to lock the books. It only DESCRIBES a reminder and links to the lock screen;
 * nothing here (or behind the banner) ever locks anything: a period lock is always an explicit owner action
 * (Constitution Art. VII.9, FinancialLockService). "Today" is India's calendar day.
 *
 *  - year-end (wins): the previous financial year has ended and the lock is before its last day;
 *  - monthly: from the 21st (GSTR-3B is usually filed by then), the lock is before the last day of last month.
 * "Not now" stores the current month ("2026-11") per shop; any reminder stays hidden until the month changes.
 */
class LockReminderService
{
    public const MONTHLY_FROM_DAY = 21;

    /** @return array{kind: string, title: string, through: string, fy?: string}|null  fy: the year that ended (year kind) */
    public static function forShop(int $shopId, ?CarbonInterface $now = null): ?array
    {
        $local = CarbonImmutable::instance($now ?? CarbonImmutable::now())->setTimezone(FinancialYear::TIMEZONE);

        $dismissed = ShopPreferences::withoutGlobalScopes()->where('shop_id', $shopId)->value('lock_reminder_dismissed_key');
        if ($dismissed === $local->format('Y-m')) {
            return null;
        }

        $lock = FinancialLockService::currentLockDate($shopId);

        $previous = FinancialYear::forInstant($local)->previous();
        if ($lock === null || $lock < $previous->endDate()) {
            return [
                'kind' => 'year',
                'title' => "Financial year {$previous->label()} has ended. Lock its books so they can't change?",
                'through' => $previous->endDate(),
                'fy' => $previous->label(),
            ];
        }

        if ($local->day >= self::MONTHLY_FROM_DAY) {
            $monthEnd = $local->startOfMonth()->subDay();
            if ($lock < $monthEnd->toDateString()) {
                return [
                    'kind' => 'month',
                    'title' => 'Lock books through ' . $monthEnd->format('j F Y') . '?',
                    'through' => $monthEnd->toDateString(),
                ];
            }
        }

        return null;
    }

    public static function dismiss(int $shopId, ?CarbonInterface $now = null): void
    {
        $month = CarbonImmutable::instance($now ?? CarbonImmutable::now())->setTimezone(FinancialYear::TIMEZONE)->format('Y-m');
        $prefs = ShopPreferences::withoutGlobalScopes()->firstOrNew(['shop_id' => $shopId]);
        $prefs->shop_id = $shopId;
        $prefs->lock_reminder_dismissed_key = $month;
        $prefs->save();
    }
}
