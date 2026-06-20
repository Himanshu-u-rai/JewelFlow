<?php

namespace Tests\Feature\Dhiran;

use App\Models\Customer;
use App\Models\Dhiran\DhiranAttachment;
use App\Models\Dhiran\DhiranLoan;
use App\Models\Dhiran\DhiranSettings;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Shop;
use App\Models\User;
use App\Services\Dhiran\DhiranAttachmentService;
use App\Services\DhiranService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Evidence readiness gate (pending_evidence → active).
 *
 * A UI-created loan starts pending_evidence and can only be activated once it has
 * at least one pledged-item photo AND a borrower ID proof. The guard is enforced
 * in the service (not UI-only). Existing active loans are unaffected.
 */
class DhiranEvidenceGateTest extends TestCase
{
    use RefreshDatabase;

    private DhiranService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(DhiranService::class);
    }

    /** @return array{0:Shop,1:User} */
    private function shopOwner(string $mobile): array
    {
        $shop = Shop::create([
            'name' => 'Pawn Co', 'shop_type' => 'dhiran', 'phone' => '9990000090',
            'owner_first_name' => 'O', 'owner_last_name' => 'W', 'owner_mobile' => '9990000090',
            'country' => 'India', 'gst_rate' => 3.0, 'wastage_recovery_percent' => 0,
        ]);
        DhiranSettings::getForShop($shop->id)->update(['is_enabled' => true]);
        $role = new Role();
        $role->forceFill(['name' => 'owner', 'display_name' => 'Owner', 'shop_id' => $shop->id])->save();
        $role->permissions()->sync(Permission::query()->pluck('id'));
        $owner = User::create([
            'mobile_number' => $mobile, 'password' => bcrypt('x'), 'realm' => 'dhiran',
            'is_active' => true, 'shop_id' => $shop->id, 'role_id' => $role->id,
        ]);

        return [$shop, $owner];
    }

    /** A pending_evidence loan (created via the UI flow). */
    private function pendingLoan(Shop $shop, User $owner): DhiranLoan
    {
        return TenantContext::runFor($shop->id, function () use ($shop, $owner) {
            $c = Customer::create(['shop_id' => $shop->id, 'first_name' => 'C', 'last_name' => 'O', 'mobile' => '98' . fake()->unique()->numerify('########')]);
            return $this->service->createLoan($shop, $c, [[
                'description' => 'Chain', 'metal_type' => 'gold', 'purity' => 22,
                'gross_weight' => 50, 'rate_per_gram_at_pledge' => 6000,
            ]], ['principal_amount' => 100000, 'gold_rate_on_date' => 6000, 'created_by' => $owner->id, 'status' => 'pending_evidence']);
        });
    }

    private function upload(Shop $shop, DhiranLoan $loan, string $type, User $by): DhiranAttachment
    {
        return TenantContext::runFor($shop->id, fn () => app(DhiranAttachmentService::class)->store(
            UploadedFile::fake()->image('f.jpg', 100, 100), $shop->id, DhiranAttachment::OWNER_LOAN, $loan->id, $type, $by->id
        ));
    }

    private function fresh(int $id): DhiranLoan
    {
        return TenantContext::runFor(DhiranLoan::withoutGlobalScope('shop')->find($id)->shop_id,
            fn () => DhiranLoan::with('items')->findOrFail($id));
    }

    // 0. UI-created loans start pending_evidence (not active).
    public function test_ui_created_loan_starts_pending_evidence(): void
    {
        [$shop, $owner] = $this->shopOwner('9390000100');
        $customer = TenantContext::runFor($shop->id, fn () => Customer::create(['shop_id' => $shop->id, 'first_name' => 'C', 'last_name' => 'O', 'mobile' => '9811120001']));

        $this->actingAs($owner)->post('https://dhiran.jewelflows.com/dhiran', [
            'customer_id' => $customer->id, 'principal_amount' => 10000, 'gold_rate_on_date' => 6000,
            'items' => [['description' => 'X', 'purity' => 22, 'gross_weight' => 50, 'rate_per_gram_at_pledge' => 6000]],
        ])->assertRedirect();

        $loan = DhiranLoan::withoutGlobalScope('shop')->where('shop_id', $shop->id)->latest('id')->first();
        $this->assertSame('pending_evidence', $loan->status);
    }

    // 1. Loan without item photo cannot activate.
    public function test_cannot_activate_without_item_photo(): void
    {
        Storage::fake('local');
        [$shop, $owner] = $this->shopOwner('9390000101');
        $loan = $this->pendingLoan($shop, $owner);
        $this->upload($shop, $loan, 'id_proof_front', $owner); // ID only, no item photo

        $this->expectException(\LogicException::class);
        TenantContext::runFor($shop->id, fn () => $this->service->activateLoan($this->fresh($loan->id)));
    }

    // 2. Loan without borrower ID proof cannot activate.
    public function test_cannot_activate_without_id_proof(): void
    {
        Storage::fake('local');
        [$shop, $owner] = $this->shopOwner('9390000102');
        $loan = $this->pendingLoan($shop, $owner);
        $this->upload($shop, $loan, 'item_photo', $owner); // photo only, no ID proof

        $this->expectException(\LogicException::class);
        TenantContext::runFor($shop->id, fn () => $this->service->activateLoan($this->fresh($loan->id)));
    }

    // 3. Loan with both required evidence can activate.
    public function test_can_activate_with_both_evidence(): void
    {
        Storage::fake('local');
        [$shop, $owner] = $this->shopOwner('9390000103');
        $loan = $this->pendingLoan($shop, $owner);
        $this->upload($shop, $loan, 'item_photo', $owner);
        $this->upload($shop, $loan, 'id_proof_front', $owner);

        TenantContext::runFor($shop->id, fn () => $this->service->activateLoan($this->fresh($loan->id)));
        $this->assertSame('active', $this->fresh($loan->id)->status);
    }

    // 4. Backend rejects forged activation when evidence missing (route-level).
    public function test_activate_route_rejects_missing_evidence(): void
    {
        Storage::fake('local');
        [$shop, $owner] = $this->shopOwner('9390000104');
        $loan = $this->pendingLoan($shop, $owner);
        // No evidence at all. Drive the controller action directly (the {loan}
        // binding resolves under tenant middleware live); the service guard rejects.
        $resp = TenantContext::runFor($shop->id, function () use ($owner, $loan) {
            $this->actingAs($owner);
            return app(\App\Http\Controllers\DhiranController::class)
                ->activateLoan(request(), DhiranLoan::findOrFail($loan->id));
        });

        // Redirected back with an error; loan is NOT activated.
        $this->assertSame(302, $resp->getStatusCode());
        $this->assertSame('pending_evidence', $this->fresh($loan->id)->status);
    }

    // 5. Already-active loan cannot be re-activated through the gate.
    public function test_active_loan_cannot_be_reactivated(): void
    {
        [$shop, $owner] = $this->shopOwner('9390000105');
        // Service default status = active (internal path), simulating a legacy loan.
        $loan = TenantContext::runFor($shop->id, function () use ($shop, $owner) {
            $c = Customer::create(['shop_id' => $shop->id, 'first_name' => 'C', 'last_name' => 'O', 'mobile' => '9811120005']);
            return $this->service->createLoan($shop, $c, [[
                'description' => 'X', 'metal_type' => 'gold', 'purity' => 22, 'gross_weight' => 50, 'rate_per_gram_at_pledge' => 6000,
            ]], ['principal_amount' => 100000, 'gold_rate_on_date' => 6000, 'created_by' => $owner->id]);
        });
        $this->assertSame('active', $this->fresh($loan->id)->status);

        $this->expectException(\LogicException::class);
        TenantContext::runFor($shop->id, fn () => $this->service->activateLoan($this->fresh($loan->id)));
    }

    // 6. Evidence checklist + gated Activate button appear on the loan show page.
    public function test_show_page_displays_evidence_checklist(): void
    {
        Storage::fake('local');
        [$shop, $owner] = $this->shopOwner('9390000106');
        $loan = $this->pendingLoan($shop, $owner);

        $html = TenantContext::runFor($shop->id, function () use ($owner, $loan) {
            $this->actingAs($owner);
            view()->share('errors', new \Illuminate\Support\ViewErrorBag);
            $fresh = DhiranLoan::with(['customer', 'items', 'payments'])->findOrFail($loan->id);
            $ev = $this->service->evidenceStatus($fresh);
            return view('dhiran.show', ['loan' => $fresh, 'attachments' => collect(), 'evidence' => $ev])->render();
        });

        $this->assertStringContainsString('Evidence required before activation', $html);
        $this->assertStringContainsString('Pledged item photo', $html);
        $this->assertStringContainsString('Borrower ID proof', $html);
        // Activate button is disabled (no evidence yet).
        $this->assertStringContainsString('disabled', $html);
    }

    // 7. Cross-shop attachment still denied (gate doesn't weaken isolation).
    public function test_cross_shop_attachment_still_denied(): void
    {
        Storage::fake('local');
        [$shopA, $ownerA] = $this->shopOwner('9390000107');
        [$shopB, $ownerB] = $this->shopOwner('9390000108');
        $loanB = $this->pendingLoan($shopB, $ownerB);
        $att = $this->upload($shopB, $loanB, 'item_photo', $ownerB);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        TenantContext::runFor($shopA->id, function () use ($ownerA, $att) {
            $this->actingAs($ownerA);
            $row = DhiranAttachment::withoutGlobalScope('shop')->findOrFail($att->id);
            app(\App\Http\Controllers\DhiranController::class)->showAttachment($row);
        });
    }
}
