<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Phase 3 — Material governance audit.
 *
 * Executable enforcement of CONSTITUTION.md Articles XIII–XV and the
 * Phase 3 anti-ERP boundaries documented in
 * docs/runbooks/phase-3-governance.md.
 *
 * Runs read-only checks across:
 *   1. config/materials.php tier definitions
 *   2. DB CHECK constraints on items / metal_lots / products
 *   3. MetalRegistry capability set
 *   4. Codebase scan for per-metal service classes / tables / hardcoded literals
 *   5. Cross-consistency between config and DB
 *
 * Exit codes: 0 = clean, 1 = violations found (CI / scheduler will surface).
 *
 * Read-only — never auto-fixes. If a violation is found, the operator
 * must investigate and either remediate the code or amend the
 * constitution (with founder sign-off per the amendment process).
 */
class AuditMaterialGovernance extends Command
{
    protected $signature = 'materials:audit
                            {--detailed : Show every check with full SQL context}';

    protected $description = 'Audit material governance: tier consistency, CHECK constraints, codebase anti-ERP boundaries. Read-only — exits 1 on violation.';

    public function handle(): int
    {
        $violations = [];

        $tier1 = (array) config('materials.tier_1', []);
        $tier2 = (array) config('materials.tier_2', []);
        $supportedAll = array_values(array_unique(array_merge($tier1, $tier2)));

        $this->info('━━━ Material Governance Audit ━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->newLine();

        // ── 1. Tier sanity ────────────────────────────────────────────
        $this->info('▸ Check 1: tier definitions in config/materials.php');
        if (empty($tier1)) {
            $violations[] = 'config/materials.php tier_1 is empty — at least one Tier 1 metal must exist.';
        }
        $overlap = array_intersect($tier1, $tier2);
        if (! empty($overlap)) {
            $violations[] = 'tier_1 and tier_2 overlap: ' . implode(', ', $overlap) . ' — a metal cannot be in both tiers.';
        }
        $this->line("  Tier 1: " . implode(', ', $tier1));
        $this->line("  Tier 2: " . implode(', ', $tier2));
        $this->newLine();

        // ── 2. DB CHECK constraints cover the supported set ───────────
        $this->info('▸ Check 2: DB CHECK constraints match supported metal set');
        if (DB::getDriverName() === 'pgsql') {
            $expectedConstraints = [
                'items'      => 'items_metal_type_check',
                'metal_lots' => 'metal_lots_metal_type_check',
                'products'   => 'products_metal_type_check',
            ];

            foreach ($expectedConstraints as $table => $constraintName) {
                $constraint = DB::selectOne(
                    "SELECT pg_get_constraintdef(oid) AS def FROM pg_constraint WHERE conname = ?",
                    [$constraintName]
                );
                if (! $constraint) {
                    $violations[] = "CHECK constraint {$constraintName} on {$table} is MISSING.";
                    continue;
                }
                $def = (string) $constraint->def;
                foreach ($supportedAll as $metal) {
                    if (stripos($def, "'{$metal}'") === false) {
                        $violations[] = "CHECK constraint {$constraintName} does NOT include '{$metal}' (declared in config/materials.php). Definition: {$def}";
                    }
                }
                if ($this->option('detailed')) {
                    $this->line("  {$table}: {$def}");
                }
            }
            $this->line('  CHECK constraints scanned: ' . count($expectedConstraints));
            $this->newLine();
        }

        // ── 3. MetalRegistry capability set ───────────────────────────
        $this->info('▸ Check 3: MetalRegistry capabilities consistent with tier classification');
        foreach ($supportedAll as $metal) {
            $tier = \App\Services\MetalRegistry::tierFor($metal);
            // Tier 1 metals should have ALL capabilities; Tier 2 should NOT have live-rate/auto-reprice/dhiran/exchange.
            if ($tier === \App\Services\MetalRegistry::TIER_1) {
                $checks = ['isLiveRateEligible', 'isAutoRepricedEligible', 'isDhiranEligible', 'isExchangePaymentEligible'];
                foreach ($checks as $m) {
                    if (! \App\Services\MetalRegistry::$m($metal)) {
                        $violations[] = "Tier 1 metal '{$metal}' missing capability {$m} — Tier 1 must be fully supported.";
                    }
                }
            } elseif ($tier === \App\Services\MetalRegistry::TIER_2) {
                $forbidden = ['isLiveRateEligible', 'isAutoRepricedEligible', 'isDhiranEligible', 'isExchangePaymentEligible'];
                foreach ($forbidden as $m) {
                    if (\App\Services\MetalRegistry::$m($metal)) {
                        $violations[] = "Tier 2 metal '{$metal}' has capability {$m} — Tier 2 metals must be restricted.";
                    }
                }
            }
        }
        $this->line('  ' . count($supportedAll) . ' supported metal(s) checked against tier rules.');
        $this->newLine();

        // ── 4. Codebase scan: no per-metal service classes ────────────
        $this->info('▸ Check 4: no per-metal service classes (anti-ERP)');
        // Recursive, deterministic discovery — never silently miss a subdirectory.
        $serviceFiles = $this->allPhpFilesUnder([base_path('app/Services')]);
        $bannedPatterns = ['Palladium', 'Brass', 'Rhodium', 'Aluminum', 'Tungsten'];
        foreach ($serviceFiles as $file) {
            $basename = basename($file);
            foreach ($bannedPatterns as $banned) {
                if (stripos($basename, $banned) !== false) {
                    $violations[] = "Banned per-metal service class detected: {$basename} — anti-ERP rule violation.";
                }
            }
        }
        $this->line('  ' . count($serviceFiles) . ' service file(s) scanned.');
        $this->newLine();

        // ── 5. Codebase scan: no per-metal tables ─────────────────────
        $this->info('▸ Check 5: no per-metal tables (anti-ERP)');
        if (DB::getDriverName() === 'pgsql') {
            $bannedTablePrefixes = ['palladium_', 'brass_', 'rhodium_', 'aluminum_', 'tungsten_'];
            foreach ($bannedTablePrefixes as $prefix) {
                $rows = DB::select(
                    "SELECT table_name FROM information_schema.tables
                     WHERE table_schema = 'public' AND table_name LIKE ?",
                    [$prefix . '%']
                );
                foreach ($rows as $row) {
                    $violations[] = "Banned per-metal table detected: {$row->table_name} — anti-ERP rule violation.";
                }
            }
            $this->line('  Per-metal table-name patterns scanned.');
            $this->newLine();
        }

        // ── 6. Codebase scan: no hardcoded metal literals in business code ──
        $this->info('▸ Check 6: no hardcoded metal literal validators');
        $hits = [];
        // Recursive, deterministic discovery across ALL controllers (incl.
        // Api/Mobile/* and any nesting) and ALL services. The previous glob
        // approach missed two-level-deep dirs and silently dropped files via
        // integer-key array union — a constitutional audit must never miss files.
        $businessGlob = $this->allPhpFilesUnder([
            base_path('app/Http/Controllers'),
            base_path('app/Services'),
        ]);
        foreach ($businessGlob as $file) {
            if (! is_file($file)) continue;
            // Whitelist: MetalRegistry itself
            if (basename($file) === 'MetalRegistry.php') continue;

            $content = file_get_contents($file);
            // Detect: Rule::in(['gold', 'silver']) or in:gold,silver
            if (preg_match("/Rule::in\(\s*\[\s*'gold'\s*,\s*'silver'\s*\]/", $content)) {
                $hits[] = "{$file} contains hardcoded Rule::in(['gold','silver'])";
            }
            if (preg_match("/'in:gold,silver/", $content)) {
                $hits[] = "{$file} contains hardcoded 'in:gold,silver'";
            }
        }
        if (! empty($hits)) {
            foreach ($hits as $h) {
                $violations[] = 'Hardcoded metal literal: ' . $h;
            }
        }
        $this->line('  ' . count($businessGlob) . ' business file(s) scanned.');
        $this->newLine();

        // ── 7. Cross-consistency: MetalRegistry vs config ─────────────
        $this->info('▸ Check 7: MetalRegistry::allSupportedMetals() matches config tier list');
        $fromRegistry = \App\Services\MetalRegistry::allSupportedMetals();
        sort($fromRegistry);
        $fromConfig   = $supportedAll;
        sort($fromConfig);
        if ($fromRegistry !== $fromConfig) {
            $violations[] = 'MetalRegistry::allSupportedMetals() (' . implode(',', $fromRegistry) . ') does NOT match config (' . implode(',', $fromConfig) . ')';
        }
        $this->line('  Registry: ' . implode(', ', $fromRegistry));
        $this->newLine();

        // ── 8. Shop opt-in consistency: every enabled metal is supported ──
        $this->info('▸ Check 8: every shop_enabled_metals row references a supported metal');
        $orphanRows = DB::table('shop_enabled_metals')
            ->whereRaw('enabled IS TRUE')
            ->whereNotIn('metal_type', $supportedAll)
            ->get();
        if ($orphanRows->isNotEmpty()) {
            foreach ($orphanRows as $r) {
                $violations[] = "Shop {$r->shop_id} has enabled metal_type='{$r->metal_type}' that is NOT in supported set.";
            }
        }
        $this->line('  ' . $orphanRows->count() . ' orphan opt-in row(s) found.');
        $this->newLine();

        // ── 9. Trigger registry coverage: every constitutional trigger ──
        $this->info('▸ Check 9: every registered constitutional trigger present in DB');
        if (DB::getDriverName() === 'pgsql') {
            // Registered constitutional triggers (Article IX.A entries 1-29).
            $registered = [
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
                // entry #13 (metal_movements_append_only_trigger) is documentation placeholder — see CONSTITUTION.md
                'return_line_items_settled_guard_trigger',
                'karigar_invoices_finalized_guard_trigger',
                'job_orders_finalized_guard_trigger',
                'vault_reconciliation_runs_append_only_trigger',
                'metal_movements_immutable_trigger',
                'cash_transactions_immutable_trigger',
                'customer_gold_transactions_immutable_trigger',
                'shop_daily_metal_rate_entries_guard_trigger',
                'stone_components_snapshot_guard_trigger',
                'stone_revaluation_events_append_only_trigger',
                'audit_logs_hash_trigger',
                'metal_rates_no_update',
                'metal_rates_no_delete',
                'platform_audit_logs_append_only_trigger',
                'store_credit_append_only_guard_trigger',
                'invoices_numbering_guard_trigger',
            ];
            $found = collect(DB::select(
                "SELECT DISTINCT trigger_name FROM information_schema.triggers WHERE trigger_schema = 'public'"
            ))->pluck('trigger_name')->all();

            $missing = array_diff($registered, $found);
            foreach ($missing as $m) {
                $violations[] = "Constitutional trigger '{$m}' registered in CONSTITUTION.md but MISSING from DB.";
            }
            $this->line('  Registered: ' . count($registered) . ', Present: ' . count(array_intersect($registered, $found)) . ', Missing: ' . count($missing));
            $this->newLine();
        }

        // ── Final verdict ─────────────────────────────────────────────
        $this->info('━━━ Verdict ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        if (empty($violations)) {
            $this->info('  ✓ Material governance CLEAN. All Phase 0–3 invariants hold.');
            return 0;
        }

        $this->error('  ✗ ' . count($violations) . ' violation(s) detected:');
        foreach ($violations as $i => $v) {
            $this->warn('    ' . ($i + 1) . '. ' . $v);
        }
        $this->newLine();
        $this->line('Constitutional Failure Protocol applies — investigate before remediation.');
        $this->line('Per CONSTITUTION.md §1: do NOT patch around violations, do NOT disable protections.');

        return 1;
    }

    /**
     * Recursively collect every .php file under the given directories.
     *
     * Deterministic and complete — replaces the prior glob('**') approach
     * which (a) did not recurse beyond one directory level (so Api/Mobile/*
     * was never scanned) and (b) lost files to integer-key array union.
     * A constitutional audit tool must never silently miss a file.
     *
     * @param  list<string>  $dirs
     * @return list<string>
     */
    private function allPhpFilesUnder(array $dirs): array
    {
        $files = [];

        foreach ($dirs as $dir) {
            if (! is_dir($dir)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if ($file->isFile() && strtolower($file->getExtension()) === 'php') {
                    // Key by pathname to dedup if directories overlap.
                    $files[$file->getPathname()] = $file->getPathname();
                }
            }
        }

        return array_values($files);
    }
}
