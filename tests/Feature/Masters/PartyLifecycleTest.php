<?php

namespace Tests\Feature\Masters;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Repair;
use App\Models\Vendor;
use App\Services\RetailerSalesService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * MASTERS PART 3 — Customer & Vendor archive / reactivate lifecycle.
 *
 * Archiving withdraws a party from NEW commercial commitments. It is NOT a
 * delete and NOT a hide: every invoice, purchase, balance, repair and report
 * keeps pointing at the party, and existing obligations stay settleable.
 *
 * The console-only tenant quirks documented at length in CategoryDeleteGuardTest
 * apply here too, hence forceCreate/forceFill for shop_id and
 * TenantContext::runFor() around requests that use route-model binding.
 */
class PartyLifecycleTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    private function makeVendor(int $shopId, array $attrs = []): Vendor
    {
        $vendor = new Vendor();
        $vendor->forceFill(array_merge([
            'shop_id' => $shopId,
            'name' => 'Test Vendor',
            'is_active' => true,
        ], $attrs));
        $vendor->save();

        return $vendor;
    }

    private function archiveCustomer(int $shopId, Customer $customer, array $payload = [])
    {
        return TenantContext::runFor($shopId, fn () => $this->patch(route('customers.archive', $customer), $payload));
    }

    private function reactivateCustomer(int $shopId, Customer $customer)
    {
        return TenantContext::runFor($shopId, fn () => $this->patch(route('customers.reactivate', $customer)));
    }

    private function auditCount(int $shopId, string $action): int
    {
        return AuditLog::withoutTenant()->where('shop_id', $shopId)->where('action', $action)->count();
    }

    // ─────────────────────────────────────────────────────────────────
    // Customer lifecycle
    // ─────────────────────────────────────────────────────────────────

    public function test_archiving_a_customer_flips_is_active_and_writes_one_audit_row(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $this->actingAs($user);

        $customer = $this->createCustomer($shop->id);

        $this->archiveCustomer($shop->id, $customer)
            ->assertRedirect(route('customers.show', $customer))
            ->assertSessionHas('success');

        $this->assertFalse((bool) $customer->fresh()->is_active);
        $this->assertSame(1, $this->auditCount($shop->id, 'customer_archived'));

        $audit = AuditLog::withoutTenant()->where('action', 'customer_archived')->firstOrFail();
        // The audit contract: who, which shop, which target, and the exact
        // before/after state — enough to reconstruct the change without the row.
        $this->assertSame((int) $user->id, (int) $audit->user_id);
        $this->assertSame((int) $shop->id, (int) $audit->shop_id);
        $this->assertSame((int) $customer->id, (int) $audit->model_id);
        $this->assertSame(['is_active' => true], $audit->before);
        $this->assertSame(['is_active' => false], $audit->after);
        $this->assertNotNull($audit->created_at);
    }

    public function test_archive_records_an_optional_reason(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $this->actingAs($user);

        $customer = $this->createCustomer($shop->id);
        $this->archiveCustomer($shop->id, $customer, ['reason' => 'Moved abroad']);

        $audit = AuditLog::withoutTenant()->where('action', 'customer_archived')->firstOrFail();
        $this->assertSame(['reason' => 'Moved abroad'], $audit->data);
    }

    public function test_archiving_twice_is_idempotent(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $this->actingAs($user);

        $customer = $this->createCustomer($shop->id);

        $this->archiveCustomer($shop->id, $customer)->assertSessionHas('success');
        $this->archiveCustomer($shop->id, $customer)->assertSessionHas('error');

        $this->assertFalse((bool) $customer->fresh()->is_active);
        // No second state change means no second audit row.
        $this->assertSame(1, $this->auditCount($shop->id, 'customer_archived'));
    }

    public function test_reactivating_restores_the_customer_and_audits_it(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $this->actingAs($user);

        $customer = $this->createCustomer($shop->id);
        $this->archiveCustomer($shop->id, $customer);

        $this->reactivateCustomer($shop->id, $customer)->assertSessionHas('success');

        $this->assertTrue((bool) $customer->fresh()->is_active);
        $this->assertSame(1, $this->auditCount($shop->id, 'customer_reactivated'));
    }

    public function test_reactivating_an_active_customer_is_idempotent(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $this->actingAs($user);

        $customer = $this->createCustomer($shop->id);

        $this->reactivateCustomer($shop->id, $customer)->assertSessionHas('error');

        $this->assertTrue((bool) $customer->fresh()->is_active);
        $this->assertSame(0, $this->auditCount($shop->id, 'customer_reactivated'));
    }

    public function test_archive_is_not_blocked_by_history(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $this->actingAs($user);

        $customer = $this->createCustomer($shop->id);
        $invoice = new Invoice();
        $invoice->forceFill([
            'shop_id' => $shop->id,
            'customer_id' => $customer->id,
            'invoice_number' => 'INV-9001',
            'gold_rate' => 6000,
            'subtotal' => 1000,
            'gst' => 30,
            'total' => 1030,
            'status' => 'finalized',
        ]);
        $invoice->save();

        // Unlike hard delete, archive must NOT be blocked by history or balance —
        // withdrawing a party from new business is exactly what you do WITH a
        // customer who has history.
        $this->archiveCustomer($shop->id, $customer)->assertSessionHas('success');

        $this->assertFalse((bool) $customer->fresh()->is_active);
        // Retention: the invoice and its link are untouched.
        $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'customer_id' => $customer->id]);
    }

    public function test_cross_shop_customer_cannot_be_archived(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        [, $otherShop] = $this->createRetailerTenant();
        $this->actingAs($user);

        $foreign = $this->createCustomer($otherShop->id);

        $this->archiveCustomer($shop->id, $foreign)->assertNotFound();

        $this->assertTrue((bool) Customer::withoutTenant()->find($foreign->id)->is_active);
        $this->assertSame(0, $this->auditCount($otherShop->id, 'customer_archived'));
    }

    public function test_archive_requires_the_delete_permission(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        // View + edit but NOT delete: editing details is a lesser act than
        // withdrawing a party from all new business.
        $this->grantOnlyPermissions($user, ['customers.view', 'customers.edit']);
        $this->actingAs($user);

        $customer = $this->createCustomer($shop->id);

        $this->archiveCustomer($shop->id, $customer)->assertForbidden();

        $this->assertTrue((bool) $customer->fresh()->is_active);
    }

    // ─────────────────────────────────────────────────────────────────
    // Vendor lifecycle + legacy toggle removal
    // ─────────────────────────────────────────────────────────────────

    public function test_vendor_archive_and_reactivate_are_explicit_and_idempotent(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $this->actingAs($user);

        $vendor = $this->makeVendor($shop->id);

        TenantContext::runFor($shop->id, fn () => $this->patch(route('vendors.archive', $vendor)))
            ->assertSessionHas('success');
        $this->assertFalse((bool) $vendor->fresh()->is_active);

        TenantContext::runFor($shop->id, fn () => $this->patch(route('vendors.archive', $vendor)))
            ->assertSessionHas('error');
        $this->assertSame(1, $this->auditCount($shop->id, 'vendor_archived'));

        TenantContext::runFor($shop->id, fn () => $this->patch(route('vendors.reactivate', $vendor)))
            ->assertSessionHas('success');
        $this->assertTrue((bool) $vendor->fresh()->is_active);
        $this->assertSame(1, $this->auditCount($shop->id, 'vendor_reactivated'));
    }

    public function test_a_crafted_vendor_update_cannot_flip_is_active(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $this->actingAs($user);

        $vendor = $this->makeVendor($shop->id);

        // The old edit form posted is_active; the field is gone from the form,
        // the validator and $fillable, so a hand-crafted request is inert.
        TenantContext::runFor($shop->id, fn () => $this->put(route('vendors.update', $vendor), [
            'name' => 'Renamed Vendor',
            'is_active' => 0,
        ]))->assertRedirect(route('vendors.show', $vendor));

        $vendor->refresh();
        $this->assertSame('Renamed Vendor', $vendor->name, 'the legitimate part of the edit must still apply');
        $this->assertTrue((bool) $vendor->is_active, 'is_active must only move through archive/reactivate');
    }

    public function test_a_crafted_vendor_create_cannot_start_archived(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $this->actingAs($user);

        TenantContext::runFor($shop->id, fn () => $this->post(route('vendors.store'), [
            'name' => 'Fresh Vendor',
            'is_active' => 0,
        ]));

        $vendor = Vendor::withoutTenant()->where('shop_id', $shop->id)->where('name', 'Fresh Vendor')->firstOrFail();
        $this->assertTrue((bool) $vendor->is_active);
    }

    // ─────────────────────────────────────────────────────────────────
    // Hard delete offers archive (never performs it)
    // ─────────────────────────────────────────────────────────────────

    public function test_blocked_customer_delete_offers_archive_without_performing_it(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $this->actingAs($user);

        $customer = $this->createCustomer($shop->id);
        $invoice = new Invoice();
        $invoice->forceFill([
            'shop_id' => $shop->id,
            'customer_id' => $customer->id,
            'invoice_number' => 'INV-9002',
            'gold_rate' => 6000,
            'subtotal' => 1000,
            'gst' => 30,
            'total' => 1030,
            'status' => 'finalized',
        ]);
        $invoice->save();

        $response = TenantContext::runFor($shop->id, fn () => $this->delete(route('customers.destroy', $customer)));

        $this->assertStringContainsString('Archive', (string) session('error'));
        $this->assertDatabaseHas('customers', ['id' => $customer->id]);
        // Offering archive must not silently perform it.
        $this->assertTrue((bool) $customer->fresh()->is_active);
        $response->assertRedirect(route('customers.show', $customer));
    }

    public function test_blocked_vendor_delete_offers_archive_without_performing_it(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $this->actingAs($user);

        $vendor = $this->makeVendor($shop->id);
        $this->createItem($shop->id, null, ['vendor_id' => $vendor->id]);

        TenantContext::runFor($shop->id, fn () => $this->delete(route('vendors.destroy', $vendor)));

        $this->assertStringContainsString('Archive', (string) session('error'));
        $this->assertDatabaseHas('vendors', ['id' => $vendor->id]);
        $this->assertTrue((bool) $vendor->fresh()->is_active);
    }

    // ─────────────────────────────────────────────────────────────────
    // Enforcement: archived parties cannot take NEW commitments
    // ─────────────────────────────────────────────────────────────────

    public function test_a_repair_cannot_be_booked_against_an_archived_customer(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $this->actingAs($user);

        $customer = $this->createCustomer($shop->id);
        $this->archiveCustomer($shop->id, $customer);

        $response = TenantContext::runFor($shop->id, fn () => $this->from(route('repairs.index'))->post(route('repairs.store'), [
            'customer_id' => $customer->id,
            'item_description' => 'Ring resize',
            'gross_weight' => 5,
        ]));

        // Field-level error, not a 500 — and no partial write. The message must
        // name the fix, not just say "invalid".
        $response->assertSessionHasErrors(['customer_id' => Customer::archivedMessage()]);
        $this->assertSame(0, Repair::withoutTenant()->where('shop_id', $shop->id)->count());
    }

    public function test_the_mobile_api_reports_an_archived_customer_as_422(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $customer = $this->createCustomer($shop->id);
        $this->actingAs($user);
        $this->archiveCustomer($shop->id, $customer);

        // Owner role has no synced permissions in the harness; the JSON error
        // contract is what is under test here, not authorization.
        $this->withoutMiddleware(\Illuminate\Auth\Middleware\Authorize::class);
        Sanctum::actingAs($user);

        $response = TenantContext::runFor($shop->id, fn () => $this->postJson('/api/mobile/repairs', [
            'customer_id' => $customer->id,
            'item_description' => 'Ring resize',
            'gross_weight' => 5,
            'estimated_cost' => 100,
        ]));

        // Unchanged contract: 422 with a field-keyed errors bag.
        $response->assertStatus(422)->assertJsonValidationErrors('customer_id');
        $this->assertSame(Customer::archivedMessage(), $response->json('errors.customer_id.0'));
        $this->assertSame(0, Repair::withoutTenant()->where('shop_id', $shop->id)->count());
    }

    public function test_a_cross_shop_customer_is_reported_as_not_found_never_as_archived(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        [, $otherShop] = $this->createRetailerTenant();
        $this->actingAs($user);

        $foreign = $this->createCustomer($otherShop->id);

        $response = TenantContext::runFor($shop->id, fn () => $this->from(route('repairs.index'))->post(route('repairs.store'), [
            'customer_id' => $foreign->id,
            'item_description' => 'Ring resize',
            'gross_weight' => 5,
        ]));

        // Another tenant's archive status is never disclosed, not even by
        // implication: the id simply does not exist here.
        $response->assertSessionHasErrors(['customer_id' => 'The selected customer was not found.']);
    }

    public function test_an_archived_vendor_cannot_be_attached_to_a_new_item(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $this->actingAs($user);

        $active = $this->makeVendor($shop->id, ['name' => 'Active Supplier']);
        $archived = $this->makeVendor($shop->id, ['name' => 'Archived Supplier']);
        TenantContext::runFor($shop->id, fn () => $this->patch(route('vendors.archive', $archived)));

        $payload = fn (int $vendorId) => [
            'design' => 'Bangle',
            'category' => 'Bangles',
            'gross_weight' => 10,
            'net_weight' => 10,
            'vendor_id' => $vendorId,
        ];

        TenantContext::runFor($shop->id, fn () => $this->post(route('inventory.items.store'), $payload($archived->id)))
            ->assertSessionHasErrors(['vendor_id' => Vendor::archivedMessage()]);

        // Control: the same payload with an active vendor clears THIS field.
        TenantContext::runFor($shop->id, fn () => $this->post(route('inventory.items.store'), $payload($active->id)))
            ->assertSessionDoesntHaveErrors('vendor_id');
    }

    public function test_search_suggestions_never_offer_an_archived_party(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $this->actingAs($user);

        $customer = $this->createCustomer($shop->id, ['first_name' => 'Suggest', 'last_name' => 'Me']);
        $vendor = $this->makeVendor($shop->id, ['name' => 'Suggest Supplier']);

        $this->archiveCustomer($shop->id, $customer);
        TenantContext::runFor($shop->id, fn () => $this->patch(route('vendors.archive', $vendor)));

        TenantContext::runFor($shop->id, fn () => $this->getJson(route('search.suggestions', ['type' => 'customers', 'q' => 'Suggest'])))
            ->assertOk()
            ->assertExactJson([]);

        TenantContext::runFor($shop->id, fn () => $this->getJson(route('search.suggestions', ['type' => 'vendors', 'q' => 'Suggest'])))
            ->assertOk()
            ->assertExactJson([]);
    }

    public function test_an_active_customer_can_still_book_a_repair(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $this->actingAs($user);

        $customer = $this->createCustomer($shop->id);

        TenantContext::runFor($shop->id, fn () => $this->post(route('repairs.store'), [
            'customer_id' => $customer->id,
            'item_description' => 'Ring resize',
            'gross_weight' => 5,
        ]))->assertSessionHasNoErrors();

        $this->assertSame(1, Repair::withoutTenant()->where('shop_id', $shop->id)->count());
    }

    public function test_an_existing_repair_stays_editable_after_its_customer_is_archived(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $this->actingAs($user);

        $customer = $this->createCustomer($shop->id);
        $repair = Repair::forceCreate([
            'shop_id' => $shop->id,
            'customer_id' => $customer->id,
            'item_description' => 'Chain solder',
            'gross_weight' => 4,
            'status' => 'received',
        ]);

        $this->archiveCustomer($shop->id, $customer);

        // Archiving withdraws a party from NEW commitments; it must not freeze
        // the obligations already on the books.
        TenantContext::runFor($shop->id, fn () => $this->put(route('repairs.update', $repair), [
            'customer_id' => $customer->id,
            'item_description' => 'Chain solder + polish',
            'gross_weight' => 4,
            'status' => 'received',
        ]))->assertSessionHasNoErrors();

        $this->assertSame('Chain Solder + Polish', $repair->fresh()->item_description);
    }

    public function test_editing_a_repair_onto_a_different_archived_customer_is_rejected_with_no_partial_write(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $this->actingAs($user);

        $current = $this->createCustomer($shop->id);
        $other = $this->createCustomer($shop->id);
        $this->archiveCustomer($shop->id, $other);

        $repair = Repair::forceCreate([
            'shop_id' => $shop->id,
            'customer_id' => $current->id,
            'item_description' => 'Chain solder',
            'gross_weight' => 4,
            'status' => 'received',
        ]);

        // Re-tagging an existing repair onto a DIFFERENT, archived customer is a
        // new commitment against that party and must fail — the grandfather
        // allowance only covers the customer already on the record.
        TenantContext::runFor($shop->id, fn () => $this->from(route('repairs.edit', $repair))->put(route('repairs.update', $repair), [
            'customer_id' => $other->id,
            'item_description' => 'Chain solder + polish',
            'gross_weight' => 4,
            'status' => 'received',
        ]))->assertSessionHasErrors('customer_id');

        // No partial write: neither the customer link nor the edited field moved.
        $fresh = $repair->fresh();
        $this->assertSame((int) $current->id, (int) $fresh->customer_id);
        $this->assertSame('Chain solder', $fresh->item_description);
    }

    public function test_editing_a_repair_onto_an_active_customer_succeeds(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $this->actingAs($user);

        $current = $this->createCustomer($shop->id);
        $this->archiveCustomer($shop->id, $current);
        $target = $this->createCustomer($shop->id);

        $repair = Repair::forceCreate([
            'shop_id' => $shop->id,
            'customer_id' => $current->id,
            'item_description' => 'Chain solder',
            'gross_weight' => 4,
            'status' => 'received',
        ]);

        // Moving a grandfathered repair off an archived customer onto an active
        // one is the intended remedy and must succeed.
        TenantContext::runFor($shop->id, fn () => $this->put(route('repairs.update', $repair), [
            'customer_id' => $target->id,
            'item_description' => 'Chain solder + polish',
            'gross_weight' => 4,
            'status' => 'received',
        ]))->assertSessionHasNoErrors();

        $this->assertSame((int) $target->id, (int) $repair->fresh()->customer_id);
    }

    // ─────────────────────────────────────────────────────────────────
    // Walk-in decision (c): match-but-guard
    // ─────────────────────────────────────────────────────────────────

    public function test_walk_in_by_mobile_matches_the_archived_customer_but_blocks_the_bill(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $this->actingAs($user);
        $this->seedRetailerPricing($shop, $user);

        $customer = $this->createCustomer($shop->id, ['mobile' => '9876500001']);
        $this->archiveCustomer($shop->id, $customer);

        $response = TenantContext::runFor($shop->id, fn () => $this->post(route('quick-bills.store'), [
            'customer_name' => 'Test Customer',
            'customer_mobile' => '9876500001',
            'items' => [[
                'description' => 'Gold chain',
                'quantity' => 1,
                'rate' => 1000,
            ]],
        ]));

        $response->assertSessionHasErrors();

        // (1) No duplicate customer: the mobile stays unique and history stays
        //     on one record. (2) No auto-reactivation. (3) No bill.
        $this->assertSame(
            1,
            Customer::withoutTenant()->where('shop_id', $shop->id)->where('mobile', '9876500001')->count(),
        );
        $this->assertFalse((bool) $customer->fresh()->is_active);
        $this->assertSame(0, DB::table('quick_bills')->where('shop_id', $shop->id)->count());
    }

    // ─────────────────────────────────────────────────────────────────
    // UI / filter contract
    // ─────────────────────────────────────────────────────────────────

    public function test_customer_index_defaults_to_active_and_can_show_archived_or_all(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $this->actingAs($user);

        $active = $this->createCustomer($shop->id, ['first_name' => 'Aarti', 'last_name' => 'Active']);
        $archived = $this->createCustomer($shop->id, ['first_name' => 'Anil', 'last_name' => 'Archived']);
        $this->archiveCustomer($shop->id, $archived);
        // Burn the flash: the archive confirmation names the customer, and it
        // would otherwise be rendered into the very next page we assert on.
        TenantContext::runFor($shop->id, fn () => $this->get(route('customers.index')));

        $default = TenantContext::runFor($shop->id, fn () => $this->get(route('customers.index')));
        $default->assertSee('Aarti')->assertDontSee('Anil');
        $default->assertViewHas('status', 'active');

        TenantContext::runFor($shop->id, fn () => $this->get(route('customers.index', ['status' => 'archived'])))
            ->assertSee('Anil')
            ->assertDontSee('Aarti');

        $all = TenantContext::runFor($shop->id, fn () => $this->get(route('customers.index', ['status' => 'all'])));
        $all->assertSee('Aarti')->assertSee('Anil');

        $counts = $all->viewData('statusCounts');
        $this->assertSame(2, (int) $counts->all_count);
        $this->assertSame(1, (int) $counts->active_count);
        $this->assertSame(1, (int) $counts->archived_count);
        $this->assertSame($active->id, $active->fresh()->id);
    }

    public function test_customer_index_counts_respect_the_search_filter(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $this->actingAs($user);

        $this->createCustomer($shop->id, ['first_name' => 'Kiran', 'last_name' => 'One']);
        $archived = $this->createCustomer($shop->id, ['first_name' => 'Kiran', 'last_name' => 'Two']);
        $this->createCustomer($shop->id, ['first_name' => 'Zoya', 'last_name' => 'Three']);
        $this->archiveCustomer($shop->id, $archived);

        $response = TenantContext::runFor(
            $shop->id,
            fn () => $this->get(route('customers.index', ['search' => 'Kiran', 'status' => 'all'])),
        );

        // A tab count that ignored the search would read 3/2/1 here.
        $counts = $response->viewData('statusCounts');
        $this->assertSame(2, (int) $counts->all_count);
        $this->assertSame(1, (int) $counts->active_count);
        $this->assertSame(1, (int) $counts->archived_count);
    }

    public function test_search_and_status_survive_pagination_links(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $this->actingAs($user);

        // 16 matches > the 15-per-page window, so a second page exists.
        for ($i = 0; $i < 16; $i++) {
            $this->createCustomer($shop->id, ['first_name' => 'Paged', 'last_name' => 'Customer' . $i]);
        }

        $response = TenantContext::runFor(
            $shop->id,
            fn () => $this->get(route('customers.index', ['search' => 'Paged', 'status' => 'all'])),
        );

        $nextPageUrl = $response->viewData('customers')->nextPageUrl();
        $this->assertStringContainsString('search=Paged', $nextPageUrl);
        $this->assertStringContainsString('status=all', $nextPageUrl);
    }

    public function test_vendor_index_defaults_to_active(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $this->actingAs($user);

        $this->makeVendor($shop->id, ['name' => 'Active Supplier']);
        $archived = $this->makeVendor($shop->id, ['name' => 'Archived Supplier']);
        TenantContext::runFor($shop->id, fn () => $this->patch(route('vendors.archive', $archived)));
        // Burn the flash — it names the vendor (see the customer index test).
        TenantContext::runFor($shop->id, fn () => $this->get(route('vendors.index')));

        TenantContext::runFor($shop->id, fn () => $this->get(route('vendors.index')))
            ->assertSee('Active Supplier')
            ->assertDontSee('Archived Supplier');

        TenantContext::runFor($shop->id, fn () => $this->get(route('vendors.index', ['status' => 'archived'])))
            ->assertSee('Archived Supplier')
            ->assertDontSee('Active Supplier');
    }

    /**
     * MASTERS PART 3 closure — retailer cash-sale sentinel compatibility.
     *
     * RetailerSalesService::sellItems() historically accepts customer_id 0 as
     * "no customer" (cash sale; see its old-gold guard `$customerId <= 0`).
     * The Part 3 party lock must not intercept exactly that sentinel: 0 maps
     * to null at the service boundary, so the next guard in line (unknown
     * items here) speaks instead — proving the lock was skipped for 0 and
     * the pre-Part-3 flow is preserved.
     */
    public function test_retailer_cash_sale_sentinel_zero_skips_party_lock(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $this->actingAs($user);

        try {
            TenantContext::runFor($shop->id, fn () => RetailerSalesService::sellItems(0, [999999]));
            $this->fail('Sale with an unknown item must fail on the item guard.');
        } catch (ValidationException $e) {
            $errors = $e->errors();
            $this->assertArrayNotHasKey(
                'customer_id',
                $errors,
                'Sentinel 0 must not be rejected as a missing customer.'
            );
            $this->assertArrayHasKey('item_ids', $errors);
        }

        $this->assertSame(0, Invoice::withoutTenant()->where('shop_id', $shop->id)->count());
    }

    /**
     * Only the exact sentinel is exempt: missing, archived and cross-shop
     * positive ids are still rejected by the party lock before any item or
     * money work, and nothing is written.
     */
    public function test_retailer_cash_sale_still_rejects_bad_positive_customer_ids(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $this->actingAs($user);

        $archived = $this->createCustomer($shop->id);
        $this->archiveCustomer($shop->id, $archived)->assertSessionHas('success');
        $foreignShop = $this->createShop('retailer');
        $foreign = $this->createCustomer($foreignShop->id);

        foreach ([
            'missing'    => [999999, 'was not found'],
            'archived'   => [(int) $archived->id, 'is archived'],
            'cross-shop' => [(int) $foreign->id, 'was not found'],
        ] as $case => [$id, $needle]) {
            try {
                TenantContext::runFor($shop->id, fn () => RetailerSalesService::sellItems($id, [999999]));
                $this->fail("Case {$case}: customer_id {$id} must be rejected by the party lock.");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('customer_id', $e->errors(), "Case {$case}");
                $this->assertStringContainsString(
                    $needle,
                    collect($e->errors())->flatten()->implode(' '),
                    "Case {$case}"
                );
            }
        }

        $this->assertSame(0, Invoice::withoutTenant()->where('shop_id', $shop->id)->count());
    }

    /**
     * The EMI draft path is NOT an optional-customer flow (an EMI plan needs a
     * person). Sentinel 0 stays rejected there, exactly like before this fix.
     */
    public function test_emi_draft_still_rejects_sentinel_zero_customer(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $this->actingAs($user);

        try {
            TenantContext::runFor($shop->id, fn () => RetailerSalesService::prepareEmiDraftSale(0, [999999]));
            $this->fail('EMI draft with customer_id 0 must be rejected.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('customer_id', $e->errors());
            $this->assertStringContainsString(
                'was not found',
                collect($e->errors())->flatten()->implode(' ')
            );
        }

        $this->assertSame(0, Invoice::withoutTenant()->where('shop_id', $shop->id)->count());
    }
}
