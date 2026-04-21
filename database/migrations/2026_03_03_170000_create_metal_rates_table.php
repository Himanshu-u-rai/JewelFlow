<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('metal_rates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shop_id')->nullable()->constrained('shops')->restrictOnDelete();
            $table->enum('metal_type', ['gold', 'silver', 'platinum']);
            $table->string('purity', 10);
            $table->decimal('rate_per_gram', 12, 4);
            $table->enum('source', ['api', 'manual']);
            $table->timestamp('fetched_at');
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::table('metal_rates', function (Blueprint $table): void {
            $table->index(['metal_type', 'purity', 'fetched_at'], 'metal_rates_metal_purity_fetched_idx');
            $table->index(['shop_id', 'metal_type', 'purity', 'fetched_at'], 'metal_rates_shop_metal_purity_fetched_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS metal_rates_metal_purity_fetched_desc_idx');
            DB::statement('CREATE INDEX metal_rates_metal_purity_fetched_desc_idx ON metal_rates (metal_type, purity, fetched_at DESC)');

            DB::statement('DROP INDEX IF EXISTS metal_rates_shop_metal_purity_fetched_desc_idx');
            DB::statement('CREATE INDEX metal_rates_shop_metal_purity_fetched_desc_idx ON metal_rates (shop_id, metal_type, purity, fetched_at DESC)');

            DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION block_metal_rates_mutation()
RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION 'metal_rates is append-only: UPDATE/DELETE not allowed';
END;
$$ LANGUAGE plpgsql;
SQL);

            DB::statement('DROP TRIGGER IF EXISTS metal_rates_no_update ON metal_rates');
            DB::statement('CREATE TRIGGER metal_rates_no_update BEFORE UPDATE ON metal_rates FOR EACH ROW EXECUTE FUNCTION block_metal_rates_mutation()');
            DB::statement('DROP TRIGGER IF EXISTS metal_rates_no_delete ON metal_rates');
            DB::statement('CREATE TRIGGER metal_rates_no_delete BEFORE DELETE ON metal_rates FOR EACH ROW EXECUTE FUNCTION block_metal_rates_mutation()');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS metal_rates_no_update ON metal_rates');
            DB::statement('DROP TRIGGER IF EXISTS metal_rates_no_delete ON metal_rates');
            DB::statement('DROP FUNCTION IF EXISTS block_metal_rates_mutation()');
            DB::statement('DROP INDEX IF EXISTS metal_rates_metal_purity_fetched_desc_idx');
            DB::statement('DROP INDEX IF EXISTS metal_rates_shop_metal_purity_fetched_desc_idx');
        }

        Schema::dropIfExists('metal_rates');
    }
};
