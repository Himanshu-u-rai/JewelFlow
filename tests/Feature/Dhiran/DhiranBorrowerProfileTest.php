<?php

namespace Tests\Feature\Dhiran;

use App\Http\Controllers\DhiranController;
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
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Dhiran borrower profile + borrowers index. Shop-scoped: ERP customers and
 * other Dhiran shops' borrowers must never appear.
 */
class DhiranBorrowerProfileTest extends TestCase
{
    use RefreshDatabase;

    private DhiranService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(DhiranService::class);
    }

    /** @return array{0:Shop,1:User} */
    private function dhiranShop(string $mobile, string $name = 'Pawn Co'): array
    {
        $shop = Shop::create([
            'name' => $name, 'shop_type' => 'dhiran', 'phone' => $mobile,
            'owner_first_name' => 'O', 'owner_last_name' => 'W', 'owner_mobile' => $mobile,
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

    /** Create a borrower + one loan in the given shop. */
    private function borrowerWithLoan(Shop $shop, User $owner, string $first, string $mobile, string $status = 'active'): array
    {
        return TenantContext::runFor($shop->id, function () use ($shop, $owner, $first, $mobile, $status) {
            $c = Customer::create(['shop_id' => $shop->id, 'first_name' => $first, 'last_name' => 'Borrower', 'mobile' => $mobile]);
            $loan = $this->service->createLoan($shop, $c, [[
                'description' => 'Chain', 'metal_type' => 'gold', 'purity' => 22,
                'gross_weight' => 50, 'rate_per_gram_at_pledge' => 6000,
            ]], ['principal_amount' => 100000, 'gold_rate_on_date' => 6000, 'created_by' => $owner->id, 'status' => $status]);
            return [$c, $loan];
        });
    }

    private function profile(Shop $shop, User $owner, int $customerId)
    {
        return TenantContext::runFor($shop->id, function () use ($owner, $customerId) {
            $this->actingAs($owner);
            view()->share('errors', new \Illuminate\Support\ViewErrorBag);
            $c = Customer::findOrFail($customerId);
            return app(DhiranController::class)->borrowerProfile($c);
        });
    }

    // 1. Borrowers nav link exists (rendered in the shell).
    public function test_borrowers_nav_link_exists(): void
    {
        [$shop, $owner] = $this->dhiranShop('9390001000');
        $this->borrowerWithLoan($shop, $owner, 'Asha', '9811110001');
        $html = TenantContext::runFor($shop->id, function () use ($owner) {
            $this->actingAs($owner);
            $req = Request::create('https://dhiran.jewelflows.com/dhiran/borrowers', 'GET');
            $this->app->instance('request', $req);
            view()->share('errors', new \Illuminate\Support\ViewErrorBag);
            return app(DhiranController::class)->borrowers($req)->render();
        });
        $this->assertStringContainsString('Borrowers', $html);
        $this->assertStringContainsString(route('dhiran.borrowers.index'), $html);
    }

    // 2+3+4. Index lists only this shop's borrowers; not ERP, not other Dhiran shop.
    public function test_index_lists_only_current_shop_borrowers(): void
    {
        [$shopA, $ownerA] = $this->dhiranShop('9390001001', 'Shop A');
        [$shopB, $ownerB] = $this->dhiranShop('9390001002', 'Shop B');
        [$ca] = $this->borrowerWithLoan($shopA, $ownerA, 'AshaA', '9811110011');
        [$cb] = $this->borrowerWithLoan($shopB, $ownerB, 'BobB', '9811110012');
        // an ERP customer in some retailer shop (no dhiran loan)
        $erpShop = Shop::create(['name' => 'ERP', 'shop_type' => 'retailer', 'phone' => '9000', 'owner_first_name' => 'E', 'owner_last_name' => 'R', 'owner_mobile' => '9000', 'country' => 'India', 'gst_rate' => 3, 'wastage_recovery_percent' => 0]);
        TenantContext::runFor($erpShop->id, fn () => Customer::create(['shop_id' => $erpShop->id, 'first_name' => 'ErpCust', 'last_name' => 'X', 'mobile' => '9811110099']));

        $html = TenantContext::runFor($shopA->id, function () use ($ownerA) {
            $this->actingAs($ownerA);
            $req = Request::create('https://dhiran.jewelflows.com/dhiran/borrowers', 'GET');
            $this->app->instance('request', $req);
            view()->share('errors', new \Illuminate\Support\ViewErrorBag);
            return app(DhiranController::class)->borrowers($req)->render();
        });
        $this->assertStringContainsString('AshaA', $html);     // own shop
        $this->assertStringNotContainsString('BobB', $html);   // other dhiran shop
        $this->assertStringNotContainsString('ErpCust', $html); // ERP customer
    }

    // 5. Profile opens for own-shop borrower.
    public function test_profile_opens_for_own_shop_borrower(): void
    {
        [$shop, $owner] = $this->dhiranShop('9390001003');
        [$c] = $this->borrowerWithLoan($shop, $owner, 'Asha', '9811110021');
        $html = $this->profile($shop, $owner, $c->id)->render();
        $this->assertStringContainsString('Asha Borrower', $html);
        $this->assertStringContainsString('Borrower details', $html);
    }

    // 6. Profile rejects ERP shop customer.
    public function test_profile_rejects_erp_customer(): void
    {
        [$shop, $owner] = $this->dhiranShop('9390001004');
        $erpShop = Shop::create(['name' => 'ERP', 'shop_type' => 'retailer', 'phone' => '9001', 'owner_first_name' => 'E', 'owner_last_name' => 'R', 'owner_mobile' => '9001', 'country' => 'India', 'gst_rate' => 3, 'wastage_recovery_percent' => 0]);
        $erpCust = TenantContext::runFor($erpShop->id, fn () => Customer::create(['shop_id' => $erpShop->id, 'first_name' => 'Erp', 'last_name' => 'Cust', 'mobile' => '9811110031']));

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        TenantContext::runFor($shop->id, function () use ($owner, $erpCust) {
            $this->actingAs($owner);
            $row = Customer::withoutGlobalScope('shop')->findOrFail($erpCust->id);
            app(DhiranController::class)->borrowerProfile($row);
        });
    }

    // 7. Profile rejects another Dhiran shop's customer.
    public function test_profile_rejects_other_dhiran_shop_customer(): void
    {
        [$shopA, $ownerA] = $this->dhiranShop('9390001005', 'A');
        [$shopB, $ownerB] = $this->dhiranShop('9390001006', 'B');
        [$cb] = $this->borrowerWithLoan($shopB, $ownerB, 'Bob', '9811110041');

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        TenantContext::runFor($shopA->id, function () use ($ownerA, $cb) {
            $this->actingAs($ownerA);
            $row = Customer::withoutGlobalScope('shop')->findOrFail($cb->id);
            app(DhiranController::class)->borrowerProfile($row);
        });
    }

    // 8+9+10. Profile shows basic details + only this borrower's loans + pending_evidence.
    public function test_profile_shows_details_and_own_loans(): void
    {
        [$shop, $owner] = $this->dhiranShop('9390001007');
        [$c, $loanA] = $this->borrowerWithLoan($shop, $owner, 'Asha', '9811110051', 'pending_evidence');
        // a second borrower with a loan — must NOT appear on Asha's profile
        [$c2, $loanB] = $this->borrowerWithLoan($shop, $owner, 'Other', '9811110052');

        $html = $this->profile($shop, $owner, $c->id)->render();
        $this->assertStringContainsString($loanA->loan_number, $html);
        $this->assertStringNotContainsString($loanB->loan_number, $html);
        $this->assertStringContainsString('Awaiting Evidence', $html); // pending_evidence shown
        $this->assertStringContainsString('9811110051', $html);        // mobile
    }

    // 11. Payments shown for this borrower only.
    public function test_profile_payments_scoped(): void
    {
        [$shop, $owner] = $this->dhiranShop('9390001008');
        [$c, $loan] = $this->borrowerWithLoan($shop, $owner, 'Asha', '9811110061');
        // record a payment on Asha's loan
        TenantContext::runFor($shop->id, fn () => $this->service->recordPayment($loan->fresh(), 1000.0, 'cash'));

        $resp = $this->profile($shop, $owner, $c->id);
        $payments = $resp->getData()['payments'];
        $this->assertGreaterThanOrEqual(1, $payments->count());
        foreach ($payments as $p) {
            $this->assertSame($loan->id, $p->dhiran_loan_id);
        }
    }

    // 12. Pledged items shown for this borrower only.
    public function test_profile_items_scoped(): void
    {
        [$shop, $owner] = $this->dhiranShop('9390001009');
        [$c, $loan] = $this->borrowerWithLoan($shop, $owner, 'Asha', '9811110071');
        $html = $this->profile($shop, $owner, $c->id)->render();
        $this->assertStringContainsString('Pledged items', $html);
        $this->assertStringContainsString($loan->loan_number, $html);
    }

    // 13. Document links use the protected, shop-scoped attachment route.
    public function test_profile_documents_use_protected_route(): void
    {
        Storage::fake('local');
        [$shop, $owner] = $this->dhiranShop('9390001010');
        [$c, $loan] = $this->borrowerWithLoan($shop, $owner, 'Asha', '9811110081');
        $att = TenantContext::runFor($shop->id, fn () => app(DhiranAttachmentService::class)->store(
            UploadedFile::fake()->image('id.jpg', 80, 80), $shop->id, DhiranAttachment::OWNER_LOAN, $loan->id, 'id_proof_front', $owner->id
        ));
        $html = $this->profile($shop, $owner, $c->id)->render();
        $this->assertStringContainsString(route('dhiran.attachments.show', $att), $html);
        // no public storage path leaked
        $this->assertStringNotContainsString('/storage/dhiran', $html);
    }

    // 14. New Loan for borrower preselects the borrower.
    public function test_new_loan_preselects_borrower(): void
    {
        [$shop, $owner] = $this->dhiranShop('9390001011');
        [$c] = $this->borrowerWithLoan($shop, $owner, 'Asha', '9811110091');
        $html = TenantContext::runFor($shop->id, function () use ($owner, $c) {
            $this->actingAs($owner);
            $req = Request::create('https://dhiran.jewelflows.com/dhiran/create', 'GET', ['customer_id' => $c->id]);
            $this->app->instance('request', $req);
            view()->share('errors', new \Illuminate\Support\ViewErrorBag);
            return app(DhiranController::class)->create($req)->render();
        });
        // the Alpine form should seed customer_id with this borrower
        $this->assertStringContainsString("customer_id: '{$c->id}'", $html);
    }

    // 15. Full Aadhaar is never displayed (only masked).
    public function test_profile_never_shows_full_aadhaar(): void
    {
        [$shop, $owner] = $this->dhiranShop('9390001012');
        // create a loan via controller store so masking applies
        $c = TenantContext::runFor($shop->id, fn () => Customer::create(['shop_id' => $shop->id, 'first_name' => 'Asha', 'last_name' => 'B', 'mobile' => '9811110101']));
        $this->actingAs($owner)->post('https://dhiran.jewelflows.com/dhiran', [
            'customer_id' => $c->id, 'principal_amount' => 10000, 'gold_rate_on_date' => 6000,
            'aadhaar' => '123456789012',
            'items' => [['description' => 'X', 'purity' => 22, 'gross_weight' => 50, 'rate_per_gram_at_pledge' => 6000]],
        ]);
        $html = $this->profile($shop, $owner, $c->id)->render();
        $this->assertStringNotContainsString('123456789012', $html);     // full aadhaar absent
        $this->assertStringContainsString('XXXX-XXXX-9012', $html);       // masked present
    }
}
