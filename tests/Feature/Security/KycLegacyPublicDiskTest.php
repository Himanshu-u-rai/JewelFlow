<?php

namespace Tests\Feature\Security;

use App\Models\KycDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Audit case family D05 (legacy KYC lifecycle) / D01 (direct storage bypass).
 *
 * KycDocumentPrivacyTest already covers documents written by the CURRENT service,
 * which records file_disk='local'. Every one of its fixtures pins that value, so
 * none of them reach the branch legacy rows actually take: kyc_documents.file_disk
 * is NOT NULL DEFAULT 'public' (2026_05_10_200003_create_kyc_documents.php:19), so
 * rows predating the private-disk change hold the literal string 'public'.
 */
class KycLegacyPublicDiskTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        // Error pages render layouts/app.blade.php; without a built manifest Vite
        // throws and every 403/404 arrives as a 500, hiding the status under test.
        $this->withoutVite();
    }

    /**
     * Issue a request the way production resolves tenant context for this route.
     *
     * bootstrap/app.php:83-84 runs SubstituteBindings BEFORE EnsureTenantUser, so
     * route-model binding scopes via the Auth::check() fallback in
     * BelongsToShop::resolveTenantShopId(). PHPUnit runs in CLI, where the
     * runningInConsole() branch short-circuits to null and fail-closes every bind
     * to 404 — which would make any cross-tenant assertion pass for the wrong
     * reason. Pinning the acting user's own shop reproduces the production value.
     */
    private function requestAs(\App\Models\User $user, string $url)
    {
        $this->actingAs($user);

        return \App\Support\TenantContext::runFor($user->shop_id, fn () => $this->get($url));
    }

    /** A document row exactly as the schema default leaves it: file_disk='public'. */
    private function legacyDocument(int $shopId, int $customerId, int $userId, string $path): KycDocument
    {
        return KycDocument::create([
            'shop_id' => $shopId,
            'customer_id' => $customerId,
            'uploaded_by' => $userId,
            'document_type' => KycDocument::TYPE_AADHAAR,
            'file_path' => $path,
            'file_disk' => 'public',
            'original_filename' => 'aadhaar.jpg',
            'mime_type' => 'image/jpeg',
            'file_size_bytes' => 32,
        ]);
    }

    /**
     * D05-url-legacy-public — the seam. KycDocument::url() returns a direct
     * /storage/ URL for a legacy row, which nginx serves from the public/storage
     * symlink with no auth guard, no tenant scope and no shop_id check.
     */
    public function test_legacy_public_disk_row_does_not_mint_an_unauthenticated_url(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $customer = $this->createCustomer($shop->id);
        $doc = $this->legacyDocument($owner->shop_id, $customer->id, $owner->id, "kyc/{$shop->id}/legacy.jpg");

        $url = $doc->url();

        $this->assertStringNotContainsString('/storage/', $url,
            'a legacy KYC row must not resolve to a statically served public URL');
        $this->assertStringContainsString("/kyc-documents/{$doc->id}/file", $url,
            'every KYC document must resolve to the authenticated, shop-scoped stream route');
    }

    /**
     * D05-show-legacy-own — positive control. Fixing url() must not strand the
     * legacy files: show() still reads whichever disk the row records, so an
     * authorised same-shop user keeps getting the document.
     */
    public function test_legacy_public_disk_row_remains_retrievable_by_its_own_shop(): void
    {
        Storage::fake('public');
        [$owner, $shop] = $this->createRetailerTenant();
        $customer = $this->createCustomer($shop->id);
        $path = "kyc/{$shop->id}/legacy-own.jpg";
        Storage::disk('public')->put($path, 'A_ONLY_KYC_BYTES_7fa');
        $doc = $this->legacyDocument($shop->id, $customer->id, $owner->id, $path);

        $response = $this->requestAs($owner, route('kyc-documents.show', $doc));

        $response->assertOk();
        $this->assertSame('A_ONLY_KYC_BYTES_7fa', $response->streamedContent());
    }

    /**
     * D05-show-crossshop-web — Owner B must not retrieve A's legacy document, and
     * the denial must disclose no bytes.
     */
    public function test_foreign_shop_cannot_retrieve_a_legacy_kyc_document(): void
    {
        Storage::fake('public');
        [$ownerA, $shopA] = $this->createRetailerTenant();
        [$ownerB] = $this->createRetailerTenant();

        $customerA = $this->createCustomer($shopA->id);
        $path = "kyc/{$shopA->id}/legacy-foreign.jpg";
        Storage::disk('public')->put($path, 'A_ONLY_KYC_BYTES_7fa');
        $docA = $this->legacyDocument($shopA->id, $customerA->id, $ownerA->id, $path);

        $response = $this->requestAs($ownerB, route('kyc-documents.show', $docA));

        $this->assertContains($response->status(), [403, 404],
            'B must be refused A document; got '.$response->status());
        $this->assertStringNotContainsString('A_ONLY_KYC_BYTES_7fa', $response->getContent());
    }
}
