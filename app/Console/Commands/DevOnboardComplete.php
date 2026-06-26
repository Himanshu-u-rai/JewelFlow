<?php

namespace App\Console\Commands;

use App\Models\Platform\Plan;
use App\Models\User;
use App\Services\OnboardingResumeService;
use App\Services\SubscriptionPaymentService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;

/**
 * Local-only escape hatch for the pay-before-shop onboarding wall.
 *
 * Normal onboarding requires a real Razorpay round-trip to create the
 * subscription before the owner can build their shop. For local dev that is
 * pure friction, so this drives the legitimate no-payment trial path
 * (SubscriptionPaymentService::startTrial — same actor, guards and edition
 * grant the UI uses) and advances the owner to the create-shop step.
 *
 * Hard-gated to the local environment: it must never become a way to skip
 * payment on staging or production.
 */
class DevOnboardComplete extends Command
{
    protected $signature = 'dev:onboard-complete {mobile : Owner mobile_number of the onboarding user}';
    protected $description = '[local only] Activate a free trial for an onboarding user so they can create their shop without paying.';

    public function handle(SubscriptionPaymentService $payments): int
    {
        if (! app()->environment('local')) {
            $this->error('Refusing to run outside the local environment.');
            return self::FAILURE;
        }

        $user = User::where('mobile_number', $this->argument('mobile'))->first();
        if (! $user) {
            $this->error("No user with mobile_number {$this->argument('mobile')}.");
            return self::FAILURE;
        }

        // Dhiran accounts live on the dhiran realm and must trial the Dhiran plan
        // even before they reach dhiran/subscribe (where onboarding_shop_type is set).
        $type = $user->isDhiran() ? 'dhiran' : ($user->onboarding_shop_type ?? 'retailer');
        $plan = Plan::whereRaw('is_active IS TRUE')->where('code', "{$type}_monthly")->first();
        if (! $plan) {
            $this->error("No active {$type}_monthly plan to trial.");
            return self::FAILURE;
        }

        // startTrial reads Auth::user()/Auth::id() — log the owner in for this run.
        Auth::login($user);

        try {
            $subscription = $payments->startTrial($plan);
        } catch (\Throwable $e) {
            $this->error('Trial start failed: '.$e->getMessage());
            return self::FAILURE;
        }

        OnboardingResumeService::setStep($user, OnboardingResumeService::STEP_CREATE_SHOP);

        $this->info("Trial active (subscription #{$subscription->id}, status {$subscription->status}) for {$user->mobile_number}.");
        $this->info('Onboarding advanced to create_shop. Log in and you will land on the shop-setup page.');

        return self::SUCCESS;
    }
}
