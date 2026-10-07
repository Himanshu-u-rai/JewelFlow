<?php

// APP_ENV=testing php tests/Rehearsal/product_promotion_rollback.php
// Only the four promotion tables may be dropped; retain every other row/trigger.
use App\Models\Invoice;
use App\Services\ProductPromotionService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestDatabaseGuard;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->afterBootstrapping(\Illuminate\Foundation\Bootstrap\LoadConfiguration::class, function ($app): void {
    TestDatabaseGuard::enforce($app);
    $app['config']->set('cache.default', 'array');
});
$app->make(Kernel::class)->bootstrap();
TestDatabaseGuard::enforce($app);
set_exception_handler(function (\Throwable $e): void {
    fwrite(STDERR, $e::class.': '.$e->getMessage()."\n");
    exit(1);
});
config(['hashing.bcrypt.rounds' => 4]);
echo json_encode(DB::selectOne('select current_database() as database, inet_server_addr() as host, inet_server_port() as port'))."\n";
$migration = '2026_10_05_000001_create_product_promotion_tables';
if (DB::table('migrations')->orderByDesc('id')->value('migration') !== $migration) {
    throw new RuntimeException('Refused: promotion migration is not the most recent migration.');
}
$fixture = new class
{
    use CreatesTestTenant;

    public function seed(): array
    {
        [$user, $shop] = $this->createRetailerTenant();
        $user->forceFill(['password' => Hash::make('password')])->save();
        $customer = $this->createCustomer($shop->id);
        (new Invoice)->forceFill(['shop_id' => $shop->id, 'customer_id' => $customer->id,
            'invoice_number' => 'PROMO-ROLLBACK-'.$shop->id, 'status' => Invoice::STATUS_FINALIZED,
            'gold_rate' => 6000, 'subtotal' => 100, 'total' => 100, 'gst' => 0,
            'gst_rate' => 0, 'finalized_at' => now()])->save();

        return [$user, $shop];
    }
};
[$source] = $fixture->seed();
[$target] = $fixture->seed();
$target->forceFill(['realm' => 'dhiran'])->save();
$service = app(ProductPromotionService::class);
$service->choose($source, 'opt_out');
$code = $service->start($source, 'password');
$request = $service->requests($source)->whereNull('consumed_at')->value('id');
$service->approve($target, 'password', $code);
$service->finish($source, 'password', $request);
$preference = $service->preference($source);
DB::table('product_promotion_exposures')->insert(['preference_id' => $preference->id,
    'campaign' => ProductPromotionService::CAMPAIGN, 'created_at' => now()]);
$tables = ['product_recognitions', 'product_recognition_requests', 'product_promotion_exposures', 'product_promotion_preferences'];
function snapshot(array $excluded): array
{
    $result = [];
    foreach (Schema::getTables() as $table) {
        $name = $table['name'];
        if (in_array($name, $excluded, true)) {
            continue;
        }
        $quoted = '"'.str_replace('"', '""', $name).'"';
        $result[$name] = DB::selectOne("select count(*) as n, md5(coalesce(string_agg(row_to_json(t)::text, '' order by row_to_json(t)::text), '')) as hash from {$quoted} t");
    }

    return json_decode(json_encode($result), true);
}
$before = snapshot([...$tables, 'migrations']);
$triggers = DB::select('select tgname, pg_get_triggerdef(oid) as definition from pg_trigger where not tgisinternal order by tgname');
$start = microtime(true);
if (Artisan::call('migrate:rollback', ['--step' => 1, '--path' => 'database/migrations/'.$migration.'.php', '--force' => true]) !== 0) {
    throw new RuntimeException(Artisan::output());
}
foreach ($tables as $table) {
    if (Schema::hasTable($table)) {
        throw new RuntimeException('Rollback retained '.$table);
    }
}
if ($before !== snapshot([...$tables, 'migrations'])) {
    throw new RuntimeException('Rollback changed business rows');
}
if (Artisan::call('migrate', ['--path' => 'database/migrations/'.$migration.'.php', '--force' => true]) !== 0) {
    throw new RuntimeException(Artisan::output());
}
foreach ($tables as $table) {
    if (! Schema::hasTable($table) || DB::table($table)->count() !== 0) {
        throw new RuntimeException('Re-apply did not create empty '.$table);
    }
}
if ($before !== snapshot([...$tables, 'migrations']) || $triggers != DB::select('select tgname, pg_get_triggerdef(oid) as definition from pg_trigger where not tgisinternal order by tgname')) {
    throw new RuntimeException('Re-apply changed business rows or trigger definitions');
}
echo json_encode(['result' => 'PASS', 'non_promotion_tables_unchanged' => count($before),
    'triggers_unchanged' => count($triggers), 'duration_ms' => round((microtime(true) - $start) * 1000),
    'warning' => 'Rollback deliberately loses promotion preferences/recognition; shared environments must retain metadata and recover code forward.'])."\n";
