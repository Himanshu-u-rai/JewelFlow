<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\Mobile\CustomerController;
use App\Models\CustomerGoldTransaction;
use App\Models\KycDocument;
use App\Models\LoyaltyTransaction;
use App\Models\Shop;
use App\Models\User;
use App\Services\KycDocumentService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Mobile customer context — additive personal fields, retailer loyalty history,
 * manufacturer gold balance/transactions.
 *
 * The controller method is invoked directly within TenantContext. No seeded/
 * authenticated users are needed: only a shop (for its edition + scoping) and an
 * in-memory User carrier that supplies shop_id / shop to the request.
 */
class CustomerContextTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    /** In-memory (unsaved) user carrying the shop context the controller reads. */
    private function userFor(Shop $shop): User
    {
        $user = new User();
        $user->forceFill(['shop_id' => $shop->id]);
        $user->setRelation('shop', $shop);

        return $user;
    }

    /** Invoke CustomerController@context and return the JSON payload as an array. */
    private function context(Shop $shop, $customer): array
    {
        $request = Request::create('/api/mobile/customers/' . $customer->id . '/context', 'GET');
        $request->setUserResolver(fn () => $this->userFor($shop));

        return TenantContext::runFor($shop->id, fn () => app(CustomerController::class)
            ->context($customer, $request)->getData(true));
    }

    public function test_additive_personal_fields_present_and_existing_unchanged(): void
    {
        $shop = $this->createShop('retailer');
        $customer = $this->createCustomer($shop->id, [
            'email' => 'asha@example.com',
            'address' => '12 MG Road',
            'date_of_birth' => '1990-05-01',
            'anniversary_date' => '2015-02-14',
            'wedding_date' => '2015-02-14',
            'notes' => 'Prefers gold',
        ]);

        $data = $this->context($shop, $customer);

        // Existing fields unchanged.
        $this->assertSame($customer->id, $data['customer']['id']);
        $this->assertSame($customer->mobile, $data['customer']['mobile']);
        $this->assertArrayHasKey('customer_code', $data['customer']);
        $this->assertArrayHasKey('loyalty_points', $data['customer']);
        $this->assertArrayHasKey('summary', $data);
        $this->assertArrayHasKey('timeline', $data);

        // Additive personal fields.
        $this->assertSame('asha@example.com', $data['customer']['email']);
        $this->assertSame('12 MG Road', $data['customer']['address']);
        $this->assertSame('1990-05-01', $data['customer']['date_of_birth']);
        $this->assertSame('2015-02-14', $data['customer']['anniversary_date']);
        $this->assertSame('2015-02-14', $data['customer']['wedding_date']);
        $this->assertSame('Prefers gold', $data['customer']['notes']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', (string) $data['customer']['member_since']);

        // Compliance status block (new customer → no PAN yet).
        $this->assertSame('missing_pan', $data['compliance']['status']);
        $this->assertNull($data['compliance']['verified_at']);
    }

    public function test_null_personal_fields_serialize_as_null(): void
    {
        $shop = $this->createShop('retailer');
        $customer = $this->createCustomer($shop->id); // no optional fields

        $data = $this->context($shop, $customer);

        $this->assertNull($data['customer']['date_of_birth']);
        $this->assertNull($data['customer']['anniversary_date']);
        $this->assertNull($data['customer']['wedding_date']);
    }

    public function test_retailer_returns_loyalty_history_and_no_gold(): void
    {
        $shop = $this->createShop('retailer');
        $customer = $this->createCustomer($shop->id);

        TenantContext::runFor($shop->id, fn () => LoyaltyTransaction::create([
            'customer_id' => $customer->id,
            'invoice_id' => null,
            'type' => 'earn',
            'points' => 50,
            'balance_after' => 350,
            'description' => 'Invoice #INV-0042',
        ]));

        $data = $this->context($shop, $customer);

        $this->assertCount(1, $data['loyalty_history']);
        $this->assertSame('earn', $data['loyalty_history'][0]['type']);
        $this->assertSame(50, $data['loyalty_history'][0]['points']);
        $this->assertSame(350, $data['loyalty_history'][0]['balance_after']);
        $this->assertSame('Invoice #INV-0042', $data['loyalty_history'][0]['description']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', (string) $data['loyalty_history'][0]['date']);

        // Manufacturer-only keys are empty/null for a retailer.
        $this->assertNull($data['gold_balance_grams']);
        $this->assertSame([], $data['gold_transactions']);
    }

    public function test_manufacturer_returns_gold_and_no_loyalty(): void
    {
        $shop = $this->createShop('manufacturer');
        $customer = $this->createCustomer($shop->id);

        TenantContext::runFor($shop->id, function () use ($customer) {
            CustomerGoldTransaction::record([
                'customer_id' => $customer->id,
                'fine_gold' => 10.5,
                'type' => 'advance',
                'created_at' => now()->subDay(),
                'updated_at' => now()->subDay(),
            ]);
            CustomerGoldTransaction::record([
                'customer_id' => $customer->id,
                'fine_gold' => -2.0,
                'type' => 'sale_offset',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $data = $this->context($shop, $customer);

        // Balance = 10.5 − 2.0 = 8.5; newest-first ordering.
        $this->assertEqualsWithDelta(8.5, $data['gold_balance_grams'], 0.0001);
        $this->assertCount(2, $data['gold_transactions']);
        $this->assertSame('debit', $data['gold_transactions'][0]['type']);
        $this->assertEqualsWithDelta(2.0, $data['gold_transactions'][0]['fine_gold_grams'], 0.0001);
        $this->assertSame('credit', $data['gold_transactions'][1]['type']);
        $this->assertEqualsWithDelta(10.5, $data['gold_transactions'][1]['fine_gold_grams'], 0.0001);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', (string) $data['gold_transactions'][0]['date']);

        // Retailer-only key is empty for a manufacturer.
        $this->assertSame([], $data['loyalty_history']);
    }

    /** Invoke CustomerController@update and return [status, payload]. */
    private function update(Shop $shop, $customer, array $payload): array
    {
        $request = Request::create('/api/mobile/customers/' . $customer->id, 'PUT', $payload);
        $request->setUserResolver(fn () => $this->userFor($shop));

        $response = TenantContext::runFor($shop->id, fn () => app(CustomerController::class)
            ->update($customer, $request));

        return [$response->getStatusCode(), $response->getData(true)];
    }

    public function test_update_changes_fields_and_returns_context_profile_shape(): void
    {
        $shop = $this->createShop('retailer');
        $customer = $this->createCustomer($shop->id, ['first_name' => 'Old', 'mobile' => '9000000001']);

        [$status, $body] = $this->update($shop, $customer, [
            'first_name' => 'Asha',
            'last_name' => 'Verma',
            'mobile' => '9876543210',
            'email' => 'asha@example.com',
            'address' => '12 MG Road',
            'date_of_birth' => '1990-05-01',
            'anniversary_date' => '2015-02-14',
            'wedding_date' => '2015-02-14',
            'notes' => 'VIP',
        ]);

        $this->assertSame(200, $status);
        // Same shape as the context endpoint's customer object.
        $this->assertSame(
            ['id', 'first_name', 'last_name', 'name', 'mobile', 'customer_code', 'loyalty_points',
             'email', 'address', 'date_of_birth', 'anniversary_date', 'wedding_date', 'notes', 'member_since'],
            array_keys($body['customer'])
        );
        $this->assertSame('Asha', $body['customer']['first_name']);
        $this->assertSame('Verma', $body['customer']['last_name']);
        $this->assertSame('9876543210', $body['customer']['mobile']);
        $this->assertSame('asha@example.com', $body['customer']['email']);
        $this->assertSame('1990-05-01', $body['customer']['date_of_birth']);

        $this->assertDatabaseHas('customers', [
            'id' => $customer->id, 'first_name' => 'Asha', 'mobile' => '9876543210',
        ]);
    }

    public function test_update_allows_keeping_own_mobile_unique_ignores_current(): void
    {
        $shop = $this->createShop('retailer');
        $customer = $this->createCustomer($shop->id, ['mobile' => '9000000002']);

        [$status] = $this->update($shop, $customer, [
            'first_name' => 'Same Mobile',
            'mobile' => '9000000002', // unchanged — must be allowed (ignore current)
        ]);

        $this->assertSame(200, $status);
    }

    public function test_update_requires_first_name(): void
    {
        $shop = $this->createShop('retailer');
        $customer = $this->createCustomer($shop->id);

        $this->expectException(ValidationException::class);
        $this->update($shop, $customer, ['mobile' => '9876543210']);
    }

    public function test_update_requires_ten_digit_mobile(): void
    {
        $shop = $this->createShop('retailer');
        $customer = $this->createCustomer($shop->id);

        $this->expectException(ValidationException::class);
        $this->update($shop, $customer, ['first_name' => 'Asha', 'mobile' => '123']);
    }

    public function test_update_rejects_other_shops_customer(): void
    {
        $shopA = $this->createShop('retailer');
        $shopB = $this->createShop('retailer');
        $customerB = $this->createCustomer($shopB->id);

        // Acting as shop A, updating shop B's customer → 404, no change.
        [$status, $body] = $this->update($shopA, $customerB, ['first_name' => 'Hacker', 'mobile' => '9876543210']);

        $this->assertSame(404, $status);
        $this->assertSame('Customer not found.', $body['message']);
        $this->assertDatabaseMissing('customers', ['id' => $customerB->id, 'first_name' => 'Hacker']);
    }

    /**
     * Invoke verifyCompliance. The verify action records who verified
     * (compliance_verified_by FK → users), so a real staff user is required here.
     */
    private function verifyCompliance(Shop $shop, $customer, array $payload): array
    {
        $staff = User::factory()->create(['shop_id' => $shop->id]);
        $staff->setRelation('shop', $shop);

        $request = Request::create('/api/mobile/customers/' . $customer->id . '/verify-compliance', 'POST', $payload);
        $request->setUserResolver(fn () => $staff);

        $response = TenantContext::runFor($shop->id, fn () => app(CustomerController::class)
            ->verifyCompliance($customer, $request));

        return [$response->getStatusCode(), $response->getData(true)];
    }

    public function test_verify_compliance_marks_customer_compliant(): void
    {
        $shop = $this->createShop('retailer');
        $customer = $this->createCustomer($shop->id);

        [$status, $body] = $this->verifyCompliance($shop, $customer, [
            'pan' => 'ABCDE1234F', 'aadhaar' => '123412341234', 'consent' => '1',
        ]);

        $this->assertSame(200, $status);
        $this->assertSame('compliant', $body['compliance']['status']);
        $this->assertSame('ABCDE1234F', $body['compliance']['pan']);
        $this->assertSame('123412341234', $body['compliance']['aadhaar']); // stored in id_number
        $this->assertNotNull($body['compliance']['verified_at']);
        $this->assertArrayHasKey('customer', $body); // refreshed profile returned

        $fresh = $customer->fresh();
        $this->assertSame('ABCDE1234F', $fresh->pan);
        $this->assertSame('123412341234', $fresh->id_number); // Aadhaar persisted in id_number
        $this->assertNotNull($fresh->compliance_verified_at);
        $this->assertNotNull($fresh->consent_given_at);
    }

    public function test_verify_compliance_rejects_invalid_aadhaar(): void
    {
        $shop = $this->createShop('retailer');
        $customer = $this->createCustomer($shop->id);

        $this->expectException(ValidationException::class);
        $this->verifyCompliance($shop, $customer, ['aadhaar' => '12345', 'consent' => '1']); // not 12 digits
    }

    public function test_upload_kyc_document_stores_private_doc(): void
    {
        Storage::fake('local');
        $shop = $this->createShop('retailer');
        $staff = User::factory()->create(['shop_id' => $shop->id]);
        $staff->setRelation('shop', $shop);
        $customer = $this->createCustomer($shop->id);

        $request = Request::create(
            "/api/mobile/customers/{$customer->id}/kyc-documents",
            'POST',
            ['document_type' => 'aadhaar'],
            [],
            ['file' => UploadedFile::fake()->create('aadhaar.jpg', 200, 'image/jpeg')],
        );
        $request->setUserResolver(fn () => $staff);

        $response = TenantContext::runFor($shop->id, fn () => app(CustomerController::class)
            ->uploadKycDocument($customer, $request));

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame('aadhaar', $response->getData(true)['document_type']);

        $doc = TenantContext::runFor($shop->id, fn () => KycDocument::where('customer_id', $customer->id)->first());
        $this->assertNotNull($doc);
        $this->assertSame('local', $doc->file_disk, 'KYC must be on the private disk');
        Storage::disk('local')->assertExists($doc->file_path);
    }

    public function test_upload_kyc_document_rejects_other_shops_customer(): void
    {
        Storage::fake('local');
        $shopA = $this->createShop('retailer');
        $shopB = $this->createShop('retailer');
        $staffA = User::factory()->create(['shop_id' => $shopA->id]);
        $staffA->setRelation('shop', $shopA);
        $customerB = $this->createCustomer($shopB->id);

        $request = Request::create(
            "/api/mobile/customers/{$customerB->id}/kyc-documents",
            'POST',
            ['document_type' => 'aadhaar'],
            [],
            ['file' => UploadedFile::fake()->create('x.jpg', 100, 'image/jpeg')],
        );
        $request->setUserResolver(fn () => $staffA);

        $response = TenantContext::runFor($shopA->id, fn () => app(CustomerController::class)
            ->uploadKycDocument($customerB, $request));

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame(0, KycDocument::where('customer_id', $customerB->id)->count());
    }

    public function test_verify_compliance_requires_consent(): void
    {
        $shop = $this->createShop('retailer');
        $customer = $this->createCustomer($shop->id);

        $this->expectException(ValidationException::class);
        $this->verifyCompliance($shop, $customer, ['pan' => 'ABCDE1234F']); // consent missing
    }

    public function test_verify_compliance_rejects_invalid_pan(): void
    {
        $shop = $this->createShop('retailer');
        $customer = $this->createCustomer($shop->id);

        $this->expectException(ValidationException::class);
        $this->verifyCompliance($shop, $customer, ['pan' => 'NOTAPAN', 'consent' => '1']);
    }

    public function test_verify_compliance_rejects_other_shops_customer(): void
    {
        $shopA = $this->createShop('retailer');
        $shopB = $this->createShop('retailer');
        $customerB = $this->createCustomer($shopB->id);

        [$status] = $this->verifyCompliance($shopA, $customerB, ['pan' => 'ABCDE1234F', 'consent' => '1']);

        $this->assertSame(404, $status);
        $this->assertNull($customerB->fresh()->compliance_verified_at);
    }

    public function test_view_and_delete_kyc_document(): void
    {
        Storage::fake('local');
        $shop = $this->createShop('retailer');
        $staff = User::factory()->create(['shop_id' => $shop->id]);
        $staff->setRelation('shop', $shop);
        $customer = $this->createCustomer($shop->id);

        $doc = TenantContext::runFor($shop->id, fn () => app(KycDocumentService::class)->store(
            $customer,
            UploadedFile::fake()->create('aadhaar.jpg', 120, 'image/jpeg'),
            'aadhaar',
            null,
            (int) $staff->id,
        ));

        // View streams the file (200).
        $viewReq = Request::create("/api/mobile/customers/{$customer->id}/kyc-documents/{$doc->id}", 'GET');
        $viewReq->setUserResolver(fn () => $staff);
        $viewRes = TenantContext::runFor($shop->id, fn () => app(CustomerController::class)
            ->showKycDocument($customer, $doc, $viewReq));
        $this->assertSame(200, $viewRes->getStatusCode());

        // Delete deactivates + removes the file.
        $delReq = Request::create("/api/mobile/customers/{$customer->id}/kyc-documents/{$doc->id}", 'DELETE');
        $delReq->setUserResolver(fn () => $staff);
        $delRes = TenantContext::runFor($shop->id, fn () => app(CustomerController::class)
            ->deleteKycDocument($customer, $doc, $delReq));
        $this->assertSame(200, $delRes->getStatusCode());
        $this->assertTrue($delRes->getData(true)['success']);
        $this->assertFalse((bool) TenantContext::runFor($shop->id, fn () => $doc->fresh()->is_active));
        Storage::disk('local')->assertMissing($doc->file_path);
    }
}
