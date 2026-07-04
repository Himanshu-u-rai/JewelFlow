<?php

namespace App\Services;

use App\Models\OnboardingBatch;
use App\Models\ShopPreferences;

/**
 * Single source of truth for whether a shop may begin live transactional use.
 *
 * Derived purely from two existing signals — never a new flag:
 *   - shop_preferences.opening_setup_skipped_at  (the "Start Fresh" marker)
 *   - onboarding_batches.status                  (the migration state machine)
 *
 * States:
 *   migration_completed  a locked batch exists                     → live
 *   started_fresh        opening_setup_skipped_at is not null       → live
 *   migration_in_progress a draft/review/posting batch, none locked → blocked
 *   setup_required       none of the above                         → blocked
 *
 * live-allowed signals win over blocking ones so the gate can never lock out a
 * shop that has already made a decision (e.g. stray active batch + skip flag).
 *
 * Reused by controller, EnsureOpeningSetupCompleted middleware, and future
 * mobile bootstrap. Queries are shop_id-explicit + withoutTenant() so it is
 * context-independent (web request, console, or a foreign tenant lookup).
 */
class ShopOpeningSetupState
{
    public const MIGRATION_COMPLETED    = 'migration_completed';
    public const STARTED_FRESH          = 'started_fresh';
    public const MIGRATION_IN_PROGRESS  = 'migration_in_progress';
    public const SETUP_REQUIRED         = 'setup_required';

    private function __construct(public readonly string $state)
    {
    }

    public static function forShop(int $shopId): self
    {
        $hasLocked = OnboardingBatch::withoutTenant()
            ->where('shop_id', $shopId)
            ->where('status', OnboardingBatch::STATUS_LOCKED)
            ->exists();

        if ($hasLocked) {
            return new self(self::MIGRATION_COMPLETED);
        }

        $startedFresh = ShopPreferences::withoutTenant()
            ->where('shop_id', $shopId)
            ->whereNotNull('opening_setup_skipped_at')
            ->exists();

        if ($startedFresh) {
            return new self(self::STARTED_FRESH);
        }

        $hasActive = OnboardingBatch::withoutTenant()
            ->where('shop_id', $shopId)
            ->whereIn('status', [
                OnboardingBatch::STATUS_DRAFT,
                OnboardingBatch::STATUS_REVIEW,
                OnboardingBatch::STATUS_POSTING,
            ])
            ->exists();

        if ($hasActive) {
            return new self(self::MIGRATION_IN_PROGRESS);
        }

        return new self(self::SETUP_REQUIRED);
    }

    public function isLiveAllowed(): bool
    {
        return in_array($this->state, [self::MIGRATION_COMPLETED, self::STARTED_FRESH], true);
    }

    public function isBlocked(): bool
    {
        return ! $this->isLiveAllowed();
    }

    public function isMigrationInProgress(): bool
    {
        return $this->state === self::MIGRATION_IN_PROGRESS;
    }

    public function isSetupRequired(): bool
    {
        return $this->state === self::SETUP_REQUIRED;
    }
}
