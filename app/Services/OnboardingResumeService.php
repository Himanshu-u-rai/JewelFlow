<?php

namespace App\Services;

use App\Models\Platform\ShopSubscription;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\RedirectResponse;

class OnboardingResumeService
{
    /**
     * Onboarding steps in order.
     */
    public const STEP_CHOOSE_TYPE = 'choose_type';
    public const STEP_SELECT_PLAN = 'select_plan';
    public const STEP_PAYMENT = 'payment';
    public const STEP_CREATE_SHOP = 'create_shop';

    /**
     * Resolve the correct onboarding step for a user based on persistent DB state.
     *
     * Returns null if onboarding is complete (user has a shop).
     */
    public static function resolveStep(User|Authenticatable $user): ?string
    {
        // Already has a shop → onboarding complete
        if ($user->shop_id) {
            return null;
        }

        // Has a paid subscription waiting → go to shop creation
        if (static::findPendingSubscription($user)) {
            return self::STEP_CREATE_SHOP;
        }

        // Has chosen a shop type → go to plan selection
        if ($user->onboarding_shop_type) {
            // Check the saved step for more precision
            $step = $user->onboarding_step;

            if ($step === self::STEP_PAYMENT) {
                return self::STEP_PAYMENT;
            }

            return self::STEP_SELECT_PLAN;
        }

        // Fresh user → start from the beginning
        return self::STEP_CHOOSE_TYPE;
    }

    /**
     * Return the correct redirect for a given onboarding step.
     */
    public static function redirectForStep(?string $step): RedirectResponse
    {
        return match ($step) {
            self::STEP_CHOOSE_TYPE => redirect()->route('shops.choose-type'),
            self::STEP_SELECT_PLAN => redirect()->route('subscription.plans'),
            self::STEP_PAYMENT => redirect()->route('subscription.payment'),
            self::STEP_CREATE_SHOP => redirect()->route('shops.create'),
            default => redirect()->route('dashboard'),
        };
    }

    /**
     * Find a subscription that belongs to this user but hasn't been linked to a shop yet,
     * OR was created during onboarding (shop_id is null).
     */
    public static function findPendingSubscription(User|Authenticatable $user): ?ShopSubscription
    {
        return ShopSubscription::query()
            ->whereNull('shop_id')
            ->where('user_id', $user->id)
            ->whereIn('status', ['active', 'trial'])
            ->latest('id')
            ->first();
    }

    /**
     * Update the user's onboarding step in the database.
     */
    public static function setStep(User|Authenticatable $user, ?string $step, ?string $shopType = null): void
    {
        $data = ['onboarding_step' => $step];

        if ($shopType !== null) {
            $data['onboarding_shop_type'] = $shopType;
        }

        $user->forceFill($data)->save();
    }

    /**
     * Clear all onboarding state from the user record.
     */
    public static function clearOnboarding(User|Authenticatable $user): void
    {
        $user->forceFill([
            'onboarding_step' => null,
            'onboarding_shop_type' => null,
        ])->save();
    }
}
