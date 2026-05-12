<?php

namespace App\Jobs;

use App\Models\Platform\Plan;
use App\Models\Platform\ShopSubscription;
use App\Models\Shop;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class AssignPlanToShop implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 30;

    public function __construct(
        public readonly int $shopId,
        public readonly int $planId,
        public readonly int $adminId,
    ) {}

    public function handle(): void
    {
        $shop = Shop::find($this->shopId);
        $plan = Plan::find($this->planId);

        if (! $shop || ! $plan) {
            Log::warning('AssignPlanToShop: shop or plan not found', [
                'shop_id' => $this->shopId,
                'plan_id' => $this->planId,
            ]);
            return;
        }

        ShopSubscription::create([
            'shop_id'             => $shop->id,
            'plan_id'             => $plan->id,
            'status'              => 'active',
            'billing_cycle'       => 'monthly',
            'starts_at'           => now(),
            'ends_at'             => now()->addMonth(),
            'price_paid'          => $plan->price_monthly ?? 0,
            'updated_by_admin_id' => $this->adminId,
            'notes'               => 'Bulk plan assignment by platform admin.',
        ]);

        Log::info('AssignPlanToShop: plan assigned', [
            'shop_id'  => $this->shopId,
            'plan_id'  => $this->planId,
            'admin_id' => $this->adminId,
        ]);
    }
}
