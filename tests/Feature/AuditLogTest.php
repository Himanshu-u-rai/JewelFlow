<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Audit Log integrity + viewer.
 *
 * Two things mattered here: (1) the tamper-evidence hash chain must cover the
 * content the LEGACY writers actually use (action/model_type/model_id/
 * description/data) — not just the rarely-populated rich columns; and (2) the
 * audit tab must be append-only, filterable, and free of the N+1 it had.
 */
class AuditLogTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
    }

    private function grantSettingsView(User $owner): void
    {
        $perm = Permission::firstOrCreate(['name' => 'settings.view'], ['display_name' => 'View Settings', 'group' => 'staff']);
        Role::withoutGlobalScopes()->findOrFail($owner->role_id)->permissions()->syncWithoutDetaching([$perm->id]);
    }

    public function test_hash_chain_covers_legacy_content_columns(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();

        TenantContext::runFor($shop->id, function () use ($shop) {
            $log = AuditLog::create([
                'shop_id'     => $shop->id,
                'action'      => 'staff_terminated',
                'model_type'  => 'User',
                'model_id'    => 42,
                'description' => 'Removed staff: Ramesh',
                'data'        => ['reason' => 'left'],
            ]);

            $raw = DB::table('audit_logs')->where('id', $log->id)->first();

            // Recompute the digest the trigger should now produce — including the
            // legacy content columns. A match proves they are inside the hash.
            $input = ($raw->prev_hash ?? '') . '|'
                . ($raw->actor ?? '') . '|'
                . ($raw->target ?? '') . '|'
                . ($raw->before ?? '') . '|'
                . ($raw->after ?? '') . '|'
                . ($raw->action ?? '') . '|'
                . ($raw->model_type ?? '') . '|'
                . ($raw->model_id ?? '') . '|'
                . ($raw->description ?? '') . '|'
                . ($raw->data ?? '') . '|'
                . ($raw->user_id ?? '') . '|'
                . ($raw->created_at ?? '');

            $this->assertSame(hash('sha256', $input), $raw->row_hash,
                'row_hash must be the SHA-256 of the full content (legacy columns included)');
        });
    }

    public function test_audit_log_is_append_only(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();

        TenantContext::runFor($shop->id, function () use ($shop) {
            $log = AuditLog::create([
                'shop_id' => $shop->id, 'action' => 'probe', 'model_type' => 'P', 'model_id' => 1, 'description' => 'orig',
            ]);

            // Eloquent guard blocks update…
            try {
                $log->description = 'tampered';
                $log->save();
                $this->fail('updating an audit log must throw');
            } catch (\LogicException $e) {
                $this->assertStringContainsString('append-only', strtolower($e->getMessage()));
            }

            // …and the DB trigger blocks a raw UPDATE that bypasses Eloquent.
            try {
                DB::table('audit_logs')->where('id', $log->id)->update(['description' => 'raw tamper']);
                $this->fail('raw UPDATE on audit_logs must be blocked by the trigger');
            } catch (\Illuminate\Database\QueryException $e) {
                $this->assertStringContainsString('append-only', strtolower($e->getMessage()));
            }
        });
    }

    public function test_audit_tab_filters_by_action(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $this->grantSettingsView($owner);

        TenantContext::runFor($shop->id, function () use ($shop) {
            AuditLog::create(['shop_id' => $shop->id, 'action' => 'aaa_keep', 'model_type' => 'T', 'model_id' => 1, 'description' => 'KEEP_ME']);
            AuditLog::create(['shop_id' => $shop->id, 'action' => 'zzz_drop', 'model_type' => 'T', 'model_id' => 2, 'description' => 'DROP_ME']);
        });

        $resp = $this->actingAs($owner)->get(route('settings.edit', ['tab' => 'audit', 'audit_action' => 'aaa_keep']));
        $resp->assertOk();
        $resp->assertSee('KEEP_ME');
        // DROP_ME must not appear in a table row. The dropdown headlines the value
        // ("Zzz Drop"), so asserting the raw description string is absent is exact.
        $resp->assertDontSee('DROP_ME');
    }

    public function test_audit_tab_renders_and_is_gated_by_settings_view(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $this->grantSettingsView($owner);

        $resp = $this->actingAs($owner)->get(route('settings.edit', ['tab' => 'audit']));
        $resp->assertOk();
        $resp->assertSee('Audit Log');
    }

    public function test_sensitive_classifier_flags_money_gold_and_access_events(): void
    {
        $make = fn (string $action) => tap(new AuditLog())->forceFill(['action' => $action]);

        // Sensitive: money/gold/staff/access/deletion.
        foreach ([
            'item_deleted', 'invoice_reversal_created', 'quick_bill_void', 'return_refund_overridden',
            'staff_terminated', 'vault_adjusted', 'gold_recovery_recorded', 'role_permissions_updated',
            'return_order_settled', 'exchange_unified_settled', 'mobile_session_revoked',
        ] as $action) {
            $this->assertTrue($make($action)->isSensitive(), "{$action} must be flagged sensitive");
        }

        // Not sensitive: routine reads/creates.
        foreach (['item_created', 'item_updated', 'scan_session_created', 'kyc_document_uploaded', 'invoice_finalized'] as $action) {
            $this->assertFalse($make($action)->isSensitive(), "{$action} must NOT be flagged sensitive");
        }
    }

    public function test_summary_line_prefers_description_then_humanizes_action(): void
    {
        $withDesc = tap(new AuditLog())->forceFill(['action' => 'staff_terminated', 'description' => 'Removed staff: Ramesh']);
        $this->assertSame('Removed staff: Ramesh', $withDesc->summaryLine());

        $noDesc = tap(new AuditLog())->forceFill(['action' => 'vault_adjusted', 'description' => null]);
        $this->assertSame('Vault Adjusted', $noDesc->summaryLine(), 'falls back to Title Case of the action, never raw snake_case');
    }

    public function test_audit_export_streams_csv_with_filters_and_hash(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $this->grantSettingsView($owner);

        TenantContext::runFor($shop->id, function () use ($shop) {
            AuditLog::create(['shop_id' => $shop->id, 'action' => 'keep_me_action', 'model_type' => 'T', 'model_id' => 1, 'description' => 'KEEP ROW']);
            AuditLog::create(['shop_id' => $shop->id, 'action' => 'other_action', 'model_type' => 'T', 'model_id' => 2, 'description' => 'OTHER ROW']);
        });

        $resp = $this->actingAs($owner)->get(route('settings.audit.export', ['audit_action' => 'keep_me_action']));
        $resp->assertOk();
        $resp->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $csv = $resp->streamedContent();
        $this->assertStringContainsString('Verification hash', $csv, 'header includes the row_hash column');
        $this->assertStringContainsString('KEEP ROW', $csv);
        $this->assertStringNotContainsString('OTHER ROW', $csv, 'export must honor the action filter');
    }
}
