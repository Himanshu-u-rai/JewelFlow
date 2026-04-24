<?php

namespace App\Services;

use App\Jobs\RepriceRetailerInventoryJob;
use App\Models\Item;
use App\Models\MetalRate;
use App\Models\Shop;
use App\Models\ShopDailyMetalRate;
use App\Models\ShopMetalPurityProfile;
use App\Models\ShopPreferences;
use Carbon\CarbonInterface;
use Carbon\CarbonImmutable;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;

class ShopPricingService
{
    public const METAL_GOLD = 'gold';
    public const METAL_SILVER = 'silver';

    public const BASIS_GOLD = 'karat_24';
    public const BASIS_SILVER = 'millesimal_1000';

    private array $schemaState = [];

    public function pricingTimezone(Shop|int $shop): string
    {
        $shopId = $shop instanceof Shop ? (int) $shop->id : (int) $shop;

        if (! $this->hasColumn('shop_preferences', 'pricing_timezone')) {
            return (string) config('app.timezone', 'UTC');
        }

        $timezone = ShopPreferences::withoutTenant()
            ->where('shop_id', $shopId)
            ->value('pricing_timezone');

        return is_string($timezone) && $timezone !== ''
            ? $timezone
            : (string) config('app.timezone', 'UTC');
    }

    public function businessDate(Shop|int $shop, ?CarbonInterface $now = null): CarbonImmutable
    {
        $instant = $now ? CarbonImmutable::instance($now) : CarbonImmutable::now('UTC');

        return $instant
            ->setTimezone($this->pricingTimezone($shop))
            ->startOfDay();
    }

    public function businessDateString(Shop|int $shop, ?CarbonInterface $now = null): string
    {
        return $this->businessDate($shop, $now)->toDateString();
    }

    public function hasCurrentDailyRates(Shop|int $shop, ?CarbonInterface $now = null): bool
    {
        return $this->currentDailyRate($shop, $now) !== null;
    }

    public function currentDailyRate(Shop|int $shop, ?CarbonInterface $now = null): ?ShopDailyMetalRate
    {
        if (! $this->hasTable('shop_daily_metal_rates')) {
            return null;
        }

        $shopId = $shop instanceof Shop ? (int) $shop->id : (int) $shop;

        return ShopDailyMetalRate::withoutTenant()
            ->where('shop_id', $shopId)
            ->where('business_date', $this->businessDateString($shopId, $now))
            ->orderByDesc('id')
            ->first();
    }

    public function assertRetailerPricingReady(Shop $shop, ?CarbonInterface $now = null): void
    {
        if (! $shop->isRetailer()) {
            return;
        }

        if ($this->hasCurrentDailyRates($shop, $now)) {
            return;
        }

        throw new LogicException(sprintf(
            "Today's retailer metal rates are missing for %s (%s). Ask the owner to save today's Pricing rates first.",
            $this->businessDateString($shop, $now),
            $this->pricingTimezone($shop)
        ));
    }

    public function allPurityProfiles(Shop|int $shop, ?string $metalType = null): Collection
    {
        if (! $this->hasTable('shop_metal_purity_profiles')) {
            return collect();
        }

        $shopId = $shop instanceof Shop ? (int) $shop->id : (int) $shop;

        $this->ensureDefaultPurityProfiles($shopId);

        return ShopMetalPurityProfile::withoutTenant()
            ->where('shop_id', $shopId)
            ->when($metalType, fn ($query) => $query->where('metal_type', $metalType))
            ->orderBy('metal_type')
            ->orderBy('sort_order')
            ->orderByDesc('purity_value')
            ->get();
    }

    public function activePurityProfiles(Shop|int $shop, ?string $metalType = null): Collection
    {
        return $this->allPurityProfiles($shop, $metalType)
            ->where('is_active', true)
            ->values();
    }

