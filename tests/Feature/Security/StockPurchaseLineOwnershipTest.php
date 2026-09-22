<?php

namespace Tests\Feature\Security;

use App\Models\Shop;
use App\Models\StockPurchase;
use App\Models\StockPurchaseItem;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * A stock-purchase line id supplied by the request, on a model with no tenant
 * scope.
 *
 * `StockPurchaseItem` has a `shop_id` column but does NOT use `BelongsToShop`,
 * so `StockPurchaseItem::find($id)` and implicit route-model binding both
 * resolve a line from ANY shop. Three code paths take that id from the request:
 *
 *   * `StockPurchaseController::syncLines:609` — `lines.*.id` in the PUT body,
 *     validated only as `nullable|integer`;
 *   * `vaultLineForm` / `vaultLine` — `{line}` in the URL, bound implicitly.
 *
 * Each is guarded by one check that the line belongs to the purchase being
 * operated on, and the purchase itself is tenant-scoped by its own binding.
 * That single check is correctly placed; it is not a defect for lacking a
 * second one. What was missing is evidence that it holds. No existing test
 * sent a foreign `lines.*.id` or a foreign `{line}`:
 * `PurchaseAndVendorAccessTest` covers cross-shop view, reverse and vendor
 * selection, not line identity.
 *
 * What "rejected" means differs between the two paths, and the tests assert
 * each as it actually is rather than forcing one shape on both:
 *
 *   * syncLines does not refuse the request. A line id that does not belong to
 *     THIS purchase is disregarded and the submitted attributes become a NEW
 *     line on the caller's own purchase — data the caller was entitled to
 *     write anyway. The foreign row is never modified.
 *   * the vault routes 404 before any validation or write.
 *
 * Every foreign fixture satisfies every OTHER precondition on its route
 * (a `bullion_reserve` line, no lot yet, a confirmed purchase), so the
 * ownership check is the only thing that can produce the refusal.
 */
class StockPurchaseLineOwnershipTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestTenant;

    private const ERP = 'https://jewelflows.com';

    /** Values only shop B holds. None may reach shop A's responses. */
    private const FOREIGN_DESIGN = 'FOREIGN-DESIGN-7Q';
    private const FOREIGN_BARCODE = 'FOREIGNBC77';

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        $this->withoutMiddleware([
            \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
            \Illuminate\Routing\Middleware\ThrottleRequests::class,
        ]);
    }

    protected function tearDown(): void
    {
        TenantContext::clear();
        parent::tearDown();
    }

    // ────────────────────────────────────────────────────────────────────
    // Fixtures
    // ────────────────────────────────────────────────────────────────────

    private function purchase(Shop $shop, string $status): StockPurchase
    {
        return TenantContext::runFor((int) $shop->id, function () use ($shop, $status) {
            $p = new StockPurchase();
            $p->forceFill([
                'shop_id' => $shop->id, 'purchase_number' => 'PO-' . uniqid(),
                'purchase_date' => now()->toDateString(), 'status' => $status,
            ])->save();

            return $p;
        });
    }

    private function line(StockPurchase $purchase, array $attrs = []): StockPurchaseItem
    {
        $line = new StockPurchaseItem();
        $line->forceFill(array_merge([
            'stock_purchase_id' => $purchase->id,
            'shop_id' => $purchase->shop_id,
            'line_type' => 'bullion_reserve',
            'metal_type' => 'gold',
            'purity' => 99.5,
            'gross_weight' => 10,
            'net_metal_weight' => 10,
            'purchase_rate_per_gram' => 6000,
            'purchase_line_amount' => 60000,
        ], $attrs))->save();

        return $line;
    }

    /** Shop B: a confirmed purchase with one line that satisfies every vault precondition. */
    private function foreignLine(): StockPurchaseItem
    {
        [, $shopB] = $this->createRetailerTenant();

        return $this->line($this->purchase($shopB, 'confirmed'), [
            'design' => self::FOREIGN_DESIGN,
            'barcode' => self::FOREIGN_BARCODE,
        ]);
    }

    private function row(StockPurchaseItem $line): array
    {
        return (array) DB::table('stock_purchase_items')->where('id', $line->id)->first();
    }

    /** Inventory and metal-ledger footprint of one shop. */
    private function footprint(int $shopId): array
    {
        return [
            'items' => DB::table('items')->where('shop_id', $shopId)->count(),
            'metal_lots' => DB::table('metal_lots')->where('shop_id', $shopId)->count(),
            'metal_movements' => DB::table('metal_movements')->where('shop_id', $shopId)->count(),
        ];
    }

    /**
     * PUT the edit, then GET the page it redirects to as a SEPARATE request.
     *
     * Not `followingRedirects()`: `EnsureTenantUser` clears TenantContext in a
     * `finally`, and under PHPUnit the scope does not fall back to the
     * authenticated user, so a followed redirect 404s on its own binding. Same
     * test-environment characteristic recorded in §7c; not a production path.
     *
     * @return array{0: \Illuminate\Testing\TestResponse, 1: string}
     */
    private function editLines(User $owner, StockPurchase $purchase, array $lines): array
    {
        $shopId = (int) $purchase->shop_id;
        $url = self::ERP . '/inventory/purchases/' . $purchase->id;

        $put = TenantContext::runFor($shopId, fn () => $this->actingAs($owner)->put($url, [
            'purchase_date' => now()->toDateString(),
            'lines' => $lines,
        ]));
        $put->assertSessionHasNoErrors()->assertRedirect(route('inventory.purchases.show', $purchase));

        $show = TenantContext::runFor($shopId, fn () => $this->actingAs($owner)->get($url));
        $show->assertOk();

        return [$put, $put->getContent() . $show->getContent()];
    }

    private function submittedLine(int $id, string $design): array
    {
        return [
            'id' => $id, 'line_type' => 'ornament', 'metal_type' => 'gold',
            'purity' => 22, 'gross_weight' => 5, 'design' => $design,
        ];
    }

    // ────────────────────────────────────────────────────────────────────
    // syncLines — lines.*.id in the request body
    // ────────────────────────────────────────────────────────────────────

    /**
     * [POSITIVE CONTROL] The caller's own line id is honoured: the same row is
     * edited in place, not replaced. Without this, the two tests below could
     * pass on a controller that ignored every submitted id.
     */
    public function test_l01_own_line_is_edited_in_place(): void
    {
        [$ownerA, $shopA] = $this->createRetailerTenant();
        $purchaseA = $this->purchase($shopA, 'draft');
        $own = $this->line($purchaseA, ['line_type' => 'ornament', 'design' => 'before']);

        $this->editLines($ownerA, $purchaseA, [$this->submittedLine($own->id, 'after')]);

        $lines = StockPurchaseItem::where('stock_purchase_id', $purchaseA->id)->get();
        $this->assertSame([$own->id], $lines->pluck('id')->all(), 'the same row, not a replacement');
        $this->assertSame('after', $lines->first()->design);
    }

    /**
     * A line id from ANOTHER SHOP is disregarded. Shop B's row, purchase and
     * inventory/ledger footprint are byte-for-byte unchanged; shop A's response
     * carries none of B's values; the submitted attributes land as a new line on
     * A's own purchase.
     */
    public function test_l02_foreign_shop_line_id_modifies_nothing_and_leaks_nothing(): void
    {
        $foreign = $this->foreignLine();
        $foreignShop = (int) $foreign->shop_id;
        $foreignBefore = $this->row($foreign);
        $foreignPurchaseBefore = (array) DB::table('stock_purchases')->where('id', $foreign->stock_purchase_id)->first();
        $foreignFootprint = $this->footprint($foreignShop);

        [$ownerA, $shopA] = $this->createRetailerTenant();
        $purchaseA = $this->purchase($shopA, 'draft');
        $ownFootprint = $this->footprint((int) $shopA->id);

        [, $content] = $this->editLines($ownerA, $purchaseA, [$this->submittedLine($foreign->id, 'ATTACKER-EDIT')]);

        $this->assertSame($foreignBefore, $this->row($foreign), "shop B's line must be untouched");
        $this->assertSame(
            $foreignPurchaseBefore,
            (array) DB::table('stock_purchases')->where('id', $foreign->stock_purchase_id)->first(),
            "and so must shop B's purchase and its totals",
        );
        $this->assertSame($foreignFootprint, $this->footprint($foreignShop), "no inventory or ledger change for shop B");
        $this->assertSame($ownFootprint, $this->footprint((int) $shopA->id), 'a draft edit stocks nothing, even on the caller');

        $this->assertStringContainsString('ATTACKER-EDIT', $content, 'the page really rendered the edited purchase');
        $this->assertStringNotContainsString(self::FOREIGN_DESIGN, $content, "shop B's data must not reach shop A");
        $this->assertStringNotContainsString(self::FOREIGN_BARCODE, $content);

        $ownLines = StockPurchaseItem::where('stock_purchase_id', $purchaseA->id)->get();
        $this->assertCount(1, $ownLines);
        $this->assertNotSame($foreign->id, $ownLines->first()->id, 'the foreign id is not adopted');
        $this->assertSame('ATTACKER-EDIT', $ownLines->first()->design);
        $this->assertSame((int) $shopA->id, (int) $ownLines->first()->shop_id);
    }

    /**
     * The check is purchase identity, not merely shop identity. A line from the
     * caller's OWN other purchase is disregarded the same way — an edit to one
     * draft cannot reach into a second.
     */
    public function test_l03_line_from_another_purchase_of_the_same_shop_is_not_moved_or_edited(): void
    {
        [$ownerA, $shopA] = $this->createRetailerTenant();
        $editing = $this->purchase($shopA, 'draft');
        $other = $this->purchase($shopA, 'draft');
        $otherLine = $this->line($other, ['line_type' => 'ornament', 'design' => 'belongs-to-other']);
        $otherBefore = $this->row($otherLine);

        $this->editLines($ownerA, $editing, [$this->submittedLine($otherLine->id, 'cross-purchase')]);

        $this->assertSame($otherBefore, $this->row($otherLine), 'the other purchase keeps its line, unedited');
        $editingLines = StockPurchaseItem::where('stock_purchase_id', $editing->id)->get();
        $this->assertCount(1, $editingLines);
        $this->assertNotSame($otherLine->id, $editingLines->first()->id);
    }

    /**
     * [CONTRACT] Why L02/L03 are "disregarded", not "rejected".
     *
     * The edit form (inventory/purchases/create.blade.php) posts each existing
     * line's own id and '' for a new one; syncLines has treated any id that
     * does not name one of THIS purchase's lines as a new line since the
     * module was added (86cfcd8). The legitimate way to arrive with such an id
     * is a stale form: the line was removed in another tab. The operator's
     * values are kept as a new line instead of being lost to an error.
     *
     * A foreign id takes the same path, and that uniformity is itself worth
     * keeping: refusing only ids that exist elsewhere would tell a caller which
     * ids belong to other shops.
     */
    public function test_l04_a_stale_id_from_a_removed_line_becomes_a_new_line(): void
    {
        [$ownerA, $shopA] = $this->createRetailerTenant();
        $purchaseA = $this->purchase($shopA, 'draft');
        $removed = $this->line($purchaseA, ['line_type' => 'ornament', 'design' => 'removed-elsewhere']);
        $staleId = $removed->id;
        $removed->delete();

        $this->editLines($ownerA, $purchaseA, [$this->submittedLine($staleId, 'kept-from-stale-form')]);

        $lines = StockPurchaseItem::where('stock_purchase_id', $purchaseA->id)->get();
        $this->assertCount(1, $lines);
        $this->assertNotSame($staleId, $lines->first()->id);
        $this->assertSame('kept-from-stale-form', $lines->first()->design);
    }

    // ────────────────────────────────────────────────────────────────────
    // vaultLineForm / vaultLine — {line} bound from the URL
    // ────────────────────────────────────────────────────────────────────

    /**
     * [POSITIVE CONTROL] The caller's own qualifying line reaches the vault
     * form. Proves the 404s below come from line ownership, not from the
     * route, the edition gate, `vault.manage`, or the fixture.
     */
    public function test_v01_own_bullion_line_opens_the_vault_form(): void
    {
        [$ownerA, $shopA] = $this->createRetailerTenant();
        $purchaseA = $this->purchase($shopA, 'confirmed');
        $own = $this->line($purchaseA);

        TenantContext::runFor((int) $shopA->id, fn () => $this->actingAs($ownerA)
            ->get(self::ERP . "/inventory/purchases/{$purchaseA->id}/vault/{$own->id}"))
            ->assertOk();
    }

    public function test_v02_foreign_shop_line_under_own_purchase_is_404_on_read_and_write(): void
    {
        $foreign = $this->foreignLine();
        $foreignBefore = $this->row($foreign);
        $foreignFootprint = $this->footprint((int) $foreign->shop_id);

        [$ownerA, $shopA] = $this->createRetailerTenant();
        $purchaseA = $this->purchase($shopA, 'confirmed');
        $ownFootprint = $this->footprint((int) $shopA->id);
        $url = self::ERP . "/inventory/purchases/{$purchaseA->id}/vault/{$foreign->id}";

        $read = TenantContext::runFor((int) $shopA->id, fn () => $this->actingAs($ownerA)->get($url));
        $read->assertNotFound();
        $this->assertStringNotContainsString(self::FOREIGN_DESIGN, $read->getContent());
        $this->assertStringNotContainsString(self::FOREIGN_BARCODE, $read->getContent());

        TenantContext::runFor((int) $shopA->id, fn () => $this->actingAs($ownerA)
            ->post($url, ['vault_action' => 'new_lot']))
            ->assertNotFound();

        $this->assertSame($foreignBefore, $this->row($foreign), 'the foreign line is not vaulted');
        $this->assertSame($foreignFootprint, $this->footprint((int) $foreign->shop_id));
        $this->assertSame($ownFootprint, $this->footprint((int) $shopA->id), 'nor is a lot minted on the caller');
    }

    public function test_v03_line_from_another_purchase_of_the_same_shop_is_404(): void
    {
        [$ownerA, $shopA] = $this->createRetailerTenant();
        $purchaseA = $this->purchase($shopA, 'confirmed');
        $otherLine = $this->line($this->purchase($shopA, 'confirmed'));
        $footprint = $this->footprint((int) $shopA->id);

        TenantContext::runFor((int) $shopA->id, fn () => $this->actingAs($ownerA)
            ->post(self::ERP . "/inventory/purchases/{$purchaseA->id}/vault/{$otherLine->id}", ['vault_action' => 'new_lot']))
            ->assertNotFound();

        $this->assertNull($otherLine->fresh()->metal_lot_id);
        $this->assertSame($footprint, $this->footprint((int) $shopA->id));
    }
}
