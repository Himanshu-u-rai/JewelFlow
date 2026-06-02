<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\KycDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use App\Support\TenantContext;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Restoration M10 (audit SEC1): KYC identity documents (PAN/Aadhaar/passport)
 * were stored on the PUBLIC disk and served by public URL, and destroy() left
 * the file on disk forever. They now live on the PRIVATE 'local' disk, are
 * served only via an authenticated shop-scoped stream route, and destroy()
 * removes the physical file.
 */
class KycDocumentPrivacyTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
    }

    private function grant(\App\Models\User $owner, array $perms): void
    {
        $role = \App\Models\Role::withoutGlobalScopes()->findOrFail($owner->role_id);
        foreach ($perms as $name) {
            $p = \App\Models\Permission::firstOrCreate(['name' => $name], ['display_name' => $name, 'group' => 'customers']);
            $role->permissions()->syncWithoutDetaching([$p->id]);
        }
    }

    public function test_stream_route_exists_and_is_gated(): void
    {
        $this->assertTrue(Route::has('kyc-documents.show'));
        $route = Route::getRoutes()->getByName('kyc-documents.show');
        $this->assertContains('can:customers.view', $route->gatherMiddleware());
    }

    public function test_upload_stores_on_private_disk_not_public(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        [$owner, $shop] = $this->createRetailerTenant();
        $this->grant($owner, ['customers.create', 'customers.view']);
        $customer = $this->createCustomer($shop->id);

        $this->actingAs($owner)->post(route('kyc-documents.store'), [
            'customer_id' => $customer->id,
            'document_type' => 'pan_card',
            'file' => UploadedFile::fake()->create('pan.pdf', 100, 'application/pdf'),
        ])->assertOk();

        $doc = KycDocument::withoutGlobalScopes()->where('customer_id', $customer->id)->first();
        $this->assertSame('local', $doc->file_disk, 'KYC must be stored on the private disk');
        Storage::disk('local')->assertExists($doc->file_path);
        Storage::disk('public')->assertMissing($doc->file_path);
    }

    public function test_url_is_the_authenticated_route_not_a_public_url(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $customer = $this->createCustomer($shop->id);
        $doc = KycDocument::create([
            'shop_id' => $shop->id, 'customer_id' => $customer->id, 'uploaded_by' => $owner->id,
            'document_type' => 'pan_card', 'file_path' => 'kyc/' . $shop->id . '/x.pdf',
            'file_disk' => 'local', 'original_filename' => 'pan.pdf', 'mime_type' => 'application/pdf',
            'file_size_bytes' => 100,
        ]);

        $this->assertStringContainsString('/kyc-documents/' . $doc->id . '/file', $doc->url());
    }

    public function test_destroy_deletes_the_physical_file(): void
    {
        Storage::fake('local');
        [$owner, $shop] = $this->createRetailerTenant();
        $this->grant($owner, ['customers.edit', 'customers.create', 'customers.view']);
        $customer = $this->createCustomer($shop->id);
        Storage::disk('local')->put('kyc/' . $shop->id . '/doc.pdf', 'data');

        $doc = KycDocument::create([
            'shop_id' => $shop->id, 'customer_id' => $customer->id, 'uploaded_by' => $owner->id,
            'document_type' => 'pan_card', 'file_path' => 'kyc/' . $shop->id . '/doc.pdf',
            'file_disk' => 'local', 'original_filename' => 'doc.pdf', 'mime_type' => 'application/pdf',
            'file_size_bytes' => 4,
        ]);

        // Invoke the controller directly within tenant context — an HTTP call
        // would 404 on the route-model bind under the console null-tenant scope (G5).
        $this->actingAs($owner);
        TenantContext::runFor($shop->id, fn () =>
            app(\App\Http\Controllers\KycDocumentController::class)->destroy($doc)
        );

        Storage::disk('local')->assertMissing('kyc/' . $shop->id . '/doc.pdf');
        $this->assertFalse((bool) KycDocument::withoutGlobalScopes()->find($doc->id)->is_active,
            'document is also soft-deactivated');
    }
}