    public function ensureDefaultPurityProfiles(int $shopId): void
    {
        if (! $this->hasTable('shop_metal_purity_profiles')) {
            return;
        }

        $defaults = [
            [self::METAL_GOLD, 24, self::BASIS_GOLD, '24', '24K', 10],
            [self::METAL_GOLD, 22, self::BASIS_GOLD, '22', '22K', 20],
            [self::METAL_GOLD, 18, self::BASIS_GOLD, '18', '18K', 30],
            [self::METAL_GOLD, 14, self::BASIS_GOLD, '14', '14K', 40],
            [self::METAL_SILVER, 999, self::BASIS_SILVER, '999', '999', 10],
            [self::METAL_SILVER, 925, self::BASIS_SILVER, '925', '925', 20],
        ];

        foreach ($defaults as [$metalType, $purityValue, $basis, $code, $label, $sortOrder]) {
            ShopMetalPurityProfile::withoutTenant()->firstOrCreate(
                [
                    'shop_id' => $shopId,
                    'metal_type' => $metalType,
                    'purity_value' => $this->normalizePurityValue($purityValue),
                    'basis' => $basis,
                ],
                [
                    'code' => $code,
                    'label' => $label,
                    'is_active' => true,
                    'sort_order' => $sortOrder,
                ]
            );
        }
    }

    public function upsertPurityProfile(Shop $shop, array $attributes, ?ShopMetalPurityProfile $profile = null): ShopMetalPurityProfile
    {
        $metalType = $this->normalizeMetalType((string) ($attributes['metal_type'] ?? ''));
        $purityValue = $this->normalizePurityValue((float) ($attributes['purity_value'] ?? 0));
        $this->assertPurityValueAllowed($metalType, $purityValue);

        $basis = $this->basisForMetalType($metalType);
        $code = trim((string) ($attributes['code'] ?? ''));
        $label = trim((string) ($attributes['label'] ?? ''));

        if ($code === '') {
            $code = $this->normalizePurityString($purityValue);
        }

        if ($label === '') {
            $label = $this->formatPurityLabel($metalType, $purityValue);
        }

        $hasExplicitSortOrder = array_key_exists('sort_order', $attributes)
            && $attributes['sort_order'] !== null
            && $attributes['sort_order'] !== '';

        if ($hasExplicitSortOrder) {
            $sortOrder = (int) $attributes['sort_order'];
            if ($sortOrder <= 0) {
                $sortOrder = $this->nextSortOrder((int) $shop->id, $metalType);
            }
        } elseif ($profile) {
            $sortOrder = (int) $profile->sort_order;
            if ($sortOrder <= 0) {
                $sortOrder = $this->nextSortOrder((int) $shop->id, $metalType);
            }
        } else {
            $sortOrder = $this->nextSortOrder((int) $shop->id, $metalType);
        }

        $payload = [
            'shop_id' => (int) $shop->id,
            'metal_type' => $metalType,
            'code' => $code,
            'label' => $label,
            'purity_value' => $purityValue,
            'basis' => $basis,
            'is_active' => (bool) ($attributes['is_active'] ?? true),
            'sort_order' => $sortOrder,
        ];

        if ($profile) {
            if ((int) $profile->shop_id !== (int) $shop->id) {
                throw new LogicException('Purity profile does not belong to this shop.');
            }

            $profile->fill($payload);
            $profile->save();

            return $profile->fresh();
        }

        return ShopMetalPurityProfile::withoutTenant()->create($payload);
    }

    public function createObservedProfileIfMissing(int $shopId, string $metalType, float $purityValue): ShopMetalPurityProfile
    {
        $metalType = $this->normalizeMetalType($metalType);
        $purityValue = $this->normalizePurityValue($purityValue);
        $this->assertPurityValueAllowed($metalType, $purityValue);

        return ShopMetalPurityProfile::withoutTenant()->firstOrCreate(
            [
                'shop_id' => $shopId,
                'metal_type' => $metalType,
                'purity_value' => $purityValue,
                'basis' => $this->basisForMetalType($metalType),
            ],
            [
                'code' => $this->normalizePurityString($purityValue),
                'label' => $this->formatPurityLabel($metalType, $purityValue),
                'is_active' => true,
                'sort_order' => $this->nextSortOrder($shopId, $metalType),
            ]
        );
    }

    public function profileForPurity(Shop|int $shop, string $metalType, float $purityValue): ?ShopMetalPurityProfile
    {
        if (! $this->hasTable('shop_metal_purity_profiles')) {
            return null;
        }

        $shopId = $shop instanceof Shop ? (int) $shop->id : (int) $shop;

        return ShopMetalPurityProfile::withoutTenant()
            ->where('shop_id', $shopId)
            ->where('metal_type', $this->normalizeMetalType($metalType))
            ->where('purity_value', $this->normalizePurityValue($purityValue))
            ->whereRaw($this->booleanComparisonSql('is_active', true))
            ->orderBy('sort_order')
            ->first();
    }

