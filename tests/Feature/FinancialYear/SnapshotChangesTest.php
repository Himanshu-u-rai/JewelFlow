<?php

namespace Tests\Feature\FinancialYear;

use App\Models\Shop;
use App\Services\FiscalYearSnapshotService;
use App\Support\FinancialYear;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/** "What changed in the closed year since the snapshot?" — the owner's answer to a late edit of last year's books. */
class SnapshotChangesTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant, SnapshotTestHelpers;

    private Shop $shop;
    private FinancialYear $fy;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        [, $this->shop] = $this->createRetailerTenant();
        $this->fy = FinancialYear::fromLabel('2025-26');
        $this->today();
    }

    private function svc(): FiscalYearSnapshotService
    {
        return app(FiscalYearSnapshotService::class);
    }

    private function changes(object $snap): array
    {
        return $this->svc()->changesSince($snap);
    }

    private function movementAt(int $itemId, string $occurred, string $createdAt, string $to = 'in_stock', ?string $from = null): void
    {
        DB::table('item_movements')->insert([
            'shop_id' => $this->shop->id, 'item_id' => $itemId, 'movement_type' => $from === null ? 'intake' : 'status_change',
            'from_status' => $from, 'to_status' => $to, 'gross_weight' => 5, 'net_metal_weight' => 5, 'purity' => 22,
            'metal_type' => 'gold', 'occurred_at' => $occurred, 'created_at' => $createdAt,
        ]);
    }

    private function audit(string $action, string $createdAt, array $data = []): int
    {
        return DB::table('audit_logs')->insertGetId([
            'shop_id' => $this->shop->id, 'user_id' => DB::table('users')->where('shop_id', $this->shop->id)->value('id'),
            'action' => $action, 'model_type' => 'shop_rules', 'model_id' => $this->shop->id,
            'description' => 'x', 'data' => json_encode($data), 'created_at' => $createdAt, 'updated_at' => $createdAt,
        ]);
    }

    public function test_nothing_changed_means_an_empty_diff(): void
    {
        $this->at('2026-02-01 10:00:00');
        $this->createItem($this->shop->id);
        $this->today();
        $snap = $this->svc()->take($this->shop->id, $this->fy, 'closing');

        $c = $this->changes($snap);

        $this->assertSame([], $c['added']);
        $this->assertSame([], $c['removed']);
        $this->assertSame([], $c['late_movements']);
        $this->assertSame([], $c['backdated_events']);
        $this->assertFalse($c['has_changes']);
    }

    public function test_a_normal_sale_after_year_end_is_not_a_change_to_the_closed_year(): void
    {
        $this->at('2026-02-01 10:00:00');
        $item = $this->createItem($this->shop->id);
        $this->today();
        $snap = $this->svc()->take($this->shop->id, $this->fy, 'closing');
        $this->setStatus($item->id, 'sold');   // sold in November: 31 March stock is unchanged

        $this->assertFalse($this->changes($snap)['has_changes']);
    }

    public function test_an_item_added_into_the_closed_year_after_the_snapshot_shows_as_added(): void
    {
        $this->at('2026-02-01 10:00:00');
        $this->createItem($this->shop->id);
        $this->today();
        $snap = $this->svc()->take($this->shop->id, $this->fy, 'closing');

        // A backdated purchase: an item entered today but dated 15 March.
        $this->at('2026-03-15 10:00:00');
        $late = $this->createItem($this->shop->id, null, ['barcode' => 'LATE-1']);
        $this->today();

        $c = $this->changes($snap);

        $this->assertSame([$late->id], array_map(fn ($r) => (int) $r['item_id'], $c['added']));
        $this->assertSame('LATE-1', $c['added'][0]['barcode']);
        $this->assertSame([], $c['removed']);
        $this->assertTrue($c['has_changes']);
    }

    public function test_an_item_taken_out_of_the_closed_year_after_the_snapshot_shows_as_removed(): void
    {
        $this->at('2026-02-01 10:00:00');
        $item = $this->createItem($this->shop->id, null, ['barcode' => 'GONE-1']);
        $this->today();
        $snap = $this->svc()->take($this->shop->id, $this->fy, 'closing');

        // A backdated sale: it left stock on 20 March, entered today.
        $this->movementAt($item->id, '2026-03-20 10:00:00', '2099-01-01 00:00:00', 'sold', 'in_stock');

        $c = $this->changes($snap);

        $this->assertSame([$item->id], array_map(fn ($r) => (int) $r['item_id'], $c['removed']));
        $this->assertSame('GONE-1', $c['removed'][0]['barcode']);
        $this->assertSame([], $c['added']);
    }

    public function test_a_backdated_item_movement_recorded_after_the_snapshot_is_listed(): void
    {
        $this->at('2026-02-01 10:00:00');
        $item = $this->createItem($this->shop->id);
        $this->today();
        $snap = $this->svc()->take($this->shop->id, $this->fy, 'closing');
        $this->movementAt($item->id, '2026-03-10 09:00:00', '2099-01-01 00:00:00', 'with_karigar', 'in_stock');

        $late = $this->changes($snap)['late_movements'];

        $this->assertCount(1, $late);
        $this->assertSame($item->id, (int) $late[0]['item_id']);
        $this->assertSame('with_karigar', $late[0]['to_status']);
    }

    public function test_movements_recorded_before_the_snapshot_or_dated_after_year_end_are_not_late(): void
    {
        $this->at('2026-02-01 10:00:00');
        $item = $this->createItem($this->shop->id);
        $this->today();
        $snap = $this->svc()->take($this->shop->id, $this->fy, 'closing');
        // Recorded long before the snapshot (even though dated inside the year).
        $this->movementAt($item->id, '2026-03-10 09:00:00', '2000-01-01 00:00:00', 'with_karigar', 'in_stock');
        // Recorded after the snapshot but dated after year end.
        $this->movementAt($item->id, '2026-04-02 09:00:00', '2099-01-01 00:00:00', 'sold', 'with_karigar');
        // Exactly 23:59:59 on 31 March is inside the year; midnight 1 April is outside.
        $this->movementAt($item->id, '2026-04-01 00:00:00', '2099-01-01 00:00:00', 'in_stock', 'sold');

        $this->assertSame([], $this->changes($snap)['late_movements']);
    }

    public function test_other_shops_movements_are_ignored(): void
    {
        [, $other] = $this->createRetailerTenant();
        $this->at('2026-02-01 10:00:00');
        $this->createItem($this->shop->id);
        $theirs = $this->createItem($other->id);
        $this->today();
        $snap = $this->svc()->take($this->shop->id, $this->fy, 'closing');
        DB::table('item_movements')->insert([
            'shop_id' => $other->id, 'item_id' => $theirs->id, 'movement_type' => 'sold', 'from_status' => 'in_stock', 'to_status' => 'sold',
            'occurred_at' => '2026-03-10 09:00:00', 'created_at' => '2099-01-01 00:00:00',
        ]);

        $this->assertFalse($this->changes($snap)['has_changes']);
    }

    public function test_backdated_audit_events_after_the_snapshot_are_listed_and_older_or_other_actions_are_not(): void
    {
        $snap = $this->svc()->take($this->shop->id, $this->fy, 'closing');
        $keep = $this->audit('books.backdated', '2099-01-01 00:00:00', ['document_date' => '2026-03-20']);
        $this->audit('books.backdated', '2000-01-01 00:00:00', ['document_date' => '2026-03-20']);          // before the snapshot
        $this->audit('books.locked', '2099-01-01 00:00:00');                                                  // another event
        $this->audit('books.backdated', '2099-01-01 00:00:00', ['document_date' => '2026-08-20']);          // a different year's document

        $events = $this->changes($snap)['backdated_events'];

        $this->assertSame([$keep], array_map(fn ($e) => (int) $e['id'], $events));
        $this->assertTrue($this->changes($snap)['has_changes']);
    }

    public function test_a_backdated_event_without_a_date_is_still_shown_rather_than_hidden(): void
    {
        $snap = $this->svc()->take($this->shop->id, $this->fy, 'closing');
        $id = $this->audit('books.backdated', '2099-01-01 00:00:00', []);

        $this->assertSame([$id], array_map(fn ($e) => (int) $e['id'], $this->changes($snap)['backdated_events']));
    }

    public function test_changes_are_measured_against_the_chosen_snapshot_version(): void
    {
        $first = $this->svc()->take($this->shop->id, $this->fy, 'closing');
        $this->at('2026-03-15 10:00:00');
        $late = $this->createItem($this->shop->id);
        $this->today();
        $second = $this->svc()->take($this->shop->id, $this->fy, 'closing');

        $this->assertSame([$late->id], array_map(fn ($r) => (int) $r['item_id'], $this->changes($first)['added']));
        $this->assertSame([], $this->changes($second)['added'], 'the later snapshot already contains it');
    }
}
