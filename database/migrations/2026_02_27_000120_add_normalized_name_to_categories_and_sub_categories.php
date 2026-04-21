<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table): void {
            if (!Schema::hasColumn('categories', 'normalized_name')) {
                $table->string('normalized_name')->nullable()->after('name');
            }
        });

        Schema::table('sub_categories', function (Blueprint $table): void {
            if (!Schema::hasColumn('sub_categories', 'normalized_name')) {
                $table->string('normalized_name')->nullable()->after('name');
            }
        });

        $this->backfillNormalizedNames();

        $categoryDuplicates = DB::select(<<<'SQL'
            SELECT shop_id, normalized_name, COUNT(*) AS c
            FROM categories
            GROUP BY shop_id, normalized_name
            HAVING COUNT(*) > 1
        SQL);

        if (!empty($categoryDuplicates)) {
            throw new \RuntimeException('Cannot enforce categories normalized_name uniqueness: duplicate category names exist per shop.');
        }

        $subCategoryDuplicates = DB::select(<<<'SQL'
            SELECT shop_id, category_id, normalized_name, COUNT(*) AS c
            FROM sub_categories
            GROUP BY shop_id, category_id, normalized_name
            HAVING COUNT(*) > 1
        SQL);

        if (!empty($subCategoryDuplicates)) {
            throw new \RuntimeException('Cannot enforce sub_categories normalized_name uniqueness: duplicate sub-category names exist per category/shop.');
        }

        Schema::table('categories', function (Blueprint $table): void {
            $table->unique(['shop_id', 'normalized_name'], 'categories_shop_normalized_unique');
        });
        $this->setNormalizedNameNotNull('categories');

        Schema::table('sub_categories', function (Blueprint $table): void {
            $table->unique(['shop_id', 'category_id', 'normalized_name'], 'sub_categories_shop_category_normalized_unique');
        });
        $this->setNormalizedNameNotNull('sub_categories');
    }

    public function down(): void
    {
        Schema::table('sub_categories', function (Blueprint $table): void {
            $table->dropUnique('sub_categories_shop_category_normalized_unique');
            $table->dropColumn('normalized_name');
        });

        Schema::table('categories', function (Blueprint $table): void {
            $table->dropUnique('categories_shop_normalized_unique');
            $table->dropColumn('normalized_name');
        });
    }

    private function backfillNormalizedNames(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("UPDATE categories SET normalized_name = lower(trim(regexp_replace(name, '\\s+', ' ', 'g'))) WHERE normalized_name IS NULL OR normalized_name = ''");
            DB::statement("UPDATE sub_categories SET normalized_name = lower(trim(regexp_replace(name, '\\s+', ' ', 'g'))) WHERE normalized_name IS NULL OR normalized_name = ''");

            return;
        }

        foreach (['categories', 'sub_categories'] as $tableName) {
            DB::table($tableName)
                ->where(function ($query): void {
                    $query->whereNull('normalized_name')->orWhere('normalized_name', '');
                })
                ->orderBy('id')
                ->get(['id', 'name'])
                ->each(function ($row) use ($tableName): void {
                    DB::table($tableName)
                        ->where('id', $row->id)
                        ->update(['normalized_name' => $this->normalizeName((string) $row->name)]);
                });
        }
    }

    private function setNormalizedNameNotNull(string $tableName): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE {$tableName} ALTER COLUMN normalized_name SET NOT NULL");

            return;
        }

        Schema::table($tableName, function (Blueprint $table): void {
            $table->string('normalized_name')->nullable(false)->change();
        });
    }

    private function normalizeName(string $value): string
    {
        $normalized = preg_replace('/\s+/', ' ', trim($value));

        return mb_strtolower((string) $normalized);
    }
};
