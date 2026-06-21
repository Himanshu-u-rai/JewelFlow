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
use App\Support\AadhaarMask;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Dhiran KYC privacy + private evidence attachments (Phase E1-E5).
 */
class DhiranEvidenceTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0:Shop,1:User} */
    private function dhiranShopWithOwner(string $mobile): array
    {
        $shop = Shop::create([
            'name' => 'Pawn Co', 'shop_type' => 'dhiran', 'phone' => '9990000080',
            'owner_first_name' => 'O', 'owner_last_name' => 'W', 'owner_mobile' => '9990000080',
            'country' => 'India', 'gst_rate' => 3.0, 'wastage_recovery_percent' => 0,
        ]);
        DhiranSettings::getForShop($shop->id)->update(['is_enabled' => true]);
        $role = new Role();
        $role->forceFill(['name' => 'owner', 'display_name' => 'Owner', 'shop_id' => $shop->id])->save();
        $role->permissions()->sync(Permission::query()->pluck('id'));
        $owner = User::create([
            'mobile_number' => $mobile, 'password' => bcrypt('x'), 'realm' => 'dhiran',
            'is_active' => true, 'email_verified_at' => now(), 'shop_id' => $shop->id, 'role_id' => $role->id,
        ]);

        return [$shop, $owner];
    }

    private function makeLoan(Shop $shop, array $params = []): DhiranLoan
    {
        return TenantContext::runFor($shop->id, function () use ($shop, $params) {
            $c = Customer::create(['shop_id' => $shop->id, 'first_name' => 'C', 'last_name' => 'O', 'mobile' => '98' . fake()->unique()->numerify('########')]);
            return app(DhiranService::class)->createLoan($shop, $c, [[
                'description' => 'Chain', 'metal_type' => 'gold', 'purity' => 22,
                'gross_weight' => 50, 'rate_per_gram_at_pledge' => 6000,
            ]], array_merge(['principal_amount' => 100000, 'gold_rate_on_date' => 6000, 'created_by' => null], $params));
        });
    }

    private function attach(Shop $shop, DhiranLoan $loan, User $by): DhiranAttachment
    {
        return TenantContext::runFor($shop->id, fn () => app(DhiranAttachmentService::class)->store(
            UploadedFile::fake()->image('id.jpg', 200, 200),
            $shop->id, DhiranAttachment::OWNER_LOAN, $loan->id, 'id_proof_front', $by->id
        ));
    }

    // ── E1 Aadhaar masking ──────────────────────────────────────

    public function test_aadhaar_helper_masks_to_last4(): void
    {
        $this->assertSame('XXXX-XXXX-1234', AadhaarMask::mask('123412341234'));
        $this->assertSame('XXXX-XXXX-1234', AadhaarMask::mask('1234 1234 1234'));
        $this->assertSame('XXXX-XXXX-1234', AadhaarMask::mask('XXXX-XXXX-1234'));
        $this->assertNull(AadhaarMask::mask(''));
        $this->assertNull(AadhaarMask::mask('12'));
    }

    public function test_loan_create_stores_only_masked_aadhaar(): void
    {
        [$shop, $owner] = $this->dhiranShopWithOwner('9390000080');
        $customer = TenantContext::runFor($shop->id, fn () => Customer::create(['shop_id' => $shop->id, 'first_name' => 'C', 'last_name' => 'O', 'mobile' => '9811110001']));

        $this->actingAs($owner)->post('https://dhiran.jewelflows.com/dhiran', [
            'customer_id' => $customer->id, 'principal_amount' => 10000, 'gold_rate_on_date' => 6000,
            'aadhaar' => '987698769876',
            'items' => [['description' => 'X', 'purity' => 22, 'gross_weight' => 50, 'rate_per_gram_at_pledge' => 6000]],
        ])->assertRedirect();

        $loan = DhiranLoan::withoutGlobalScope('shop')->where('shop_id', $shop->id)->latest('id')->first();
        $this->assertSame('XXXX-XXXX-9876', $loan->kyc_aadhaar);
        // Full Aadhaar must NOT be present anywhere in the row.
        $this->assertStringNotContainsString('987698769876', json_encode($loan->getAttributes()));
    }

    // ── E2/E3 attachment privacy + isolation ────────────────────

    public function test_upload_stores_on_private_disk_with_shop_id(): void
    {
        Storage::fake('local');
        [$shop, $owner] = $this->dhiranShopWithOwner('9390000081');
        $loan = $this->makeLoan($shop);

        $att = $this->attach($shop, $loan, $owner);

        $this->assertSame('local', $att->file_disk);
        $this->assertSame($shop->id, $att->shop_id);
        $this->assertStringStartsWith("dhiran/{$shop->id}/", $att->file_path);
        Storage::disk('local')->assertExists($att->file_path);
    }

    public function test_owner_can_view_own_attachment(): void
    {
        Storage::fake('local');
        [$shop, $owner] = $this->dhiranShopWithOwner('9390000082');
        $loan = $this->makeLoan($shop);
        $att = $this->attach($shop, $loan, $owner);

        // Drive the controller method under the owner's tenant context (the
        // {attachment} binding resolves under tenant middleware in the live app).
        $resp = TenantContext::runFor($shop->id, function () use ($owner, $att) {
            $this->actingAs($owner);
            return app(\App\Http\Controllers\DhiranController::class)
                ->showAttachment(\App\Models\Dhiran\DhiranAttachment::findOrFail($att->id));
        });
        $this->assertSame(200, $resp->getStatusCode());
    }

    public function test_other_dhiran_shop_cannot_view_attachment(): void
    {
        Storage::fake('local');
        [$shopA, $ownerA] = $this->dhiranShopWithOwner('9390000083');
        [$shopB, $ownerB] = $this->dhiranShopWithOwner('9390000084');
        $loanB = $this->makeLoan($shopB);
        $att = $this->attach($shopB, $loanB, $ownerB);

        // Shop A owner asking for Shop B's attachment must 404 (explicit shop check).
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        TenantContext::runFor($shopA->id, function () use ($ownerA, $att) {
            $this->actingAs($ownerA);
            // Fetch the row without scope to simulate a forged id reaching the action.
            $row = \App\Models\Dhiran\DhiranAttachment::withoutGlobalScope('shop')->findOrFail($att->id);
            app(\App\Http\Controllers\DhiranController::class)->showAttachment($row);
        });
    }

    public function test_erp_user_cannot_reach_dhiran_attachment_route(): void
    {
        Storage::fake('local');
        [$shop, $owner] = $this->dhiranShopWithOwner('9390000085');
        $loan = $this->makeLoan($shop);
        $att = $this->attach($shop, $loan, $owner);

        $erp = User::create(['mobile_number' => '9391000085', 'password' => bcrypt('x'), 'realm' => 'erp', 'is_active' => true]);
        // An ERP account is never served the Dhiran file — denied via the realm
        // gate (302 away) or the shop-scoped binding (404). Either way: not 200.
        $resp = $this->actingAs($erp)->get("https://dhiran.jewelflows.com/dhiran/attachments/{$att->id}");
        $this->assertContains($resp->getStatusCode(), [302, 403, 404]);
        $this->assertNotSame(200, $resp->getStatusCode());
    }

    public function test_attachment_file_not_public(): void
    {
        Storage::fake('local');
        [$shop, $owner] = $this->dhiranShopWithOwner('9390000086');
        $loan = $this->makeLoan($shop);
        $att = $this->attach($shop, $loan, $owner);

        // Stored on the private 'local' disk, not the public disk → no public URL.
        Storage::disk('local')->assertExists($att->file_path);
        Storage::fake('public');
        Storage::disk('public')->assertMissing($att->file_path);
    }

    public function test_unsupported_file_type_rejected(): void
    {
        Storage::fake('local');
        [$shop, $owner] = $this->dhiranShopWithOwner('9390000087');
        $loan = $this->makeLoan($shop);

        $this->expectException(\LogicException::class);
        TenantContext::runFor($shop->id, fn () => app(DhiranAttachmentService::class)->store(
            UploadedFile::fake()->create('evil.exe', 10, 'application/x-msdownload'),
            $shop->id, DhiranAttachment::OWNER_LOAN, $loan->id, 'loan_document', $owner->id
        ));
    }

    public function test_oversized_file_rejected(): void
    {
        Storage::fake('local');
        [$shop, $owner] = $this->dhiranShopWithOwner('9390000088');
        $loan = $this->makeLoan($shop);

        $this->expectException(\LogicException::class);
        TenantContext::runFor($shop->id, fn () => app(DhiranAttachmentService::class)->store(
            UploadedFile::fake()->create('big.pdf', 9000, 'application/pdf'), // 9 MB > 8 MB cap
            $shop->id, DhiranAttachment::OWNER_LOAN, $loan->id, 'loan_document', $owner->id
        ));
    }

    public function test_upload_route_rejects_cross_shop_owner_id(): void
    {
        Storage::fake('local');
        [$shopA, $ownerA] = $this->dhiranShopWithOwner('9390000089');
        [$shopB] = $this->dhiranShopWithOwner('9390000090');
        $loanB = $this->makeLoan($shopB);

        // Owner A tries to attach to Shop B's loan id → 404 (owner not in shop).
        $this->actingAs($ownerA)->post('https://dhiran.jewelflows.com/dhiran/attachments', [
            'owner_type' => 'dhiran_loan', 'owner_id' => $loanB->id, 'document_type' => 'loan_document',
            'file' => UploadedFile::fake()->image('x.jpg'),
        ])->assertNotFound();

        $this->assertSame(0, DhiranAttachment::withoutGlobalScope('shop')->where('owner_id', $loanB->id)->count());
    }

    // ── E4 evidence display ─────────────────────────────────────

    public function test_show_page_lists_evidence_links(): void
    {
        Storage::fake('local');
        [$shop, $owner] = $this->dhiranShopWithOwner('9390000091');
        $loan = $this->makeLoan($shop);
        $att = $this->attach($shop, $loan, $owner);

        $html = TenantContext::runFor($shop->id, function () use ($owner, $loan, $att) {
            $this->actingAs($owner);
            view()->share('errors', new \Illuminate\Support\ViewErrorBag);
            $fresh = DhiranLoan::with(['customer', 'items', 'payments'])->findOrFail($loan->id);
            return view('dhiran.show', ['loan' => $fresh, 'attachments' => collect([$att])])->render();
        });

        $this->assertStringContainsString('Evidence &amp; Documents', $html);
        $this->assertStringContainsString(route('dhiran.attachments.show', $att), $html);
        $this->assertStringContainsString('Id Proof Front', $html);
    }

    // ── E5 collateral wording / limits ──────────────────────────

    public function test_unsupported_collateral_type_is_rejected(): void
    {
        [$shop, $owner] = $this->dhiranShopWithOwner('9390000092');
        $customer = TenantContext::runFor($shop->id, fn () => Customer::create(['shop_id' => $shop->id, 'first_name' => 'C', 'last_name' => 'O', 'mobile' => '9811110002']));

        // metal_type=diamond is not in the gold/silver allow-list → validation error.
        $this->actingAs($owner)->post('https://dhiran.jewelflows.com/dhiran', [
            'customer_id' => $customer->id, 'principal_amount' => 10000, 'gold_rate_on_date' => 6000,
            'items' => [['description' => 'Ring', 'metal_type' => 'diamond', 'purity' => 22, 'gross_weight' => 50, 'rate_per_gram_at_pledge' => 6000]],
        ])->assertSessionHasErrors('items.0.metal_type');
    }

    public function test_loan_listing_uses_pledge_wording_not_gold_only(): void
    {
        [$shop, $owner] = $this->dhiranShopWithOwner('9390000093');

        $html = TenantContext::runFor($shop->id, function () use ($owner) {
            $this->actingAs($owner);
            view()->share('errors', new \Illuminate\Support\ViewErrorBag);
            return view('dhiran.loans', ['loans' => DhiranLoan::query()->paginate(20)])->render();
        });

        $this->assertStringContainsString('Pledge Loans', $html);
        $this->assertStringNotContainsString('>Gold Loans<', $html);
    }
}
