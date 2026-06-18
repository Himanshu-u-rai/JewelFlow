<?php

namespace Tests\Feature\Mobile\V1;

use App\Models\InstallmentPlan;
use App\Models\InvoicePayment;
use App\Models\ShopPaymentMethod;
use App\Services\RetailerSalesService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Mobile v1 — EMI / Installments. The native endpoints that finalize a POS-EMI
 * draft, record monthly payments, and discard a draft, all reusing the web
 * InstallmentService. Mirrors the web behaviour: items stay in_stock through the
 * draft, finalize moves them to sold, account linkage on non-cash payments.
 */
class InstallmentApiTest extends TestCase
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

    private function idem(string $tag = ''): array
    {
        return ['X-Idempotency-Key' => 'emi-' . $tag . '-' . uniqid()];
    }

    /** Returns [user, shop, draftInvoice]. */
    private function setupDraft(): array
    {
        [$user, $shop] = $this->createRetailerTenant();
        $this->grant($user, 'sales.view', 'sales.create');
        $lot = $this->createMetalLot($shop->id);
        $customer = $this->createCustomer($shop->id);
        $item = $this->createItem($shop->id, $lot->id);

        $draft = TenantContext::runFor($shop->id, function () use ($user, $customer, $item) {
            // prepareEmiDraftSale reads auth()->user()->shop — act as the user.
            $this->actingAs($user);
            return RetailerSalesService::prepareEmiDraftSale(customerId: $customer->id, itemIds: [$item->id]);
        });

        return [$user, $shop, $draft];
    }

    public function test_requires_auth(): void
    {
        $this->getJson('/api/mobile/v1/installments')->assertStatus(401);
    }

    public function test_finalize_creates_a_plan_from_the_draft(): void
    {
        [$user, $shop, $draft] = $this->setupDraft();
        Sanctum::actingAs($user);
        TenantContext::set((int) $shop->id);

        $res = $this->withHeaders($this->idem('fin'))->postJson('/api/mobile/v1/installments/finalize', [
            'invoice_id' => $draft->id,
            'down_payment' => 0,
            'total_emis' => 6,
            'interest_rate_annual' => 3,
        ]);

        $res->assertCreated();
        $this->assertSame('active', $res->json('data.status'));
        $this->assertSame(6, $res->json('data.total_emis'));
        $this->assertArrayHasKey('data', $res->json());
        $this->assertArrayHasKey('meta', $res->json());
        // plan persisted
        $this->assertDatabaseHas('installment_plans', ['invoice_id' => $draft->id, 'shop_id' => $shop->id]);
    }

    public function test_finalize_down_payment_records_chosen_upi_account(): void
    {
        [$user, $shop, $draft] = $this->setupDraft();
        $upi = ShopPaymentMethod::create([
            'shop_id' => $shop->id, 'type' => 'upi', 'name' => 'Shop GPay',
            'upi_id' => 'shop@upi', 'is_active' => true, 'sort_order' => 0,
        ]);
        Sanctum::actingAs($user);
        TenantContext::set((int) $shop->id);

        $res = $this->withHeaders($this->idem('fin2'))->postJson('/api/mobile/v1/installments/finalize', [
            'invoice_id' => $draft->id,
            'down_payment' => 5000,
            'total_emis' => 6,
            'interest_rate_annual' => 0,
            'down_payment_method' => 'upi',
            'down_payment_method_id' => $upi->id,
        ]);

        $res->assertCreated();
        $payment = InvoicePayment::query()->withoutGlobalScopes()
            ->where('invoice_id', $draft->id)->where('mode', 'upi')->first();
        $this->assertNotNull($payment);
        $this->assertSame($upi->id, (int) $payment->payment_method_id);
    }

    public function test_finalize_rejects_upi_without_account(): void
    {
        [$user, $shop, $draft] = $this->setupDraft();
        Sanctum::actingAs($user);
        TenantContext::set((int) $shop->id);

        $this->withHeaders($this->idem('fin3'))->postJson('/api/mobile/v1/installments/finalize', [
            'invoice_id' => $draft->id,
            'down_payment' => 5000,
            'total_emis' => 6,
            'down_payment_method' => 'upi',
            // no down_payment_method_id
        ])->assertStatus(422);
    }

    public function test_pay_records_a_monthly_emi(): void
    {
        [$user, $shop, $draft] = $this->setupDraft();
        Sanctum::actingAs($user);
        TenantContext::set((int) $shop->id);

        $this->withHeaders($this->idem('a'))->postJson('/api/mobile/v1/installments/finalize', [
            'invoice_id' => $draft->id, 'down_payment' => 0, 'total_emis' => 6, 'interest_rate_annual' => 0,
        ])->assertCreated();

        $plan = TenantContext::runFor((int) $shop->id, fn () => InstallmentPlan::where('invoice_id', $draft->id)->firstOrFail());

        $res = $this->withHeaders($this->idem('pay'))->postJson("/api/mobile/v1/installments/{$plan->id}/pay", [
            'amount' => 5000,
            'payment_method' => 'cash',
        ]);
        $res->assertCreated();
        $this->assertGreaterThanOrEqual(1, count($res->json('data.payments')));
    }

    public function test_index_and_show_are_listed_and_scoped(): void
    {
        [$user, $shop, $draft] = $this->setupDraft();
        Sanctum::actingAs($user);
        TenantContext::set((int) $shop->id);

        $this->withHeaders($this->idem('b'))->postJson('/api/mobile/v1/installments/finalize', [
            'invoice_id' => $draft->id, 'down_payment' => 0, 'total_emis' => 6, 'interest_rate_annual' => 0,
        ])->assertCreated();
        $plan = TenantContext::runFor((int) $shop->id, fn () => InstallmentPlan::where('invoice_id', $draft->id)->firstOrFail());

        $this->getJson('/api/mobile/v1/installments')->assertOk()
            ->assertJsonPath('data.plans.0.id', $plan->id);

        $this->getJson("/api/mobile/v1/installments/{$plan->id}")->assertOk()
            ->assertJsonPath('data.id', $plan->id);
    }

    public function test_discard_cancels_the_draft(): void
    {
        [$user, $shop, $draft] = $this->setupDraft();
        Sanctum::actingAs($user);
        TenantContext::set((int) $shop->id);

        $this->withHeaders($this->idem('disc'))->postJson('/api/mobile/v1/installments/discard-draft', [
            'invoice_id' => $draft->id,
        ])->assertOk()->assertJsonPath('data.discarded', true);

        $this->assertDatabaseHas('invoices', ['id' => $draft->id, 'status' => 'cancelled']);
    }

    public function test_create_requires_sales_create_permission(): void
    {
        [$user, $shop, $draft] = $this->setupDraft();
        // Revoke sales.create — only sales.view remains.
        TenantContext::runFor((int) $shop->id, function () use ($user) {
            \App\Models\Role::withoutTenant()->findOrFail($user->role_id)->revokePermission('sales.create');
        });
        Sanctum::actingAs($user->fresh());
        TenantContext::set((int) $shop->id);

        $this->withHeaders($this->idem('perm'))->postJson('/api/mobile/v1/installments/finalize', [
            'invoice_id' => $draft->id, 'down_payment' => 0, 'total_emis' => 6,
        ])->assertStatus(403);
    }
}
