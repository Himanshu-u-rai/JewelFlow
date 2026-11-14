<?php

namespace Tests\Feature\Mobile\V1;

use App\Models\Item;
use App\Models\PendingUpload;
use App\Services\Mobile\UploadIntentService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Audit N8 (2026-11-14): store() and consume() checked status / consumed_at / expires_at on the row as the request
 * first read it and then wrote unconditionally, so two concurrent consumes both succeeded (one upload on two items)
 * and store/consume could race expiry. Every state move is now a single conditional UPDATE on the current row.
 */
class UploadIntentStateRaceTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        Storage::fake('public');
    }

    private function intent(string $status, array $over = []): PendingUpload
    {
        [$user, $shop] = $this->createRetailerTenant();
        Sanctum::actingAs($user);

        return PendingUpload::create(array_merge([
            'shop_id' => $shop->id, 'user_id' => $user->id, 'kind' => 'item_image', 'content_type' => 'image/png',
            'declared_size_bytes' => 100, 'upload_token' => 'n8-' . uniqid(), 'status' => $status,
            'original_path' => 'uploads/originals/n8.png', 'expires_at' => now()->addMinutes(10),
        ], $over));
    }

    private function png(): string
    {
        return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
    }

    /** Right after the code under test loads the upload, another request changes the row. */
    private function changedOnceLoaded(PendingUpload $upload, array $changes): void
    {
        $done = false;
        Event::listen('eloquent.retrieved: ' . PendingUpload::class, function (PendingUpload $loaded) use ($upload, $changes, &$done) {
            if ($done || $loaded->id !== $upload->id) {
                return;
            }
            $done = true;
            DB::table('pending_uploads')->where('id', $upload->id)->update($changes);
        });
    }

    public function test_an_upload_consumed_meanwhile_cannot_be_consumed_again(): void
    {
        $upload = $this->intent('ready');
        $stale  = PendingUpload::find($upload->id);
        DB::table('pending_uploads')->where('id', $upload->id)->update([
            'consumed_at' => now(), 'consumed_by_type' => Item::class, 'consumed_by_id' => 111,
        ]);
        $other = new Item();
        $other->id = 222;

        try {
            app(UploadIntentService::class)->consume($stale, $other);
            $this->fail('A consumed upload was consumed a second time.');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('consumed', $e->getMessage());
        }

        $this->assertSame(111, (int) DB::table('pending_uploads')->where('id', $upload->id)->value('consumed_by_id'));
    }

    public function test_an_upload_that_expired_meanwhile_is_not_consumed(): void
    {
        $upload = $this->intent('ready');
        $stale  = PendingUpload::find($upload->id);
        DB::table('pending_uploads')->where('id', $upload->id)->update(['expires_at' => now()->subMinute()]);
        $item = new Item();
        $item->id = 1;

        $this->expectException(\LogicException::class);
        try {
            app(UploadIntentService::class)->consume($stale, $item);
        } finally {
            $this->assertNull(DB::table('pending_uploads')->where('id', $upload->id)->value('consumed_at'));
        }
    }

    /**
     * Found reviewing N8: prune's "ready but never used, 24 hours past expiry" query combined consumable() (not
     * expired) with "expired over 24 hours ago", which can never match: unused ready photos were never pruned and
     * their files stayed on disk for good. A used photo is never pruned.
     */
    /**
     * Security review of b5a81831: a database error while finishing a store left the claimed row "uploaded" and
     * its files on disk; the row recorded no path, so prune could never find them. Now the files go and the
     * intent goes back to pending for a retry.
     */
    public function test_a_store_that_fails_after_writing_leaves_no_files_and_can_be_retried(): void
    {
        $upload = $this->intent('pending', ['original_path' => null]);
        \Illuminate\Support\Facades\Event::listen('eloquent.updating: ' . PendingUpload::class, function (PendingUpload $row) {
            if ($row->status === 'ready') {
                throw new \Illuminate\Database\QueryException('pgsql', 'update pending_uploads', [], new \Exception('connection lost'));
            }
        });

        try {
            app(UploadIntentService::class)->store(PendingUpload::find($upload->id), $this->png());
            $this->fail('the store should have failed');
        } catch (\Illuminate\Database\QueryException) {
        }

        $this->assertSame('pending', DB::table('pending_uploads')->where('id', $upload->id)->value('status'));
        $this->assertSame([], Storage::disk('public')->allFiles('uploads'), 'nothing left on disk');
    }

    /** Security review of b5a81831: prune deleted from its snapshot; a row that changed since is left alone. */
    public function test_prune_leaves_an_upload_that_changed_after_it_was_selected(): void
    {
        $upload = $this->intent('ready');
        DB::table('pending_uploads')->where('id', $upload->id)->update(['expires_at' => now()->subHours(25)]);
        Storage::disk('public')->put('uploads/originals/n8.png', 'png');
        $done = false;
        \Illuminate\Support\Facades\Event::listen('eloquent.retrieved: ' . PendingUpload::class, function (PendingUpload $loaded) use ($upload, &$done) {
            if (! $done && $loaded->id === $upload->id) {
                $done = true;
                DB::table('pending_uploads')->where('id', $upload->id)->update(['consumed_at' => now(), 'consumed_by_type' => Item::class, 'consumed_by_id' => 114]);
            }
        });

        $this->artisan('mobile:prune-uploads')->assertSuccessful();

        $this->assertTrue(DB::table('pending_uploads')->where('id', $upload->id)->exists());
        Storage::disk('public')->assertExists('uploads/originals/n8.png');
    }

    public function test_prune_removes_an_unused_ready_upload_a_day_past_expiry_and_keeps_a_used_one(): void
    {
        $unused = $this->intent('ready');
        $used = $this->intent('ready', ['original_path' => 'uploads/originals/n8-used.png']);
        DB::table('pending_uploads')->whereIn('id', [$unused->id, $used->id])->update(['expires_at' => now()->subHours(25)]);
        DB::table('pending_uploads')->where('id', $used->id)->update(['consumed_at' => now()->subHours(25), 'consumed_by_type' => Item::class, 'consumed_by_id' => 113]);
        Storage::disk('public')->put('uploads/originals/n8.png', 'png');
        Storage::disk('public')->put('uploads/originals/n8-used.png', 'png');

        $this->artisan('mobile:prune-uploads')->assertSuccessful();

        $this->assertFalse(DB::table('pending_uploads')->where('id', $unused->id)->exists());
        Storage::disk('public')->assertMissing('uploads/originals/n8.png');
        $this->assertTrue(DB::table('pending_uploads')->where('id', $used->id)->exists());
        Storage::disk('public')->assertExists('uploads/originals/n8-used.png');
    }

    public function test_store_does_not_resurrect_an_upload_that_expired_or_was_stored_meanwhile(): void
    {
        $upload = $this->intent('pending', ['original_path' => null]);
        $stale  = PendingUpload::find($upload->id);
        DB::table('pending_uploads')->where('id', $upload->id)->update(['status' => 'expired']);

        try {
            app(UploadIntentService::class)->store($stale, $this->png());
            $this->fail('Bytes were stored on an upload that is no longer pending.');
        } catch (\LogicException|\RuntimeException $e) {
            // refused
        }

        $this->assertSame('expired', DB::table('pending_uploads')->where('id', $upload->id)->value('status'));
        $this->assertEmpty(Storage::disk('public')->allFiles());
    }

    public function test_item_edit_with_an_upload_consumed_meanwhile_changes_nothing(): void
    {
        $upload = $this->intent('ready');
        $user   = \App\Models\User::find($upload->user_id);
        $shop   = \App\Models\Shop::find($upload->shop_id);
        $this->seedRetailerPricing($shop, $user);
        TenantContext::runFor($shop->id, fn () => \App\Models\Category::create(['shop_id' => $shop->id, 'name' => 'Ring']));
        $this->postJson('/api/mobile/items', [
            'barcode' => 'N8-1', 'category' => 'Ring', 'metal_type' => 'gold', 'purity' => 22,
            'gross_weight' => 5, 'stone_weight' => 0, 'cost_price' => 0, 'selling_price' => 0,
        ])->assertCreated();
        $item = Item::withoutTenant()->where('barcode', 'N8-1')->firstOrFail();
        $this->changedOnceLoaded($upload, ['consumed_at' => now(), 'consumed_by_type' => Item::class, 'consumed_by_id' => 111]);
        TenantContext::set((int) $shop->id);

        $response = $this->patchJson("/api/mobile/items/{$item->id}", ['image_upload_id' => $upload->upload_token]);

        $response->assertStatus(422);
        $this->assertNull(Item::withoutTenant()->find($item->id)->image);
        $this->assertSame(111, (int) DB::table('pending_uploads')->where('id', $upload->id)->value('consumed_by_id'));
    }
}
