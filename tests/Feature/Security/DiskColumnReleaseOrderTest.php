<?php

namespace Tests\Feature\Security;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * S3-01c / S3-02 / S3-04 — release-order safety of the three disk-column
 * migrations.
 *
 * WHY THIS EXISTS
 * ---------------
 * I described these migrations as "additive" and treated that as meaning they
 * were safe to apply while the deployed application was still serving. That
 * inference is wrong, and these tests are what establish it.
 *
 * Each migration did three things in one file: add a nullable disk column,
 * backfill it, and add a both-or-neither CHECK constraint. The column and the
 * backfill really are additive. The CONSTRAINT is not: it forbids a row that
 * has a path and no disk, and writing exactly that row is what baseline
 * 018b3d8 does on every upload. Verified in the baseline tree:
 *
 *   SettingsController:526-527        signature: writes _path, never _disk
 *   StockPurchaseController:140,354   purchase: writes invoice_image only
 *   KarigarInvoiceService:49,114      karigar:  writes invoice_file_path only
 *
 * So applying the constraint before the new code is live turns every file
 * upload on the running site into a database error. Splitting each migration
 * into an EXPAND phase (column + backfill, safe now) and a CONTRACT phase
 * (constraint, only after the new code is live) is what makes the release
 * orderable at all.
 *
 * These tests are written against the real migration artifacts rather than a
 * hand-built schema, so they cannot pass while the shipped migrations say
 * something different.
 *
 * WHAT THIS DOES NOT PROVE. The CHECK is a nullness invariant only. It does not
 * establish tenant ownership, nor that a recorded disk matches where the bytes
 * physically are, and it does not reject a path-only UPDATE on a row that
 * already carries a disk value. T-04 pins that last point, because it is the
 * case most likely to be mistaken for coverage this constraint does not give.
 */
class DiskColumnReleaseOrderTest extends TestCase
{
    use CreatesTestTenant;
    use RefreshDatabase;

