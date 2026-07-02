<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\OnboardingBatch;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Onboarding Phase 1 — batch orchestration + state machine + atomic lock.
 * Covers: create, one-active-batch guard, lock (+ idempotent double-lock),
 * cancel, and owner-only enforcement.
 *
 * Two console-only test artifacts are handled here (production is unaffected):
 *  - Test-side reads use withoutTenant(): EnsureTenantUser clears TenantContext
 *    when each request tears down, so a scoped query afterwards finds nothing.
 *  - Route-model binding needs TenantContext::set() before any request that
 *    binds {onboarding}: resolveTenantShopId() returns null under
 *    runningInConsole(), so the Auth fallback that resolves it in a real
 *    browser request does not fire in tests. Re-set before each such request
 *    because the middleware clears context on every teardown.
 */
class OnboardingBatchTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    private function batches()
    {
        return OnboardingBatch::withoutTenant();
    }

    public function test_owner_creates_batch_with_as_of_day_before_start(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();

        $this->actingAs($user)
            ->post(route('onboarding.store'), ['start_date' => '2026-08-01'])
            ->assertRedirect(route('onboarding.index'));

        $batch = $this->batches()->firstOrFail();
        $this->assertSame($shop->id, $batch->shop_id);
        $this->assertSame('2026-08-01', $batch->start_date->toDateString());
        $this->assertSame('2026-07-31', $batch->as_of_date->toDateString());
        $this->assertSame(OnboardingBatch::STATUS_DRAFT, $batch->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'onboarding_batch_created', 'model_id' => $batch->id]);
    }

    public function test_only_one_active_batch_per_shop(): void
    {
        [$user] = $this->createManufacturerTenant();

        $this->actingAs($user)->post(route('onboarding.store'), ['start_date' => '2026-08-01']);
        $this->actingAs($user)
            ->post(route('onboarding.store'), ['start_date' => '2026-09-01'])
            ->assertSessionHasErrors('start_date');

        $this->assertSame(1, $this->batches()->whereNotIn('status', OnboardingBatch::TERMINAL)->count());
    }

    public function test_lock_transitions_to_locked_and_is_idempotent(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();

        $this->actingAs($user)->post(route('onboarding.store'), ['start_date' => '2026-08-01']);
        $batch = $this->batches()->firstOrFail();

        TenantContext::set($shop->id);
        $this->actingAs($user)->post(route('onboarding.lock', $batch))->assertRedirect(route('onboarding.index'));

        $batch = $this->batches()->find($batch->id);
        $this->assertSame(OnboardingBatch::STATUS_LOCKED, $batch->status);
        $this->assertNotNull($batch->locked_at);
        $lockedAt = $batch->locked_at;

        // Second lock must be a no-op: rejected, no re-post, no extra audit row.
        TenantContext::set($shop->id);
        $this->actingAs($user)->post(route('onboarding.lock', $batch))->assertSessionHasErrors('lock');

        $batch = $this->batches()->find($batch->id);
        $this->assertEquals($lockedAt, $batch->locked_at);
        $this->assertSame(1, AuditLog::withoutTenant()->where('action', 'onboarding_batch_locked')->count());
    }

    public function test_cancel_frees_the_active_slot(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();

        $this->actingAs($user)->post(route('onboarding.store'), ['start_date' => '2026-08-01']);
        $batch = $this->batches()->firstOrFail();

        TenantContext::set($shop->id);
        $this->actingAs($user)->post(route('onboarding.cancel', $batch))->assertRedirect(route('onboarding.index'));
        $this->assertSame(OnboardingBatch::STATUS_CANCELLED, $this->batches()->find($batch->id)->status);

        // A new batch can now be created (the partial unique index slot is free).
        $this->actingAs($user)
            ->post(route('onboarding.store'), ['start_date' => '2026-09-01'])
            ->assertRedirect(route('onboarding.index'));
        $this->assertSame(2, $this->batches()->count());
    }

    public function test_non_owner_is_forbidden_even_with_imports_permission(): void
    {
        [$owner, $shop] = $this->createManufacturerTenant();

        // A staff role holding imports.manage (passes middleware) but not "owner"
        // (fails the in-controller assertOwner) must be blocked.
        $staffRole = new Role();
        $staffRole->forceFill(['name' => 'manager', 'display_name' => 'Manager', 'shop_id' => $shop->id]);
        $staffRole->save();
        $staffRole->permissions()->sync(Permission::where('name', 'imports.manage')->pluck('id'));

        $staff = User::factory()->create(['shop_id' => $shop->id, 'role_id' => $staffRole->id, 'is_active' => true]);

        $this->actingAs($staff)
            ->post(route('onboarding.store'), ['start_date' => '2026-08-01'])
            ->assertForbidden();

        $this->assertSame(0, $this->batches()->count());
    }
}
