<?php

namespace Tests\Feature\Masters;

use App\Models\AuditLog;
use App\Models\Item;
use App\Models\JobOrder;
use App\Models\Karigar;
use App\Models\MetalLot;
use App\Models\Role;
use App\Services\JobOrderService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * MASTERS PART 6 — Retailer Karigar master lifecycle & access.
 *
 * Karigar joins Customer/Vendor on the shared Part-3 archive/reactivate
 * standard: disabling withdraws a karigar from NEW items, jobs and commitments
 * while every existing item, job order, invoice, payment and balance keeps
 * pointing at it. There is deliberately NO global active scope — history and
 * settlement always see disabled karigars.
 *
 * The console-only tenant quirks documented in PartyLifecycleTest apply here
 * too: forceCreate/forceFill for shop_id and is_active (is_active is NOT
 * fillable — it only moves through archive/reactivate), and
 * TenantContext::runFor() around requests that use route-model binding.
 */
class KarigarLifecycleTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
    }

    private function makeKarigar(int $shopId, array $attrs = []): Karigar
    {
        $karigar = new Karigar();
        $karigar->forceFill(array_merge([
            'shop_id' => $shopId,
            'name' => 'Test Karigar ' . fake()->unique()->numerify('###'),
            'is_active' => true,
        ], $attrs));
        $karigar->save();

        return $karigar;
    }

    private function archive(int $shopId, Karigar $karigar, array $payload = [])
    {
        return TenantContext::runFor($shopId, fn () => $this->patch(route('karigars.archive', $karigar), $payload));
    }

    private function reactivate(int $shopId, Karigar $karigar)
    {
        return TenantContext::runFor($shopId, fn () => $this->patch(route('karigars.reactivate', $karigar)));
    }

    private function auditCount(int $shopId, string $action): int
    {
        return AuditLog::withoutTenant()->where('shop_id', $shopId)->where('action', $action)->count();
    }

    // ── Access / edition / permission ────────────────────────────────────

    public function test_index_requires_the_view_permission(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $this->grantOnlyPermissions($user, []); // no karigar.view
        $this->actingAs($user);

        TenantContext::runFor($shop->id, fn () => $this->get(route('karigars.index')))->assertForbidden();
    }

    public function test_lifecycle_requires_the_manage_permission(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        // View, but not manage: reading the directory is a lesser act than
        // disabling an artisan.
        $this->grantOnlyPermissions($user, ['karigar.view']);
        $this->actingAs($user);

        $karigar = $this->makeKarigar($shop->id);

        $this->archive($shop->id, $karigar)->assertForbidden();
        $this->assertTrue((bool) $karigar->fresh()->is_active);
    }

    public function test_manufacturer_only_shop_cannot_reach_the_retailer_karigar_admin(): void
    {
        // Karigar admin is retailer job-work. A manufacturer-only shop being
        // unable to reach it is correct, not a regression.
        [$user, $shop] = $this->createManufacturerTenant();
        $this->actingAs($user);

        TenantContext::runFor($shop->id, fn () => $this->get(route('karigars.index')))->assertForbidden();
    }

    public function test_a_cross_shop_karigar_is_not_found_never_forbidden_leak(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        [, $otherShop] = $this->createRetailerTenant();
        $this->actingAs($user);

        $foreign = $this->makeKarigar($otherShop->id);

        // Route-model binding is tenant-scoped, so a foreign id resolves to 404.
        TenantContext::runFor($shop->id, fn () => $this->get(route('karigars.show', $foreign)))->assertNotFound();
        $this->archive($shop->id, $foreign)->assertNotFound();
        $this->assertTrue((bool) Karigar::withoutTenant()->find($foreign->id)->is_active);
    }

    public function test_a_read_only_shop_cannot_disable_a_karigar(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $this->actingAs($user);
        $karigar = $this->makeKarigar($shop->id);

        $shop->forceFill(['access_mode' => 'read_only'])->save();
        $this->actingAs($user->fresh());

        $this->archive($shop->id, $karigar);

        // The security property: no state change, no audit row.
        $this->assertTrue((bool) $karigar->fresh()->is_active);
        $this->assertSame(0, $this->auditCount($shop->id, 'karigar_archived'));
    }

    // ── Lifecycle: disable / reactivate / toggle compat ──────────────────

    public function test_disable_flips_is_active_and_writes_one_audit_row(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $this->actingAs($user);
        $karigar = $this->makeKarigar($shop->id);

        $this->archive($shop->id, $karigar)
            ->assertRedirect(route('karigars.show', $karigar))
            ->assertSessionHas('success');

        $this->assertFalse((bool) $karigar->fresh()->is_active);
        $this->assertSame(1, $this->auditCount($shop->id, 'karigar_archived'));

        $audit = AuditLog::withoutTenant()->where('action', 'karigar_archived')->firstOrFail();
        $this->assertSame((int) $user->id, (int) $audit->user_id);
        $this->assertSame(['is_active' => true], $audit->before);
        $this->assertSame(['is_active' => false], $audit->after);
    }

    public function test_disable_is_idempotent(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $this->actingAs($user);
        $karigar = $this->makeKarigar($shop->id);

        $this->archive($shop->id, $karigar)->assertSessionHas('success');
        $this->archive($shop->id, $karigar)->assertSessionHas('error');

        $this->assertFalse((bool) $karigar->fresh()->is_active);
        $this->assertSame(1, $this->auditCount($shop->id, 'karigar_archived'));
    }

    public function test_reactivate_restores_eligibility_and_audits(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $this->actingAs($user);
        $karigar = $this->makeKarigar($shop->id);
        $this->archive($shop->id, $karigar);

        $this->reactivate($shop->id, $karigar)->assertSessionHas('success');

        $this->assertTrue((bool) $karigar->fresh()->is_active);
        $this->assertSame(1, $this->auditCount($shop->id, 'karigar_reactivated'));
    }

    public function test_a_crafted_update_cannot_flip_is_active(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $this->actingAs($user);
        $karigar = $this->makeKarigar($shop->id, ['name' => 'Original Name']);

        // is_active is gone from the validator and $fillable; a hand-crafted
        // edit can rename but never disable.
        TenantContext::runFor($shop->id, fn () => $this->put(route('karigars.update', $karigar), [
            'name' => 'Renamed Karigar',
            'is_active' => 0,
        ]))->assertRedirect(route('karigars.show', $karigar));

        $karigar->refresh();
        $this->assertSame('Renamed Karigar', $karigar->name);
        $this->assertTrue((bool) $karigar->is_active);
    }

    public function test_legacy_toggle_route_is_audited_and_idempotent_via_the_same_path(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $this->actingAs($user);
        $karigar = $this->makeKarigar($shop->id);

        // The old toggle route now delegates to setPartyActive: disabling once
        // writes exactly one audit row, no raw flip.
        TenantContext::runFor($shop->id, fn () => $this->patch(route('karigars.toggle', $karigar)))
            ->assertSessionHas('success');

        $this->assertFalse((bool) $karigar->fresh()->is_active);
        $this->assertSame(1, $this->auditCount($shop->id, 'karigar_archived'));
    }

    // ── Safe deletion ────────────────────────────────────────────────────

    public function test_delete_is_blocked_when_items_reference_the_karigar(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $this->actingAs($user);
        $karigar = $this->makeKarigar($shop->id);
        // items.karigar_id is nullOnDelete — deleting would strip the karigar off
        // this historical stock, so it must be blocked.
        $this->createItem($shop->id, null, ['karigar_id' => $karigar->id]);

        TenantContext::runFor($shop->id, fn () => $this->delete(route('karigars.destroy', $karigar)))
            ->assertSessionHas('error');

        $this->assertStringContainsString('Disable instead', (string) session('error'));
        $this->assertDatabaseHas('karigars', ['id' => $karigar->id]);
    }

    public function test_delete_is_blocked_when_job_orders_reference_the_karigar(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $this->actingAs($user);
        $karigar = $this->makeKarigar($shop->id);
        $this->makeJobOrder($shop->id, $karigar->id);

        TenantContext::runFor($shop->id, fn () => $this->delete(route('karigars.destroy', $karigar)))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('karigars', ['id' => $karigar->id]);
    }

    public function test_an_unused_karigar_can_be_hard_deleted(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $this->actingAs($user);
        $karigar = $this->makeKarigar($shop->id);

        TenantContext::runFor($shop->id, fn () => $this->delete(route('karigars.destroy', $karigar)))
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('karigars', ['id' => $karigar->id]);
    }

    // ── New assignment: item create ──────────────────────────────────────

    public function test_a_disabled_karigar_cannot_be_attached_to_a_new_item(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $this->seedRetailerPricing($shop, $user);
        $this->actingAs($user);

        $active = $this->makeKarigar($shop->id);
        $disabled = $this->makeKarigar($shop->id);
        $this->archive($shop->id, $disabled);

        $payload = fn (int $karigarId, string $barcode) => [
            'barcode' => $barcode,
            'design' => 'Bangle',
            'category' => 'Gold Jewellery',
            'metal_type' => 'gold',
            'gross_weight' => 10,
            'stone_weight' => 0,
            'purity' => 22,
            'making_charges' => 300,
            'stone_charges' => 0,
            'karigar_id' => $karigarId,
        ];

        TenantContext::runFor($shop->id, fn () => $this->post(route('inventory.items.store'), $payload($disabled->id, 'P6-D-1')))
            ->assertSessionHasErrors(['karigar_id' => Karigar::archivedMessage()]);

        // No partial write: the rejected item never persisted.
        $this->assertSame(0, Item::withoutTenant()->where('shop_id', $shop->id)->where('barcode', 'P6-D-1')->count());

        // Control: the same payload with an active karigar clears this field and persists.
        TenantContext::runFor($shop->id, fn () => $this->post(route('inventory.items.store'), $payload($active->id, 'P6-A-1')))
            ->assertSessionDoesntHaveErrors('karigar_id');

        $item = Item::withoutTenant()->where('shop_id', $shop->id)->where('barcode', 'P6-A-1')->firstOrFail();
        $this->assertSame((int) $active->id, (int) $item->karigar_id);
    }

    // ── New assignment: item update grandfather ──────────────────────────

    public function test_an_item_stays_editable_after_its_karigar_is_disabled(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $this->seedRetailerPricing($shop, $user);
        $this->actingAs($user);

        $karigar = $this->makeKarigar($shop->id);
        $item = $this->createItem($shop->id, null, ['karigar_id' => $karigar->id, 'barcode' => 'P6-EDIT-1']);
        $this->archive($shop->id, $karigar);

        // Disabling withdraws a karigar from NEW work; the obligation already on
        // this item stays editable with the same karigar attached.
        TenantContext::runFor($shop->id, fn () => $this->put(route('inventory.items.update', $item), [
            'barcode' => 'P6-EDIT-1',
            'design' => 'Renamed Design',
            'category' => 'Gold Jewellery',
            'metal_type' => 'gold',
            'gross_weight' => 10,
            'purity' => 22,
            'karigar_id' => $karigar->id,
        ]))->assertSessionDoesntHaveErrors('karigar_id');

        $this->assertSame('Renamed Design', $item->fresh()->design);
    }

    public function test_retagging_an_item_onto_a_different_disabled_karigar_is_rejected(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $this->seedRetailerPricing($shop, $user);
        $this->actingAs($user);

        $current = $this->makeKarigar($shop->id);
        $otherDisabled = $this->makeKarigar($shop->id);
        $this->archive($shop->id, $otherDisabled);

        $item = $this->createItem($shop->id, null, ['karigar_id' => $current->id, 'barcode' => 'P6-EDIT-2']);

        TenantContext::runFor($shop->id, fn () => $this->put(route('inventory.items.update', $item), [
            'barcode' => 'P6-EDIT-2',
            'design' => 'Should Not Apply',
            'category' => 'Gold Jewellery',
            'metal_type' => 'gold',
            'gross_weight' => 10,
            'purity' => 22,
            'karigar_id' => $otherDisabled->id,
        ]))->assertSessionHasErrors(['karigar_id' => Karigar::archivedMessage()]);

        // No partial write: neither the link nor the edited field moved.
        $fresh = $item->fresh();
        $this->assertSame((int) $current->id, (int) $fresh->karigar_id);
        $this->assertNotSame('Should Not Apply', $fresh->design);
    }

    public function test_retagging_an_item_onto_an_active_karigar_succeeds(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $this->seedRetailerPricing($shop, $user);
        $this->actingAs($user);

        $current = $this->makeKarigar($shop->id);
        $this->archive($shop->id, $current);
        $target = $this->makeKarigar($shop->id);

        $item = $this->createItem($shop->id, null, ['karigar_id' => $current->id, 'barcode' => 'P6-EDIT-3']);

        TenantContext::runFor($shop->id, fn () => $this->put(route('inventory.items.update', $item), [
            'barcode' => 'P6-EDIT-3',
            'design' => 'Moved',
            'category' => 'Gold Jewellery',
            'metal_type' => 'gold',
            'gross_weight' => 10,
            'purity' => 22,
            'karigar_id' => $target->id,
        ]))->assertSessionDoesntHaveErrors('karigar_id');

        $this->assertSame((int) $target->id, (int) $item->fresh()->karigar_id);
    }

    // ── New assignment: job-work paths ───────────────────────────────────

    public function test_a_job_cannot_be_reassigned_to_a_disabled_karigar(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $this->actingAs($user);

        $from = $this->makeKarigar($shop->id);
        $disabled = $this->makeKarigar($shop->id);
        $this->archive($shop->id, $disabled);
        $job = $this->makeJobOrder($shop->id, $from->id);

        TenantContext::runFor($shop->id, fn () => $this->from(route('job-orders.show', $job))->post(route('job-orders.reassign', $job), [
            'to_karigar_id' => $disabled->id,
        ]))->assertSessionHasErrors(['to_karigar_id' => Karigar::archivedMessage()]);

        $this->assertSame((int) $from->id, (int) $job->fresh()->karigar_id);
    }

    public function test_held_metal_cannot_be_transferred_to_a_disabled_karigar(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $this->actingAs($user);

        $from = $this->makeKarigar($shop->id);
        $disabled = $this->makeKarigar($shop->id);
        $this->archive($shop->id, $disabled);

        TenantContext::runFor($shop->id, fn () => $this->post(route('karigar-balance.transfer'), [
            'from_karigar_id' => $from->id,
            'to_karigar_id' => $disabled->id,
            'metal_type' => 'gold',
            'purity' => 22,
            'fine_weight' => 5,
        ]))->assertSessionHasErrors(['to_karigar_id' => Karigar::archivedMessage()]);
    }

    // ── Historical continuity + directory filter ─────────────────────────

    public function test_disabled_karigar_is_retained_and_still_resolves_history(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $this->actingAs($user);

        $karigar = $this->makeKarigar($shop->id);
        $item = $this->createItem($shop->id, null, ['karigar_id' => $karigar->id]);
        $this->archive($shop->id, $karigar);

        // No global active scope: the disabled karigar is still findable and the
        // historical item still points at it. Read inside the tenant context so
        // the BelongsToShop scope resolves (outside it, it fail-closes to 1=0).
        TenantContext::runFor($shop->id, function () use ($karigar, $item) {
            $found = Karigar::query()->find($karigar->id);
            $this->assertNotNull($found);
            $this->assertSame((int) $karigar->id, (int) $item->fresh()->karigar_id);
            $this->assertSame(1, $karigar->fresh()->items()->count());
        });
    }

    public function test_directory_defaults_to_active_and_can_show_disabled_or_all(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $this->actingAs($user);

        $active = $this->makeKarigar($shop->id, ['name' => 'Aarav Active']);
        $disabled = $this->makeKarigar($shop->id, ['name' => 'Dev Disabled']);
        $this->archive($shop->id, $disabled);
        // Burn the flash — the disable confirmation names the karigar.
        TenantContext::runFor($shop->id, fn () => $this->get(route('karigars.index')));

        $default = TenantContext::runFor($shop->id, fn () => $this->get(route('karigars.index')));
        $default->assertSee('Aarav Active')->assertDontSee('Dev Disabled');
        $default->assertViewHas('status', 'active');

        TenantContext::runFor($shop->id, fn () => $this->get(route('karigars.index', ['status' => 'archived'])))
            ->assertSee('Dev Disabled')
            ->assertDontSee('Aarav Active');

        $all = TenantContext::runFor($shop->id, fn () => $this->get(route('karigars.index', ['status' => 'all'])));
        $all->assertSee('Aarav Active')->assertSee('Dev Disabled');

        $counts = $all->viewData('counts');
        $this->assertSame(2, (int) $counts->all_count);
        $this->assertSame(1, (int) $counts->active_count);
        $this->assertSame(1, (int) $counts->archived_count);
    }

    public function test_item_create_selector_hides_disabled_karigars(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $this->seedRetailerPricing($shop, $user);
        $this->actingAs($user);

        $active = $this->makeKarigar($shop->id, ['name' => 'Selectable Smith']);
        $disabled = $this->makeKarigar($shop->id, ['name' => 'Hidden Hammer']);
        $this->archive($shop->id, $disabled);
        // Burn the flash — the disable confirmation names the karigar, and it
        // would otherwise render on the next page and defeat the assertion.
        TenantContext::runFor($shop->id, fn () => $this->get(route('karigars.index')));

        $response = TenantContext::runFor($shop->id, fn () => $this->get(route('inventory.items.create')));
        $response->assertOk()
            ->assertSee('Selectable Smith')
            ->assertDontSee('Hidden Hammer');
    }

    // ── helpers ──────────────────────────────────────────────────────────

    private function makeJobOrder(int $shopId, int $karigarId): JobOrder
    {
        return JobOrder::forceCreate([
            'shop_id' => $shopId,
            'karigar_id' => $karigarId,
            'job_order_number' => 'JO-' . fake()->unique()->numerify('######'),
            'metal_type' => 'gold',
            'purity' => 22,
            'issue_date' => now()->toDateString(),
            'status' => 'issued',
        ]);
    }

    private function vaultLot(int $shopId, float $fine = 100.0): MetalLot
    {
        $lot = new MetalLot();
        $lot->forceFill([
            'shop_id' => $shopId,
            'source' => 'purchase',
            'metal_type' => 'gold',
            'purity' => 22.00,
            'fine_weight_total' => $fine,
            'fine_weight_remaining' => $fine,
            'cost_per_fine_gram' => 5000,
        ]);
        $lot->save();

        return $lot;
    }

    private function grant(\App\Models\User $user, string ...$perms): void
    {
        $role = Role::withoutTenant()->findOrFail($user->role_id);
        foreach ($perms as $p) {
            $role->givePermission($p);
        }
    }

    private function jobOrderCount(int $shopId): int
    {
        return JobOrder::withoutTenant()->where('shop_id', $shopId)->count();
    }

    // ── New assignment: web job-order create (Section 2 — race guard) ─────

    public function test_web_job_order_create_rejects_a_disabled_karigar_with_no_partial_write(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $this->withoutMiddleware(\Illuminate\Auth\Middleware\Authorize::class);
        $this->actingAs($user);

        $disabled = $this->makeKarigar($shop->id);
        $this->archive($shop->id, $disabled);

        // Labor-only payload isolates the karigar guard: no metal movement, so a
        // stray JobOrder row could only come from the guard leaking.
        TenantContext::runFor($shop->id, fn () => $this->post(route('job-orders.store'), [
            'karigar_id' => $disabled->id,
            'metal_type' => 'gold',
            'purity' => 22,
            'allowed_wastage_percent' => 5,
            'issue_date' => now()->toDateString(),
            'metal_source' => 'none',
            'job_type' => 'repair',
        ]))->assertSessionHasErrors(['karigar_id' => Karigar::archivedMessage()]);

        $this->assertSame(0, $this->jobOrderCount($shop->id));
    }

    public function test_web_job_order_create_succeeds_for_an_active_karigar(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $this->withoutMiddleware(\Illuminate\Auth\Middleware\Authorize::class);
        $this->actingAs($user);

        $active = $this->makeKarigar($shop->id);

        TenantContext::runFor($shop->id, fn () => $this->post(route('job-orders.store'), [
            'karigar_id' => $active->id,
            'metal_type' => 'gold',
            'purity' => 22,
            'allowed_wastage_percent' => 5,
            'issue_date' => now()->toDateString(),
            'metal_source' => 'none',
            'job_type' => 'repair',
        ]))->assertSessionDoesntHaveErrors('karigar_id');

        $this->assertSame(1, $this->jobOrderCount($shop->id));
    }

    /**
     * Layer-2 proof: call the shared authoritative write directly, bypassing the
     * controller's Layer-1 validation entirely. Only the in-transaction
     * lockActiveOrFail can stop the write here — if that lock were removed, this
     * test fails while the controller tests (which still catch at Layer 1) pass,
     * pinpointing the race guard.
     */
    public function test_job_order_service_issue_locks_out_a_disabled_karigar_with_no_partial_write(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $this->actingAs($user);
        $disabled = $this->makeKarigar($shop->id);
        $this->archive($shop->id, $disabled);

        TenantContext::runFor($shop->id, function () use ($shop, $user, $disabled) {
            try {
                app(JobOrderService::class)->issue([
                    'karigar_id' => $disabled->id,
                    'metal_type' => 'gold',
                    'purity' => 22,
                    'allowed_wastage_percent' => 5,
                    'issue_date' => now()->toDateString(),
                    'metal_source' => 'none',
                ], (int) $shop->id, (int) $user->id);
                $this->fail('Expected the disabled karigar to be locked out of a new job order.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('karigar_id', $e->errors());
            }
        });

        $this->assertSame(0, $this->jobOrderCount($shop->id));
    }

    // ── New assignment: mobile V1 job-order issue (Section 1) ────────────

    public function test_mobile_v1_job_order_issue_rejects_a_disabled_karigar(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $this->grant($user, 'job_order.manage');
        $this->actingAs($user);
        $disabled = $this->makeKarigar($shop->id);
        $this->archive($shop->id, $disabled);
        $lot = $this->vaultLot($shop->id);

        Sanctum::actingAs($user);
        TenantContext::set((int) $shop->id);

        $response = $this->postJson('/api/mobile/v1/job-orders', [
            'karigar_id' => $disabled->id,
            'metal_type' => 'gold',
            'purity' => 22,
            'allowed_wastage_percent' => 5,
            'issuances' => [['metal_lot_id' => $lot->id, 'gross_weight' => 5, 'fine_weight' => 4.58]],
        ], ['X-Idempotency-Key' => 'jo-reject-' . uniqid()]);

        $response->assertStatus(422);
        $fields = array_column($response->json('errors') ?? [], 'field');
        $this->assertContains('karigar_id', $fields);

        // No partial write — a disabled karigar never gets a job or a lot debit.
        $this->assertSame(0, $this->jobOrderCount($shop->id));
        $this->assertEqualsWithDelta(100.0, (float) $lot->fresh()->fine_weight_remaining, 0.0001);
    }

    public function test_mobile_v1_job_order_issue_succeeds_for_an_active_karigar(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $this->grant($user, 'job_order.manage');
        $active = $this->makeKarigar($shop->id);
        $lot = $this->vaultLot($shop->id);

        Sanctum::actingAs($user);
        TenantContext::set((int) $shop->id);

        $response = $this->postJson('/api/mobile/v1/job-orders', [
            'karigar_id' => $active->id,
            'metal_type' => 'gold',
            'purity' => 22,
            'allowed_wastage_percent' => 5,
            'issuances' => [['metal_lot_id' => $lot->id, 'gross_weight' => 5, 'fine_weight' => 4.58]],
        ], ['X-Idempotency-Key' => 'jo-ok-' . uniqid()]);

        $response->assertStatus(201);
        $this->assertSame(1, $this->jobOrderCount($shop->id));
    }

    // ── New assignment: mobile item create (Section 3) ───────────────────

    public function test_mobile_item_create_rejects_a_disabled_karigar_with_no_partial_write(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $this->seedRetailerPricing($shop, $user);
        $this->grant($user, 'inventory.create');
        $this->actingAs($user);
        $disabled = $this->makeKarigar($shop->id);
        $this->archive($shop->id, $disabled);

        Sanctum::actingAs($user);
        TenantContext::set((int) $shop->id);

        $response = $this->postJson('/api/mobile/items', [
            'barcode' => 'P6-MOB-1',
            'design' => 'Bangle',
            'category' => 'Gold Jewellery',
            'metal_type' => 'gold',
            'gross_weight' => 10,
            'stone_weight' => 0,
            'purity' => 22,
            'making_charges' => 300,
            'stone_charges' => 0,
            'karigar_id' => $disabled->id,
        ]);

        // Legacy /api/mobile/items is not wrapped by MobileEnvelope (only /v1 is),
        // so it returns Laravel's raw {errors:{field:[...]}} validation map.
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['karigar_id' => Karigar::archivedMessage()]);

        $this->assertSame(0, Item::withoutTenant()->where('shop_id', $shop->id)->where('barcode', 'P6-MOB-1')->count());
    }
}
