<?php

namespace Tests\Feature\Dhiran;

use App\Http\Controllers\DhiranController;
use App\Models\Customer;
use App\Models\Dhiran\DhiranAttachment;
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
 * Owner 2026-11-08: Evidence & Documents shows every uploaded photo, whatever its type (ID proof, pledged item, ...),
 * as a row of small thumbnails, each titled with its type and openable large. Non-image files get a titled tile.
 */
class DhiranEvidenceGalleryTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->shop = Shop::create([
            'name' => 'Pawn Co', 'shop_type' => 'dhiran', 'phone' => '9390003000',
            'owner_first_name' => 'O', 'owner_last_name' => 'W', 'owner_mobile' => '9390003000',
            'country' => 'India', 'gst_rate' => 3.0, 'wastage_recovery_percent' => 0,
        ]);
        DhiranSettings::getForShop($this->shop->id)->update(['is_enabled' => true]);
        $role = new Role();
        $role->forceFill(['name' => 'owner', 'display_name' => 'Owner', 'shop_id' => $this->shop->id])->save();
        $role->permissions()->sync(Permission::query()->pluck('id'));
        $this->owner = User::create([
            'mobile_number' => '9390003000', 'password' => bcrypt('x'), 'realm' => 'dhiran',
            'is_active' => true, 'email_verified_at' => now(), 'shop_id' => $this->shop->id, 'role_id' => $role->id,
        ]);
    }

    private function as(callable $fn): string
    {
        return TenantContext::runFor($this->shop->id, function () use ($fn) {
            $this->actingAs($this->owner);
            view()->share('errors', new \Illuminate\Support\ViewErrorBag);

            return $fn()->render();
        });
    }

    public function test_every_photo_shows_as_a_titled_thumbnail_that_opens_large(): void
    {
        [$customer, $loan, $item, $photos, $pdf] = TenantContext::runFor($this->shop->id, function () {
            $c = Customer::create(['shop_id' => $this->shop->id, 'first_name' => 'Asha', 'last_name' => 'B', 'mobile' => '9813330001']);
            $loan = app(DhiranService::class)->createLoan($this->shop, $c, [[
                'description' => 'Gold Chain', 'metal_type' => 'gold', 'purity' => 22, 'gross_weight' => 50, 'rate_per_gram_at_pledge' => 6000,
            ]], ['principal_amount' => 100000, 'gold_rate_on_date' => 6000, 'created_by' => $this->owner->id]);
            $item = $loan->items->first();
            $svc = app(DhiranAttachmentService::class);
            $photos = [
                'Pledged item photo' => $svc->store(UploadedFile::fake()->image('chain.jpg', 60, 60), $this->shop->id, DhiranAttachment::OWNER_ITEM, $item->id, 'item_photo', $this->owner->id),
                'ID proof (front)' => $svc->store(UploadedFile::fake()->image('id.png', 60, 60), $this->shop->id, DhiranAttachment::OWNER_LOAN, $loan->id, 'id_proof_front', $this->owner->id),
            ];
            $pdf = DhiranAttachment::create([
                'shop_id' => $this->shop->id, 'owner_type' => DhiranAttachment::OWNER_LOAN, 'owner_id' => $loan->id,
                'document_type' => 'pledge_agreement', 'file_disk' => 'local', 'file_path' => 'dhiran/x/agreement.pdf',
                'original_name' => 'agreement.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 1000,
            ]);

            return [$c, $loan, $item, $photos, $pdf];
        });

        $loanPage = $this->as(fn () => app(DhiranController::class)->show($loan));
        $gallery = substr($loanPage, strpos($loanPage, 'data-evidence-gallery'));
        foreach ($photos as $title => $att) {
            $url = route('dhiran.attachments.show', $att);
            // Tiles load a small copy; the large view loads the original.
            $this->assertStringContainsString('<img src="' . e(route('dhiran.attachments.show', [$att, 'thumb' => 1])) . '"', $gallery, "{$title} thumbnail");
            $this->assertStringContainsString($title, $gallery);
            $this->assertMatchesRegularExpression('/data-evidence-open[^>]*data-src="' . preg_quote($url, '/') . '"/', $gallery, "{$title} opens large");
        }
        // A PDF has no image to show: a titled tile that opens the file.
        $this->assertStringContainsString('Pledge agreement', $gallery);
        $this->assertStringNotContainsString('<img src="' . route('dhiran.attachments.show', $pdf), $gallery);
        $this->assertStringContainsString('href="' . route('dhiran.attachments.show', $pdf) . '"', $gallery);
        $this->assertStringContainsString('<dialog', $gallery, 'one large viewer');

        // Each tile says what it belongs to: the pledged item (and its loan), or the whole loan.
        $this->assertStringContainsString('Gold Chain · ' . $loan->loan_number, $gallery);
        $this->assertStringContainsString('Loan ' . $loan->loan_number, $gallery);

        // Same gallery on the customer profile and the item page.
        $profile = $this->as(fn () => app(DhiranController::class)->borrowerProfile(Customer::findOrFail($customer->id)));
        $this->assertStringContainsString('<img src="' . e(route('dhiran.attachments.show', [$photos['ID proof (front)'], 'thumb' => 1])) . '"', $profile);
        $this->assertStringContainsString('Gold Chain · ' . $loan->loan_number, $profile);
        $itemPage = $this->as(fn () => app(DhiranController::class)->itemDetail($item));
        $this->assertStringContainsString('<img src="' . e(route('dhiran.attachments.show', [$photos['Pledged item photo'], 'thumb' => 1])) . '"', $itemPage);
    }

    // A tile asked for ?thumb=1 gets a small JPEG made once and kept next to the original; a PDF gets the file itself.
    public function test_thumbnail_is_a_small_copy_made_once(): void
    {
        [$photo, $pdf] = TenantContext::runFor($this->shop->id, function () {
            $c = Customer::create(['shop_id' => $this->shop->id, 'first_name' => 'Big', 'last_name' => 'Photo', 'mobile' => '9813330002']);
            $photo = app(DhiranAttachmentService::class)->store(UploadedFile::fake()->image('big.jpg', 2400, 1600), $this->shop->id, DhiranAttachment::OWNER_CUSTOMER, $c->id, 'id_proof_front', $this->owner->id);
            Storage::disk('local')->put('dhiran/x/terms.pdf', '%PDF-1.4 test');
            $pdf = DhiranAttachment::create([
                'shop_id' => $this->shop->id, 'owner_type' => DhiranAttachment::OWNER_CUSTOMER, 'owner_id' => $c->id,
                'document_type' => 'signed_terms', 'file_disk' => 'local', 'file_path' => 'dhiran/x/terms.pdf',
                'original_name' => 'terms.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 13,
            ]);

            return [$photo, $pdf];
        });

        $get = function (DhiranAttachment $att, bool $thumb) {
            return TenantContext::runFor($this->shop->id, function () use ($att, $thumb) {
                $this->actingAs($this->owner);
                $this->app->instance('request', \Illuminate\Http\Request::create('/dhiran/attachments/' . $att->id, 'GET', $thumb ? ['thumb' => 1] : []));
                $response = app(DhiranController::class)->showAttachment($att);
                ob_start();
                $response->sendContent();

                return [$response, ob_get_clean()];
            });
        };

        [$res, $bytes] = $get($photo, true);
        [$w, $h] = getimagesizefromstring($bytes);
        $this->assertLessThanOrEqual(320, max($w, $h), 'thumbnail is small');
        $this->assertSame('image/jpeg', $res->headers->get('Content-Type'));
        $this->assertLessThan(strlen(Storage::disk('local')->get($photo->file_path)), strlen($bytes));
        $this->assertTrue(Storage::disk('local')->exists($photo->file_path . '.thumb.jpg'), 'kept for the next visit');

        [, $full] = $get($photo, false);
        $this->assertSame(2400, getimagesizefromstring($full)[0], 'the large view still gets the original');

        [, $pdfBytes] = $get($pdf, true);
        $this->assertSame('%PDF-1.4 test', $pdfBytes);
    }
}
