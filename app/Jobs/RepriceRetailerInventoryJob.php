<?php

namespace App\Jobs;

use App\Services\ShopPricingService;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RepriceRetailerInventoryJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Ceiling on the uniqueness lock, not the dedupe window.
     *
     * Laravel releases the lock as soon as the job finishes, so a genuine
     * correction ("I typed 5500, I meant 5600") a minute later still reprices.
     * This only bounds how long a worker that died mid-job can block its
     * successors.
     */
    public int $uniqueFor = 300;

    /**
     * Declared, not promoted, and nullable on purpose.
     *
     * A promoted constructor property has no declared default, so a job that
     * was serialised onto the queue by the previous release — which had no
     * businessDate at all — would come back with this property uninitialised
     * and fatal the moment uniqueId() touched it. Every reprice queued at the
     * moment of deploy would be lost, silently, because nothing surfaces a
     * failed background job to the shop. A declared default survives
     * unserialize(); null then means "resolve from the clock", which is
     * exactly what the old code did.
     *
     * ponytail: this nullability exists only to cross one deploy. Once the
     * queue has drained past it, make the argument required.
     */
    public ?string $businessDate = null;

    public function __construct(public int $shopId, ?string $businessDate = null)
    {
        $this->businessDate = $businessDate;
    }

    public function uniqueId(): string
    {
        return $this->shopId.':'.($this->businessDate ?? 'current');
    }

    public function handle(ShopPricingService $pricing): void
    {
        TenantContext::runFor($this->shopId, function () use ($pricing): void {
            // Pin the reprice to the business date the rates were saved under.
            // Without this the job resolves "today" when it runs, so a save at
            // 23:59 IST picked up by a worker at 00:01 reprices against the new
            // day — which has no rates yet, so repriceInStockItems bails and the
            // save appears to have done nothing to prices.
            $businessDate = $this->businessDate === null
                ? null
                : CarbonImmutable::parse(
                    $this->businessDate,
                    $pricing->pricingTimezone($this->shopId)
                )->startOfDay();

            $pricing->repriceInStockItems($this->shopId, $businessDate);
        });
    }
}
