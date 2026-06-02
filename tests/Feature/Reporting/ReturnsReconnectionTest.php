<?php

namespace Tests\Feature\Reporting;

use App\Models\Invoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Returns/Exchange web-surface RE-CONNECTION (RETURNS_SYSTEM_DIAGNOSTIC.md).
 *
 * Proves the previously-orphaned web surface is reachable again: every route
 * name resolves, the views render (which resolves their internal route(...)
 * calls — the exact failure mode that was broken), permissions gate correctly,
 * and the invoice page exposes the entry points. No semantics tested here —
 * accounting integration is covered by returns:validate + the GST report tests.
 */
class ReturnsReconnectionTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
    }

    private function grant(\App\Models\User $user, string ...$permissions): void
    {
        $role = \App\Models\Role::withoutTenant()->findOrFail($user->role_id);
        foreach ($permissions as $p) {
            $role->givePermission($p);
        }
    }

    public function test_every_returns_and_exchange_route_name_resolves(): void
    {
        // The original bug: these names were unregistered, so route() threw.
        $names = [
            'returns.index', 'returns.control-center', 'returns.batch-restock',
            'returns.show', 'returns.approve-review', 'returns.approve', 'returns.reject',
            'returns.items.redispose', 'returns.items.fix-orphan-status',
            'returns.items.recover', 'returns.items.recover.store',
            'returns.disposition.recover-inline', 'returns.create', 'returns.store',
            'exchanges.index', 'exchanges.create', 'exchanges.store',
            'exchanges.unified.create', 'exchanges.unified.store',
            'exchanges.show', 'exchanges.receipt',
        ];

        foreach ($names as $name) {
            $this->assertTrue(\Illuminate\Support\Facades\Route::has($name), "Route {$name} must be registered");
        }
    }

    public function test_returns_inbox_renders_for_viewer(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $this->grant($user, 'returns.view');

        // A real shop has a preferences row, so the return-policy-banner partial
        // actually invokes ShopPreferences::hasConfiguredReturnPolicy(). (The
        // earlier version had no preferences row and silently skipped it — which
        // is exactly how the live "Something Went Wrong" slipped past.)
        DB::table('shop_preferences')->insert([
            'shop_id' => $shop->id, 'return_policy_configured_at' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // Rendering inbox resolves all its internal route('returns.*') calls AND
        // the banner method; unconfigured policy → the warning banner shows.
        $this->actingAs($user)->get(route('returns.index'))
            ->assertOk()
            ->assertSee('Returns', false)
            ->assertSee('Return policy not configured', false);
    }

    public function test_control_center_requires_approve_permission(): void
    {
        [$viewer] = $this->createRetailerTenant();
        $this->grant($viewer, 'returns.view'); // view but NOT approve
        $this->actingAs($viewer)->get(route('returns.control-center'))->assertForbidden();

        [$approver] = $this->createRetailerTenant();
        $this->grant($approver, 'returns.approve');
        $this->actingAs($approver)->get(route('returns.control-center'))->assertOk();
    }

    public function test_exchanges_index_renders(): void
    {
        [$user] = $this->createRetailerTenant();
        $this->grant($user, 'returns.view');
        $this->actingAs($user)->get(route('exchanges.index'))->assertOk();
    }

    public function test_invoice_show_template_exposes_return_and_exchange_entry_points(): void
    {
        // The invoice show page must offer Return + Exchange entry points,
        // permission-gated and only for finalized invoices. Asserted at the
        // template source level: full-page render of bound-model show routes is
        // blocked by a separate, pre-existing test-harness binding issue (the
        // route names themselves are proven to resolve in the route test above).
        $blade = file_get_contents(resource_path('views/invoices/show.blade.php'));

        $this->assertStringContainsString("@can('returns.create')", $blade);
        $this->assertStringContainsString("route('returns.create', \$invoice)", $blade);
        $this->assertStringContainsString("route('exchanges.unified.create', \$invoice)", $blade);
        $this->assertStringContainsString('Invoice::STATUS_FINALIZED', $blade, 'entry points must be gated to finalized invoices');
    }

    public function test_compatibility_relations_and_constants_exist(): void
    {
        // These were referenced by the committed Returns Control Center but were
        // missing from the models — the exact runtime breaks reconnection exposed.
        // Locked here so they cannot silently disappear again.
        $this->assertSame('manufacture', \App\Models\JobOrder::JOB_TYPE_MANUFACTURE);
        $this->assertSame('repair', \App\Models\JobOrder::JOB_TYPE_REPAIR);
        $this->assertSame('rework', \App\Models\JobOrder::JOB_TYPE_REWORK);

        $sourceItem = (new \App\Models\JobOrder)->sourceItem();
        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class, $sourceItem);
        $this->assertSame('source_item_id', $sourceItem->getForeignKeyName());

        $latestDisp = (new \App\Models\Item)->latestReturnDisposition();
        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Relations\HasOne::class, $latestDisp);
        $this->assertSame('item_id', $latestDisp->getForeignKeyName());

        // ShopPreferences methods consumed by the returns flow + return-policy
        // banner (the live "Something Went Wrong" was these being undefined).
        $prefs = new \App\Models\ShopPreferences(['return_policy_configured_at' => null]);
        $this->assertFalse($prefs->hasConfiguredReturnPolicy());
        $prefs->return_policy_configured_at = now();
        $this->assertTrue($prefs->hasConfiguredReturnPolicy());

        $deductions = (new \App\Models\ShopPreferences([
            'refund_making_charges' => false, 'refund_stone_charges' => true,
        ]))->toRefundDeductions();
        $this->assertFalse($deductions['making_charges_refundable']);
        $this->assertTrue($deductions['stone_charges_refundable']);
        $this->assertArrayHasKey('hallmark_charges_refundable', $deductions);
    }

    public function test_default_role_permissions_include_returns_view_and_create_for_staff(): void
    {
        // New shops provisioned via TenantRoleService must grant staff the
        // returns surface (so the disappearance cannot silently recur).
        [$user, $shop] = $this->createRetailerTenant();
        app(\App\Services\TenantRoleService::class)->ensureDefaultsForShop($shop->id);

        $staffRole = \App\Models\Role::withoutTenant()->where('shop_id', $shop->id)->where('name', 'staff')->first();
        $perms = $staffRole->permissions()->pluck('name');

        $this->assertTrue($perms->contains('returns.view'));
        $this->assertTrue($perms->contains('returns.create'));
        // Approval stays owner/manager-only — staff must NOT get it.
        $this->assertFalse($perms->contains('returns.approve'));
    }
}
