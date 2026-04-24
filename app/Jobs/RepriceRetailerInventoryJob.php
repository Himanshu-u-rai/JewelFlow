<?php

namespace App\Jobs;

use App\Services\ShopPricingService;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RepriceRetailerInventoryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $shopId)
    {
    }

    public function handle(ShopPricingService $pricing): void
    {
        TenantContext::runFor($this->shopId, function () use ($pricing): void {
            $pricing->repriceInStockItems($this->shopId);
        });
    }
}
