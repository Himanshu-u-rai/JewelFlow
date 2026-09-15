<?php

namespace Tests\Feature\Security;

use App\Models\Karigar;
use App\Models\KarigarInvoice;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Audit finding S3-02 — karigar invoice attachments on the public disk.
 *
 * Distinct from the legacy KYC finding (S3-01a/b/c): KycDocumentService already
 * writes the PRIVATE 'local' disk, so KYC's public-tree population is historical.
 * KarigarInvoiceService::create():48 and update():114 write 'public' on EVERY
 * upload at baseline 018b3d8, and both blade templates link the raw /storage/
 * URL, so this path is actively growing.
 *
 * Supplier invoices carry GST numbers, amounts and counterparty identity. Served
 * off the public/storage symlink by nginx, they reach no PHP and therefore no
 * auth guard, no tenant scope and no shop_id check.
 *
 * Every request here goes through requestAs(), which pins TenantContext the way
 * production does. See §M-01 in the findings ledger: bootstrap/app.php:83-84 runs
 * SubstituteBindings BEFORE EnsureTenantUser, so binding falls back to
 * BelongsToShop::resolveTenantShopId()'s Auth::check() branch — which is gated
 * behind runningInConsole() and returns null under PHPUnit. Without the pin every
 * bind fail-closes to 404 and every cross-tenant assertion passes for the wrong
 * reason.
 */
