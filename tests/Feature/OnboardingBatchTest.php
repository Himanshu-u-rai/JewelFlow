<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\CashTransaction;
use App\Models\OnboardingBatch;
use App\Models\OnboardingEntry;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\OnboardingPostingService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    public function test_lock_failure_does_not_leave_batch_stuck_or_write_partial_rows(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();

        $this->actingAs($user)->post(route('onboarding.store'), ['start_date' => '2026-08-01']);
        $batch = $this->batches()->firstOrFail();

        // A posting service that writes ONE real ledger row, then throws — so the
        // test proves the enclosing transaction rolls the partial write back and
        // never leaves the batch stuck in "posting".
        $this->app->bind(OnboardingPostingService::class, fn () => new class extends OnboardingPostingService {
            public function post(OnboardingBatch $batch): array
            {
                CashTransaction::record([
                    'shop_id'      => $batch->shop_id,
                    'user_id'      => $batch->locked_by ?? $batch->created_by,
                    'type'         => 'in',
                    'amount'       => 999,
                    'source_type'  => 'opening_balance',
                    'payment_mode' => 'cash',
                    'description'  => 'partial write',
                    'is_opening'   => true,
                ]);

                throw new \RuntimeException('simulated posting failure');
            }
        });

        TenantContext::set($shop->id);
        $this->actingAs($user)->post(route('onboarding.lock', $batch))->assertSessionHasErrors('lock');

        // Not stuck: reverted to editable, never "posting"/"locked".
        $batch = $this->batches()->find($batch->id);
        $this->assertSame(OnboardingBatch::STATUS_DRAFT, $batch->status);
        $this->assertTrue($batch->isEditable());
        $this->assertNull($batch->locked_at);

        // No partial ledger rows survived the rollback.
        $this->assertSame(0, CashTransaction::withoutTenant()->count());

        // Failure audited; no success audit written.
        $this->assertSame(1, AuditLog::withoutTenant()->where('action', 'onboarding_batch_lock_failed')->count());
        $this->assertSame(0, AuditLog::withoutTenant()->where('action', 'onboarding_batch_locked')->count());

        // Owner can still cancel the recovered batch (proves not stuck).
        TenantContext::set($shop->id);
        $this->actingAs($user)->post(route('onboarding.cancel', $batch))->assertRedirect(route('onboarding.index'));
        $this->assertSame(OnboardingBatch::STATUS_CANCELLED, $this->batches()->find($batch->id)->status);
    }

    public function test_lock_is_blocked_when_as_of_date_is_financially_locked(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();

        // start 2026-08-01 → as-of 2026-07-31.
        $this->actingAs($user)->post(route('onboarding.store'), ['start_date' => '2026-08-01']);
        $batch = $this->batches()->firstOrFail();

        TenantContext::set($shop->id);
        $this->actingAs($user)->post(route('onboarding.entries.store', $batch), [
            'kind' => OnboardingEntry::KIND_CASH, 'payment_mode' => 'cash', 'amount' => 5000,
        ])->assertSessionHasNoErrors();

        // Close the period through the as-of date: opening posts back-date into it.
        DB::table('shop_rules')->updateOrInsert(
            ['shop_id' => $shop->id],
            ['financial_lock_date' => '2026-07-31']
        );

        TenantContext::set($shop->id);
        $this->actingAs($user)->post(route('onboarding.lock', $batch))->assertSessionHasErrors('lock');

        // Nothing posted behind the closed period; batch recoverable; failure audited.
        $batch = $this->batches()->find($batch->id);
        $this->assertSame(OnboardingBatch::STATUS_DRAFT, $batch->status);
        $this->assertTrue($batch->isEditable());
        $this->assertSame(0, CashTransaction::withoutTenant()->count());
        $this->assertSame(1, AuditLog::withoutTenant()->where('action', 'onboarding_batch_lock_failed')->count());
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
