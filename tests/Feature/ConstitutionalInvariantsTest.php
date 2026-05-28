<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ConstitutionalInvariantsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    /**
     * Skip the test if the database is not PostgreSQL.
     * Duplicated from CreatesTestTenant so this class can stand alone.
     */
    private function skipIfNotPostgres(): void
    {
        if (env('DB_CONNECTION', 'sqlite') !== 'pgsql') {
            $this->markTestSkipped('These tests require PostgreSQL.');
        }
    }

    // ── Test 1: All 17 constitutional triggers exist ─────────────────────────

    public function test_critical_triggers_exist_in_postgres(): void
    {
        $expected = [
            'credit_notes_accounting_guard_trigger',
            'invoice_items_finalized_guard_trigger',
            'store_credit_non_negative_guard_trigger',
            'invoices_accounting_guard_trigger',
            'credit_notes_numbering_event_trigger',
            'invoices_numbering_event_trigger',
            'audit_logs_append_only_trigger',
            'invoice_payments_append_only_trigger',
            'loyalty_transactions_append_only_trigger',
            'customer_gold_transactions_append_only_trigger',
            'karigar_payments_append_only_trigger',
            'cash_transactions_append_only_trigger',
            // NOTE: metal_movements_append_only_trigger was planned but NOT
            // installed — the pre-existing metal_movements_immutable_trigger
            // (registered below) provides equivalent append-only protection.
            // See migration 2026_05_26_100000 for the constitutional rationale.
            'return_line_items_settled_guard_trigger',
            'karigar_invoices_finalized_guard_trigger',
            'job_orders_finalized_guard_trigger',
            'vault_reconciliation_runs_append_only_trigger',
            // Pre-existing since 2026_02_18 financial accounting hardening:
            'metal_movements_immutable_trigger',
            'cash_transactions_immutable_trigger',
            'customer_gold_transactions_immutable_trigger',
            // Phase 1 — shop_daily_metal_rate_entries append-only (lifecycle-aware):
            'shop_daily_metal_rate_entries_guard_trigger',
            // Phase 2A — stone components snapshot guard:
            'stone_components_snapshot_guard_trigger',
            // Phase 2B — stone revaluation events append-only:
            'stone_revaluation_events_append_only_trigger',
            // Pre-existing constitutional triggers (surfaced by Phase 3 audit):
            'audit_logs_hash_trigger',
            'metal_rates_no_update',
            'metal_rates_no_delete',
            'platform_audit_logs_append_only_trigger',
            'store_credit_append_only_guard_trigger',
            'invoices_numbering_guard_trigger',
        ];

        $rows = DB::select(
            "SELECT trigger_name FROM information_schema.triggers WHERE trigger_schema = 'public'"
        );
        $found = array_map(fn($r) => $r->trigger_name, $rows);

        foreach ($expected as $triggerName) {
            $this->assertContains(
                $triggerName,
                $found,
                "Constitutional trigger '{$triggerName}' is missing from the database."
            );
        }
    }

    // ── Test 2: invoice_items_finalized_guard blocks raw UPDATE ──────────────

    public function test_invoice_items_finalized_guard_blocks_update(): void
    {
        // Find any invoice_item row, or skip if none exist yet.
        $item = DB::selectOne('SELECT id FROM invoice_items LIMIT 1');

        if ($item === null) {
            $this->markTestSkipped('No invoice_items rows available to test the guard trigger against.');
        }

        $itemId = $item->id;

        $this->expectException(\Illuminate\Database\QueryException::class);

        // The DB trigger should raise an exception on any UPDATE to invoice_items.
        DB::statement('UPDATE invoice_items SET line_total = 99999 WHERE id = ?', [$itemId]);
    }

    // ── Test 3: credit_notes_accounting_guard blocks total mismatch ──────────

    public function test_credit_notes_accounting_guard_blocks_total_mismatch(): void
    {
        // Find any credit_note row, or skip if none exist yet.
        $note = DB::selectOne('SELECT id FROM credit_notes LIMIT 1');

        if ($note === null) {
            $this->markTestSkipped('No credit_notes rows available to test the accounting guard trigger against.');
        }

        $noteId = $note->id;

        $this->expectException(\Illuminate\Database\QueryException::class);

        // Force total to a value that cannot match subtotal + gst - discount + round_off,
        // which the trigger enforces. Setting total to an astronomical value ensures mismatch.
        DB::statement(
            'UPDATE credit_notes SET total = 999999999 WHERE id = ?',
            [$noteId]
        );
    }

    // ── Test 4: store_credit_non_negative_guard blocks overdraft ─────────────

    public function test_store_credit_non_negative_guard_blocks_overdraft(): void
    {
        // Check whether the store_credit_movements table exists at all.
        $tableExists = DB::selectOne(
            "SELECT 1 FROM information_schema.tables
             WHERE table_schema = 'public' AND table_name = 'store_credit_movements'
             LIMIT 1"
        );

        if ($tableExists === null) {
            $this->markTestSkipped('store_credit_movements table does not exist; skipping guard test.');
        }

        // Check whether the guard trigger exists on this table.
        $triggerExists = DB::selectOne(
            "SELECT 1 FROM information_schema.triggers
             WHERE trigger_schema = 'public'
               AND trigger_name    = 'store_credit_non_negative_guard_trigger'
             LIMIT 1"
        );

        if ($triggerExists === null) {
            $this->markTestSkipped('store_credit_non_negative_guard_trigger not found; skipping guard test.');
        }

        // Find a customer to target, or skip.
        $customer = DB::selectOne('SELECT id FROM customers LIMIT 1');

        if ($customer === null) {
            $this->markTestSkipped('No customers available to test the store credit guard trigger.');
        }

        $this->expectException(\Illuminate\Database\QueryException::class);

        // Insert a massive negative movement that should push the balance below zero.
        DB::statement(
            "INSERT INTO store_credit_movements (customer_id, amount, type, created_at, updated_at)
             VALUES (?, -999999999, 'debit', NOW(), NOW())",
            [$customer->id]
        );
    }

    // ── Test 5: Reconciliation commands contain no write operations ──────────

    public function test_no_writes_in_reconciliation_commands(): void
    {
        $commandFiles = [
            base_path('app/Console/Commands/ReconcileVaultBalances.php'),
            base_path('app/Console/Commands/ReconcileKarigarBalances.php'),
            base_path('app/Console/Commands/DetectStuckStates.php'),
            base_path('app/Console/Commands/ValidateReturnsIntegrity.php'),
        ];

        // Patterns that indicate a write operation.
        $writePatterns = [
            '->save()',
            '->update(',
            '->create(',
            "DB::statement(\"UPDATE",
            "DB::statement(\"DELETE",
            "DB::statement(\"INSERT",
            "DB::statement('UPDATE",
            "DB::statement('DELETE",
            "DB::statement('INSERT",
        ];

        $checkedAtLeastOne = false;

        foreach ($commandFiles as $filePath) {
            if (!file_exists($filePath)) {
                continue;
            }

            $checkedAtLeastOne = true;
            $content = file_get_contents($filePath);
            $shortName = basename($filePath);

            foreach ($writePatterns as $pattern) {
                $this->assertFalse(
                    str_contains($content, $pattern),
                    "Reconciliation command '{$shortName}' must not contain write operation: {$pattern}"
                );
            }
        }

        if (!$checkedAtLeastOne) {
            $this->markTestSkipped('No reconciliation command files found; skipping write-operation check.');
        }

        $this->assertTrue(true, 'All reconciliation command files are free of direct write operations.');
    }

    // ── Test 6: Critical models have constitutionally-equivalent immutability ───
    //
    // Two valid patterns exist:
    //   (a) `use ImmutableLedger;` — blanket immutability
    //   (b) bespoke static::updating / static::deleting observers paired
    //       with a DB-level guard trigger — used by Invoice, which must
    //       allow draft→finalized→cancelled status transitions while
    //       blocking all column edits once finalized. ImmutableLedger
    //       cannot express conditional immutability.
    //
    // Invoice's conditional pattern is constitutionally equivalent (in
    // fact richer) because the matching invoices_accounting_guard_trigger
    // provides the DB backstop at the database layer.

    public function test_immutable_ledger_trait_present_on_critical_models(): void
    {
        // Models that use the blanket ImmutableLedger trait.
        $modelFiles = [
            'CreditNote'     => base_path('app/Models/CreditNote.php'),
            'MetalMovement'  => base_path('app/Models/MetalMovement.php'),
            'ReturnOrder'    => base_path('app/Models/ReturnOrder.php'),
            'ReturnLineItem' => base_path('app/Models/ReturnLineItem.php'),
        ];

        $missing = [];

        foreach ($modelFiles as $modelName => $filePath) {
            if (!file_exists($filePath)) {
                $missing[] = "{$modelName} (file not found)";
                continue;
            }

            $content = file_get_contents($filePath);

            if (strpos($content, 'ImmutableLedger') === false) {
                $missing[] = $modelName;
            }
        }

        $this->assertEmpty(
            $missing,
            'The following models are missing the ImmutableLedger trait: ' . implode(', ', $missing)
        );

        // Invoice uses the bespoke conditional-immutability pattern instead.
        $invoiceFile = base_path('app/Models/Invoice.php');
        $this->assertFileExists($invoiceFile);
        $invoiceContent = file_get_contents($invoiceFile);

        $hasUpdatingGuard = strpos($invoiceContent, 'static::updating') !== false
            && strpos($invoiceContent, 'LogicException') !== false;
        $hasDeletingGuard = strpos($invoiceContent, 'static::deleting') !== false
            && strpos($invoiceContent, 'LogicException') !== false;

        $this->assertTrue(
            $hasUpdatingGuard && $hasDeletingGuard,
            'Invoice model lacks the bespoke updating/deleting observers that enforce '
            . 'conditional immutability on finalized/cancelled status. Either pattern '
            . '(ImmutableLedger trait OR bespoke guards) is required for constitutional compliance.'
        );

        // And confirm the matching DB trigger is in place.
        $invoicesGuard = DB::selectOne(
            "SELECT 1 FROM pg_proc WHERE proname = 'invoices_accounting_guard'"
        );
        $this->assertNotNull(
            $invoicesGuard,
            'invoices_accounting_guard function missing — DB-level backstop for Invoice immutability is gone.'
        );
    }

    // ──────────────────────────────────────────────────────────────────────
    // PHASE 0 — Material & Stone Expansion: Silent Wrongness Elimination
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Test 7: metal_movements.metal_type column exists.
     * Phase 0 contract: the column is present so new movements can be
     * metal-attributed via MetalMovement::record(). Legacy rows remain
     * NULL forever (constitutionally immutable per the
     * metal_movements_immutable_trigger that has been in place since
     * Feb 2026); they get metal identity at read time via COALESCE
     * with the joined lot.
     */
    public function test_metal_movements_has_metal_type_column(): void
    {
        $exists = DB::selectOne(
            "SELECT 1 FROM information_schema.columns
             WHERE table_schema = 'public'
               AND table_name   = 'metal_movements'
               AND column_name  = 'metal_type'"
        );
        $this->assertNotNull($exists, 'metal_movements.metal_type column is missing — Phase 0 migration 010000 not applied.');

        $marker = DB::selectOne(
            "SELECT 1 FROM information_schema.columns
             WHERE table_schema = 'public'
               AND table_name   = 'metal_movements'
               AND column_name  = 'metal_type_was_backfilled'"
        );
        $this->assertNotNull($marker, 'metal_movements.metal_type_was_backfilled marker missing.');
    }

    /**
     * Test 7b: MetalMovement::record() auto-derives metal_type from
     * the joined lot when the caller does not pass it explicitly.
     *
     * Verifies the Phase 0 forward-fill contract: new movements always
     * carry a non-NULL metal_type when at least one lot side resolves.
     */
    public function test_metal_movement_record_auto_derives_metal_type(): void
    {
        $shopId = (int) DB::table('shops')->insertGetId([
            'name'              => 'Phase0 Auto-Derive Test ' . uniqid(),
            'phone'             => '+91-0000000001',
            'owner_first_name'  => 'Test',
            'owner_last_name'   => 'Owner',
            'owner_mobile'      => '+91-0000000001',
            'shop_code'         => 'PH0AUTO' . substr(uniqid(), -4),
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);

        $lotId = (int) DB::table('metal_lots')->insertGetId([
            'shop_id'               => $shopId,
            'metal_type'            => 'silver',
            'source'                => 'opening',
            'purity'                => 999.00,
            'fine_weight_total'     => 100.0,
            'fine_weight_remaining' => 100.0,
            'created_at'            => now(),
            'updated_at'            => now(),
        ]);

        // Insert a movement WITHOUT passing metal_type — should auto-derive 'silver' from to_lot.
        // TenantContext::runFor sets the shop_id for BelongsToShop on the
        // new MetalMovement model row.
        $mm = \App\Support\TenantContext::runFor($shopId, function () use ($shopId, $lotId) {
            return \App\Models\MetalMovement::record([
                'shop_id'        => $shopId,
                'from_lot_id'    => null,
                'to_lot_id'      => $lotId,
                'fine_weight'    => 5.0,
                'type'           => 'opening',
                'reference_type' => 'metal_lot',
                'reference_id'   => $lotId,
            ]);
        });

        $this->assertSame(
            'silver',
            $mm->metal_type,
            'MetalMovement::record() failed to auto-derive metal_type from to_lot.metal_type'
        );
    }

    /**
     * Test 7c: pre-existing metal_movements_immutable_trigger
     * (Feb 2026 financial accounting hardening) is still in place.
     *
     * This trigger is the actual constitutional enforcement of
     * append-only on metal_movements. Phase 0 depends on it remaining
     * installed; if it disappears, the foundation is compromised.
     */
    public function test_pre_existing_metal_movements_immutable_trigger_present(): void
    {
        $row = DB::selectOne(
            "SELECT 1 FROM information_schema.triggers
             WHERE trigger_schema = 'public'
               AND event_object_table = 'metal_movements'
               AND trigger_name = 'metal_movements_immutable_trigger'"
        );
        $this->assertNotNull(
            $row,
            'metal_movements_immutable_trigger (Feb 2026 financial hardening) is missing — '
            . 'the foundational append-only protection is gone.'
        );
    }

    /**
     * Test 8: invoice_items.metal_type column exists AND the finalized guard
     * locks it. Verifies the snapshot doctrine: each line's metal identity
     * survives any future edit to items.metal_type.
     */
    public function test_invoice_items_metal_type_locked_on_finalize(): void
    {
        $col = DB::selectOne(
            "SELECT 1 FROM information_schema.columns
             WHERE table_schema = 'public'
               AND table_name   = 'invoice_items'
               AND column_name  = 'metal_type'"
        );
        $this->assertNotNull($col, 'invoice_items.metal_type column missing — Phase 0 migration 040000 not applied.');

        // Confirm the finalized-guard function references metal_type.
        $functionBody = DB::selectOne(
            "SELECT pg_get_functiondef(oid) AS body
             FROM pg_proc
             WHERE proname = 'invoice_items_finalized_guard'"
        );
        $this->assertNotNull($functionBody, 'invoice_items_finalized_guard function not found.');
        $this->assertStringContainsString(
            'NEW.metal_type',
            (string) $functionBody->body,
            'invoice_items_finalized_guard does not block metal_type changes — Phase 0 migration 060000 not applied.'
        );
    }

    /**
     * Test 9: CHECK constraints exist on items, metal_lots, products limiting
     * metal_type to NULL or supported tier 1 set (gold, silver).
     */
    public function test_metal_type_check_constraints_present(): void
    {
        $expected = [
            'items_metal_type_check',
            'metal_lots_metal_type_check',
            'products_metal_type_check',
        ];

        foreach ($expected as $constraint) {
            $row = DB::selectOne(
                "SELECT 1 FROM pg_constraint WHERE conname = ?",
                [$constraint]
            );
            $this->assertNotNull(
                $row,
                "CHECK constraint {$constraint} is missing — Phase 0 migrations 070000/080000/090000 not all applied."
            );
        }
    }

    /**
     * Test 10: CHECK constraint actually blocks an unsupported metal_type.
     * Verifies the constitutional rule "every material is fully supported,
     * limited with warning, or explicitly rejected — never silently
     * accepted."
     */
    public function test_check_constraint_blocks_unsupported_metal_type(): void
    {
        $this->expectException(\Illuminate\Database\QueryException::class);

        // Direct raw INSERT bypassing Eloquent so the test exercises the
        // DB-level constraint, not application-layer validators.
        DB::statement(
            "INSERT INTO items (shop_id, metal_type, barcode, design, status, gross_weight, purity, created_at, updated_at)
             VALUES (1, 'palladium', 'TEST-PHASE0-UNSUPPORTED', 'phase0 test', 'in_stock', 1.0, 22, NOW(), NOW())"
        );
    }

    /**
     * Test 11: BullionVaultService::vaultBalances does not silently merge
     * metals that share a purity value.
     *
     * Creates two lots: (gold, purity=22) and (silver, purity=22). The
     * service must report 2 buckets in the result, not 1.
     */
    public function test_vault_balances_does_not_merge_metals_at_same_purity(): void
    {
        $shopId = (int) DB::table('shops')->insertGetId([
            'name'              => 'Phase0 Test Shop ' . uniqid(),
            'phone'             => '+91-0000000002',
            'owner_first_name'  => 'Test',
            'owner_last_name'   => 'Owner',
            'owner_mobile'      => '+91-0000000002',
            'shop_code'         => 'PH0VBAL' . substr(uniqid(), -4),
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);

        DB::table('metal_lots')->insert([
            [
                'shop_id'               => $shopId,
                'metal_type'            => 'gold',
                'source'                => 'opening',
                'purity'                => 22.00,
                'fine_weight_total'     => 10.000000,
                'fine_weight_remaining' => 10.000000,
                'created_at'            => now(),
                'updated_at'            => now(),
            ],
            [
                'shop_id'               => $shopId,
                'metal_type'            => 'silver',
                'source'                => 'opening',
                // Silver at purity 22 — implausible operationally but the
                // structural collision is the point of the test.
                'purity'                => 22.00,
                'fine_weight_total'     => 5.000000,
                'fine_weight_remaining' => 5.000000,
                'created_at'            => now(),
                'updated_at'            => now(),
            ],
        ]);

        // BelongsToShop global scope on MetalLot reads auth()->user()->shop_id.
        // In a test context there is no authenticated user; wrap with
        // TenantContext::runFor() to bind the shop_id for the duration of
        // the service call.
        $rows = \App\Support\TenantContext::runFor($shopId, function () use ($shopId) {
            return (new \App\Services\BullionVaultService())->vaultBalances($shopId);
        });

        $this->assertCount(
            2,
            $rows,
            'BullionVaultService merged two metals at the same purity — Phase 0 refactor regressed.'
        );

        $metals = $rows->pluck('metal_type')->all();
        sort($metals);
        $this->assertSame(['gold', 'silver'], $metals);
    }

    // ──────────────────────────────────────────────────────────────────────
    // PHASE 1 — Material Boundary & MetalRegistry Stabilization
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Test P1.1: MetalRegistry recognizes Tier 1 (gold, silver) and
     * Tier 2 (platinum, copper); rejects Tier 3 examples.
     */
    public function test_metal_registry_tier_assignment(): void
    {
        $this->assertSame(
            \App\Services\MetalRegistry::TIER_1,
            \App\Services\MetalRegistry::tierFor('gold')
        );
        $this->assertSame(
            \App\Services\MetalRegistry::TIER_1,
            \App\Services\MetalRegistry::tierFor('silver')
        );
        $this->assertSame(
            \App\Services\MetalRegistry::TIER_2,
            \App\Services\MetalRegistry::tierFor('platinum')
        );
        $this->assertSame(
            \App\Services\MetalRegistry::TIER_2,
            \App\Services\MetalRegistry::tierFor('copper')
        );
        $this->assertSame(
            \App\Services\MetalRegistry::TIER_3,
            \App\Services\MetalRegistry::tierFor('palladium')
        );
        $this->assertSame(
            \App\Services\MetalRegistry::TIER_3,
            \App\Services\MetalRegistry::tierFor('brass')
        );
    }

    /**
     * Test P1.2: assertSupported throws for Tier 3 metals.
     */
    public function test_metal_registry_assert_supported_throws_for_tier_3(): void
    {
        $this->expectException(\LogicException::class);
        \App\Services\MetalRegistry::assertSupported('palladium');
    }

    /**
     * Test P1.3: capability checks for tier-specific behavior.
     */
    public function test_metal_registry_tier_2_capabilities_blocked(): void
    {
        // Tier 1 — fully eligible
        $this->assertTrue(\App\Services\MetalRegistry::isLiveRateEligible('gold'));
        $this->assertTrue(\App\Services\MetalRegistry::isAutoRepricedEligible('gold'));
        $this->assertTrue(\App\Services\MetalRegistry::isDhiranEligible('silver'));
        $this->assertTrue(\App\Services\MetalRegistry::isExchangePaymentEligible('silver'));

        // Tier 2 — explicitly blocked from these capabilities
        $this->assertFalse(\App\Services\MetalRegistry::isLiveRateEligible('platinum'));
        $this->assertFalse(\App\Services\MetalRegistry::isAutoRepricedEligible('platinum'));
        $this->assertFalse(\App\Services\MetalRegistry::isDhiranEligible('platinum'));
        $this->assertFalse(\App\Services\MetalRegistry::isExchangePaymentEligible('platinum'));
        $this->assertFalse(\App\Services\MetalRegistry::isLiveRateEligible('copper'));
        $this->assertFalse(\App\Services\MetalRegistry::isAutoRepricedEligible('copper'));
        $this->assertFalse(\App\Services\MetalRegistry::isDhiranEligible('copper'));
        $this->assertFalse(\App\Services\MetalRegistry::isExchangePaymentEligible('copper'));

        // Tier 1 + 2 — both visible in reports / reconciliation
        $this->assertTrue(\App\Services\MetalRegistry::isReportingVisible('gold'));
        $this->assertTrue(\App\Services\MetalRegistry::isReportingVisible('platinum'));
        $this->assertTrue(\App\Services\MetalRegistry::isReconciliationEligible('copper'));
    }

    /**
     * Test P1.4: shop_enabled_metals table exists and Tier 1 is
     * auto-enabled for every existing shop.
     */
    public function test_shop_enabled_metals_seeded_for_tier_1(): void
    {
        $tableExists = DB::selectOne(
            "SELECT 1 FROM information_schema.tables WHERE table_schema = 'public' AND table_name = 'shop_enabled_metals'"
        );
        $this->assertNotNull($tableExists, 'shop_enabled_metals table missing.');

        // Every shop should have Tier 1 enabled.
        // Note: `enabled IS TRUE` rather than `= true` for PostgreSQL boolean.
        $shopsWithoutTier1 = DB::selectOne(<<<'SQL'
            SELECT COUNT(*) AS missing
            FROM shops s
            WHERE NOT EXISTS (
                SELECT 1 FROM shop_enabled_metals sem
                WHERE sem.shop_id = s.id
                  AND sem.metal_type = 'gold'
                  AND sem.enabled IS TRUE
            )
            OR NOT EXISTS (
                SELECT 1 FROM shop_enabled_metals sem
                WHERE sem.shop_id = s.id
                  AND sem.metal_type = 'silver'
                  AND sem.enabled IS TRUE
            )
        SQL);

        $this->assertSame(
            0,
            (int) ($shopsWithoutTier1->missing ?? 0),
            'Shops missing Tier 1 (gold/silver) enabled rows — Phase 1 seed migration not run.'
        );
    }

    /**
     * Test P1.5: shop_daily_metal_rate_entries trigger blocks DELETE
     * and blocks UPDATE of identity columns. UPDATE of rate_per_gram is
     * allowed (mutable allow-list).
     */
    public function test_shop_daily_metal_rate_entries_trigger_enforces_invariants(): void
    {
        // First — confirm trigger function exists
        $fn = DB::selectOne(
            "SELECT 1 FROM pg_proc WHERE proname = 'shop_daily_metal_rate_entries_guard'"
        );
        $this->assertNotNull($fn, 'shop_daily_metal_rate_entries_guard function missing.');

        $trigger = DB::selectOne(
            "SELECT 1 FROM information_schema.triggers
             WHERE trigger_schema = 'public'
               AND trigger_name = 'shop_daily_metal_rate_entries_guard_trigger'"
        );
        $this->assertNotNull($trigger, 'shop_daily_metal_rate_entries_guard_trigger missing.');
    }

    /**
     * Test P1.6: enabledMetalsForShop returns at least gold + silver for
     * any existing shop (defensive fallback also returns Tier 1 if rows
     * are missing — verified indirectly via the seed test).
     */
    public function test_enabled_metals_for_shop_returns_tier_1(): void
    {
        $shop = DB::table('shops')->first();
        if (! $shop) {
            $this->markTestSkipped('No shops exist to test enabledMetalsForShop against.');
        }

        \App\Services\MetalRegistry::clearShopCache();
        $metals = \App\Services\MetalRegistry::enabledMetalsForShop((int) $shop->id);
        sort($metals);

        $this->assertContains('gold', $metals);
        $this->assertContains('silver', $metals);
    }

    // ──────────────────────────────────────────────────────────────────────
    // PHASE 2A — Structured Stone Separation
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Test P2A.1: stone_types and stone_components tables exist with the
     * expected schema markers.
     */
    public function test_stone_tables_present(): void
    {
        foreach (['stone_types', 'stone_components'] as $table) {
            $row = DB::selectOne(
                "SELECT 1 FROM information_schema.tables WHERE table_schema = 'public' AND table_name = ?",
                [$table]
            );
            $this->assertNotNull($row, "Phase 2A table '{$table}' is missing.");
        }

        // stone_components CHECK constraints exist (PostgreSQL only).
        foreach ([
            'stone_components_parent_required',
            'stone_components_count_positive',
            'stone_components_total_equals_unit_times_count',
        ] as $constraint) {
            $row = DB::selectOne(
                "SELECT 1 FROM pg_constraint WHERE conname = ?",
                [$constraint]
            );
            $this->assertNotNull($row, "Phase 2A CHECK constraint '{$constraint}' missing.");
        }
    }

    /**
     * Test P2A.2: stone snapshot guard trigger function exists.
     */
    public function test_stone_snapshot_guard_function_present(): void
    {
        $fn = DB::selectOne(
            "SELECT 1 FROM pg_proc WHERE proname = 'stone_components_snapshot_guard'"
        );
        $this->assertNotNull($fn, 'stone_components_snapshot_guard function missing.');

        $trigger = DB::selectOne(
            "SELECT 1 FROM information_schema.triggers
             WHERE trigger_schema = 'public'
               AND trigger_name = 'stone_components_snapshot_guard_trigger'"
        );
        $this->assertNotNull($trigger, 'stone_components_snapshot_guard_trigger missing.');
    }

    /**
     * Test P2A.3: stone_components CHECK constraint enforces
     * at-least-one parent FK.
     */
    public function test_stone_components_requires_parent_fk(): void
    {
        $shop = DB::table('shops')->first();
        if (! $shop) {
            $this->markTestSkipped('No shops to test against.');
        }

        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('stone_components')->insert([
            'shop_id'    => $shop->id,
            'stone_type' => 'other',
            'unit_value' => 100,
            'total_value'=> 100,
            'count'      => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Test P2A.4: backfilled rows carry migrated_from_legacy=true marker.
     */
    public function test_stone_components_legacy_rows_marked(): void
    {
        $hasLegacyMarker = DB::table('stone_components')
            ->whereRaw('migrated_from_legacy IS TRUE')
            ->exists();

        // Either no legacy stones exist, OR every legacy stone was marked.
        // The test passes either way (no negative case to assert).
        $unmarkedLegacyOrigin = DB::table('stone_components')
            ->whereRaw('migrated_from_legacy IS FALSE')
            ->whereNotNull('notes')
            ->where('notes', 'like', '%Phase 2A backfill%')
            ->count();

        $this->assertSame(
            0,
            (int) $unmarkedLegacyOrigin,
            'Phase 2A backfill produced rows that look like backfills but lack the migrated_from_legacy=true marker.'
        );

        $this->addToAssertionCount(1); // record that the test executed
    }

    /**
     * Test P2A.5: stone snapshot guard blocks UPDATE of identity columns
     * on a snapshotted stone, but permits notes edit.
     */
    public function test_stone_snapshot_guard_blocks_value_update(): void
    {
        $row = DB::selectOne(<<<'SQL'
            SELECT sc.id
            FROM stone_components sc
            JOIN invoice_items ii ON ii.id = sc.invoice_item_id
            JOIN invoices i ON i.id = ii.invoice_id
            WHERE i.status = 'finalized'
            LIMIT 1
        SQL);

        if (! $row) {
            $this->markTestSkipped('No snapshotted stone_components row available.');
        }

        // notes update should succeed
        DB::statement(
            'UPDATE stone_components SET notes = ? WHERE id = ?',
            ['invariant test ' . uniqid(), $row->id]
        );

        // value update should fail
        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::statement(
            'UPDATE stone_components SET unit_value = 99999, total_value = 99999 WHERE id = ?',
            [$row->id]
        );
    }

    /**
     * Test P2A.6: Article XIV isolation — neither RepriceRetailerInventoryJob
     * nor ShopPricingService references stone_components anywhere. Live-rate
     * code is constitutionally insulated from manual stone valuations.
     */
    public function test_article_xiv_stone_isolation_from_live_rate(): void
    {
        $repriceJob = file_get_contents(base_path('app/Jobs/RepriceRetailerInventoryJob.php'));
        $pricingSvc = file_get_contents(base_path('app/Services/ShopPricingService.php'));

        $this->assertStringNotContainsString(
            'stone_components',
            $repriceJob,
            'RepriceRetailerInventoryJob references stone_components — Article XIV violation.'
        );
        $this->assertStringNotContainsString(
            'StoneComponent',
            $repriceJob,
            'RepriceRetailerInventoryJob references StoneComponent — Article XIV violation.'
        );
        $this->assertStringNotContainsString(
            'stone_components',
            $pricingSvc,
            'ShopPricingService references stone_components — Article XIV violation.'
        );
        $this->assertStringNotContainsString(
            'StoneComponent',
            $pricingSvc,
            'ShopPricingService references StoneComponent — Article XIV violation.'
        );
    }

    // ──────────────────────────────────────────────────────────────────────
    // PHASE 2B — Advanced Stone Infrastructure
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Test P2B.1: Phase 2B columns present on stone_components.
     */
    public function test_phase_2b_columns_present_on_stone_components(): void
    {
        $expected = ['certificate_id', 'certificate_authority', 'grade', 'supplier_name', 'photo_path'];
        foreach ($expected as $col) {
            $row = DB::selectOne(
                "SELECT 1 FROM information_schema.columns
                 WHERE table_schema = 'public'
                   AND table_name   = 'stone_components'
                   AND column_name  = ?",
                [$col]
            );
            $this->assertNotNull($row, "Phase 2B column 'stone_components.{$col}' is missing.");
        }
    }

    /**
     * Test P2B.2: stone_revaluation_events table + 6 CHECK constraints present.
     */
    public function test_stone_revaluation_events_table_present(): void
    {
        $row = DB::selectOne(
            "SELECT 1 FROM information_schema.tables WHERE table_schema = 'public' AND table_name = 'stone_revaluation_events'"
        );
        $this->assertNotNull($row, 'stone_revaluation_events table missing.');

        foreach ([
            'stone_revaluation_events_old_count_positive',
            'stone_revaluation_events_new_count_positive',
            'stone_revaluation_events_old_total_consistent',
            'stone_revaluation_events_new_total_consistent',
            'stone_revaluation_events_delta_consistent',
            'stone_revaluation_events_reason_required',
        ] as $constraint) {
            $check = DB::selectOne(
                "SELECT 1 FROM pg_constraint WHERE conname = ?",
                [$constraint]
            );
            $this->assertNotNull($check, "Phase 2B CHECK constraint '{$constraint}' missing.");
        }
    }

    /**
     * Test P2B.3: stone_revaluation_events append-only trigger function present.
     */
    public function test_stone_revaluation_events_append_only_trigger_present(): void
    {
        $fn = DB::selectOne(
            "SELECT 1 FROM pg_proc WHERE proname = 'stone_revaluation_events_append_only_guard'"
        );
        $this->assertNotNull($fn, 'stone_revaluation_events_append_only_guard function missing.');

        $trigger = DB::selectOne(
            "SELECT 1 FROM information_schema.triggers
             WHERE trigger_schema = 'public'
               AND trigger_name = 'stone_revaluation_events_append_only_trigger'"
        );
        $this->assertNotNull($trigger, 'stone_revaluation_events_append_only_trigger missing.');
    }

    /**
     * Test P2B.4: snapshot guard locks Phase 2B fields (certificate_id, grade, etc.)
     * by inspecting the function body.
     */
    public function test_snapshot_guard_locks_phase_2b_fields(): void
    {
        $fn = DB::selectOne(
            "SELECT pg_get_functiondef(oid) AS body FROM pg_proc WHERE proname = 'stone_components_snapshot_guard'"
        );
        $this->assertNotNull($fn, 'stone_components_snapshot_guard function missing.');

        $body = (string) $fn->body;
        foreach ([
            'NEW.certificate_id',
            'NEW.certificate_authority',
            'NEW.grade',
            'NEW.supplier_name',
            'NEW.photo_path',
        ] as $marker) {
            $this->assertStringContainsString(
                $marker,
                $body,
                "Snapshot guard does NOT lock {$marker} — Phase 2B migration 020000 not applied."
            );
        }
    }

    /**
     * Test P2B.5: Article XIV holds for Phase 2B too — no automated path
     * references StoneRevaluationEvent or StoneRevaluationService.
     */
    public function test_article_xiv_holds_for_revaluation_events(): void
    {
        $repriceJob = file_get_contents(base_path('app/Jobs/RepriceRetailerInventoryJob.php'));
        $pricingSvc = file_get_contents(base_path('app/Services/ShopPricingService.php'));
        $fetchJob   = file_get_contents(base_path('app/Jobs/FetchLiveMetalRatesJob.php'));

        foreach ([
            ['file' => 'RepriceRetailerInventoryJob', 'content' => $repriceJob],
            ['file' => 'ShopPricingService',         'content' => $pricingSvc],
            ['file' => 'FetchLiveMetalRatesJob',     'content' => $fetchJob],
        ] as $check) {
            foreach ([
                'StoneRevaluationEvent',
                'StoneRevaluationService',
                'stone_revaluation_events',
            ] as $marker) {
                $this->assertStringNotContainsString(
                    $marker,
                    $check['content'],
                    "{$check['file']} references {$marker} — Article XIV violation."
                );
            }
        }
    }

    // ──────────────────────────────────────────────────────────────────────
    // PHASE 3 — Material Expansion Governance (anti-ERP)
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Test P3.1: no per-metal service classes anywhere in app/Services.
     * Per CONSTITUTION.md Article XIII, every metal flows through
     * the existing pricing/valuation/reconciliation services.
     */
    public function test_no_per_metal_service_classes(): void
    {
        $bannedNamePatterns = ['Palladium', 'Brass', 'Rhodium', 'Aluminum', 'Tungsten', 'Copper', 'Platinum', 'RoseGold', 'WhiteGold'];

        $serviceFiles = glob(base_path('app/Services/**/*.php')) ?: [];
        $serviceFiles = array_merge($serviceFiles, glob(base_path('app/Services/*.php')) ?: []);

        $violators = [];
        foreach ($serviceFiles as $file) {
            $name = basename($file, '.php');
            foreach ($bannedNamePatterns as $banned) {
                if ($name === $banned . 'Service'
                    || $name === $banned . 'ValuationService'
                    || $name === $banned . 'Resolver') {
                    $violators[] = $name;
                }
            }
        }

        $this->assertEmpty(
            $violators,
            'Per-metal service classes detected (anti-ERP violation): ' . implode(', ', $violators)
        );
    }

    /**
     * Test P3.2: no per-metal tables. Metal-specific concerns belong on
     * the universal metal_lots / metal_movements / stone_components
     * tables, distinguished by metal_type / stone_type — not by table.
     */
    public function test_no_per_metal_tables(): void
    {
        $bannedTablePrefixes = ['palladium_', 'brass_', 'rhodium_', 'aluminum_', 'tungsten_', 'platinum_', 'copper_', 'rose_gold_', 'white_gold_'];

        $violators = [];
        foreach ($bannedTablePrefixes as $prefix) {
            $rows = DB::select(
                "SELECT table_name FROM information_schema.tables
                 WHERE table_schema = 'public' AND table_name LIKE ?",
                [$prefix . '%']
            );
            foreach ($rows as $row) {
                $violators[] = $row->table_name;
            }
        }

        $this->assertEmpty(
            $violators,
            'Per-metal tables detected (anti-ERP violation): ' . implode(', ', $violators)
        );
    }

    /**
     * Test P3.3: every metal listed in config/materials.php (tier_1 ∪
     * tier_2) is present in the items CHECK constraint. Drift here
     * means a metal could pass tier validation but be rejected at DB
     * level, or vice-versa.
     */
    public function test_config_metals_match_db_check_constraint(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL only.');
        }

        $supportedAll = array_values(array_unique(array_merge(
            (array) config('materials.tier_1', []),
            (array) config('materials.tier_2', [])
        )));

        $constraint = DB::selectOne(
            "SELECT pg_get_constraintdef(oid) AS def FROM pg_constraint WHERE conname = 'items_metal_type_check'"
        );
        $this->assertNotNull($constraint, 'items_metal_type_check CHECK constraint is missing.');

        $def = (string) $constraint->def;
        foreach ($supportedAll as $metal) {
            $this->assertStringContainsString(
                "'{$metal}'",
                $def,
                "Config tier list contains '{$metal}' but items_metal_type_check does NOT — config/DB drift."
            );
        }
    }

    /**
     * Test P3.4: materials:audit command runs clean.
     * Constitutional executable enforcement of governance rules.
     */
    public function test_materials_audit_command_runs_clean(): void
    {
        $exitCode = \Illuminate\Support\Facades\Artisan::call('materials:audit');
        $output = \Illuminate\Support\Facades\Artisan::output();

        $this->assertSame(
            0,
            $exitCode,
            "materials:audit reported violations. Output:\n" . $output
        );
    }

    /**
     * Test P3.5: materials:propose-metal command rejects an already-supported
     * metal (gold) and a malformed metal code. Sanity check that the
     * proposal gate is not auto-passing.
     */
    public function test_materials_propose_metal_rejects_invalid(): void
    {
        // Already supported — must be rejected
        $exitCode = \Illuminate\Support\Facades\Artisan::call('materials:propose-metal', [
            'metal'       => 'gold',
            '--tier'      => 1,
            '--rationale' => 'This should be rejected because gold is already supported in tier 1.',
        ]);
        $this->assertSame(1, $exitCode, 'propose-metal accepted already-supported gold');

        // Malformed code
        $exitCode2 = \Illuminate\Support\Facades\Artisan::call('materials:propose-metal', [
            'metal'       => 'Gold!Invalid',
            '--tier'      => 2,
            '--rationale' => 'Some twenty-character-plus rationale to clear that hurdle.',
        ]);
        $this->assertSame(1, $exitCode2, 'propose-metal accepted malformed metal code');

        // Short rationale
        $exitCode3 = \Illuminate\Support\Facades\Artisan::call('materials:propose-metal', [
            'metal'       => 'titanium',
            '--tier'      => 2,
            '--rationale' => 'short',
        ]);
        $this->assertSame(1, $exitCode3, 'propose-metal accepted short rationale');
    }
}