    public function saveTodayBaseRates(Shop $shop, int $userId, array $attributes): ShopDailyMetalRate
    {
        $this->assertPricingSchemaReady();

        $dailyRate = DB::transaction(function () use ($shop, $userId, $attributes): ShopDailyMetalRate {
            $businessDate = $this->businessDateString($shop);
            $timezone = $this->pricingTimezone($shop);

            $goldRate = round((float) ($attributes['gold_24k_rate_per_gram'] ?? 0), 4);
            $silverRate = round((float) ($attributes['silver_999_rate_per_gram'] ?? 0), 4);

            if ($goldRate <= 0 || $silverRate <= 0) {
                throw new LogicException('Gold and silver base rates must be greater than zero.');
            }

            $dailyRate = ShopDailyMetalRate::withoutTenant()
                ->firstOrNew([
                    'shop_id' => (int) $shop->id,
                    'business_date' => $businessDate,
                ]);

            if (! $dailyRate->exists) {
                $dailyRate->entered_at = now();
            }

            $dailyRate->timezone = $timezone;
            $dailyRate->gold_24k_rate_per_gram = $goldRate;
            $dailyRate->silver_999_rate_per_gram = $silverRate;
            $dailyRate->entered_by_user_id = $userId;
            $dailyRate->updated_at = now();
            $dailyRate->save();

            $this->resolveAndRecordCurrentDayRates($dailyRate, true);

            return $dailyRate;
        });

        RepriceRetailerInventoryJob::dispatch((int) $shop->id);

        return $dailyRate->fresh();
    }

    public function saveSameDayOverride(
        Shop $shop,
        ShopMetalPurityProfile $profile,
        float $ratePerGram
    ): MetalRate {
        $this->assertPricingSchemaReady();

        if ((int) $profile->shop_id !== (int) $shop->id) {
            throw new LogicException('Purity profile does not belong to this shop.');
        }

        $dailyRate = $this->currentDailyRate($shop);
        if (! $dailyRate) {
            throw new LogicException('Save today\'s base rates before adding overrides.');
        }

        $ratePerGram = round($ratePerGram, 4);
        if ($ratePerGram <= 0) {
            throw new LogicException('Override rate must be greater than zero.');
        }

        $rate = $this->recordResolvedRate($profile, $dailyRate->business_date->toDateString(), $ratePerGram, true);
        RepriceRetailerInventoryJob::dispatch((int) $shop->id);

        return $rate;
    }

    public function resolveAndRecordCurrentDayRates(ShopDailyMetalRate $dailyRate, bool $preserveOverrides = true): Collection
    {
        $this->assertPricingSchemaReady();

        $shopId = (int) $dailyRate->shop_id;
        $businessDate = $dailyRate->business_date->toDateString();
        $profiles = $this->activePurityProfiles($shopId);
        $rows = collect();

        $latestOverrides = $preserveOverrides
            ? $this->latestCurrentDayOverrides($shopId, $businessDate)
            : collect();

        foreach ($profiles as $profile) {
            $resolvedRate = $this->deriveRateForProfile($dailyRate, $profile);
            $rows->push($this->recordResolvedRate($profile, $businessDate, $resolvedRate, false));
        }

        foreach ($latestOverrides as $override) {
            if (! $override->shop_metal_purity_profile_id) {
                continue;
            }

            $profile = $profiles->firstWhere('id', (int) $override->shop_metal_purity_profile_id);
            if (! $profile) {
                continue;
            }

            $rows->push($this->recordResolvedRate(
                $profile,
                $businessDate,
                (float) $override->rate_per_gram,
                true
            ));
        }

        return $rows;
    }