class KarigarInvoiceAttachmentTest extends TestCase
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

    private function requestAs(User $user, string $url)
    {
        $this->actingAs($user);

        return \App\Support\TenantContext::runFor($user->shop_id, fn () => $this->get($url));
    }

    private function createKarigar(int $shopId): Karigar
    {
        return \App\Support\TenantContext::runFor($shopId, fn () => Karigar::create([
            'shop_id' => $shopId,
            'name' => 'Test Karigar',
            'mobile' => '9800000001',
        ]));
    }

    /**
     * A row exactly as rows created before this fix look: a path, and the file
     * physically resident on the public disk.
     */
    private function legacyInvoice(int $shopId, int $karigarId, int $userId, string $path): KarigarInvoice
    {
        return \App\Support\TenantContext::runFor($shopId, fn () => KarigarInvoice::create([
            'shop_id' => $shopId,
            'karigar_id' => $karigarId,
            'mode' => KarigarInvoice::MODE_PURCHASE,
            'karigar_invoice_number' => 'KI-'.$shopId.'-'.uniqid(),
            'karigar_invoice_date' => now()->toDateString(),
            'payment_status' => KarigarInvoice::PAYMENT_UNPAID,
            'amount_paid' => 0,
            'invoice_file_path' => $path,
            'invoice_file_disk' => 'public',
            'created_by_user_id' => $userId,
        ]));
    }

    /** A same-shop user on a separate role, holding exactly $permissions. */
    private function staffUser(int $shopId, array $permissions): User
    {
        // shop_id is not fillable on Role — set it explicitly.
        $role = new Role();
        $role->forceFill([
            'shop_id' => $shopId,
            'name' => 'staff_'.uniqid(),
            'display_name' => 'Staff',
        ])->save();
        $role->permissions()->sync(Permission::whereIn('name', $permissions)->pluck('id'));

        return User::factory()->create([
            'shop_id' => $shopId,
            'role_id' => $role->id,
            'is_active' => true,
        ]);
    }

    /**
     * S3-02-url — the seam. The attachment must not resolve to a statically
     * served public URL from any template.
     */
    public function test_attachment_does_not_resolve_to_an_unauthenticated_public_url(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $karigar = $this->createKarigar($shop->id);
        $invoice = $this->legacyInvoice($shop->id, $karigar->id, $owner->id, "karigar-invoices/{$shop->id}/legacy.pdf");

        $url = $invoice->attachmentUrl();

        $this->assertStringNotContainsString('/storage/', $url,
            'a karigar invoice attachment must not resolve to a statically served public URL');
        $this->assertStringContainsString("/karigar-invoices/{$invoice->id}/file", $url,
            'attachments must resolve to the authenticated, shop-scoped stream route');
    }

    /**
     * S3-02-download-own — positive control. Existing public-disk attachments must
     * NOT be stranded: the route reads whichever disk the row records.
     */
    public function test_existing_public_disk_attachment_remains_retrievable_by_its_own_shop(): void
    {
        Storage::fake('public');
        [$owner, $shop] = $this->createRetailerTenant();
        $karigar = $this->createKarigar($shop->id);
        $path = "karigar-invoices/{$shop->id}/legacy-own.pdf";
        Storage::disk('public')->put($path, 'A_ONLY_KARIGAR_BYTES_91c');
        $invoice = $this->legacyInvoice($shop->id, $karigar->id, $owner->id, $path);

        $response = $this->requestAs($owner, route('karigar-invoices.file', $invoice));

        $response->assertOk();
        $this->assertSame('A_ONLY_KARIGAR_BYTES_91c', $response->streamedContent());
    }

    /** S3-02-download-crossshop — Owner B must not retrieve A's attachment. */
    public function test_foreign_shop_cannot_retrieve_a_karigar_invoice_attachment(): void
    {
        Storage::fake('public');
        [$ownerA, $shopA] = $this->createRetailerTenant();
        [$ownerB] = $this->createRetailerTenant();

        $karigarA = $this->createKarigar($shopA->id);
        $path = "karigar-invoices/{$shopA->id}/legacy-foreign.pdf";
        Storage::disk('public')->put($path, 'A_ONLY_KARIGAR_BYTES_91c');
        $invoiceA = $this->legacyInvoice($shopA->id, $karigarA->id, $ownerA->id, $path);

        $response = $this->requestAs($ownerB, route('karigar-invoices.file', $invoiceA));

        $this->assertContains($response->status(), [403, 404],
            'B must be refused A\'s attachment; got '.$response->status());
        $this->assertStringNotContainsString('A_ONLY_KARIGAR_BYTES_91c', $response->getContent());
    }

    /** S3-02-download-guest — an unauthenticated request must never get bytes. */
    public function test_guest_cannot_retrieve_a_karigar_invoice_attachment(): void
    {
        Storage::fake('public');
        [$ownerA, $shopA] = $this->createRetailerTenant();
        $karigarA = $this->createKarigar($shopA->id);
        $path = "karigar-invoices/{$shopA->id}/legacy-guest.pdf";
        Storage::disk('public')->put($path, 'A_ONLY_KARIGAR_BYTES_91c');
        $invoiceA = $this->legacyInvoice($shopA->id, $karigarA->id, $ownerA->id, $path);

        $response = $this->get(route('karigar-invoices.file', $invoiceA));

        $this->assertNotEquals(200, $response->status(),
            'a guest must never receive attachment bytes; got '.$response->status());
        $this->assertStringNotContainsString('A_ONLY_KARIGAR_BYTES_91c', $response->getContent());
    }

    /**
     * S3-02-download-staff-perm — intra-shop permission boundary (matrix group U).
     * Same shop is NOT sufficient: the route is gated on karigar_invoice.view.
     */
    public function test_same_shop_user_without_karigar_permission_is_refused(): void
    {
        Storage::fake('public');
        [$ownerA, $shopA] = $this->createRetailerTenant();
        $karigarA = $this->createKarigar($shopA->id);
        $path = "karigar-invoices/{$shopA->id}/legacy-staff.pdf";
        Storage::disk('public')->put($path, 'A_ONLY_KARIGAR_BYTES_91c');
        $invoiceA = $this->legacyInvoice($shopA->id, $karigarA->id, $ownerA->id, $path);

        $staff = $this->staffUser($shopA->id, ['customers.view']);

        $response = $this->requestAs($staff, route('karigar-invoices.file', $invoiceA));

        $this->assertContains($response->status(), [403, 404],
            'a same-shop user lacking karigar_invoice.view must be refused; got '.$response->status());
        $this->assertStringNotContainsString('A_ONLY_KARIGAR_BYTES_91c', $response->getContent());
    }

    /**
     * S3-02-staff-perm-positive — the control that proves the previous test failed
     * for the RIGHT reason. Same construction, permission granted, bytes returned.
     */
    public function test_same_shop_user_with_karigar_permission_is_allowed(): void
    {
        Storage::fake('public');
        [$ownerA, $shopA] = $this->createRetailerTenant();
        $karigarA = $this->createKarigar($shopA->id);
        $path = "karigar-invoices/{$shopA->id}/legacy-staff-ok.pdf";
        Storage::disk('public')->put($path, 'A_ONLY_KARIGAR_BYTES_91c');
        $invoiceA = $this->legacyInvoice($shopA->id, $karigarA->id, $ownerA->id, $path);

        $staff = $this->staffUser($shopA->id, ['karigar_invoice.view']);

        $response = $this->requestAs($staff, route('karigar-invoices.file', $invoiceA));

        $response->assertOk();
        $this->assertSame('A_ONLY_KARIGAR_BYTES_91c', $response->streamedContent());
    }

    /**
     * S3-02-write-private — new uploads must land on the private disk, not public.
     * This is the half that stops the exposed tree from continuing to grow.
     */
    public function test_new_attachment_upload_is_written_to_the_private_disk(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        [$owner, $shop] = $this->createRetailerTenant();
        $karigar = $this->createKarigar($shop->id);

        $file = \Illuminate\Http\UploadedFile::fake()->create('supplier.pdf', 12, 'application/pdf');

        $invoice = \App\Support\TenantContext::runFor($shop->id, fn () => app(\App\Services\KarigarInvoiceService::class)->create(
            [
                'karigar_id' => $karigar->id,
                'karigar_invoice_number' => 'KI-NEW-1',
                'karigar_invoice_date' => now()->toDateString(),
            ],
            [['description' => 'Item', 'net_weight' => 1, 'rate_per_gram' => 100, 'metal_amount' => 100]],
            $file,
            $shop->id,
            $owner->id,
        ));

        $this->assertSame('local', $invoice->invoice_file_disk,
            'a new karigar attachment must record the private disk');
        $this->assertTrue(Storage::disk('local')->exists($invoice->invoice_file_path),
            'the file must physically land on the private disk');
        $this->assertFalse(Storage::disk('public')->exists($invoice->invoice_file_path),
            'the file must NOT land on the web-served public disk');
    }

    /**
     * S3-02-relocation-precondition — CHARACTERIZATION test, not a regression.
     *
     * This pins existing database behaviour that the relocation procedure depends
     * on, so it is expected to pass without any production change. Recorded as a
     * precondition rather than a fix.
     *
     * karigar_invoices_finalized_guard_trigger (Constitution Art. IX.A #15) is
     * BEFORE UPDATE OR DELETE and freezes billed content once payment_status
     * leaves 'unpaid'. The relocation procedure must rewrite invoice_file_path and
     * invoice_file_disk on rows that may well be finalized. If the guard rejected
     * those columns, relocation would be impossible without touching a protected
     * trigger — which is forbidden. It does not: the frozen set is totals, invoice
     * number, invoice date and karigar_id only.
     */
    public function test_finalized_invoice_still_accepts_attachment_metadata_relocation(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $karigar = $this->createKarigar($shop->id);
        $invoice = $this->legacyInvoice($shop->id, $karigar->id, $owner->id, "karigar-invoices/{$shop->id}/frozen.pdf");

        // Finalize it: the guard now freezes billed content.
        DB::table('karigar_invoices')->where('id', $invoice->id)
            ->update(['payment_status' => KarigarInvoice::PAYMENT_PAID]);

        $newPath = "karigar-invoices/{$shop->id}/relocated.pdf";
        DB::table('karigar_invoices')->where('id', $invoice->id)->update([
            'invoice_file_path' => $newPath,
            'invoice_file_disk' => 'local',
        ]);

        $row = DB::table('karigar_invoices')->where('id', $invoice->id)->first();
        $this->assertSame($newPath, $row->invoice_file_path);
        $this->assertSame('local', $row->invoice_file_disk);

        // Positive control: the guard is genuinely armed on this row, so the
        // assertion above is not passing merely because the trigger is inert.
        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('karigar_invoices')->where('id', $invoice->id)
            ->update(['total_after_tax' => 999999]);
    }

    /**
     * S3-02-schema-invariant — a stored path must always carry an explicit disk.
     * This is what stops a future writer silently inheriting 'public', which is
     * exactly how the KYC finding (S3-01c) arose.
     */
    public function test_an_attachment_path_cannot_be_stored_without_an_explicit_disk(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $karigar = $this->createKarigar($shop->id);

        $this->expectException(\Illuminate\Database\QueryException::class);

        \App\Support\TenantContext::runFor($shop->id, fn () => KarigarInvoice::create([
            'shop_id' => $shop->id,
            'karigar_id' => $karigar->id,
            'mode' => KarigarInvoice::MODE_PURCHASE,
            'karigar_invoice_number' => 'KI-NO-DISK',
            'karigar_invoice_date' => now()->toDateString(),
            'payment_status' => KarigarInvoice::PAYMENT_UNPAID,
            'amount_paid' => 0,
            'invoice_file_path' => "karigar-invoices/{$shop->id}/orphan.pdf",
            // invoice_file_disk deliberately omitted
            'created_by_user_id' => $owner->id,
        ]));
    }
}
