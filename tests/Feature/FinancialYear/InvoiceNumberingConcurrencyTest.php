<?php

namespace Tests\Feature\FinancialYear;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Two tills take the first number of a new financial year at the same instant (owner, 2026-11-16: "break what
 * you make"). Two real sessions against the same database: the second must wait for the first and get the
 * next number, never the same one. The old April reset ran outside the counter lock and could hand both 1001.
 */
class InvoiceNumberingConcurrencyTest extends TestCase
{
    use DatabaseTruncation, CreatesTestTenant;

    protected $exceptTables = ['permissions', 'platform_products', 'platform_settings'];

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        config(['database.connections.pgsql_rival' => config('database.connections.pgsql')]);
        DB::purge('pgsql_rival');
    }

    protected function tearDown(): void
    {
        if (RefreshDatabaseState::$migrated && $this->app) {
            $this->truncateDatabaseTables();
        }
        parent::tearDown();
    }

    /** @dataProvider series */
    public function test_two_sessions_never_get_the_same_first_number_of_a_year(string $prefix, ?string $yearlyFrom, string $expectFirst, string $expectSecond): void
    {
        [, $shop] = $this->createRetailerTenant();
        DB::table('shop_billing_settings')->where('shop_id', $shop->id)
            ->update(['invoice_prefix' => $prefix, 'invoice_series_yearly_from' => $yearlyFrom]);

        $rival = DB::connection('pgsql_rival');

        DB::beginTransaction();
        $first = DB::selectOne('SELECT invoice_number FROM next_invoice_identity(?, ?)', [$shop->id, '2027-28']);

        // While the first till holds the counter, the second cannot read past it.
        $rival->statement("SET lock_timeout = '300ms'");
        try {
            $rival->selectOne('SELECT invoice_number FROM next_invoice_identity(?, ?)', [$shop->id, '2027-28']);
            $this->fail('The second session got a number while the first still held the counter.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('lock timeout', $e->getMessage());
        }

        DB::commit();
        $second = $rival->selectOne('SELECT invoice_number FROM next_invoice_identity(?, ?)', [$shop->id, '2027-28']);

        $this->assertSame($expectFirst, $first->invoice_number);
        $this->assertSame($expectSecond, $second->invoice_number);
    }

    public static function series(): array
    {
        return [
            'yearly series, brand-new year counter' => ['INV/{FY}/', '2027-28', 'INV/27-28/1001', 'INV/27-28/1002'],
            'continuous series'                     => ['INV-', null, 'INV-1001', 'INV-1002'],
        ];
    }
}
