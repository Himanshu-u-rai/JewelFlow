<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CONTRACT PHASE for audit findings S3-02, S3-03 and S3-04.
 *
 * DO NOT APPLY THIS UNTIL THE NEW APPLICATION CODE IS LIVE ON EVERY SERVING
 * NODE. That is not a style preference; it is the whole reason this file exists
 * separately from the three migrations that add the columns.
 *
 * WHAT WENT WRONG THE FIRST TIME
 * ------------------------------
 * Those three migrations each did three things at once: add a nullable disk
 * column, backfill it, and add a both-or-neither CHECK. I called the set
 * "additive" and treated that as meaning it was safe to apply while the deployed
 * application kept serving. The column and the backfill are additive. The
 * constraint is not. It forbids a row with a path and no disk, and writing
 * exactly that row is what baseline 018b3d8 does on every upload:
 *
 *   SettingsController:526-527        signature: writes _path, never _disk
 *   StockPurchaseController:140,354   purchase: writes invoice_image only
 *   KarigarInvoiceService:49,114      karigar:  writes invoice_file_path only
 *
 * Applying the constraint ahead of the code therefore turns every file upload on
 * the running site into a database error. DiskColumnReleaseOrderTest T-01, T-03
 * and T-05 demonstrate the rejection; T-02 demonstrates that the expand phase
 * alone accepts all of those same writes.
 *
 * RECONCILIATION BEFORE ENFORCEMENT
 * ---------------------------------
 * Between the two phases the old code keeps running, so it keeps producing rows
 * the constraint will reject — in BOTH directions:
 *
 *   path without disk   every upload             (T-01, T-05)
 *   disk without path   every removal, because the deployed remove-signature
 *                       branch nulls the path and leaves the disk behind
 *                       (SettingsController:528-530, pinned by T-03)
 *
 * The backfill in the expand migrations ran once, before those rows existed. So
 * this migration re-reconciles immediately before enforcing, or VALIDATE fails
 * on exactly the rows the window was designed to tolerate. Both sweeps are
 * truthful relabels of the status quo, not remediation: a path still on the
 * public tree is recorded as 'public', and a disk with no file left to describe
 * is cleared. Neither moves a byte.
 *
 * LOCKING — explicit boundaries (XR-04)
 * ------------------------------------
 * CORRECTION. An earlier revision described NOT VALID-then-VALIDATE as brief
 * locking, but it ran inside the one transaction Laravel's migrator wraps
 * around up() on PostgreSQL ($withinTransaction defaults to true). Each
 * ADD CONSTRAINT's ACCESS EXCLUSIVE was therefore held to the end of the whole
 * migration — through every VALIDATE and every later table. Measured by
 * tests/Rehearsal/contract_migration_locks.php: held at the third table, the
 * migrator still held ACCESS EXCLUSIVE on the other two, and reads and writes
 * there were blocked.
 *
 * Now $withinTransaction is false, and each table gets:
 *
 *   1. ONE short transaction: LOCK ... IN SHARE ROW EXCLUSIVE MODE, which
 *      blocks writes and not reads; reconcile; DROP IF EXISTS; ADD CONSTRAINT
 *      ... NOT VALID, which takes ACCESS EXCLUSIVE only for the catalogue
 *      change. From the commit on, every new or changed row is checked.
 *      Because writes are blocked between the reconcile and the ADD, no
 *      unreconciled row can slip in between them, so step 2 cannot fail on
 *      one.
 *   2. VALIDATE CONSTRAINT as its own statement, holding only SHARE UPDATE
 *      EXCLUSIVE: reads and writes continue while it scans.
 *
 * PARTIAL FAILURE. A table whose step 1 failed is unchanged, and one that
 * finished stays constrained and validated. The migration is not recorded,
 * so re-running `migrate` repeats all three steps. That is idempotent: the
 * reconcile relabels nothing twice, and DROP IF EXISTS / ADD / VALIDATE
 * rebuild a finished table's constraint. If step 2 ever failed, the
 * constraint would stay NOT VALID — still enforced on new writes — until the
 * re-run. Measured: killed mid-run, then re-run to completion.
 *
 * T-06 asserts convalidated, so the second step cannot be quietly dropped.
 *
 * ONE INCONSISTENCY, RECORDED RATHER THAN SILENTLY FIXED
 * -----------------------------------------------------
 * The signature and purchase constraints also restrict the disk to
 * ('public','local'); the karigar one never did. The SQL below is carried over
 * verbatim from each original migration, so this contract phase changes release
 * ORDER and nothing else. Tightening karigar to match is a behaviour change that
 * belongs in its own reviewed commit, not smuggled into a release-ordering fix.
 * Logged as S3-02b.
 *
 * ROLLBACK. down() drops all three. That returns the schema to the expand-only
 * window, which the deployed baseline tolerates — so this phase is reversible on
 * its own. What is NOT reversible by rolling back schema alone: files already
 * written to the private disk by the new code, and files already relocated. Both
 * are recorded per row, so a rollback leaves them readable by the new code only.
 * See docs/runbooks/signature-migration-release-order.md.
 */
