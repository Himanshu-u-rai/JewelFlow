<?php

namespace App\Console\Commands;

use App\Models\Shop;
use App\Services\LoyaltyService;
use Illuminate\Console\Command;

class ExpireLoyaltyPoints extends Command
{
    protected $signature = 'loyalty:expire';
    protected $description = 'Expire loyalty points that have passed their expiry date';

    public function handle(): int
    {
        $shops = Shop::active()->pluck('id');
        $total = 0;
        $errors = 0;

        foreach ($shops as $shopId) {
            try {
                $expired = LoyaltyService::expirePoints($shopId);
                if ($expired > 0) {
                    $this->info("Shop #{$shopId}: expired {$expired} points");
                    $total += $expired;
                }
            } catch (\Throwable $e) {
                $errors++;
                $this->error("Shop #{$shopId}: loyalty expire failed — {$e->getMessage()}");
                \Log::error("loyalty:expire failed for shop #{$shopId}", ['exception' => $e]);
            }
        }

        $this->info("Done. Total expired: {$total} points across " . $shops->count() . " shops. Errors: {$errors}");

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }
}
