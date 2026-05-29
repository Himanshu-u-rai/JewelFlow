<?php

namespace Tests\Feature\Mobile\V1;

use App\Models\PendingUpload;
use App\Services\Mobile\UploadIntentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * M6 — Upload infrastructure contract tests.
 *
 * Covers: intent minting, byte storage, finalize/thumbnail, expiry,
 * cross-shop isolation, item creation with image_upload_id.
 */
class UploadInfrastructureTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        Storage::fake('public');
    }

    private function grant(\App\Models\User $user, string ...$perms): void
    {
        $role = \App\Models\Role::withoutTenant()->findOrFail($user->role_id);
        foreach ($perms as $p) {
            $role->givePermission($p);
        }
    }

    private function idempotency(string $tag = ''): array
    {
        return ['X-Idempotency-Key' => 'upload-test-' . $tag . '-' . uniqid()];
    }

    private function smallJpegBytes(): string
    {
        // 1×1 red JPEG — valid minimal file.
        $img = imagecreatetruecolor(1, 1);
        imagecolorallocate($img, 255, 0, 0);
        ob_start();
        imagejpeg($img, null, 85);
        $bytes = ob_get_clean();
        imagedestroy($img);
        return $bytes;
    }

    // ─── Intent minting ───────────────────────────────────────────────

    public function test_mint_intent_returns_upload_id_and_url(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $this->grant($user, 'inventory.create');
        Sanctum::actingAs($user);

        $response = $this->withHeaders($this->idempotency('mint'))
            ->postJson('/api/mobile/v1/uploads/intent', [
                'kind'         => 'item_image',
                'content_type' => 'image/jpeg',
                'size_bytes'   => 1024,
            ]);

        $response->assertStatus(201);
        $this->assertNotEmpty($response->json('data.upload_id'));
        $this->assertStringContainsString('/uploads/', $response->json('data.upload_url'));
        $this->assertSame('pending', $response->json('data.status'));
        $this->assertNotEmpty($response->json('data.expires_at'));
        $this->assertSame(UploadIntentService::MAX_SIZE_BYTES, $response->json('data.max_size_bytes'));
    }

    public function test_intent_rejects_unsupported_content_type(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $this->grant($user, 'inventory.create');
        Sanctum::actingAs($user);

        $response = $this->withHeaders($this->idempotency('ct'))
            ->postJson('/api/mobile/v1/uploads/intent', [
                'kind'         => 'item_image',
                'content_type' => 'application/pdf',
                'size_bytes'   => 512,
            ]);

        $response->assertStatus(422);
    }

    public function test_intent_rejects_oversize(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $this->grant($user, 'inventory.create');
        Sanctum::actingAs($user);

        $response = $this->withHeaders($this->idempotency('big'))
            ->postJson('/api/mobile/v1/uploads/intent', [
                'kind'         => 'item_image',
                'content_type' => 'image/jpeg',
                'size_bytes'   => UploadIntentService::MAX_SIZE_BYTES + 1,
            ]);

        $response->assertStatus(422);
    }

    public function test_intent_rejects_unknown_kind(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $this->grant($user, 'inventory.create');
        Sanctum::actingAs($user);

        $response = $this->withHeaders($this->idempotency('kind'))
            ->postJson('/api/mobile/v1/uploads/intent', [
                'kind'         => 'customer_selfie',
                'content_type' => 'image/jpeg',
                'size_bytes'   => 512,
            ]);

        $response->assertStatus(422);
    }

    // ─── Byte upload ─────────────────────────────────────────────────

    public function test_upload_bytes_returns_ready_status(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $this->grant($user, 'inventory.create', 'inventory.view');
        Sanctum::actingAs($user);

        // Create intent via service directly (bypasses idempotency).
        $service = app(UploadIntentService::class);
        $upload  = $service->mintIntent((int) $shop->id, (int) $user->id, 'item_image', 'image/jpeg', 512);
        $token   = $upload->upload_token;
        $bytes   = $this->smallJpegBytes();

        $response = $this->withHeaders([
            'Content-Type' => 'image/jpeg',
        ])->call('PUT', '/api/mobile/v1/uploads/' . $token, [], [], [], [], $bytes);

        $response->assertOk();
        $this->assertSame('ready', $response->json('status'));
        $this->assertNotEmpty($response->json('original_url'));

        // Row in DB is ready.
        $this->assertSame('ready', PendingUpload::find($upload->id)->status);
    }

    public function test_upload_bytes_rejects_expired_token(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $this->grant($user, 'inventory.create');
        Sanctum::actingAs($user);

        $upload = PendingUpload::create([
            'shop_id'             => $shop->id,
            'user_id'             => $user->id,
            'kind'                => 'item_image',
            'content_type'        => 'image/jpeg',
            'declared_size_bytes' => 512,
            'upload_token'        => 'expired-token-' . uniqid(),
            'status'              => 'pending',
            'expires_at'          => now()->subMinutes(30),
        ]);

        $response = $this->withHeaders(['Content-Type' => 'image/jpeg'])
            ->call('PUT', '/api/mobile/v1/uploads/' . $upload->upload_token, [], [], [], [], 'bytes');

        $response->assertStatus(410);
        $this->assertSame('upload_expired', $response->json('errors.0.code'));
    }

    public function test_upload_bytes_rejects_cross_shop_token(): void
    {
        [$userA, $shopA] = $this->createRetailerTenant();
        [$userB, $shopB] = $this->createRetailerTenant();
        $this->grant($userA, 'inventory.create');

        // Create token for shop B.
        $upload = app(UploadIntentService::class)
            ->mintIntent((int) $shopB->id, (int) $userB->id, 'item_image', 'image/jpeg', 512);

        Sanctum::actingAs($userA);
        $response = $this->withHeaders(['Content-Type' => 'image/jpeg'])
            ->call('PUT', '/api/mobile/v1/uploads/' . $upload->upload_token, [], [], [], [], 'bytes');

        $response->assertStatus(404);
    }

    // ─── Status polling ───────────────────────────────────────────────

    public function test_get_upload_shows_status(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $this->grant($user, 'inventory.create', 'inventory.view');
        Sanctum::actingAs($user);
        \App\Support\TenantContext::set((int) $shop->id);

        $upload = app(UploadIntentService::class)
            ->mintIntent((int) $shop->id, (int) $user->id, 'item_image', 'image/jpeg', 512);

        $response = $this->getJson('/api/mobile/v1/uploads/' . $upload->upload_token);
        $response->assertOk();
        $this->assertSame('pending', $response->json('data.status'));
    }

    // ─── Prune command ────────────────────────────────────────────────

    public function test_prune_removes_expired_pending_uploads(): void
    {
        [$user, $shop] = $this->createRetailerTenant();

        PendingUpload::create([
            'shop_id'             => $shop->id,
            'user_id'             => $user->id,
            'kind'                => 'item_image',
            'content_type'        => 'image/jpeg',
            'declared_size_bytes' => 100,
            'upload_token'        => 'prune-test-' . uniqid(),
            'status'              => 'pending',
            'expires_at'          => now()->subHour(),
        ]);

        $this->assertSame(1, PendingUpload::count());
        $this->artisan('mobile:prune-uploads')->assertSuccessful();
        $this->assertSame(0, PendingUpload::count());
    }
}
