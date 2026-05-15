<?php

namespace App\Console\Commands;

use App\Models\Shop;
use App\Services\TenantRoleService;
use Illuminate\Console\Command;

/**
 * Re-runs TenantRoleService::ensureDefaultsForShop() against every existing
 * shop. The service uses sync() on the role_permission pivot, so it idempotently
 * brings each shop's seeded role permissions back in line with the canonical
 * default list.
 *
 * Use after fixing seeder bugs (e.g., when a permission key was renamed or a
 * non-existent key was being referenced) to backfill all existing shops.
 *
 * Owner role always re-syncs to ALL permissions.
 * Manager role re-syncs to all-except-(settings.edit + staff.manage).
 * Staff role re-syncs to the 14-permission canonical default.
 */
class ResyncTenantPermissions extends Command
{
    protected $signature = 'tenants:resync-permissions {--dry-run : Show what would change without writing}';
    protected $description = 'Re-sync every tenant shop\'s default Owner/Manager/Staff role permissions to the canonical seed.';

    public function handle(TenantRoleService $service): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->warn('Running in DRY-RUN mode — no changes will be written.');
        }

        $shopIds = Shop::query()->pluck('id');

        if ($shopIds->isEmpty()) {
            $this->info('No shops found.');
            return self::SUCCESS;
        }

        $this->info("Resyncing role permissions for {$shopIds->count()} shops...");

        $synced = 0;
        $errors = 0;

        foreach ($shopIds as $shopId) {
            try {
                if (! $dryRun) {
                    $service->ensureDefaultsForShop((int) $shopId);
                }
                $synced++;
                $this->line(" ✓ Shop #{$shopId}");
            } catch (\Throwable $e) {
                $errors++;
                $this->error(" ✗ Shop #{$shopId}: {$e->getMessage()}");
                \Log::error("tenants:resync-permissions failed for shop #{$shopId}", ['exception' => $e]);
            }
        }

        $this->newLine();
        $this->info("Done. Synced: {$synced}, errors: {$errors}.");

        return $errors === 0 ? self::SUCCESS : self::FAILURE;
    }
}
