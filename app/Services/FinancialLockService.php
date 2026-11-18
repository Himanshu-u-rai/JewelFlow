<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Shop;
use App\Models\User;
use App\Support\LockDateRules;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The owner's way to lock and unlock the books (shop_rules.financial_lock_date, enforced by
 * InvoiceAccountingService::assertShopLockForDate and the invoice DB guards).
 * Constitution Art. VII.9: a period lock or unlock is ALWAYS an explicit owner action — nothing calls this on its own.
 * Every change is audited (append-only) with before/after, reason and user.
 */
class FinancialLockService
{
    public const AUDIT_LOCKED = 'books.locked';
    public const AUDIT_UNLOCKED = 'books.unlocked';

    public static function currentLockDate(int $shopId): ?string
    {
        $v = DB::table('shop_rules')->where('shop_id', $shopId)->value('financial_lock_date');

        return $v ? substr((string) $v, 0, 10) : null;
    }

    /** Move the lock forward to $throughDate (a day before today at the latest). */
    public function lock(Shop $shop, string $throughDate, User $by, string $reason): void
    {
        $this->assertOwner($shop, $by);
        $date = LockDateRules::parse($throughDate);
        $latest = LockDateRules::latestLockable(CarbonImmutable::now());
        if ($date > $latest) {
            throw new InvalidArgumentException('Books can only be locked up to yesterday. Today and future dates stay open.');
        }

        DB::transaction(function () use ($shop, $date, $by, $reason): void {
            $current = $this->currentForUpdate($shop);
            if ($current !== null && $date <= $current) {
                throw new InvalidArgumentException('Books are already locked through ' . $current . '. A lock can only move forward; to go back, unlock with a reason.');
            }
            $this->write($shop, $date);
            $this->audit($shop, $by, self::AUDIT_LOCKED, $current, $date, $reason);
        });
    }

    public function lockThroughLastFinancialYear(Shop $shop, User $by, string $reason): void
    {
        $this->lock($shop, LockDateRules::lastFinancialYearEnd(CarbonImmutable::now()), $by, $reason);
    }

    /** Open the books fully ($newThroughDate null) or move the lock back to an earlier date. A reason is mandatory. */
    public function unlock(Shop $shop, ?string $newThroughDate, User $by, string $reason): void
    {
        $this->assertOwner($shop, $by);
        if (! LockDateRules::reasonIsEnough($reason)) {
            throw new InvalidArgumentException('Give a reason of at least ' . LockDateRules::MIN_REASON_LENGTH . ' characters to unlock the books.');
        }
        $new = ($newThroughDate === null || $newThroughDate === '') ? null : LockDateRules::parse($newThroughDate);

        DB::transaction(function () use ($shop, $new, $by, $reason): void {
            $current = $this->currentForUpdate($shop);
            if ($current === null) {
                throw new InvalidArgumentException('The books are not locked.');
            }
            if ($new !== null && $new >= $current) {
                throw new InvalidArgumentException('To unlock, choose a date earlier than ' . $current . ', or open the books completely.');
            }
            $this->assertReopensOnlyTheLatestClosedYear($shop, $new);
            $this->write($shop, $new);
            $this->audit($shop, $by, self::AUDIT_UNLOCKED, $current, $new, trim($reason));
        });
    }

    /**
     * Unlocking to $new reopens every closed year that ends after it. Only the most recent closed year (one with
     * a 'closing' snapshot) may be reopened: reopening an older one would change the opening figures of the
     * closed years after it. Automatic snapshots are not closings.
     */
    private function assertReopensOnlyTheLatestClosedYear(Shop $shop, ?string $new): void
    {
        $closed = DB::table('fiscal_year_snapshots')
            ->where('shop_id', $shop->id)->where('kind', 'closing')
            ->distinct()->orderByDesc('fiscal_year')->pluck('fiscal_year');

        $reopened = $closed->filter(fn (string $fy) => $new === null || \App\Support\FinancialYear::fromLabel($fy)->endDate() > $new);
        if ($reopened->count() > 1) {
            $latest = $closed->first();
            throw new InvalidArgumentException(
                "Only the latest closed financial year ({$latest}) can be reopened. Choose a date on or after "
                . \App\Support\FinancialYear::fromLabel($latest)->previous()->endDate() . '.'
            );
        }
    }

    private function assertOwner(Shop $shop, User $by): void
    {
        if ((int) $by->shop_id !== (int) $shop->id || ! $by->isShopOwner()) {
            // Owner rule (2026-10-30): a refusal never explains itself to a non-owner.
            throw new AuthorizationException(\App\Support\NotAvailable::MESSAGE);
        }
    }

    /** Reads the date under a row lock so two simultaneous submits are serialised. Creates the row if the shop has none. */
    private function currentForUpdate(Shop $shop): ?string
    {
        $row = DB::table('shop_rules')->where('shop_id', $shop->id)->lockForUpdate()->first();
        if (! $row) {
            DB::table('shop_rules')->insertOrIgnore(['shop_id' => $shop->id, 'created_at' => now(), 'updated_at' => now()]);
            $row = DB::table('shop_rules')->where('shop_id', $shop->id)->lockForUpdate()->first();
        }

        return $row->financial_lock_date ? substr((string) $row->financial_lock_date, 0, 10) : null;
    }

    private function write(Shop $shop, ?string $date): void
    {
        DB::table('shop_rules')->where('shop_id', $shop->id)->update(['financial_lock_date' => $date, 'updated_at' => now()]);
    }

    private function audit(Shop $shop, User $by, string $action, ?string $before, ?string $after, string $reason): void
    {
        $log = new AuditLog();
        $log->shop_id = (int) $shop->id; // not mass-assignable; set explicitly so it never depends on ambient tenant context
        $log->fill([
            'user_id' => (int) $by->id,
            'action' => $action,
            'model_type' => 'shop_rules',
            'model_id' => (int) $shop->id,
            'description' => $action === self::AUDIT_LOCKED
                ? "Books locked through {$after}."
                : 'Books unlocked' . ($after ? " back to {$after}." : ' completely.'),
            'before' => ['financial_lock_date' => $before],
            'after' => ['financial_lock_date' => $after],
            'data' => ['reason' => trim($reason)],
        ]);
        $log->save();
    }
}
