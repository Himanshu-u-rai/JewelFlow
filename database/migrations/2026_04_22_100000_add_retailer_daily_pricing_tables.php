<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_preferences', function (Blueprint $table): void {
            if (! Schema::hasColumn('shop_preferences', 'pricing_timezone')) {
                $table->string('pricing_timezone', 100)->nullable()->after('language');
            }
        });

        Schema::table('items', function (Blueprint $table): void {
            if (! Schema::hasColumn('items', 'metal_type')) {
                $table->string('metal_type', 20)->nullable()->after('category');
            }

            if (! Schema::hasColumn('items', 'pricing_review_required')) {
                $table->boolean('pricing_review_required')->default(false)->after('metal_type');
            }

            if (! Schema::hasColumn('items', 'pricing_review_notes')) {
                $table->string('pricing_review_notes', 255)->nullable()->after('pricing_review_required');
            }
        });

        Schema::table('products', function (Blueprint $table): void {
            if (! Schema::hasColumn('products', 'metal_type')) {
                $table->string('metal_type', 20)->nullable()->after('sub_category_id');
            }
        });

        $this->alterProductDefaultPurityToDecimal();

        Schema::create('shop_metal_purity_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->string('metal_type', 20);
            $table->string('code', 30);
            $table->string('label', 60);
            $table->decimal('purity_value', 8, 3);
            $table->string('basis', 30);
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->unique(
                ['shop_id', 'metal_type', 'purity_value', 'basis'],
                'shop_metal_purity_profiles_unique_profile'
            );
            $table->index(
                ['shop_id', 'metal_type', 'is_active', 'sort_order'],
                'shop_metal_purity_profiles_lookup_idx'
            );
        });

        Schema::create('shop_daily_metal_rates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->date('business_date');
            $table->string('timezone', 100);
            $table->decimal('gold_24k_rate_per_gram', 12, 4);
            $table->decimal('silver_999_rate_per_gram', 12, 4);
            $table->foreignId('entered_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('entered_at')->nullable();
            $table->timestamp('updated_at')->nullable();

            $table->unique(['shop_id', 'business_date'], 'shop_daily_metal_rates_shop_business_date_unique');
            $table->index(['shop_id', 'business_date'], 'shop_daily_metal_rates_lookup_idx');
        });

        Schema::table('metal_rates', function (Blueprint $table): void {
            if (! Schema::hasColumn('metal_rates', 'business_date')) {
                $table->date('business_date')->nullable()->after('shop_id');
            }

            if (! Schema::hasColumn('metal_rates', 'shop_metal_purity_profile_id')) {
                $table->foreignId('shop_metal_purity_profile_id')
                    ->nullable()
                    ->after('shop_id')
                    ->constrained('shop_metal_purity_profiles')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('metal_rates', 'purity_value')) {
                $table->decimal('purity_value', 8, 3)->nullable()->after('purity');
            }

            if (! Schema::hasColumn('metal_rates', 'purity_basis')) {
                $table->string('purity_basis', 30)->nullable()->after('purity_value');
            }

            if (! Schema::hasColumn('metal_rates', 'is_override')) {
                $table->boolean('is_override')->default(false)->after('source');
            }
        });

        Schema::table('metal_rates', function (Blueprint $table): void {
            $table->index(
                ['shop_id', 'business_date', 'metal_type', 'purity_value', 'fetched_at'],
                'metal_rates_shop_day_lookup_idx'
            );
            $table->index(
                ['shop_metal_purity_profile_id', 'business_date', 'fetched_at'],
                'metal_rates_profile_day_lookup_idx'
            );
        });

        $this->backfillPricingTimezone();
        $this->backfillLegacyMetalTypes();
        $this->seedDefaultPurityProfiles();
        $this->seedObservedPurityProfiles();
    }

    public function down(): void
    {
        Schema::table('metal_rates', function (Blueprint $table): void {
            if (Schema::hasColumn('metal_rates', 'shop_metal_purity_profile_id')) {
                $table->dropForeign(['shop_metal_purity_profile_id']);
            }

            $table->dropIndex('metal_rates_shop_day_lookup_idx');
            $table->dropIndex('metal_rates_profile_day_lookup_idx');
        });

        Schema::table('metal_rates', function (Blueprint $table): void {
            $columns = array_values(array_filter([
                Schema::hasColumn('metal_rates', 'business_date') ? 'business_date' : null,
                Schema::hasColumn('metal_rates', 'shop_metal_purity_profile_id') ? 'shop_metal_purity_profile_id' : null,
                Schema::hasColumn('metal_rates', 'purity_value') ? 'purity_value' : null,
                Schema::hasColumn('metal_rates', 'purity_basis') ? 'purity_basis' : null,
                Schema::hasColumn('metal_rates', 'is_override') ? 'is_override' : null,
            ]));

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });

        Schema::dropIfExists('shop_daily_metal_rates');
        Schema::dropIfExists('shop_metal_purity_profiles');

        Schema::table('products', function (Blueprint $table): void {
            if (Schema::hasColumn('products', 'metal_type')) {
                $table->dropColumn('metal_type');
            }
        });

        Schema::table('items', function (Blueprint $table): void {
            $columns = array_values(array_filter([
                Schema::hasColumn('items', 'metal_type') ? 'metal_type' : null,
                Schema::hasColumn('items', 'pricing_review_required') ? 'pricing_review_required' : null,
                Schema::hasColumn('items', 'pricing_review_notes') ? 'pricing_review_notes' : null,
            ]));

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });

        Schema::table('shop_preferences', function (Blueprint $table): void {
            if (Schema::hasColumn('shop_preferences', 'pricing_timezone')) {
                $table->dropColumn('pricing_timezone');
            }
        });

        $this->alterProductDefaultPurityToInteger();
    }

    private function alterProductDefaultPurityToDecimal(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            DB::statement(
                'ALTER TABLE products ALTER COLUMN default_purity TYPE NUMERIC(8,3) USING default_purity::numeric(8,3)'
            );
            return;
        }

        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE products MODIFY default_purity DECIMAL(8,3) NULL');
        }
    }

    private function alterProductDefaultPurityToInteger(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            DB::statement(
                'ALTER TABLE products ALTER COLUMN default_purity TYPE INTEGER USING ROUND(default_purity)::integer'
            );
            return;
        }

        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE products MODIFY default_purity INT NULL');
        }
    }

    private function backfillPricingTimezone(): void
    {
        $timezone = (string) config('app.timezone', 'UTC');

        DB::table('shop_preferences')
            ->whereNull('pricing_timezone')
            ->update(['pricing_timezone' => $timezone]);
    }

    private function backfillLegacyMetalTypes(): void
    {
        DB::table('items')->orderBy('id')->chunkById(500, function ($rows): void {
            foreach ($rows as $row) {
                $metalType = $this->guessMetalType(
                    $row->category ?? null,
                    $row->sub_category ?? null,
                    $row->design ?? null,
                    $row->purity ?? null
                );

                DB::table('items')
                    ->where('id', $row->id)
                    ->update([
                        'metal_type' => $metalType,
                        'pricing_review_required' => $this->databaseBoolean($metalType === null),
                        'pricing_review_notes' => $metalType === null
                            ? 'Could not confidently determine the legacy metal type.'
                            : null,
                    ]);
            }
        });

        DB::table('products')->orderBy('id')->chunkById(500, function ($rows): void {
            foreach ($rows as $row) {
                $metalType = $this->guessMetalType(
                    $row->name ?? null,
                    null,
                    $row->design_code ?? null,
                    $row->default_purity ?? null
                );

                DB::table('products')
                    ->where('id', $row->id)
                    ->update(['metal_type' => $metalType]);
            }
        });
    }

    private function seedDefaultPurityProfiles(): void
    {
        $defaults = [
            ['metal_type' => 'gold', 'purity_value' => 24, 'basis' => 'karat_24', 'code' => '24', 'label' => '24K', 'sort_order' => 10],
            ['metal_type' => 'gold', 'purity_value' => 22, 'basis' => 'karat_24', 'code' => '22', 'label' => '22K', 'sort_order' => 20],
            ['metal_type' => 'gold', 'purity_value' => 18, 'basis' => 'karat_24', 'code' => '18', 'label' => '18K', 'sort_order' => 30],
            ['metal_type' => 'gold', 'purity_value' => 14, 'basis' => 'karat_24', 'code' => '14', 'label' => '14K', 'sort_order' => 40],
            ['metal_type' => 'silver', 'purity_value' => 999, 'basis' => 'millesimal_1000', 'code' => '999', 'label' => '999', 'sort_order' => 10],
            ['metal_type' => 'silver', 'purity_value' => 925, 'basis' => 'millesimal_1000', 'code' => '925', 'label' => '925', 'sort_order' => 20],
        ];

        DB::table('shops')->select('id')->orderBy('id')->chunkById(200, function ($shops) use ($defaults): void {
            foreach ($shops as $shop) {
                foreach ($defaults as $profile) {
                    $exists = DB::table('shop_metal_purity_profiles')
                        ->where('shop_id', $shop->id)
                        ->where('metal_type', $profile['metal_type'])
                        ->where('purity_value', $profile['purity_value'])
                        ->where('basis', $profile['basis'])
                        ->exists();

                    if ($exists) {
                        continue;
                    }

                    DB::table('shop_metal_purity_profiles')->insert([
                        'shop_id' => $shop->id,
                        'metal_type' => $profile['metal_type'],
                        'code' => $profile['code'],
                        'label' => $profile['label'],
                        'purity_value' => $profile['purity_value'],
                        'basis' => $profile['basis'],
                        'is_active' => $this->databaseBoolean(true),
                        'sort_order' => $profile['sort_order'],
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        });
    }

    private function seedObservedPurityProfiles(): void
    {
        $observed = collect();

        $itemRows = DB::table('items')
            ->select('shop_id', 'metal_type', 'purity as purity_value')
            ->whereNotNull('shop_id')
            ->whereNotNull('metal_type')
            ->whereNotNull('purity')
            ->distinct()
            ->get();

        $productRows = DB::table('products')
            ->select('shop_id', 'metal_type', 'default_purity as purity_value')
            ->whereNotNull('shop_id')
            ->whereNotNull('metal_type')
            ->whereNotNull('default_purity')
            ->distinct()
            ->get();

        $observed = $observed
            ->merge($itemRows)
            ->merge($productRows)
            ->filter(fn ($row) => in_array($row->metal_type, ['gold', 'silver'], true))
            ->unique(fn ($row) => implode('|', [
                $row->shop_id,
                $row->metal_type,
                $this->normalizePurity((float) $row->purity_value),
            ]))
            ->values();

        foreach ($observed as $row) {
            $purityValue = $this->normalizePurity((float) $row->purity_value);
            if ($purityValue <= 0) {
                continue;
            }

            $basis = $row->metal_type === 'silver' ? 'millesimal_1000' : 'karat_24';
            $exists = DB::table('shop_metal_purity_profiles')
                ->where('shop_id', $row->shop_id)
                ->where('metal_type', $row->metal_type)
                ->where('purity_value', $purityValue)
                ->where('basis', $basis)
                ->exists();

            if ($exists) {
                continue;
            }

            $sortOrder = (int) DB::table('shop_metal_purity_profiles')
                ->where('shop_id', $row->shop_id)
                ->where('metal_type', $row->metal_type)
                ->max('sort_order');

            DB::table('shop_metal_purity_profiles')->insert([
                'shop_id' => $row->shop_id,
                'metal_type' => $row->metal_type,
                'code' => $this->normalizePurityString($purityValue),
                'label' => $row->metal_type === 'gold'
                    ? $this->normalizePurityString($purityValue) . 'K'
                    : $this->normalizePurityString($purityValue),
                'purity_value' => $purityValue,
                'basis' => $basis,
                'is_active' => $this->databaseBoolean(true),
                'sort_order' => max(10, $sortOrder + 10),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function guessMetalType(?string $category, ?string $subCategory, ?string $design, mixed $purity): ?string
    {
        $haystack = mb_strtolower(trim(implode(' ', array_filter([
            $category,
            $subCategory,
            $design,
        ]))));

        $mentionsGold = str_contains($haystack, 'gold');
        $mentionsSilver = str_contains($haystack, 'silver') || str_contains($haystack, 'sterling');

        if ($mentionsGold && ! $mentionsSilver) {
            return 'gold';
        }

        if ($mentionsSilver && ! $mentionsGold) {
            return 'silver';
        }

        if (is_numeric($purity)) {
            $normalized = (float) $purity;

            if ($normalized > 0 && $normalized <= 24) {
                return 'gold';
            }

            if ($normalized > 24 && $normalized <= 1000) {
                return 'silver';
            }
        }

        return null;
    }

    private function normalizePurity(float $value): float
    {
        return (float) number_format($value, 3, '.', '');
    }

    private function normalizePurityString(float $value): string
    {
        return rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.');
    }

    private function databaseBoolean(bool $value): mixed
    {
        if (DB::getDriverName() === 'pgsql') {
            return DB::raw($value ? 'true' : 'false');
        }

        return $value;
    }
};