    public function currentResolvedRateRecordForProfile(
        Shop|int $shop,
        int $profileId,
        ?CarbonInterface $now = null
    ): ?MetalRate {
        if (! $this->hasPricingSchema()) {
            return null;
        }

        $shopId = $shop instanceof Shop ? (int) $shop->id : (int) $shop;
        $businessDate = $this->businessDateString($shopId, $now);

        return MetalRate::query()
            ->where('shop_id', $shopId)
            ->where('shop_metal_purity_profile_id', $profileId)
            ->where('business_date', $businessDate)
            ->orderByDesc('fetched_at')
            ->orderByDesc('id')
            ->first();
    }

    public function resolvedRateForToday(
        Shop|int $shop,
        string $metalType,
        float $purityValue,
        ?CarbonInterface $now = null
    ): ?float {
        if (! $this->hasPricingSchema()) {
            return null;
        }

        $shopId = $shop instanceof Shop ? (int) $shop->id : (int) $shop;
        $businessDate = $this->businessDateString($shopId, $now);

        $rate = MetalRate::latestResolvedForDay(
            $shopId,
            $businessDate,
            $this->normalizeMetalType($metalType),
            $this->normalizePurityValue($purityValue)
        );

        if ($rate) {
            return round((float) $rate->rate_per_gram, 4);
        }

        return $this->backfillMissingResolvedRateForToday(
            $shopId,
            $this->normalizeMetalType($metalType),
            $this->normalizePurityValue($purityValue),
            $now
        );
    }

    public function costPriceFromResolvedRate(
        float $netMetalWeight,
        float $resolvedRatePerGram
    ): float {
        return round($netMetalWeight * $resolvedRatePerGram, 2);
    }

    public function computeRetailerCostPayload(Shop $shop, array $attributes, ?CarbonInterface $now = null): array
    {
        $this->assertPricingSchemaReady();
        $this->assertRetailerPricingReady($shop, $now);

        $metalType = $this->normalizeMetalType((string) ($attributes['metal_type'] ?? ''));
        $purityValue = $this->normalizePurityValue((float) ($attributes['purity'] ?? 0));
        $grossWeight = round((float) ($attributes['gross_weight'] ?? 0), 6);
        $stoneWeight = round((float) ($attributes['stone_weight'] ?? 0), 6);

        if ($grossWeight <= 0) {
            throw new LogicException('Gross weight must be greater than zero.');
        }

        if ($stoneWeight < 0 || $stoneWeight >= $grossWeight) {
            throw new LogicException('Stone weight must be less than gross weight.');
        }

        $profile = $this->profileForPurity($shop, $metalType, $purityValue);
        if (! $profile) {
            throw new LogicException('Select an active purity profile for the chosen metal type.');
        }

        $netMetalWeight = round($grossWeight - $stoneWeight, 6);
        $resolvedRate = $this->resolvedRateForToday($shop, $metalType, $purityValue, $now);

        if ($resolvedRate === null) {
            throw new LogicException('Could not resolve today\'s rate for the selected purity.');
        }

        $makingCharges   = round((float) ($attributes['making_charges']   ?? 0), 2);
        $stoneCharges    = round((float) ($attributes['stone_charges']    ?? 0), 2);
        $hallmarkCharges = round((float) ($attributes['hallmark_charges'] ?? 0), 2);
        $rhodiumCharges  = round((float) ($attributes['rhodium_charges']  ?? 0), 2);
        $otherCharges    = round((float) ($attributes['other_charges']    ?? 0), 2);

        $metalCost    = $this->costPriceFromResolvedRate($netMetalWeight, $resolvedRate);
        $sellingPrice = round(
            $metalCost + $makingCharges + $stoneCharges + $hallmarkCharges + $rhodiumCharges + $otherCharges,
            2
        );

        return [
            'metal_type'           => $metalType,
            'purity'               => $purityValue,
            'net_metal_weight'     => $netMetalWeight,
            'resolved_rate_per_gram' => $resolvedRate,
            'cost_price'           => $metalCost,
            'selling_price'        => $sellingPrice,
        ];
    }

