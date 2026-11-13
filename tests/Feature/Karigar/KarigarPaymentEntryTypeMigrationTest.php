<?php

namespace Tests\Feature\Karigar;

use App\Models\Karigar;
use App\Models\KarigarPayment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Security review of f99cb301: advance bookkeeping rows written before entry_type existed (reference text only)
 * would be read as ordinary payments, i.e. a spent advance would look unspent. karigar_payments is append-only
 * (CONSTITUTION trigger #11), so such rows cannot be backfilled; per §4 Rule 2 the migration stops and names them.
 * (None can exist in practice: before d734d188 applying an advance always failed, and d734d188 never shipped alone.)
 */
class KarigarPaymentEntryTypeMigrationTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    private const MIGRATION = 'database/migrations/2026_11_13_120000_add_entry_type_to_karigar_payments.php';

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    public function test_the_migration_stops_on_unmarked_advance_bookkeeping_rows(): void
    {
        [, $shop] = $this->createRetailerTenant();
        $karigar = new Karigar();
        $karigar->forceFill(['shop_id' => $shop->id, 'name' => 'K', 'is_active' => true])->save();

        $migration = require base_path(self::MIGRATION);
        $migration->down();
        $this->assertFalse(Schema::hasColumn('karigar_payments', 'entry_type'));
        $legacy = KarigarPayment::record([
            'shop_id' => $shop->id, 'karigar_id' => $karigar->id, 'amount' => -500, 'mode' => 'cash',
            'paid_on' => now()->toDateString(), 'reference' => 'ADVANCE-USED:42',
        ]);
        // An ordinary typed reference and an old reversal are not in the way.
        KarigarPayment::record(['shop_id' => $shop->id, 'karigar_id' => $karigar->id, 'amount' => 100, 'mode' => 'upi',
            'paid_on' => now()->toDateString(), 'reference' => 'UPI 12345']);

        $refused = null;
        try {
            $migration->up();
        } catch (\RuntimeException $e) {
            $refused = $e;
        }
        $this->assertNotNull($refused, 'the columns went live over unmarked bookkeeping rows');
        $this->assertStringContainsString("payment ids: {$legacy->id}", $refused->getMessage());
        $this->assertFalse(Schema::hasColumn('karigar_payments', 'entry_type'), 'nothing half-applied');
    }
}
