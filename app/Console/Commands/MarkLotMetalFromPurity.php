<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Services\MetalRegistry;
use App\Support\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-shot correction for lots saved before a lot had to say its metal (they show as "Unknown material").
 *
 * The purity decides, by the scales every fine weight was computed with: karats (up to 24) are gold, fineness (above
 * 24, up to 1000) is silver. A lot whose purity cannot tell is listed and left alone, never guessed.
 *
 * Default is a dry run that writes nothing; --commit marks each lot and records an audit row per lot.
 */
class MarkLotMetalFromPurity extends Command
{
    protected $signature = 'lots:mark-metal {--commit : actually write (default is a dry run)}';

    protected $description = 'Mark old metal lots that have no metal as gold or silver from their purity';

    public function handle(): int
    {
        $commit = (bool) $this->option('commit');
        $lots = DB::table('metal_lots')->whereNull('metal_type')->orderBy('shop_id')->orderBy('id')
            ->get(['id', 'shop_id', 'lot_number', 'source', 'purity', 'fine_weight_remaining']);

        if ($lots->isEmpty()) {
            $this->info('Every lot already has its metal.');

            return self::SUCCESS;
        }

        $rows = [];
        foreach ($lots as $lot) {
            $metal = MetalRegistry::metalFromPurity((float) $lot->purity);
            $rows[] = [$lot->shop_id, $lot->lot_number, $lot->source, (float) $lot->purity, (float) $lot->fine_weight_remaining, $metal ?? 'LEFT (purity cannot tell)'];

            if (! $commit || $metal === null) {
                continue;
            }
            DB::transaction(function () use ($lot, $metal) {
                // whereNull: never overwrite a metal someone set in the meantime.
                $updated = DB::table('metal_lots')->where('id', $lot->id)->whereNull('metal_type')->update(['metal_type' => $metal, 'updated_at' => now()]);
                if ($updated === 1) {
                    TenantContext::runFor((int) $lot->shop_id, fn () => AuditLog::create([
                        'action' => 'metal_lot.metal_marked',
                        'model_type' => 'MetalLot',
                        'model_id' => $lot->id,
                        'description' => "Lot {$lot->lot_number} marked {$metal} from its purity {$lot->purity}",
                        'data' => ['metal_type' => $metal, 'purity' => (float) $lot->purity, 'by' => 'lots:mark-metal'],
                    ]));
                }
            });
        }

        $this->table(['Shop', 'Lot', 'Source', 'Purity', 'Fine left (g)', 'Metal'], $rows);
        $this->info($commit ? 'Marked.' : 'Dry run: nothing written. Run with --commit to mark these lots.');

        return self::SUCCESS;
    }
}
