<?php

namespace Tests\Feature\Dhiran;

use App\Http\Controllers\DhiranController;
use App\Models\Customer;
use App\Models\Dhiran\DhiranAttachment;
use App\Models\Dhiran\DhiranLoan;
use App\Models\Dhiran\DhiranLoanItem;
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
 * Dhiran pledged-item detail page. Shop-scoped: other shops' / ERP items and
 * attachments must never appear.
 */
class DhiranItemDetailTest extends TestCase
{
    use RefreshDatabase;

    private DhiranService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(DhiranService::class);
    }

    /** @return array{0:Shop,1:User} */
    private function dhiranShop(string $mobile): array
    {
        $shop = Shop::create([
            'name' => 'Pawn Co', 'shop_type' => 'dhiran', 'phone' => $mobile,
            'owner_first_name' => 'O', 'owner_last_name' => 'W', 'owner_mobile' => $mobile,
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

    /** @return array{0:Customer,1:DhiranLoan,2:DhiranLoanItem} */
    private function loanWithItem(Shop $shop, User $owner, string $mobile): array
    {
        return TenantContext::runFor($shop->id, function () use ($shop, $owner, $mobile) {
            $c = Customer::create(['shop_id' => $shop->id, 'first_name' => 'Asha', 'last_name' => 'B', 'mobile' => $mobile]);
            $loan = $this->service->createLoan($shop, $c, [[
                'description' => 'Gold Chain', 'metal_type' => 'gold', 'purity' => 22,
                'gross_weight' => 50, 'rate_per_gram_at_pledge' => 6000,
            ]], ['principal_amount' => 100000, 'gold_rate_on_date' => 6000, 'created_by' => $owner->id]);
            return [$c, $loan, $loan->items->first()];
        });
    }

    private function render(Shop $shop, User $owner, DhiranLoanItem $item)
    {
        return TenantContext::runFor($shop->id, function () use ($owner, $item) {
            $this->actingAs($owner);
            view()->share('errors', new \Illuminate\Support\ViewErrorBag);
            return app(DhiranController::class)->itemDetail($item);
        });
    }

    // 1. Own-shop item detail loads + shows details + linked borrower + loan.
    public function test_own_shop_item_detail_loads(): void
    {
        [$shop, $owner] = $this->dhiranShop('9390002000');
        [$c, $loan, $item] = $this->loanWithItem($shop, $owner, '9812220001');
        $html = $this->render($shop, $owner, $item)->render();

        $this->assertStringContainsString('Gold Chain', $html);          // description
        $this->assertStringContainsString('Item details', $html);
        $this->assertStringContainsString($loan->loan_number, $html);    // linked loan (5)
        $this->assertStringContainsString('Asha B', $html);              // linked borrower (4)
        $this->assertStringContainsString('22K', $html);                 // purity rendered (trailing zeros stripped) (6)
        $this->assertStringContainsString('History', $html);             // history (6)
    }

    // 2. Cross-shop item returns 404.
    public function test_cross_shop_item_denied(): void
    {
        [$shopA, $ownerA] = $this->dhiranShop('9390002001');
        [$shopB, $ownerB] = $this->dhiranShop('9390002002');
        [, , $itemB] = $this->loanWithItem($shopB, $ownerB, '9812220002');

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        TenantContext::runFor($shopA->id, function () use ($ownerA, $itemB) {
            $this->actingAs($ownerA);
            $row = DhiranLoanItem::withoutGlobalScope('shop')->findOrFail($itemB->id);
            app(DhiranController::class)->itemDetail($row);
        });
    }

    // 3. ERP user cannot reach the Dhiran item detail route (realm bounce / deny).
    public function test_erp_user_cannot_reach_item_route(): void
    {
        [$shop, $owner] = $this->dhiranShop('9390002003');
        [, , $item] = $this->loanWithItem($shop, $owner, '9812220003');
        $erp = User::create(['mobile_number' => '9390002099', 'password' => bcrypt('x'), 'realm' => 'erp', 'is_active' => true]);

        $resp = $this->actingAs($erp)->get("https://dhiran.jewelflows.com/dhiran/items/{$item->id}");
        $this->assertContains($resp->getStatusCode(), [302, 403, 404]);
        $this->assertNotSame(200, $resp->getStatusCode());
    }

    // 6+7. Stored valuation fields + release/forfeit status.
    public function test_shows_valuation_and_status(): void
    {
        [$shop, $owner] = $this->dhiranShop('9390002004');
        [$c, $loan, $item] = $this->loanWithItem($shop, $owner, '9812220004');
        $html = $this->render($shop, $owner, $item)->render();

        $this->assertStringContainsString('Market value', $html);
        $this->assertStringContainsString('Loan value', $html);
        $this->assertStringContainsString('Fine weight', $html);
        // default pledged status banner not shown, but status field present
        $this->assertStringContainsString('Pledged', $html);
    }

    // 8. Loan show links to item detail.
    public function test_loan_show_links_to_item(): void
    {
        [$shop, $owner] = $this->dhiranShop('9390002005');
        [$c, $loan, $item] = $this->loanWithItem($shop, $owner, '9812220005');
        $html = TenantContext::runFor($shop->id, function () use ($owner, $loan) {
            $this->actingAs($owner);
            view()->share('errors', new \Illuminate\Support\ViewErrorBag);
            $fresh = DhiranLoan::with(['customer', 'items', 'payments'])->findOrFail($loan->id);
            $ev = $this->service->evidenceStatus($fresh);
            return view('dhiran.show', ['loan' => $fresh, 'attachments' => collect(), 'evidence' => $ev])->render();
        });
        $this->assertStringContainsString(route('dhiran.items.show', $item), $html);
    }

    // 9. Borrower profile links to item detail.
    public function test_borrower_profile_links_to_item(): void
    {
        [$shop, $owner] = $this->dhiranShop('9390002006');
        [$c, $loan, $item] = $this->loanWithItem($shop, $owner, '9812220006');
        $html = TenantContext::runFor($shop->id, function () use ($owner, $c) {
            $this->actingAs($owner);
            view()->share('errors', new \Illuminate\Support\ViewErrorBag);
            return app(DhiranController::class)->borrowerProfile(Customer::findOrFail($c->id))->render();
        });
        $this->assertStringContainsString(route('dhiran.items.show', $item), $html);
    }

    // 10+11. Evidence links use the protected attachment route; no public path.
    public function test_item_evidence_uses_protected_route(): void
    {
        Storage::fake('local');
        [$shop, $owner] = $this->dhiranShop('9390002007');
        [$c, $loan, $item] = $this->loanWithItem($shop, $owner, '9812220007');
        $att = TenantContext::runFor($shop->id, fn () => app(DhiranAttachmentService::class)->store(
            UploadedFile::fake()->image('p.jpg', 80, 80), $shop->id, DhiranAttachment::OWNER_ITEM, $item->id, 'item_photo', $owner->id
        ));
        $html = $this->render($shop, $owner, $item)->render();
        $this->assertStringContainsString(route('dhiran.attachments.show', $att), $html);
        $this->assertStringNotContainsString('/storage/dhiran', $html);
    }

    // 12. Item-level upload works (owner_type=dhiran_loan_item).
    public function test_item_level_upload_works(): void
    {
        Storage::fake('local');
        [$shop, $owner] = $this->dhiranShop('9390002008');
        [$c, $loan, $item] = $this->loanWithItem($shop, $owner, '9812220008');

        $this->actingAs($owner);
        TenantContext::runFor($shop->id, function () use ($shop, $item) {
            $req = \Illuminate\Http\Request::create('https://dhiran.jewelflows.com/dhiran/attachments', 'POST', [
                'owner_type' => 'dhiran_loan_item',
                'owner_id' => $item->id,
                'document_type' => 'item_photo',
            ], [], ['file' => UploadedFile::fake()->image('x.png', 60, 60)]);
            $this->app->instance('request', $req);
            app(DhiranController::class)->storeAttachment($req);
        });

        $this->assertDatabaseHas('dhiran_attachments', [
            'shop_id' => $shop->id,
            'owner_type' => 'dhiran_loan_item',
            'owner_id' => $item->id,
            'document_type' => 'item_photo',
            'file_disk' => 'local',
        ]);
    }

    // 13. Cross-shop attachment still denied (item detail doesn't weaken isolation).
    public function test_cross_shop_attachment_denied(): void
    {
        Storage::fake('local');
        [$shopA, $ownerA] = $this->dhiranShop('9390002009');
        [$shopB, $ownerB] = $this->dhiranShop('9390002010');
        [, , $itemB] = $this->loanWithItem($shopB, $ownerB, '9812220009');
        $att = TenantContext::runFor($shopB->id, fn () => app(DhiranAttachmentService::class)->store(
            UploadedFile::fake()->image('p.jpg', 80, 80), $shopB->id, DhiranAttachment::OWNER_ITEM, $itemB->id, 'item_photo', $ownerB->id
        ));

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        TenantContext::runFor($shopA->id, function () use ($ownerA, $att) {
            $this->actingAs($ownerA);
            $row = DhiranAttachment::withoutGlobalScope('shop')->findOrFail($att->id);
            app(DhiranController::class)->showAttachment($row);
        });
    }
}
