<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * S3-04b — the settings page previews the current signature (a regression from S3-04).
 *
 * Found while classifying the remaining `asset('storage/…')` consumers for
 * S3-02/S3-03. settings.blade.php was never touched by this branch and still
 * builds the preview as `asset('storage/' . $billing->digital_signature_path)`.
 * Since S3-04, a new signature is stored on the PRIVATE disk (SignatureStore::
 * DISK = 'local'), so that URL names a file the public tree does not have: the
 * owner uploads a signature and sees a broken image. A regression introduced
 * by S3-04 itself, not by the baseline.
 *
 * The repair renders the preview the way the printed bill already does —
 * inline, through InvoiceSignatureRenderer's hardened read (disk allowlist,
 * path belongs to the shop, size, MIME and dimension limits, relocation
 * integrity) — so no public URL is emitted for either disk.
 */
class SettingsSignaturePreviewTest extends TestCase
{
    use CreatesTestTenant;
    use RefreshDatabase;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    private function upload(User $owner): string
    {
        $this->actingAs($owner)
            ->patch(route('settings.update.billing'), [
                'invoice_prefix' => 'INV',
                'invoice_start_number' => 1,
                'show_digital_signature' => '1',
                'digital_signature' => UploadedFile::fake()->image('sig.png', 300, 100),
            ])
            ->assertSessionHasNoErrors();

        $row = DB::table('shop_billing_settings')->where('shop_id', $owner->shop_id)->first();

        return base64_encode(Storage::disk($row->digital_signature_disk)->get($row->digital_signature_path));
    }

    private function settingsPage(User $owner): string
    {
        $response = $this->actingAs($owner)->get(route('settings.edit', ['tab' => 'billing']));
        $response->assertOk();

        return $response->getContent();
    }

    /** The src attribute of the current-signature <img>, or null. */
    private function previewSrc(string $html): ?string
    {
        return preg_match('/<img\s+src="([^"]*)"\s+alt="Current Signature"/', $html, $m) ? $m[1] : null;
    }

    public function test_a_newly_uploaded_signature_previews_from_its_stored_bytes(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $bytes = $this->upload($owner);

        $this->assertSame('local', DB::table('shop_billing_settings')->where('shop_id', $shop->id)->value('digital_signature_disk'),
            'precondition: S3-04 stores new signatures on the private disk');

        $src = $this->previewSrc($this->settingsPage($owner));

        $this->assertNotNull($src, 'the page must render the current-signature preview');
        $this->assertStringStartsWith('data:image/', $src, 'the preview must be renderable — not a URL into the public tree');
        $this->assertStringContainsString($bytes, $src, 'and it must be the stored signature');
    }

    public function test_no_public_storage_url_is_emitted_for_the_signature(): void
    {
        [$owner] = $this->createRetailerTenant();
        $this->upload($owner);

        $this->assertStringNotContainsString('/storage/signatures/', $this->settingsPage($owner));
    }

    /** [CONTROL] A legacy signature still on the public disk previews too. */
    public function test_a_legacy_public_signature_still_previews(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $path = "signatures/{$shop->id}/legacy.png";
        $png = UploadedFile::fake()->image('legacy.png', 200, 80)->getContent();
        Storage::disk('public')->put($path, $png);
        DB::table('shop_billing_settings')->where('shop_id', $shop->id)
            ->update(['digital_signature_path' => $path, 'digital_signature_disk' => 'public', 'show_digital_signature' => DB::raw('true')]);

        try {
            $src = $this->previewSrc($this->settingsPage($owner));
            $this->assertSame('data:image/png;base64,'.base64_encode($png), $src);
        } finally {
            Storage::disk('public')->delete($path);
        }
    }

    /**
     * The preview must not borrow the print path's `sales.view` gate: the page
     * is gated by `settings.view`, and a user with only that must still get it.
     */
    public function test_a_settings_only_user_still_gets_the_page_and_the_preview(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $this->upload($owner);
        $this->grantOnlyPermissions($owner, ['settings.view']);

        $src = $this->previewSrc($this->settingsPage($owner->fresh()));

        $this->assertStringStartsWith('data:image/', (string) $src);
    }

    /** A signature that cannot be read is reported, not drawn as a broken image. */
    public function test_an_unreadable_signature_is_reported_instead_of_a_broken_image(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        DB::table('shop_billing_settings')->where('shop_id', $shop->id)
            ->update(['digital_signature_path' => "signatures/{$shop->id}/gone.png", 'digital_signature_disk' => 'local']);

        $html = $this->settingsPage($owner);

        $this->assertNull($this->previewSrc($html));
        $this->assertStringContainsString('Signature file unavailable', $html);
    }
}