    public function repriceInStockItems(int $shopId): int
    {
        if (! $this->hasPricingSchema() || ! $this->hasRetailerPricingItemColumns()) {
            return 0;
        }

        $shop = Shop::query()->find($shopId);
        if (! $shop || ! $shop->isRetailer() || ! $this->hasCurrentDailyRates($shop)) {
            return 0;
        }

        $updated = 0;

        Item::withoutTenant()
            ->where('shop_id', $shopId)
            ->where('status', 'in_stock')
            ->whereNotNull('metal_type')
            ->where(function ($query): void {
                $query->whereNull('pricing_review_required')
                    ->orWhereRaw($this->booleanComparisonSql('pricing_review_required', false));
            })
            ->orderBy('id')
            ->chunkById(200, function ($items) use ($shop, &$updated): void {
                foreach ($items as $item) {
                    try {
                        $payload = $this->computeRetailerCostPayload($shop, [
                            'metal_type'       => $item->metal_type,
                            'purity'           => $item->purity,
                            'gross_weight'     => $item->gross_weight,
                            'stone_weight'     => $item->stone_weight,
                            'making_charges'   => $item->making_charges,
                            'stone_charges'    => $item->stone_charges,
                            'hallmark_charges' => $item->hallmark_charges ?? 0,
                            'rhodium_charges'  => $item->rhodium_charges  ?? 0,
                            'other_charges'    => $item->other_charges    ?? 0,
                        ]);
                    } catch (\Throwable) {
                        continue;
                    }

                    DB::table('items')
                        ->where('id', $item->id)
                        ->update([
                            'net_metal_weight' => $payload['net_metal_weight'],
                            'cost_price'       => $payload['cost_price'],
                            'selling_price'    => $payload['selling_price'],
                            'updated_at'       => now(),
                        ]);

                    $updated++;
                }
            });

        return $updated;
    }

    public function legacyItemsNeedingReview(Shop|int $shop): Collection
    {
        if (! $this->hasRetailerPricingItemColumns()) {
            return collect();
        }

        $shopId = $shop instanceof Shop ? (int) $shop->id : (int) $shop;

        return Item::withoutTenant()
            ->where('shop_id', $shopId)
            ->where(function ($query): void {
                $query->whereNull('metal_type')
                    ->orWhereRaw($this->booleanComparisonSql('pricing_review_required', true));
            })
            ->orderByDesc('updated_at')
            ->limit(100)
            ->get();
    }

    public function resolveLegacyItem(Item $item, string $metalType): Item
    {
        $this->assertPricingSchemaReady();

        $metalType = $this->normalizeMetalType($metalType);
        $shopId = (int) $item->shop_id;
        $this->createObservedProfileIfMissing($shopId, $metalType, (float) $item->purity);

        $updates = [
            'metal_type' => $metalType,
            'pricing_review_required' => $this->databaseBoolean(false),
            'pricing_review_notes' => null,
        ];

        $shop = Shop::query()->findOrFail($shopId);
        if ($shop->isRetailer() && $item->status === 'in_stock' && $this->hasCurrentDailyRates($shop)) {
            $payload = $this->computeRetailerCostPayload($shop, [
                'metal_type'       => $metalType,
                'purity'           => $item->purity,
                'gross_weight'     => $item->gross_weight,
                'stone_weight'     => $item->stone_weight,
                'making_charges'   => $item->making_charges,
                'stone_charges'    => $item->stone_charges,
                'hallmark_charges' => $item->hallmark_charges ?? 0,
                'rhodium_charges'  => $item->rhodium_charges  ?? 0,
                'other_charges'    => $item->other_charges    ?? 0,
            ]);

            $updates['net_metal_weight'] = $payload['net_metal_weight'];
            $updates['cost_price']       = $payload['cost_price'];
            $updates['selling_price']    = $payload['selling_price'];
        }

        Item::withoutTenant()->where('id', $item->id)->update(array_merge($updates, [
            'updated_at' => now(),
        ]));

        return Item::withoutTenant()->findOrFail($item->id);
    }

    public function resolvedRateGrid(Shop|int $shop): Collection
    {
        if (! $this->hasPricingSchema()) {
            return collect();
        }

        $shopId = $shop instanceof Shop ? (int) $shop->id : (int) $shop;
        $profiles = $this->allPurityProfiles($shopId);

        return $profiles->map(function (ShopMetalPurityProfile $profile) use ($shopId) {
            $currentRate = $this->currentResolvedRateRecordForProfile($shopId, (int) $profile->id);

            return [
                'profile' => $profile,
                'current_rate' => $currentRate,
                'rate_per_gram' => $currentRate ? (float) $currentRate->rate_per_gram : null,
                'is_override' => (bool) ($currentRate?->is_override ?? false),
            ];
        });
    }

