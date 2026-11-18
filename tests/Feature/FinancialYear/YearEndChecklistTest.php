<?php

namespace Tests\Feature\FinancialYear;

use App\Services\YearEndChecklist;
use App\Support\FinancialYear;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/** "Before you close" — what is still open in a financial year when the owner closes it (owner, 2026-11-16). */
class YearEndChecklistTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant, SnapshotTestHelpers;

    private $owner;
    private $shop;
    private FinancialYear $fy;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        [$this->owner, $this->shop] = $this->createRetailerTenant();
        $this->fy = FinancialYear::fromLabel('2025-26');
        $this->travelTo(CarbonImmutable::parse('2026-04-10 12:00:00', 'Asia/Kolkata'));
    }

    private function checks(): array
    {
        return collect(app(YearEndChecklist::class)->for($this->shop->id, $this->fy))->keyBy('key')->all();
    }

    private function insertInvoice(string $status, string $createdAt): void
    {
        $customer = $this->createCustomer($this->shop->id);
        DB::table('invoices')->insert([
            'shop_id' => $this->shop->id, 'customer_id' => $customer->id, 'status' => $status,
            'subtotal' => 0, 'gst' => 0, 'total' => 0, 'gold_rate' => 0,
            'created_at' => $createdAt, 'updated_at' => $createdAt,
            'finalized_at' => $status === 'finalized' ? $createdAt : null,
        ]);
    }

    public function test_a_clean_year_has_nothing_open_except_the_rate_check(): void
    {
        $this->rate($this->shop->id, '2026-03-31', 'gold', 22.0, 6600);

        $open = collect($this->checks())->filter(fn ($c) => $c['count'] > 0);

        $this->assertSame([], $open->keys()->all());
    }

    public function test_unfinished_bills_from_the_year_are_listed_but_not_later_ones(): void
    {
        $this->insertInvoice('draft', '2026-03-20 10:00:00');
        $this->insertInvoice('draft', '2026-03-31 23:30:00');
        $this->insertInvoice('draft', '2026-04-01 09:00:00'); // next year
        $this->insertInvoice('finalized', '2026-03-20 10:00:00');

        $this->assertSame(2, $this->checks()['draft_bills']['count']);
    }

    public function test_draft_purchases_dated_in_the_year_are_listed(): void
    {
        $vendor = DB::table('vendors')->insertGetId(['shop_id' => $this->shop->id, 'name' => 'V', 'created_at' => now(), 'updated_at' => now()]);
        foreach (['2026-03-10', '2026-04-02'] as $n => $date) {
            DB::table('stock_purchases')->insert([
                'shop_id' => $this->shop->id, 'vendor_id' => $vendor, 'status' => 'draft', 'purchase_number' => 'PUR-T' . $n,
                'purchase_date' => $date, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $this->assertSame(1, $this->checks()['draft_purchases']['count']);
    }

    public function test_stock_held_at_year_end_with_unknown_cost_is_listed(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-02-01 10:00:00', 'Asia/Kolkata'));
        $this->createItem($this->shop->id, null, ['acquisition_cost' => null]);
        $this->createItem($this->shop->id, null, ['acquisition_cost' => 5000, 'acquisition_cost_source' => 'entered']);
        $this->travelTo(CarbonImmutable::parse('2026-04-10 12:00:00', 'Asia/Kolkata'));

        $this->assertSame(1, $this->checks()['unknown_cost_items']['count']);
    }

    public function test_a_missing_year_end_rate_is_flagged(): void
    {
        $check = $this->checks()['year_end_rate'];

        $this->assertSame(1, $check['count']);
        $this->assertStringContainsString('31 March', $check['label']);
    }

    public function test_a_rate_a_few_days_before_year_end_is_enough(): void
    {
        $this->rate($this->shop->id, '2026-03-29', 'gold', 22.0, 6500);

        $this->assertSame(0, $this->checks()['year_end_rate']['count']);
    }

    public function test_every_check_has_a_label_and_the_shop_is_isolated(): void
    {
        [, $other] = $this->createRetailerTenant();
        $customer = $this->createCustomer($other->id);
        DB::table('invoices')->insert([
            'shop_id' => $other->id, 'customer_id' => $customer->id, 'status' => 'draft', 'subtotal' => 0, 'gst' => 0,
            'total' => 0, 'gold_rate' => 0, 'created_at' => '2026-03-20 10:00:00', 'updated_at' => '2026-03-20 10:00:00',
        ]);

        $checks = $this->checks();

        $this->assertSame(0, $checks['draft_bills']['count']);
        foreach ($checks as $c) {
            $this->assertNotSame('', $c['label']);
        }
        $this->assertEqualsCanonicalizing(
            ['draft_bills', 'pending_returns', 'draft_purchases', 'open_job_orders', 'unknown_cost_items', 'year_end_rate'],
            array_keys($checks)
        );
    }

    public function test_the_year_end_page_shows_the_checklist(): void
    {
        $this->insertInvoice('draft', '2026-03-20 10:00:00');

        \App\Support\TenantContext::runFor($this->shop->id, fn () => $this->actingAs($this->owner)->get(route('books.year-end', ['fy' => '2025-26'])))
            ->assertOk()
            ->assertSee('Before you close')
            ->assertSee('unfinished bill');
    }
}
