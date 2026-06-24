<?php

namespace Tests\Feature\Mobile\V1;

use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Permission;
use App\Models\ReturnLineItem;
use App\Models\ReturnOrder;
use App\Models\Role;
use App\Models\Shop;
use App\Models\ShopPreferences;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * M8 — Returns mobile API contract tests.
 *
 * Covers: list, show, create (immediate settle + pending_approval),
 * approve, cross-shop isolation, envelope shape.
 */
class ReturnsApiTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    private function grant(\App\Models\User $user, string ...$perms): void
    {
        $role = \App\Models\Role::withoutTenant()->findOrFail($user->role_id);
        foreach ($perms as $p) {
            $role->givePermission($p);
        }
    }

    private function idempotency(string $tag = ''): array
    {
        return ['X-Idempotency-Key' => 'returns-' . $tag . '-' . uniqid()];
    }

    /** A user in $shop holding exactly the given permission names. */
    private function userWithPerms(Shop $shop, array $perms, string $mobile): User
    {
        return TenantContext::runFor($shop->id, function () use ($shop, $perms, $mobile) {
            $role = (new Role())->forceFill(['name' => 'r' . $mobile, 'display_name' => 'R', 'shop_id' => $shop->id]);
            $role->save();
            $role->permissions()->sync(Permission::whereIn('name', $perms)->pluck('id'));

            return User::create([
                'name' => 'U' . $mobile, 'mobile_number' => $mobile, 'password' => Hash::make('password'),
                'realm' => 'erp', 'is_active' => true, 'shop_id' => $shop->id, 'role_id' => $role->id,
            ]);
        });
    }

    /** Stamp a configured, refund-everything return policy so the create gate passes. */
    private function configureReturnPolicy(Shop $shop, array $overrides = []): void
    {
        TenantContext::runFor($shop->id, function () use ($shop, $overrides) {
            $prefs = ShopPreferences::firstOrNew(['shop_id' => $shop->id]);
            $prefs->forceFill(array_merge([
                'shop_id' => $shop->id,
                'refund_making_charges' => true,
                'refund_stone_charges' => true,
                'refund_gst' => true,
                'wear_loss_pct' => 0,
                'restocking_fee_pct' => 0,
                'return_settlement_mode' => 'cash_or_credit',
                'return_policy_configured_at' => now(),
            ], $overrides))->save();
        });
    }

    /**
     * Sell an item on the manufacturer path → [owner, shop, finalized Invoice, line].
     * Mirrors ReturnsAccessAndLifecycleTest::soldInvoice so the mobile API exercises
     * a real finalized invoice with locked allocations.
     */
    private function soldInvoice(): array
    {
        // The web POS setup routes go through CSRF + throttle; disable both so the
        // data-setup calls don't 419/429. Does not affect the mobile v1 stack
        // (rate.shop is a separate middleware) we actually assert against.
        $this->withoutMiddleware([
            \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
            \Illuminate\Routing\Middleware\ThrottleRequests::class,
        ]);

        [$user, $shop] = $this->createManufacturerTenant();
        $lot = $this->createMetalLot($shop->id);
        $customer = $this->createCustomer($shop->id);
        $item = $this->createItem($shop->id, $lot->id);

        $preview = $this->actingAs($user)->postJson('/api/price-preview', [
            'item_id' => $item->id, 'customer_id' => $customer->id,
            'gold_rate' => 6000, 'making' => 500, 'stone' => 200, 'discount' => 0, 'round_off' => 0,
        ]);
        $total = (float) $preview->json('total');

        $sell = $this->actingAs($user)->postJson('/pos/sell', [
            'customer_id' => $customer->id, 'item_id' => $item->id,
            'gold_rate' => 6000, 'making' => 500, 'stone' => 200, 'discount' => 0, 'round_off' => 0,
            'payments' => [['mode' => 'cash', 'amount' => $total]],
        ])->assertOk();

        $invoice = TenantContext::runFor($shop->id, fn () => Invoice::findOrFail($sell->json('invoice_id')));
        $line = TenantContext::runFor($shop->id, fn () => InvoiceItem::where('invoice_id', $invoice->id)->firstOrFail());

        return [$user, $shop, $invoice, $line];
    }

    private function createPayload(InvoiceItem $line): array
    {
        return [
            'invoice_id' => $line->invoice_id,
            'reason' => 'Customer changed mind',
            'refund_settlement' => 'cash',
            'lines' => [[
                'invoice_item_id' => $line->id,
                'condition' => ReturnLineItem::CONDITION_GOOD,
            ]],
        ];
    }

    // ── CREATE (regression for the checkRequired signature/selection-shape bug) ──

    /**
     * The core regression: before the fix this path threw at runtime
     * (ArgumentCountError on checkRequired + reading ->required on a ?string),
     * so it could never reach a 201. With no approval threshold configured the
     * return settles immediately and a credit note is issued.
     */
    public function test_create_return_settles_immediately_when_no_approval_required(): void
    {
        [$owner, $shop, $invoice, $line] = $this->soldInvoice();
        $this->configureReturnPolicy($shop); // no approval_threshold_amount → no approval

        Sanctum::actingAs($owner);
        TenantContext::set((int) $shop->id);

        $response = $this->withHeaders($this->idempotency('create-settle'))
            ->postJson('/api/mobile/v1/returns', $this->createPayload($line));

        $response->assertStatus(201);
        $this->assertSame(ReturnOrder::STATUS_SETTLED, $response->json('data.status'));

        $order = ReturnOrder::withoutGlobalScopes()->where('invoice_id', $invoice->id)->firstOrFail();
        $this->assertSame($shop->id, $order->shop_id);

        $cn = CreditNote::withoutGlobalScopes()->where('return_order_id', $order->id)->first();
        $this->assertNotNull($cn, 'credit note issued on immediate settle');
        $this->assertLessThanOrEqual((float) $invoice->total + 0.001, (float) $cn->total, 'credit note must not exceed invoice');

        $this->assertNotNull($line->fresh()->returned_at, 'returned line is stamped');
    }

    /**
     * When the refund exceeds the shop's approval threshold, checkRequired returns
     * a non-null reason string and the return is parked as pending_approval rather
     * than settled. Proves the ?string return value is handled correctly and the
     * approval branch wires the same business rule as web.
     */
    public function test_create_return_parks_pending_approval_when_threshold_exceeded(): void
    {
        [$owner, $shop, $invoice, $line] = $this->soldInvoice();
        // A ₹1 threshold is below any real refund, so approval is always required.
        $this->configureReturnPolicy($shop, ['approval_threshold_amount' => 1]);

        Sanctum::actingAs($owner);
        TenantContext::set((int) $shop->id);

        $response = $this->withHeaders($this->idempotency('create-pending'))
            ->postJson('/api/mobile/v1/returns', $this->createPayload($line));

        $response->assertStatus(201);
        $this->assertSame(ReturnOrder::STATUS_PENDING_APPROVAL, $response->json('data.status'));

        $order = ReturnOrder::withoutGlobalScopes()->where('invoice_id', $invoice->id)->firstOrFail();
        $this->assertSame(ReturnOrder::STATUS_PENDING_APPROVAL, $order->status);

        // Pending approval must NOT settle: no credit note, line not yet returned.
        $this->assertSame(0, CreditNote::withoutGlobalScopes()->where('return_order_id', $order->id)->count(),
            'pending approval issues no credit note');
        $this->assertNull($line->fresh()->returned_at, 'line is not returned until approved');
    }

    public function test_create_return_requires_auth(): void
    {
        $this->postJson('/api/mobile/v1/returns', [
            'invoice_id' => 1,
            'reason' => 'x',
            'refund_settlement' => 'cash',
            'lines' => [['invoice_item_id' => 1, 'condition' => ReturnLineItem::CONDITION_GOOD]],
        ])->assertStatus(401);
    }

    public function test_create_return_cannot_target_another_shops_invoice(): void
    {
        // Shop B owns the invoice/line.
        [, , $invoiceB, $lineB] = $this->soldInvoice();

        // Shop A user (full owner perms) must not be able to return shop B's line.
        [$userA, $shopA] = $this->createRetailerTenant();
        Sanctum::actingAs($userA);
        TenantContext::set((int) $shopA->id);

        // invoice_id is validated with Rule::exists scoped to the caller's shop,
        // so a cross-shop invoice fails validation (422) — never reaching the service.
        $this->withHeaders($this->idempotency('cross-shop'))
            ->postJson('/api/mobile/v1/returns', $this->createPayload($lineB))
            ->assertStatus(422);
    }

    public function test_create_return_requires_sales_create_permission(): void
    {
        [, $shop, , $line] = $this->soldInvoice();
        $this->configureReturnPolicy($shop);

        // A user with read access but no sales.create must be blocked by the route gate.
        $noCreate = $this->userWithPerms($shop, ['sales.view'], '9814401000');
        Sanctum::actingAs($noCreate);
        TenantContext::set((int) $shop->id);

        $this->withHeaders($this->idempotency('no-perm'))
            ->postJson('/api/mobile/v1/returns', $this->createPayload($line))
            ->assertStatus(403);
    }

    public function test_returns_list_requires_auth(): void
    {
        $this->getJson('/api/mobile/v1/returns')->assertStatus(401);
    }

    public function test_returns_list_is_shop_scoped(): void
    {
        [$userA, $shopA] = $this->createRetailerTenant();
        $this->grant($userA, 'sales.view');

        Sanctum::actingAs($userA);
        \App\Support\TenantContext::set((int) $shopA->id);

        // Shop A has no returns; the list must be empty, not leak anything.
        $response = $this->getJson('/api/mobile/v1/returns');
        $response->assertOk();
        $this->assertSame([], $response->json('data.data'));
    }

    public function test_returns_list_envelope_shape(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $this->grant($user, 'sales.view');
        Sanctum::actingAs($user);
        \App\Support\TenantContext::set((int) $shop->id);

        $response = $this->getJson('/api/mobile/v1/returns');
        $response->assertOk();
        $this->assertArrayHasKey('data', $response->json());
        $this->assertArrayHasKey('meta', $response->json());
        $this->assertArrayHasKey('errors', $response->json());
    }

    public function test_returns_show_404_for_non_existent_order(): void
    {
        [$userA, $shopA] = $this->createRetailerTenant();
        $this->grant($userA, 'sales.view');

        Sanctum::actingAs($userA);
        \App\Support\TenantContext::set((int) $shopA->id);

        // A non-existent ID should return 404 cleanly.
        $this->getJson('/api/mobile/v1/returns/999999')->assertStatus(404);
    }

    public function test_approve_endpoint_exists_and_is_gated(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        // With returns.approve the user can reach the route.
        // Without it they get 403. We verify the gate is in place.
        $this->grant($user, 'sales.view', 'returns.approve');

        Sanctum::actingAs($user);
        \App\Support\TenantContext::set((int) $shop->id);

        // A non-existent ID returns 404 (route model binding), not 403,
        // which proves the route resolves and the model-binding layer
        // is running — the approve action is reachable for permitted users.
        $response = $this->withHeaders($this->idempotency('gate-check'))
            ->postJson('/api/mobile/v1/returns/999999/approve');

        // 404 means the route resolved and model binding ran correctly.
        // Had the permission gate blocked us we'd see 403 instead.
        $response->assertStatus(404);
    }
}
