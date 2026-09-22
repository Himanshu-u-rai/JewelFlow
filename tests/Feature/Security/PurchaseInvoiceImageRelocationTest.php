<?php

namespace Tests\Feature\Security;

use App\Models\StockPurchase;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * S3-03 — existing purchase invoice images still sit on the web-served public
 * disk. The authenticated route and private storage for NEW uploads were
 * already on the branch; nothing moved the files uploaded before them.
 *
 * `purchases:relocate-invoice-images` is the karigar relocation procedure
 * (KarigarInvoiceRelocationTest R-01…R-18) pointed at stock_purchases. The
 * procedure's own contract — per-row failure isolation, --shop, --limit,
 * wholesale purge refusal — is proven by that suite on the shared code. These
 * tests prove the wiring to THIS table and THIS consumer: the right rows are
 * found, the right column flips, and the purchase invoice route keeps serving
 * the same bytes before and after.
 */
class PurchaseInvoiceImageRelocationTest extends TestCase
{
    use CreatesTestTenant;
    use RefreshDatabase;

    private const COMMAND = 'purchases:relocate-invoice-images';

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        Storage::fake('public');
        Storage::fake('local');
    }

    private function publicDiskPurchase(int $shopId, string $basename, string $bytes): StockPurchase
    {
        $path = "purchases/{$basename}";
        Storage::disk('public')->put($path, $bytes);

        return TenantContext::runFor($shopId, function () use ($shopId, $path) {
            $purchase = new StockPurchase;
            $purchase->forceFill([
                'shop_id' => $shopId, 'purchase_number' => 'PO-'.uniqid(), 'purchase_date' => now()->toDateString(),
                'status' => 'confirmed', 'invoice_image' => $path, 'invoice_image_disk' => 'public',
            ])->save();

            return $purchase;
        });
    }

    private function recordedDisk(StockPurchase $purchase): ?string
    {
        return DB::table('stock_purchases')->where('id', $purchase->id)->value('invoice_image_disk');
    }

    private function viewImage(User $owner, StockPurchase $purchase)
    {
        $this->actingAs($owner);

        return TenantContext::runFor((int) $owner->shop_id,
            fn () => $this->get(route('inventory.purchases.invoice-image', $purchase)));
    }

    public function test_dry_run_is_the_default_and_moves_nothing(): void
    {
        [, $shop] = $this->createRetailerTenant();
        $purchase = $this->publicDiskPurchase((int) $shop->id, 'dry.jpg', 'DRY_BYTES');

        $this->artisan(self::COMMAND)->assertExitCode(0);

        $this->assertSame('public', $this->recordedDisk($purchase));
        $this->assertFalse(Storage::disk('local')->exists($purchase->invoice_image));
        $manifest = Storage::disk('local')->files('relocation-manifests');
        $this->assertCount(1, $manifest);
        $this->assertStringContainsString('"table":"stock_purchases"', Storage::disk('local')->get($manifest[0]));
    }

    public function test_execute_copies_the_bytes_flips_the_recorded_disk_and_keeps_the_original(): void
    {
        [, $shop] = $this->createRetailerTenant();
        $purchase = $this->publicDiskPurchase((int) $shop->id, 'exec.jpg', 'EXEC_BYTES');

        $this->artisan(self::COMMAND, ['--execute' => true])->assertExitCode(0);

        $this->assertSame(StockPurchase::ATTACHMENT_DISK, $this->recordedDisk($purchase));
        $this->assertSame('EXEC_BYTES', Storage::disk('local')->get($purchase->invoice_image));
        $this->assertTrue(Storage::disk('public')->exists($purchase->invoice_image), 'the original stays until a gated purge');
    }

    /** The consumer: the authenticated purchase-image route serves the same bytes before and after. */
    public function test_the_purchase_image_route_serves_the_same_bytes_before_and_after(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $purchase = $this->publicDiskPurchase((int) $shop->id, 'e2e.jpg', 'END_TO_END_PURCHASE');

        $before = $this->viewImage($owner, $purchase);
        $before->assertOk();
        $this->assertSame('END_TO_END_PURCHASE', $before->streamedContent());

        $this->artisan(self::COMMAND, ['--execute' => true])->assertExitCode(0);

        $after = $this->viewImage($owner, $purchase->fresh());
        $after->assertOk();
        $this->assertSame('END_TO_END_PURCHASE', $after->streamedContent());
    }

    public function test_a_row_whose_file_is_missing_is_left_alone(): void
    {
        [, $shop] = $this->createRetailerTenant();
        $purchase = $this->publicDiskPurchase((int) $shop->id, 'gone.jpg', 'GONE');
        Storage::disk('public')->delete($purchase->invoice_image);

        $this->artisan(self::COMMAND, ['--execute' => true])->assertExitCode(1);

        $this->assertSame('public', $this->recordedDisk($purchase), 'a row that could not be copied keeps its disk');
    }

    public function test_purge_removes_the_original_only_after_the_copy_verifies(): void
    {
        [, $shop] = $this->createRetailerTenant();
        $good = $this->publicDiskPurchase((int) $shop->id, 'good.jpg', 'GOOD_BYTES');
        $this->artisan(self::COMMAND, ['--execute' => true])->assertExitCode(0);

        Storage::disk('local')->put($good->invoice_image, 'CORRUPTED');
        $this->artisan(self::COMMAND, ['--purge-originals' => true, '--execute' => true])->assertExitCode(1);
        $this->assertTrue(Storage::disk('public')->exists($good->invoice_image), 'refused wholesale: nothing deleted');

        Storage::disk('local')->put($good->invoice_image, 'GOOD_BYTES');
        $this->artisan(self::COMMAND, ['--purge-originals' => true, '--execute' => true])->assertExitCode(0);
        $this->assertFalse(Storage::disk('public')->exists($good->invoice_image));
        $this->assertSame('GOOD_BYTES', Storage::disk('local')->get($good->invoice_image));
    }
}
