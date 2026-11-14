<?php

namespace Tests\Feature\Admin;

use App\Http\Middleware\EnsurePlatformAdminMfa;
use App\Models\Shop;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Audit N22: a GDPR-deletion suspension was cleared by the bulk unsuspend (and nothing stopped the single-shop toggle)
 * while the "delete this shop" marker stayed, so a later cleanup would delete a reactivated shop. No unsuspend path may
 * reopen a shop whose deletion is pending; the bulk action says which shops it skipped.
 */
class GdprPendingDeletionUnsuspendTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    private function asAdmin(): self
    {
        $admin = $this->createPlatformAdmin();
        $admin->forceFill(['email_verified_at' => now()])->save();

        return $this->actingAs($admin, 'platform_admin')->withSession([EnsurePlatformAdminMfa::SESSION_PASSED => true]);
    }

    private function scheduledForDeletion(): Shop
    {
        [, $shop] = $this->createRetailerTenant();
        $this->asAdmin()->post(route('admin.shops.gdpr-delete', $shop), ['reason' => 'Owner asked for erasure'])->assertRedirect();
        $this->assertSame('suspended', $shop->fresh()->access_mode, 'precondition: deletion scheduled');

        return $shop;
    }

    public function test_bulk_unsuspend_skips_a_shop_with_pending_gdpr_deletion_and_names_it(): void
    {
        $pending = $this->scheduledForDeletion();
        [, $plain] = $this->createRetailerTenant();
        $this->asAdmin()->post(route('admin.shops.bulk'), ['action' => 'suspend', 'shop_ids' => [$plain->id]]);

        $response = $this->asAdmin()->post(route('admin.shops.bulk'), ['action' => 'unsuspend', 'shop_ids' => [$pending->id, $plain->id]]);

        $this->assertSame('suspended', $pending->fresh()->access_mode);
        $this->assertFalse((bool) $pending->fresh()->is_active);
        $this->assertSame('active', $plain->fresh()->access_mode, 'control: an ordinary suspended shop is unsuspended');
        $this->assertStringContainsString((string) $pending->name, (string) session('warning') . (string) session('success'));
        $this->assertStringContainsString('skipped', strtolower((string) session('warning') . (string) session('success')));
        $this->assertTrue(DB::table('platform_settings')->where('key', 'gdpr_pending_delete_shop_' . $pending->id)->exists());
    }

    public function test_single_shop_activation_is_refused_while_deletion_is_pending(): void
    {
        $pending = $this->scheduledForDeletion();

        $this->asAdmin()->patch(route('admin.shops.status', $pending), ['access_mode' => 'active'])->assertSessionHasErrors('access_mode');

        $this->assertSame('suspended', $pending->fresh()->access_mode);
        $this->assertFalse((bool) $pending->fresh()->is_active);
    }

    public function test_model_save_cannot_reactivate_a_shop_with_pending_deletion(): void
    {
        $pending = $this->scheduledForDeletion();

        try {
            $pending->fresh()->update(['access_mode' => 'active', 'is_active' => DB::raw('true')]);
            $this->fail('expected the reactivation to be refused');
        } catch (\DomainException) {
            // expected
        }
        $this->assertSame('suspended', $pending->fresh()->access_mode);
    }
}
