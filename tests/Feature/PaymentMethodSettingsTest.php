<?php

namespace Tests\Feature;

use App\Models\ShopPaymentMethod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Payment Methods settings: CRUD + the security guarantees — shop-scoped 403,
 * unique name per (shop,type), type whitelist, mass-assignment safety, the
 * delete-in-use guard, the toggle, and masked account_label.
 */
class PaymentMethodSettingsTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    private $user;
    private int $shopId;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        // can:settings.edit-gated; harness owner role has no synced perms.
        $this->withoutMiddleware([
            \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
            \Illuminate\Auth\Middleware\Authorize::class,
        ]);
        [$user, $shop] = $this->createManufacturerTenant();
        $this->user = $user;
        $this->shopId = $shop->id;
    }

    // Seed names already in the title-cased form the NormalizeHumanTextInput
    // middleware produces, so unique-name checks against a POSTed (normalized)
    // value compare apples-to-apples. forceFill bypasses the middleware.
    private function method(array $attrs = []): ShopPaymentMethod
    {
        $m = new ShopPaymentMethod();
        $m->forceFill(array_merge([
            'shop_id' => $this->shopId, 'type' => 'upi', 'name' => 'Phonepe',
            'upi_id' => 'shop@upi', 'is_active' => true, 'sort_order' => 0,
        ], $attrs));
        $m->save();
        return $m;
    }

    // ── create ──────────────────────────────────────────────────────────────

    public function test_store_creates_a_method_scoped_to_the_shop(): void
    {
        $this->actingAs($this->user)->post(route('settings.payment-methods.store'), [
            'type' => 'upi', 'name' => 'PhonePe', 'upi_id' => 'shop@upi',
        ])->assertRedirect();

        $m = ShopPaymentMethod::withoutGlobalScopes()->where('shop_id', $this->shopId)->first();
        $this->assertNotNull($m);
        // Name is title-cased by NormalizeHumanTextInput middleware (app-wide),
        // so compare case-insensitively.
        $this->assertSame('phonepe', strtolower($m->name));
        $this->assertSame($this->shopId, $m->shop_id);
        $this->assertTrue((bool) $m->is_active);
    }

    public function test_invalid_type_is_rejected(): void
    {
        $this->actingAs($this->user)->post(route('settings.payment-methods.store'), [
            'type' => 'bitcoin', 'name' => 'X',
        ])->assertSessionHasErrors('type');
    }

    public function test_duplicate_name_within_same_type_is_rejected(): void
    {
        // Seeded as the normalized form 'Phonepe'; the POST 'PhonePe' normalizes
        // to the same → unique-name violation.
        $this->method(['type' => 'upi', 'name' => 'Phonepe']);

        $this->actingAs($this->user)->post(route('settings.payment-methods.store'), [
            'type' => 'upi', 'name' => 'PhonePe', 'upi_id' => 'other@upi',
        ])->assertSessionHasErrors('name');
    }

    public function test_same_name_allowed_across_different_types(): void
    {
        $this->method(['type' => 'upi', 'name' => 'Myshop']);

        $this->actingAs($this->user)->post(route('settings.payment-methods.store'), [
            'type' => 'bank', 'name' => 'Myshop', 'account_number' => '123456',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(2, ShopPaymentMethod::withoutGlobalScopes()->where('shop_id', $this->shopId)->count());
    }

    public function test_store_does_not_let_a_crafted_shop_id_override_the_real_one(): void
    {
        // Mass-assignment guard: a smuggled shop_id must be ignored (server sets it).
        $this->actingAs($this->user)->post(route('settings.payment-methods.store'), [
            'type' => 'upi', 'name' => 'PhonePe', 'shop_id' => 999999, 'sort_order' => 50,
        ])->assertRedirect();

        $m = ShopPaymentMethod::withoutGlobalScopes()->where('shop_id', $this->shopId)->first();
        $this->assertNotNull($m);
        $this->assertSame($this->shopId, $m->shop_id, 'crafted shop_id ignored');
    }

    // ── update / toggle ──────────────────────────────────────────────────────

    public function test_update_changes_fields(): void
    {
        $m = $this->method(['type' => 'bank', 'name' => 'Sbi', 'account_number' => '111122223333']);

        $this->callController(fn ($c) => $c->update(
            $this->req('PUT', ['type' => 'bank', 'name' => 'SBI Main', 'account_number' => '999988887777']),
            $m,
        ));

        $m->refresh();
        // Direct controller call bypasses the input-normalization middleware, so
        // the name is stored verbatim here (the HTTP path would title-case it).
        $this->assertSame('SBI Main', $m->name);
        $this->assertSame('999988887777', $m->account_number);
    }

    public function test_toggle_flips_active_state(): void
    {
        $m = $this->method(['is_active' => true]);

        $this->callController(fn ($c) => $c->toggle($m));
        $this->assertFalse((bool) $m->fresh()->is_active);

        $this->callController(fn ($c) => $c->toggle($m->fresh()));
        $this->assertTrue((bool) $m->fresh()->is_active);
    }

    // ── delete + in-use guard ─────────────────────────────────────────────────

    public function test_unused_method_can_be_deleted(): void
    {
        $m = $this->method();

        $this->callController(fn ($c) => $c->destroy($m));
        $this->assertNull(ShopPaymentMethod::withoutGlobalScopes()->find($m->id));
    }

    public function test_method_used_in_a_transaction_cannot_be_deleted(): void
    {
        $m = $this->method();

        // A referencing KarigarPayment makes the method "in use" (one of the 4
        // tables the destroy guard checks). Lightest real referencing row.
        $karigarId = (int) DB::table('karigars')->insertGetId([
            'shop_id' => $this->shopId, 'name' => 'Ramesh', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('karigar_payments')->insert([
            'shop_id' => $this->shopId, 'karigar_id' => $karigarId, 'payment_method_id' => $m->id,
            'amount' => 100, 'mode' => 'upi', 'paid_on' => now()->toDateString(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->callController(fn ($c) => $c->destroy($m));

        // Still present — the in-use guard blocked the delete.
        $this->assertNotNull(ShopPaymentMethod::withoutGlobalScopes()->find($m->id), 'in-use method not deleted');
    }

    // ── cross-shop isolation ──────────────────────────────────────────────────

    public function test_cannot_update_another_shops_method(): void
    {
        [, $otherShop] = $this->createManufacturerTenant();
        $foreign = new ShopPaymentMethod();
        $foreign->forceFill(['shop_id' => $otherShop->id, 'type' => 'upi', 'name' => 'Foreign', 'is_active' => true, 'sort_order' => 0]);
        $foreign->save();

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $this->callController(fn ($c) => $c->update($this->req('PUT', ['type' => 'upi', 'name' => 'Hacked']), $foreign));
    }

    public function test_cannot_delete_another_shops_method(): void
    {
        [, $otherShop] = $this->createManufacturerTenant();
        $foreign = new ShopPaymentMethod();
        $foreign->forceFill(['shop_id' => $otherShop->id, 'type' => 'upi', 'name' => 'Foreign', 'is_active' => true, 'sort_order' => 0]);
        $foreign->save();

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $this->callController(fn ($c) => $c->destroy($foreign));
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    /**
     * Invoke a PaymentMethodController method directly as the acting user inside
     * the shop's tenant context. The HTTP layer's {method} route-model binding
     * resolves before the tenant scope in the test harness (404), so the thin
     * controller logic is exercised here instead — mirroring the GST/JobOrder
     * controller tests.
     */
    private function callController(\Closure $fn)
    {
        $this->actingAs($this->user);
        return \App\Support\TenantContext::runFor(
            $this->shopId,
            fn () => $fn(app(\App\Http\Controllers\PaymentMethodController::class)),
        );
    }

    private function req(string $method, array $data): \Illuminate\Http\Request
    {
        return \Illuminate\Http\Request::create('', $method, $data)->setUserResolver(fn () => $this->user);
    }

    // ── account_label masking ─────────────────────────────────────────────────

    public function test_bank_account_label_masks_the_number(): void
    {
        $m = $this->method(['type' => 'bank', 'name' => 'SBI', 'upi_id' => null, 'account_number' => '111122223333']);

        $label = $m->account_label;
        $this->assertStringContainsString('****3333', $label, 'masked to last 4');
        $this->assertStringNotContainsString('111122223333', $label, 'full number not exposed in label');
    }
}