    /**
     * Every constraint the CONTRACT phase adds, keyed by the table it guards.
     *
     * @var array<string, string>
     */
    private const CONTRACT_CONSTRAINTS = [
        'shop_billing_settings' => 'shop_billing_settings_digital_signature_disk_check',
        'karigar_invoices'      => 'karigar_invoices_attachment_disk_check',
        'stock_purchases'       => 'stock_purchases_invoice_image_disk_check',
    ];

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    /**
     * Put the database into the state that exists between the two phases:
     * columns present and backfilled, constraints not yet added. This is the
     * window the old application actually runs in.
     */
    private function dropContractPhase(): void
    {
        foreach (self::CONTRACT_CONSTRAINTS as $table => $constraint) {
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$constraint}");
        }
    }

    private function constraintExists(string $constraint): bool
    {
        return DB::table('pg_constraint')->where('conname', $constraint)->exists();
    }

    // -------------------------------------------------------------------- T-01
    /**
     * The hazard itself. With the constraint in place, a write shaped exactly
     * like the deployed code's signature upload is rejected.
     *
     * If this ever stops failing, the constraint has been weakened and the
     * both-or-neither invariant is no longer enforced.
     */
    public function test_t01_a_baseline_shaped_signature_write_is_rejected_once_the_constraint_exists(): void
    {
        [, $shop] = $this->createRetailerTenant();

        $this->assertTrue(
            $this->constraintExists(self::CONTRACT_CONSTRAINTS['shop_billing_settings']),
            'precondition: the contract phase is applied in the test schema'
        );

        $this->ensureBillingRow((int) $shop->id);

        $this->expectException(QueryException::class);

        // SettingsController:526-527 at baseline: path set, disk never mentioned.
        DB::table('shop_billing_settings')
            ->where('shop_id', $shop->id)
            ->update(['digital_signature_path' => 'signatures/01JBASELINE.png']);
    }

    // -------------------------------------------------------------------- T-02
    /**
     * The same three writes the deployed code performs, against the EXPAND-only
     * schema. All three must succeed, or the first phase cannot be released
     * ahead of the application either.
     *
     * This is the positive control for T-01: without it, T-01 would be
     * consistent with the constraint rejecting everything.
     */
    public function test_t02_the_expand_phase_alone_accepts_every_baseline_shaped_write(): void
    {
        [, $shop] = $this->createRetailerTenant();

        $this->dropContractPhase();

        // 1. Signature upload — SettingsController:526-527.
        $this->ensureBillingRow((int) $shop->id);
        DB::table('shop_billing_settings')
            ->where('shop_id', $shop->id)
            ->update(['digital_signature_path' => 'signatures/01JBASELINE.png']);

        $this->assertSame(
            'signatures/01JBASELINE.png',
            DB::table('shop_billing_settings')->where('shop_id', $shop->id)->value('digital_signature_path'),
            'a baseline signature upload must survive the expand phase'
        );
        $this->assertNull(
            DB::table('shop_billing_settings')->where('shop_id', $shop->id)->value('digital_signature_disk'),
            'old code leaves the new column NULL — that is the state the expand phase must tolerate'
        );

        // 2. Signature removal — SettingsController:528-530 nulls the path and
        //    never touches the disk, leaving disk populated with path NULL.
        //    Under the contract phase that is a violation in the other
        //    direction; under expand-only it must pass.
        DB::table('shop_billing_settings')
            ->where('shop_id', $shop->id)
            ->update(['digital_signature_disk' => 'public']);
        DB::table('shop_billing_settings')
            ->where('shop_id', $shop->id)
            ->update(['digital_signature_path' => null]);

        $this->assertSame(
            'public',
            DB::table('shop_billing_settings')->where('shop_id', $shop->id)->value('digital_signature_disk'),
            'baseline removal strands the disk value; the expand phase must accept it'
        );
    }

    // -------------------------------------------------------------------- T-03
    /**
     * Baseline removal against the CONTRACT phase — the second, opposite
     * violation. T-01 covers path-without-disk; this covers disk-without-path,
     * which is what the deployed remove-signature branch produces.
     *
     * Both directions matter: a release that only considered uploads would
     * still break every removal.
     */
    public function test_t03_a_baseline_shaped_signature_removal_is_rejected_once_the_constraint_exists(): void
    {
        [, $shop] = $this->createRetailerTenant();

        $this->ensureBillingRow((int) $shop->id, 'signatures/01JBASELINE.png', 'public');

        $this->expectException(QueryException::class);

        // SettingsController:528-530 at baseline: path nulled, disk left behind.
        DB::table('shop_billing_settings')
            ->where('shop_id', $shop->id)
            ->update(['digital_signature_path' => null]);
    }

    // -------------------------------------------------------------------- T-04
    /**
     * The limit of what the constraint proves, made explicit.
     *
     * A path-only UPDATE is NOT rejected when a disk value is already present:
     * the row stays both-non-null, so the CHECK passes, while the recorded disk
     * now describes the OLD file and the path names a new one. The constraint
     * is a nullness invariant; it says nothing about whether the disk is
     * correct, and nothing about tenancy.
     *
     * Asserted rather than described, because this is exactly the gap a reader
     * would otherwise assume the constraint closes.
     */
    public function test_t04_the_constraint_does_not_reject_a_path_only_update_over_an_existing_disk(): void
    {
        [, $shop] = $this->createRetailerTenant();

        $this->ensureBillingRow((int) $shop->id, 'signatures/01JOLD.png', 'local');

        // Baseline-shaped replacement: new path, disk untouched. Accepted.
        DB::table('shop_billing_settings')
            ->where('shop_id', $shop->id)
            ->update(['digital_signature_path' => 'signatures/01JNEW.png']);

        $row = DB::table('shop_billing_settings')->where('shop_id', $shop->id)->first();

        $this->assertSame('signatures/01JNEW.png', $row->digital_signature_path);
        $this->assertSame(
            'local',
            $row->digital_signature_disk,
            'the constraint permits a stale disk beside a fresh path — it checks nullness, not correctness'
        );
    }

    // -------------------------------------------------------------------- T-05
    /**
     * The other two tables, same hazard. The signature column is the one this
     * work touches most, but the karigar and purchase migrations have the same
     * shape and the same deployed writers, so a release plan that only ordered
     * the signature migration would still break file uploads elsewhere.
     *
     * Table-driven so adding a fourth disk column cannot quietly go untested.
     *
     * @dataProvider baselineShapedFileWrites
     */
    public function test_t05_every_disk_column_rejects_a_baseline_shaped_write(
        string $table,
        string $pathColumn,
        string $value,
    ): void {
        [, $shop] = $this->createRetailerTenant();

        $id = DB::table($table)->insertGetId(
            $this->minimalRowFor($table, (int) $shop->id)
        );

        $this->expectException(QueryException::class);

        DB::table($table)->where('id', $id)->update([$pathColumn => $value]);
    }

    // -------------------------------------------------------------------- T-06
    /**
     * Every contract constraint is present AND validated.
     *
     * WHY THIS IS NOT REDUNDANT WITH T-01/T-03/T-05. Those three prove the
     * constraint rejects a new write. A constraint added `NOT VALID` does that
     * too — PostgreSQL enforces NOT VALID CHECKs on INSERT and UPDATE and skips
     * only the scan of rows that already exist. So all three of those tests pass
     * against a contract migration that adds the constraint and never validates
     * it, leaving any row written during the expand-only window permanently
     * unchecked. That is the whole hazard the split creates, and this is the only
     * test that can see it.
     *
     * The contract migration uses NOT VALID + VALIDATE deliberately: a plain ADD
     * CONSTRAINT holds ACCESS EXCLUSIVE for a full table scan, while VALIDATE
     * takes only SHARE UPDATE EXCLUSIVE. The two-step form is the reason the
     * contract phase is releasable without a write outage; convalidated is the
     * evidence the second step actually ran.
     */
    public function test_t06_every_contract_constraint_is_present_and_validated(): void
    {
        foreach (self::CONTRACT_CONSTRAINTS as $table => $constraint) {
            $row = DB::table('pg_constraint')->where('conname', $constraint)->first();

            $this->assertNotNull($row, "{$constraint} is missing for {$table}");
            $this->assertSame('c', $row->contype, "{$constraint} must be a CHECK constraint");
            $this->assertTrue(
                (bool) $row->convalidated,
                "{$constraint} exists but was never validated — rows written during the "
                .'expand-only window are exempt from it forever'
            );
        }
    }

    // -------------------------------------------------------------------- T-07
    /**
     * The contract phase survives the rows the expand window is designed to
     * tolerate.
     *
     * This is the test that makes the split honest. Splitting the migration
     * creates a window in which the old code keeps writing rows the constraint
     * will later reject — that is the POINT of the window, not an accident. But
     * it means the backfill that ran inside the expand migration is already
     * stale by the time the contract phase runs, and VALIDATE CONSTRAINT scans
     * every existing row. Without a second reconciliation the contract migration
     * aborts the deploy on precisely the rows the plan told the old code it was
     * free to write.
     *
     * Both directions are seeded, because both occur:
     *   path without disk   every baseline upload
     *   disk without path   every baseline signature removal, which nulls the
     *                       path and leaves the disk (SettingsController:528-530)
     *
     * All three tables are seeded rather than just the signature one, because
     * the reconciliation is driven by a table => columns map and a single wrong
     * column name there would be invisible in a one-table test.
     */
    public function test_t07_the_contract_phase_reconciles_rows_written_during_the_expand_window(): void
    {
        [, $uploadShop] = $this->createRetailerTenant();
        [, $removalShop] = $this->createRetailerTenant();

        $this->dropContractPhase();

        // Direction 1 — baseline upload: path written, disk never mentioned.
        $this->ensureBillingRow((int) $uploadShop->id, 'signatures/01JWINDOW.png', null);

        // Direction 2 — baseline removal: path nulled, disk stranded.
        $this->ensureBillingRow((int) $removalShop->id, null, 'public');

        // Same hazard on the other two tables.
        $karigarId = DB::table('karigar_invoices')->insertGetId(
            $this->minimalRowFor('karigar_invoices', (int) $uploadShop->id)
        );
        DB::table('karigar_invoices')->where('id', $karigarId)
            ->update(['invoice_file_path' => 'karigar-invoices/1/window.pdf']);

        $purchaseId = DB::table('stock_purchases')->insertGetId(
            $this->minimalRowFor('stock_purchases', (int) $uploadShop->id)
        );
        DB::table('stock_purchases')->where('id', $purchaseId)
            ->update(['invoice_image' => 'purchases/01JWINDOW.webp']);

        // Re-run the real contract migration against that state.
        $migration = require database_path(
            'migrations/2026_09_20_130000_add_disk_column_check_constraints.php'
        );
        $migration->up();

        $this->assertTrue(
            $this->constraintExists(self::CONTRACT_CONSTRAINTS['shop_billing_settings']),
            'the contract phase must complete over expand-window rows, not abort on them'
        );

        // An upload written during the window is relabelled to where its bytes
        // actually are. This records the truth; it does not move a file.
        $this->assertSame(
            'public',
            DB::table('shop_billing_settings')->where('shop_id', $uploadShop->id)->value('digital_signature_disk'),
            'a path written during the window must be reconciled to public, not deleted'
        );
        $this->assertSame(
            'signatures/01JWINDOW.png',
            DB::table('shop_billing_settings')->where('shop_id', $uploadShop->id)->value('digital_signature_path'),
            'reconciliation must never discard the path itself'
        );

        // A removal written during the window leaves a disk describing no file.
        $this->assertNull(
            DB::table('shop_billing_settings')->where('shop_id', $removalShop->id)->value('digital_signature_disk'),
            'a stranded disk with no path must be cleared, not left to fail VALIDATE'
        );

        $this->assertSame(
            'public',
            DB::table('karigar_invoices')->where('id', $karigarId)->value('invoice_file_disk'),
            'the reconciliation must cover karigar_invoices, not only the signature table'
        );
        $this->assertSame(
            'public',
            DB::table('stock_purchases')->where('id', $purchaseId)->value('invoice_image_disk'),
            'the reconciliation must cover stock_purchases, not only the signature table'
        );
    }

    /** @return array<string, array{0:string,1:string,2:string}> */
    public static function baselineShapedFileWrites(): array
    {
        return [
            'karigar attachment' => ['karigar_invoices', 'invoice_file_path', 'karigar-invoices/1/f.pdf'],
            'purchase invoice'   => ['stock_purchases', 'invoice_image', 'purchases/01JIMG.webp'],
        ];
    }

    /**
     * The smallest row each table will accept, so the test exercises the disk
     * constraint and not some unrelated NOT NULL column.
     *
     * @return array<string, mixed>
     */
    private function minimalRowFor(string $table, int $shopId): array
    {
        return match ($table) {
            'karigar_invoices' => [
                'shop_id'                => $shopId,
                'karigar_id'             => $this->makeKarigar($shopId),
                'karigar_invoice_number' => 'KI-SYN-1',
                'karigar_invoice_date'   => now()->toDateString(),
                'created_at'             => now(),
                'updated_at'             => now(),
            ],
            'stock_purchases' => [
                'shop_id'         => $shopId,
                'purchase_number' => 'PO-SYN-1',
                'purchase_date'   => now()->toDateString(),
                'created_at'      => now(),
                'updated_at'      => now(),
            ],
            default => throw new \InvalidArgumentException($table),
        };
    }

    /**
     * createRetailerTenant() already creates the shop's billing row, so these
     * tests upsert rather than insert. Writing the signature columns through an
     * UPDATE is also the shape baseline actually uses.
     */
    private function ensureBillingRow(int $shopId, ?string $path = null, ?string $disk = null): void
    {
        if (! DB::table('shop_billing_settings')->where('shop_id', $shopId)->exists()) {
            DB::table('shop_billing_settings')->insert(['shop_id' => $shopId]);
        }

        DB::table('shop_billing_settings')->where('shop_id', $shopId)->update([
            'digital_signature_path' => $path,
            'digital_signature_disk' => $disk,
        ]);
    }

    private function makeKarigar(int $shopId): int
    {
        return DB::table('karigars')->insertGetId([
            'shop_id'    => $shopId,
            'name'       => 'Synthetic Karigar',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
