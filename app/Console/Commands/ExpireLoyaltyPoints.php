<?php

namespace App\Console\Commands;

use App\Models\Shop;
use App\Services\LoyaltyService;
use App\Support\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * S3-16. Scheduled daily. Each shop runs in its own tenant context: without
 * it every loyalty query failed closed and the command expired nothing while
 * reporting success. Expiry is append-only (LoyaltyService::expireDue) and
 * starts only at loyalty.expiry_active_from; until that is set, and for lots
 * that fell due before it, this reports and writes nothing.
 */
class ExpireLoyaltyPoints extends Command
{
    protected $signature = 'loyalty:expire {--dry-run : Report what is due; write nothing}';

    protected $description = 'Expire, append-only, loyalty points that fell due on or after loyalty.expiry_active_from';

    public function handle(LoyaltyService $loyalty): int
    {
        $configured = config('loyalty.expiry_active_from');
        $activeFrom = filled($configured) ? Carbon::parse($configured)->startOfDay() : null;
        $write = $activeFrom !== null && ! $this->option('dry-run');
        $this->line($activeFrom === null
            ? 'Loyalty expiry NOT ACTIVATED (loyalty.expiry_active_from unset): reporting only, nothing is written.'
            : 'Loyalty expiry enforced for points due on or after '.$activeFrom->toDateString().'.');

        $shops = Shop::active()->orderBy('id')->pluck('id');
        $errors = 0;
        foreach ($shops as $shopId) {
            try {
                $r = TenantContext::runFor((int) $shopId, fn () => $loyalty->expireDue((int) $shopId, $activeFrom, $write));
            } catch (\Throwable $e) {
                $errors++;
                $this->error("Shop #{$shopId}: loyalty expire failed — {$e->getMessage()}");
                \Log::error("loyalty:expire failed for shop #{$shopId}", ['exception' => $e]);

                continue;
            }
            if ($r['due_lots'] + $r['kept_lots'] + count($r['skipped']) === 0) {
                continue;
            }

            $due = match (true) {
                $activeFrom === null => 'nothing written',
                ! $write => "due, not written (dry run): {$r['due_lots']} lot(s) / {$r['due_points']} pts",
                default => "expired {$r['due_lots']} lot(s) / {$r['due_points']} pts",
            };
            $this->line("Shop #{$shopId}: {$due}; ".($activeFrom === null ? 'overdue' : 'overdue before activation')
                .", kept: {$r['kept_lots']} lot(s) / {$r['kept_points']} pts"
                .($r['skipped'] === [] ? '' : '; skipped, ledger ≠ balance: customer '.implode(', ', $r['skipped'])));
        }

        $this->line("Done: {$shops->count()} shop(s), {$errors} error(s).");

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }
}
