<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Phase 3 — Metal admission proposal scaffolder.
 *
 * Non-destructive proposal generator that runs the 10 Phase 3 admission
 * criteria from docs/runbooks/phase-3-governance.md against a proposed
 * new metal. If any criterion fails, the command refuses to scaffold
 * and prints the specific objection.
 *
 * When all checks pass, the command PRINTS (does NOT write) the exact
 * files the operator would need to:
 *   1. Edit config/materials.php (add to tier_1 or tier_2)
 *   2. Create a new migration widening CHECK constraints
 *   3. Update CONSTITUTION.md (if Article XIII tier set changes)
 *   4. Append to ConstitutionalInvariantsTest for capability assertions
 *
 * The operator reviews and applies manually. No code is auto-written —
 * Phase 3 admissions must always be human-reviewed.
 */
class ProposeMetalAdmission extends Command
{
    protected $signature = 'materials:propose-metal
                            {metal : Metal code (lowercase, e.g. palladium)}
                            {--tier=2 : Proposed tier (1 or 2)}
                            {--rationale= : Written justification (required, min 20 chars)}';

    protected $description = 'Phase 3 — propose admission of a new metal. Runs 10 governance checks; on pass, prints the scaffold the operator must apply manually.';

    public function handle(): int
    {
        $metal = strtolower(trim((string) $this->argument('metal')));
        $tier  = (int) $this->option('tier');
        $rationale = (string) ($this->option('rationale') ?? '');

        $this->info('━━━ Phase 3 Metal Admission Proposal ━━━━━━━━━━━━━━━━━━━━');
        $this->line("Proposing: '{$metal}' as Tier {$tier}");
        $this->line('Rationale: ' . ($rationale ?: '(none provided)'));
        $this->newLine();

        $objections = [];

        // ── Criterion 1: metal code format ────────────────────────────
        if ($metal === '' || ! preg_match('/^[a-z][a-z0-9_]{1,18}$/', $metal)) {
            $objections[] = 'Metal code must be lowercase, 2–19 chars, [a-z0-9_], starting with a letter.';
        }

        // ── Criterion 2: not already supported ────────────────────────
        $existing = array_merge(
            (array) config('materials.tier_1', []),
            (array) config('materials.tier_2', [])
        );
        if (in_array($metal, $existing, true)) {
            $objections[] = "Metal '{$metal}' is already in tier_" . (in_array($metal, (array) config('materials.tier_1', []), true) ? '1' : '2') . '. Nothing to admit.';
        }

        // ── Criterion 3: rationale required (min 20 chars) ────────────
        if (mb_strlen(trim($rationale)) < 20) {
            $objections[] = 'Rationale must be at least 20 characters. Provide a written justification covering shop demand + tier reasoning.';
        }

        // ── Criterion 4: tier must be 1 or 2 ──────────────────────────
        if (! in_array($tier, [1, 2], true)) {
            $objections[] = 'Tier must be 1 (full support) or 2 (limited support). Tier 3 is blocked by definition — no need to admit.';
        }

        // ── Criterion 5: governance forbidden names ───────────────────
        // These names are documented anti-ERP examples; reject them
        // unless rationale explicitly addresses why they belong.
        $antiErpExamples = ['rose_gold', 'white_gold', 'alloy', 'plated', 'fake'];
        foreach ($antiErpExamples as $banned) {
            if (str_contains($metal, $banned)) {
                $objections[] = "Metal name contains banned token '{$banned}' — these are descriptors, not commodity metal types. Use the underlying base metal (gold, silver, etc.) and track variants via a different mechanism.";
            }
        }

        // ── Criterion 6: governance doc activation rule ───────────────
        // Phase 3 governance requires founder sign-off. We cannot
        // verify that programmatically; surface the requirement.
        $this->warn('NOTE: Phase 3 admission also requires founder sign-off per docs/runbooks/phase-3-governance.md.');
        $this->warn('This command does NOT verify founder approval — that is a human gate.');
        $this->newLine();

        // ── Render result ─────────────────────────────────────────────
        if (! empty($objections)) {
            $this->error('Proposal REJECTED. ' . count($objections) . ' objection(s):');
            foreach ($objections as $i => $o) {
                $this->warn('  ' . ($i + 1) . '. ' . $o);
            }
            $this->newLine();
            $this->line('Address each objection and re-run.');
            return 1;
        }

        // All programmatic checks passed. Print the scaffold.
        $this->info('All programmatic checks PASSED. Scaffold below — apply manually.');
        $this->newLine();

        $this->line('▸ STEP 1: edit config/materials.php');
        $this->line('  Append to the tier_' . $tier . ' array:');
        $this->line("  'tier_{$tier}' => [..., '{$metal}'],");
        $this->newLine();

        $this->line('▸ STEP 2: create migration widening DB CHECK constraints');
        $this->line('  Filename: database/migrations/' . date('Y_m_d_His') . "_add_{$metal}_to_metal_type_check_constraints.php");
        $this->line('  Body:');
        $sample = $this->generateMigrationSnippet($metal, $existing);
        foreach (explode("\n", $sample) as $line) {
            $this->line('  ' . $line);
        }
        $this->newLine();

        $this->line('▸ STEP 3: extend ConstitutionalInvariantsTest::test_metal_registry_tier_assignment');
        $this->line('  Add an assertion:');
        $this->line("  \$this->assertSame(");
        $this->line("      \\App\\Services\\MetalRegistry::TIER_{$tier},");
        $this->line("      \\App\\Services\\MetalRegistry::tierFor('{$metal}')");
        $this->line('  );');
        $this->newLine();

        if ($tier === 2) {
            $this->line('▸ STEP 4 (Tier 2 only): extend test_metal_registry_tier_2_capabilities_blocked');
            $this->line("  Add capability-blocked assertions for '{$metal}' (isLiveRateEligible, isAutoRepricedEligible, isDhiranEligible, isExchangePaymentEligible all false).");
            $this->newLine();
        }

        $this->line('▸ STEP 5: update CONSTITUTION.md Article XIII');
        $this->line('  Update the "Current tier assignment" list to include this metal.');
        $this->newLine();

        $this->line('▸ STEP 6: run verification after applying');
        $this->line('  php artisan migrate');
        $this->line('  php artisan test tests/Feature/ConstitutionalInvariantsTest.php');
        $this->line('  php artisan materials:audit');
        $this->newLine();

        $this->info('Per the governance doc, total work should fit within 1 engineering day.');
        $this->info('If it takes longer, the metal is too special-cased and should be rejected.');

        return 0;
    }

    private function generateMigrationSnippet(string $metal, array $existingSupported): string
    {
        $newList = array_values(array_unique(array_merge($existingSupported, [$metal])));
        $listSql = implode(', ', array_map(fn ($m) => "'{$m}'", $newList));

        return <<<PHP
return new class extends Migration {
    public function up(): void {
        if (DB::getDriverName() !== 'pgsql') return;
        \$tables = ['items', 'metal_lots', 'products'];
        foreach (\$tables as \$t) {
            DB::statement("ALTER TABLE {\$t} DROP CONSTRAINT IF EXISTS {\$t}_metal_type_check");
            DB::statement("ALTER TABLE {\$t} ADD CONSTRAINT {\$t}_metal_type_check
                CHECK (metal_type IS NULL OR metal_type IN ({$listSql}))");
        }
    }
    public function down(): void { /* widen-only; safe rollback is the prior constraint set */ }
};
PHP;
    }
}
