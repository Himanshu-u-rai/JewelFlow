<?php

namespace App\Jobs;

use App\Services\ShopPricingService;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RepriceRetailerInventoryJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * UntilProcessing, not plain ShouldBeUnique, and the distinction matters.
     *
     * The lock is taken at *dispatch* time — PendingDispatch::shouldDispatch()
     * acquires it before the dispatcher ever sees the job — so a double-submit
     * that lands while an identical reprice is still queued is dropped, which
     * is what we want.
     *
     * Plain ShouldBeUnique holds that lock until handle() *finishes*. On a real
     * worker a reprice over a large stock takes a while, and an owner
     * correcting a typo in that window ("5500, I meant 5600") would have the
     * second reprice silently discarded — the rate row updates but the item
     * costs keep the wrong rate. UntilProcessing releases the lock the moment
     * the job starts, so the correction always gets its own run.
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
