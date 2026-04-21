<?php

namespace App\Console\Commands;

use App\Models\Shop;
use App\Services\DashboardMetricsService;
use App\Services\PosSearchCacheService;
use App\Support\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class WarmShopCache extends Command
{
    protected $signature = 'cache:warm-shops {--shop=* : Warm only specific shop IDs} {--terms= : Comma-separated search terms}';
    protected $description = 'Pre-warm dashboard and POS read cache for active shops';

    public function handle(): int
    {
        $shopIds = $this->resolveShops();
        $terms = $this->resolveTerms();
        $today = now()->toDateString();
        $dashboardTtl = (int) config('performance.cache.dashboard_ttl_seconds', 300);

        if ($shopIds->isEmpty()) {
            $this->warn('No shops selected for cache warm-up.');
            return self::SUCCESS;
        }

        foreach ($shopIds as $shopId) {
            TenantContext::runFor((int) $shopId, function () use ($shopId, $today, $dashboardTtl, $terms): void {
                Cache::remember(
                    "dashboard:{$shopId}:{$today}",
                    now()->addSeconds($dashboardTtl),
                    fn () => DashboardMetricsService::build((int) $shopId)
                );

                foreach ($terms as $term) {
                    PosSearchCacheService::items((int) $shopId, $term);
                    PosSearchCacheService::customers((int) $shopId, $term);
                }
            });

            $this->line("Warmed cache for shop #{$shopId}");
        }

        $this->info('Cache warm-up completed.');
        return self::SUCCESS;
    }

    private function resolveShops()
    {
        $explicit = collect((array) $this->option('shop'))
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->values();

        if ($explicit->isNotEmpty()) {
            return $explicit;
        }

        return Shop::query()
            ->active()
            ->whereIn('access_mode', ['active', 'read_only'])
            ->pluck('id');
    }

    private function resolveTerms(): array
    {
        $custom = trim((string) $this->option('terms'));
        if ($custom !== '') {
            $terms = collect(explode(',', $custom))
                ->map(fn ($term) => trim($term))
                ->filter()
                ->values()
                ->all();

            return array_values(array_unique(array_merge([''], $terms)));
        }

        $configTerms = (array) config('performance.cache.warmup_search_terms', []);
        $configTerms = collect($configTerms)
            ->map(fn ($term) => trim((string) $term))
            ->filter()
            ->values()
            ->all();

        return array_values(array_unique(array_merge([''], $configTerms)));
    }
}