    public function normalizeResolvedRateHistoryFilters(array $filters): array
    {
        $dateFrom = $this->normalizeDateFilter($filters['date_from'] ?? null);
        $dateTo = $this->normalizeDateFilter($filters['date_to'] ?? null);

        if ($dateFrom !== null && $dateTo !== null && $dateFrom > $dateTo) {
            [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
        }

        $metalType = strtolower(trim((string) ($filters['metal_type'] ?? '')));
        if (! in_array($metalType, [self::METAL_GOLD, self::METAL_SILVER], true)) {
            $metalType = '';
        }

        $purityRaw = trim((string) ($filters['purity_value'] ?? ''));
        if ($purityRaw !== '' && str_ends_with(strtolower($purityRaw), 'k')) {
            $purityRaw = trim(substr($purityRaw, 0, -1));
        }
        $purityValue = null;
        if ($purityRaw !== '' && is_numeric($purityRaw)) {
            $numericPurity = (float) $purityRaw;
            if ($numericPurity > 0 && $numericPurity <= 1000) {
                $purityValue = $this->normalizePurityValue($numericPurity);
            }
        }

        $entryType = strtolower(trim((string) ($filters['entry_type'] ?? '')));
        if (! in_array($entryType, ['base', 'override'], true)) {
            $entryType = '';
        }

        $sortBy = strtolower(trim((string) ($filters['sort_by'] ?? 'business_date')));
        if (! in_array($sortBy, ['business_date', 'metal_type', 'purity_value', 'rate_per_gram', 'entry_type', 'recorded_at'], true)) {
            $sortBy = 'business_date';
        }

        $sortDir = strtolower(trim((string) ($filters['sort_dir'] ?? 'desc')));
        if (! in_array($sortDir, ['asc', 'desc'], true)) {
            $sortDir = 'desc';
        }

        return [
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'metal_type' => $metalType,
            'purity_value' => $purityValue,
            'entry_type' => $entryType,
            'sort_by' => $sortBy,
            'sort_dir' => $sortDir,
        ];
    }

    public function resolvedRateHistory(Shop|int $shop, array $filters = [], int $perPage = 50): LengthAwarePaginator
    {
        if (! $this->hasPricingSchema()) {
            return new LengthAwarePaginator(
                collect(),
                0,
                $perPage,
                1,
                ['path' => LengthAwarePaginator::resolveCurrentPath(), 'pageName' => 'pricing_history_page']
            );
        }

        $shopId = $shop instanceof Shop ? (int) $shop->id : (int) $shop;
        $filters = $this->normalizeResolvedRateHistoryFilters($filters);

        $query = DB::table('metal_rates as rates')
            ->leftJoin('shop_metal_purity_profiles as profiles', function ($join) use ($shopId): void {
                $join->on('profiles.id', '=', 'rates.shop_metal_purity_profile_id')
                    ->where('profiles.shop_id', '=', $shopId);
            })
            ->where('rates.shop_id', $shopId)
            ->whereNotNull('rates.business_date');

        if ($filters['date_from']) {
            $query->whereDate('rates.business_date', '>=', $filters['date_from']);
        }

        if ($filters['date_to']) {
            $query->whereDate('rates.business_date', '<=', $filters['date_to']);
        }

        if ($filters['metal_type']) {
            $query->where('rates.metal_type', $filters['metal_type']);
        }

        if ($filters['purity_value'] !== null) {
            $query->where('rates.purity_value', $filters['purity_value']);
        }

        if ($filters['entry_type'] === 'override') {
            $query->whereRaw($this->booleanComparisonSql('rates.is_override', true));
        } elseif ($filters['entry_type'] === 'base') {
            $query->whereRaw($this->booleanComparisonSql('rates.is_override', false));
        }

        $sortColumnMap = [
            'business_date' => 'rates.business_date',
            'metal_type' => 'rates.metal_type',
            'purity_value' => 'rates.purity_value',
            'rate_per_gram' => 'rates.rate_per_gram',
            'entry_type' => 'rates.is_override',
            'recorded_at' => 'rates.fetched_at',
        ];
        $sortColumn = $sortColumnMap[$filters['sort_by']] ?? 'rates.business_date';

        $query
            ->orderBy($sortColumn, $filters['sort_dir'])
            ->orderBy('rates.fetched_at', 'desc')
            ->orderByDesc('rates.id');

        $paginator = $query->select([
            'rates.id',
            'rates.business_date',
            'rates.metal_type',
            'rates.purity',
            'rates.purity_value',
            'rates.rate_per_gram',
            'rates.is_override',
            'rates.source',
            'rates.fetched_at',
            'profiles.code as profile_code',
            'profiles.label as profile_label',
        ])->paginate($perPage, ['*'], 'pricing_history_page');

        return $paginator->withQueryString();
    }

    public function guessLegacyMetalType(?string $category, ?string $subCategory, ?string $design, mixed $purity): ?string
    {
        $haystack = mb_strtolower(trim(implode(' ', array_filter([
            $category,
            $subCategory,
            $design,
        ]))));

        $mentionsGold = str_contains($haystack, 'gold');
        $mentionsSilver = str_contains($haystack, 'silver') || str_contains($haystack, 'sterling');

        if ($mentionsGold && ! $mentionsSilver) {
            return self::METAL_GOLD;
        }

        if ($mentionsSilver && ! $mentionsGold) {
            return self::METAL_SILVER;
        }

        if (is_numeric($purity)) {
            $numericPurity = (float) $purity;

            if ($numericPurity > 0 && $numericPurity <= 24) {
                return self::METAL_GOLD;
            }

            if ($numericPurity > 24 && $numericPurity <= 1000) {
                return self::METAL_SILVER;
            }
        }

        return null;
    }

    public function normalizeMetalType(string $metalType): string
    {
        $normalized = strtolower(trim($metalType));

        if (! in_array($normalized, [self::METAL_GOLD, self::METAL_SILVER], true)) {
            throw new LogicException('Metal type must be gold or silver.');
        }

        return $normalized;
    }

    public function normalizePurityValue(float $value): float
    {
        return (float) number_format($value, 3, '.', '');
    }

    public function normalizePurityString(float $value): string
    {
        return rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.');
    }

    public function formatPurityLabel(string $metalType, float $purityValue): string
    {
        $value = $this->normalizePurityString($purityValue);

        return $metalType === self::METAL_GOLD ? $value . 'K' : $value;
    }

    private function deriveRateForProfile(ShopDailyMetalRate $dailyRate, ShopMetalPurityProfile $profile): float
    {
        $purityValue = (float) $profile->purity_value;

        if ($profile->metal_type === self::METAL_GOLD) {
            return round(((float) $dailyRate->gold_24k_rate_per_gram * $purityValue) / 24, 4);
        }

        return round(((float) $dailyRate->silver_999_rate_per_gram * $purityValue) / 1000, 4);
    }

    private function recordResolvedRate(
        ShopMetalPurityProfile $profile,
        string $businessDate,
        float $ratePerGram,
        bool $isOverride
    ): MetalRate {
        return MetalRate::record([
            'shop_id' => (int) $profile->shop_id,
            'business_date' => $businessDate,
            'shop_metal_purity_profile_id' => (int) $profile->id,
            'metal_type' => $profile->metal_type,
            'purity' => $this->normalizePurityString((float) $profile->purity_value),
            'purity_value' => $this->normalizePurityValue((float) $profile->purity_value),
            'purity_basis' => $profile->basis,
            'rate_per_gram' => round($ratePerGram, 4),
            'source' => 'manual',
            'is_override' => $isOverride,
            'fetched_at' => now(),
            'created_at' => now(),
        ]);
    }

    private function latestCurrentDayOverrides(int $shopId, string $businessDate): Collection
    {
        return MetalRate::query()
            ->where('shop_id', $shopId)
            ->where('business_date', $businessDate)
            ->whereRaw($this->booleanComparisonSql('is_override', true))
            ->orderByDesc('fetched_at')
            ->orderByDesc('id')
            ->get()
            ->unique('shop_metal_purity_profile_id')
            ->values();
    }

    private function backfillMissingResolvedRateForToday(
        int $shopId,
        string $metalType,
        float $purityValue,
        ?CarbonInterface $now = null
    ): ?float {
        $dailyRate = $this->currentDailyRate($shopId, $now);
        if (! $dailyRate) {
            return null;
        }

        $profile = $this->profileForPurity($shopId, $metalType, $purityValue);
        if (! $profile) {
            return null;
        }

        $businessDate = $this->businessDateString($shopId, $now);
        $existing = MetalRate::latestResolvedForDay($shopId, $businessDate, $metalType, $purityValue);
        if ($existing) {
            return round((float) $existing->rate_per_gram, 4);
        }

        $resolvedRate = $this->deriveRateForProfile($dailyRate, $profile);
        $this->recordResolvedRate($profile, $businessDate, $resolvedRate, false);

        return $resolvedRate;
    }

    private function nextSortOrder(int $shopId, string $metalType): int
    {
        $currentMax = (int) ShopMetalPurityProfile::withoutTenant()
            ->where('shop_id', $shopId)
            ->where('metal_type', $metalType)
            ->max('sort_order');

        return max(10, $currentMax + 10);
    }

    private function normalizeDateFilter(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);
        if ($value === '' || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        try {
            $date = CarbonImmutable::createFromFormat('Y-m-d', $value, 'UTC');
        } catch (\Throwable) {
            return null;
        }

        if (! $date instanceof CarbonImmutable) {
            return null;
        }

        return $date->format('Y-m-d');
    }

    private function basisForMetalType(string $metalType): string
    {
        return $metalType === self::METAL_SILVER
            ? self::BASIS_SILVER
            : self::BASIS_GOLD;
    }

    private function assertPurityValueAllowed(string $metalType, float $purityValue): void
    {
        if ($purityValue <= 0) {
            throw new LogicException('Purity must be greater than zero.');
        }

        if ($metalType === self::METAL_GOLD && $purityValue > 24) {
            throw new LogicException('Gold purity must be 24 or below.');
        }

        if ($metalType === self::METAL_SILVER && $purityValue > 1000) {
            throw new LogicException('Silver purity must be 1000 or below.');
        }
    }

    private function assertPricingSchemaReady(): void
    {
        if ($this->hasPricingSchema()) {
            return;
        }

        throw new LogicException(
            'Retailer daily pricing setup is incomplete. Run "php artisan migrate" to apply the new pricing schema.'
        );
    }

    private function hasPricingSchema(): bool
    {
        return $this->hasColumn('shop_preferences', 'pricing_timezone')
            && $this->hasTable('shop_daily_metal_rates')
            && $this->hasTable('shop_metal_purity_profiles')
            && $this->hasColumn('metal_rates', 'business_date')
            && $this->hasColumn('metal_rates', 'shop_metal_purity_profile_id')
            && $this->hasColumn('metal_rates', 'purity_value')
            && $this->hasColumn('metal_rates', 'purity_basis')
            && $this->hasColumn('metal_rates', 'is_override');
    }

    private function hasRetailerPricingItemColumns(): bool
    {
        return $this->hasColumn('items', 'metal_type')
            && $this->hasColumn('items', 'pricing_review_required')
            && $this->hasColumn('items', 'pricing_review_notes');
    }

    private function hasTable(string $table): bool
    {
        $key = 'table:' . $table;

        if (! array_key_exists($key, $this->schemaState)) {
            $this->schemaState[$key] = Schema::hasTable($table);
        }

        return $this->schemaState[$key];
    }

    private function hasColumn(string $table, string $column): bool
    {
        $key = 'column:' . $table . '.' . $column;

        if (! array_key_exists($key, $this->schemaState)) {
            $this->schemaState[$key] = $this->hasTable($table) && Schema::hasColumn($table, $column);
        }

        return $this->schemaState[$key];
    }

    private function booleanComparisonSql(string $column, bool $value): string
    {
        $wrappedColumn = DB::getQueryGrammar()->wrap($column);

        return $wrappedColumn . ' = ' . $this->databaseBooleanLiteral($value);
    }

    private function databaseBoolean(bool $value): mixed
    {
        if (DB::getDriverName() === 'pgsql') {
            return DB::raw($this->databaseBooleanLiteral($value));
        }

        return $value;
    }

    private function databaseBooleanLiteral(bool $value): string
    {
        return $value ? 'true' : 'false';
    }
}