return new class extends Migration
{
    /** XR-04: explicit per-table boundaries instead of one migration-long transaction. */
    public $withinTransaction = false;

    /**
     * table => [path column, disk column, constraint name, CHECK body]
     *
     * @var array<string, array{0:string,1:string,2:string,3:string}>
     */
    private const CONTRACTS = [
        'shop_billing_settings' => [
            'digital_signature_path',
            'digital_signature_disk',
            'shop_billing_settings_digital_signature_disk_check',
            <<<'SQL'
                (digital_signature_path IS NULL AND digital_signature_disk IS NULL)
                OR (
                    digital_signature_path IS NOT NULL
                    AND digital_signature_disk IS NOT NULL
                    AND digital_signature_disk IN ('public', 'local')
                )
                SQL,
        ],
        'karigar_invoices' => [
            'invoice_file_path',
            'invoice_file_disk',
            'karigar_invoices_attachment_disk_check',
            <<<'SQL'
                (invoice_file_path IS NULL AND invoice_file_disk IS NULL)
                OR (invoice_file_path IS NOT NULL AND invoice_file_disk IS NOT NULL)
                SQL,
        ],
        'stock_purchases' => [
            'invoice_image',
            'invoice_image_disk',
            'stock_purchases_invoice_image_disk_check',
            <<<'SQL'
                (invoice_image IS NULL AND invoice_image_disk IS NULL)
                OR (
                    invoice_image IS NOT NULL
                    AND invoice_image_disk IS NOT NULL
                    AND invoice_image_disk IN ('public', 'local')
                )
                SQL,
        ],
    ];

    public function up(): void
    {
        foreach (self::CONTRACTS as $table => [$pathColumn, $diskColumn, $constraint, $check]) {
            if (! $this->expandPhaseApplied($table, $diskColumn)) {
                continue;
            }

            // Step 1 — one short transaction (see LOCKING).
            [$labelled, $cleared] = DB::transaction(function () use ($table, $pathColumn, $diskColumn, $constraint, $check) {
                DB::statement("LOCK TABLE {$table} IN SHARE ROW EXCLUSIVE MODE");

                $result = $this->reconcile($table, $pathColumn, $diskColumn);

                DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$constraint}");
                DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$constraint} CHECK ({$check}) NOT VALID");

                return $result;
            });

            if ($labelled || $cleared) {
                echo "[contract/130000] {$table}: recorded 'public' for {$labelled} expand-window "
                    ."attachment(s), cleared {$cleared} stranded disk value(s).\n";
            }

            // Step 2 — its own statement, after step 1's commit released ACCESS EXCLUSIVE.
            DB::statement("ALTER TABLE {$table} VALIDATE CONSTRAINT {$constraint}");
        }
    }

    public function down(): void
    {
        foreach (self::CONTRACTS as $table => [, , $constraint,]) {
            if (Schema::hasTable($table)) {
                DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$constraint}");
            }
        }
    }

    /**
     * Bring both both-or-neither violations back into line, immediately before
     * the constraint is enforced.
     *
     * Neither sweep touches a file. A path with no disk is labelled 'public'
     * because that is where baseline 018b3d8 physically put it — same reasoning,
     * and same truthful-relabel caveat, as the expand-phase backfill. A disk with
     * no path describes a file the old code already deleted, so it is cleared;
     * there is nothing left for it to point at.
     *
     * Deliberately NOT a transaction spanning all three tables. Each table is
     * independent, the sweeps are idempotent, and a partial run simply leaves the
     * remaining tables for a re-run. Wrapping the VALIDATE scans of three tables
     * in one transaction would hold their locks for the combined duration.
     *
     * @return array{0:int,1:int} rows labelled, rows cleared
     */
    private function reconcile(string $table, string $pathColumn, string $diskColumn): array
    {
        $labelled = DB::table($table)
            ->whereNotNull($pathColumn)
            ->whereNull($diskColumn)
            ->update([$diskColumn => 'public']);

        $cleared = DB::table($table)
            ->whereNull($pathColumn)
            ->whereNotNull($diskColumn)
            ->update([$diskColumn => null]);

        return [$labelled, $cleared];
    }

    /**
     * Skip rather than fail when the expand phase is absent.
     *
     * A fresh install that has not yet run the column migrations, or an
     * installation where a table genuinely does not exist, should not have the
     * deploy abort here. The expand migrations are the ones that own creating
     * the column; this file only ever hardens what is already there.
     */
    private function expandPhaseApplied(string $table, string $diskColumn): bool
    {
        return Schema::hasTable($table) && Schema::hasColumn($table, $diskColumn);
    }
};
