<?php

namespace Tests\Feature\Customer;

use App\Models\Customer;
use App\Models\KycDocument;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Shop;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Customer Management — feature / security / privacy (Module 6). Closes gaps the
 * existing suites leave open: create-side shop_id forge, search shop-scoping,
 * cross-shop profile/KYC isolation, permission gating, and the Aadhaar-masking
 * privacy regression (full Aadhaar must not render to a customers.view user; the
 * verify form is gated to customers.edit).
 */
class CustomerManagementTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestTenant;

    private const ERP = 'https://jewelflows.com';

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        $this->withoutMiddleware([
            \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
            \Illuminate\Routing\Middleware\ThrottleRequests::class,
        ]);
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

    private function customer(Shop $shop, array $attrs = []): Customer
    {
        return TenantContext::runFor($shop->id, fn () => Customer::create(array_merge([
            'shop_id' => $shop->id, 'first_name' => 'Asha', 'last_name' => 'B', 'mobile' => '9812300001',
        ], $attrs)));
    }

    // ── Create: shop_id forge ──────────────────────────────────────────────

    public function test_create_forces_auth_shop_id_ignoring_request_body(): void
    {
        [, $shopA] = $this->createRetailerTenant();
        [, $shopB] = $this->createRetailerTenant();
        $owner = $this->userWithPerms($shopA, ['customers.view', 'customers.create'], '9812300010');

        TenantContext::runFor($shopA->id, fn () => $this->actingAs($owner)
            ->post(self::ERP . '/customers', [
                'first_name' => 'New', 'last_name' => 'Cust', 'mobile' => '9812300011',
                'shop_id' => $shopB->id, // injected
            ]))->assertRedirect();

        $created = Customer::withoutGlobalScopes()->where('mobile', '9812300011')->first();
        $this->assertNotNull($created);
        $this->assertSame($shopA->id, $created->shop_id, 'shop_id comes from auth, never request body');
    }

    public function test_create_validates_required_fields(): void
    {
        [, $shop] = $this->createRetailerTenant();
        $owner = $this->userWithPerms($shop, ['customers.view', 'customers.create'], '9812300012');

        $res = TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)
            ->post(self::ERP . '/customers', ['first_name' => '', 'mobile' => '123']));

        $res->assertSessionHasErrors();
    }

    // ── Cross-shop isolation ───────────────────────────────────────────────

    public function test_cannot_view_another_shops_customer(): void
    {
        [, $shopA] = $this->createRetailerTenant();
        [, $shopB] = $this->createRetailerTenant();
        $custB = $this->customer($shopB, ['mobile' => '9812300020']);
        $ownerA = $this->userWithPerms($shopA, ['customers.view'], '9812300021');

        $res = TenantContext::runFor($shopA->id, fn () => $this->actingAs($ownerA)
            ->get(self::ERP . '/customers/' . $custB->id));

        $this->assertContains($res->getStatusCode(), [403, 404], 'cross-shop customer must not be viewable');
    }

    public function test_search_suggestions_are_shop_scoped(): void
    {
        [, $shopA] = $this->createRetailerTenant();
        [, $shopB] = $this->createRetailerTenant();
        $this->customer($shopA, ['first_name' => 'Aarav', 'mobile' => '9812300030']);
        $this->customer($shopB, ['first_name' => 'AaravB', 'mobile' => '9812300031']);
        $userA = $this->userWithPerms($shopA, ['inventory.view'], '9812300032');

        $res = TenantContext::runFor($shopA->id, fn () => $this->actingAs($userA)
            ->getJson(self::ERP . '/search/suggestions?q=Aarav&type=customers'));

        $res->assertOk();
        $body = $res->getContent();
        $this->assertStringContainsString('Aarav', $body);
        $this->assertStringNotContainsString('9812300031', $body, 'must not surface another shop customer');
    }

    // ── Permission gating ──────────────────────────────────────────────────

    public function test_user_without_customers_view_is_denied(): void
    {
        [, $shop] = $this->createRetailerTenant();
        $noPerm = $this->userWithPerms($shop, ['sales.pos'], '9812300040'); // no customers.*

        TenantContext::runFor($shop->id, fn () => $this->actingAs($noPerm)
            ->get(self::ERP . '/customers'))->assertForbidden();
    }

    public function test_guest_cannot_reach_customers(): void
    {
        $res = $this->get(self::ERP . '/customers');
        $res->assertRedirect();
        $this->assertStringContainsString('/login', (string) $res->headers->get('Location'));
    }

    // ── KYC document isolation / privacy ───────────────────────────────────

    public function test_cannot_fetch_another_shops_kyc_document(): void
    {
        Storage::fake('local');
        [, $shopA] = $this->createRetailerTenant();
        [, $shopB] = $this->createRetailerTenant();
        $custB = $this->customer($shopB, ['mobile' => '9812300050']);

        $docB = TenantContext::runFor($shopB->id, fn () => KycDocument::create([
            'shop_id' => $shopB->id, 'customer_id' => $custB->id,
            'document_type' => 'pan_card', 'file_disk' => 'local', 'file_path' => 'kyc/secret-b.jpg',
        ]));
        Storage::disk('local')->put('kyc/secret-b.jpg', 'x');

        $ownerA = $this->userWithPerms($shopA, ['customers.view'], '9812300051');

        $res = TenantContext::runFor($shopA->id, fn () => $this->actingAs($ownerA)
            ->get(self::ERP . '/kyc-documents/' . $docB->id . '/file'));

        // Denied either way: KycDocument's BelongsToShop scope 404s the cross-shop
        // doc at route binding (even safer than the controller's 403 guard).
        $this->assertContains($res->getStatusCode(), [403, 404], 'cross-shop KYC doc must not be fetchable');
    }

    // ── Aadhaar masking privacy (regression for this module's fix) ─────────

    private function verifiedCustomer(Shop $shop): Customer
    {
        return $this->customer($shop, [
            'mobile' => '9812300060', 'pan' => 'ABCDE1234F',
            'id_number' => '123412341234', // full Aadhaar persisted (compliance flow)
            'compliance_verified_at' => now(),
        ]);
    }

    public function test_view_only_user_sees_masked_aadhaar_and_no_verify_form(): void
    {
        [, $shop] = $this->createRetailerTenant();
        $cust = $this->verifiedCustomer($shop);
        $viewer = $this->userWithPerms($shop, ['customers.view'], '9812300061');

        $html = TenantContext::runFor($shop->id, fn () => $this->actingAs($viewer)
            ->get(self::ERP . '/customers/' . $cust->id))->assertOk()->getContent();

        // Full Aadhaar must never render; masked form must.
        $this->assertStringNotContainsString('123412341234', $html, 'full Aadhaar must not be exposed');
        $this->assertStringContainsString('XXXX-XXXX-1234', $html, 'masked Aadhaar shown instead');
        // The verify form (with the editable full-Aadhaar input) is gated to edit.
        $this->assertStringNotContainsString('name="aadhaar"', $html, 'view-only user must not see the editable Aadhaar input');
    }

    public function test_editor_sees_the_verify_form(): void
    {
        [, $shop] = $this->createRetailerTenant();
        $cust = $this->verifiedCustomer($shop);
        $editor = $this->userWithPerms($shop, ['customers.view', 'customers.edit'], '9812300062');

        $html = TenantContext::runFor($shop->id, fn () => $this->actingAs($editor)
            ->get(self::ERP . '/customers/' . $cust->id))->assertOk()->getContent();

        $this->assertStringContainsString('name="aadhaar"', $html, 'editor sees the verify-compliance form');
        // The read-only display is still masked even for the editor.
        $this->assertStringContainsString('XXXX-XXXX-1234', $html);
    }
}
