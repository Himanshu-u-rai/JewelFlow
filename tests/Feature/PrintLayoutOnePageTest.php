<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * A printed bill keeps one structure height and gives up blank space as its
 * data grows.
 *
 * Both print templates used to pad the items table with a FIXED number of
 * blank rows (26 on A4, minus the item count). That left a bill with almost no
 * slack: measured on 2026-10-02 with a real shop's settings, a one-item bill
 * had 43px to spare on an A4 page and a two-item bill 18px. A phone whose
 * system font size is one step above default scales the bill's text in
 * Android's print engine, and the same bill went to two pages.
 *
 * Now there is ONE filler row and it is elastic: the items table is a flex
 * item that takes whatever the page has left, and that row absorbs it. More
 * items, more payments, longer terms or larger text all come out of the
 * filler, never onto a second page, until the bill's own content is taller
 * than the page.
 *
 * These tests pin the markup that mechanism depends on. Whether a given bill
 * fits a page is a property of a browser's layout, and was measured with one
 * (desktop Chrome and Android's print engine), not asserted here.
 */
class PrintLayoutOnePageTest extends TestCase
{
    use CreatesTestTenant;
    use RefreshDatabase;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        Storage::fake('public');
    }

    public function test_an_invoice_ends_its_items_table_with_one_elastic_row_and_no_fixed_blank_rows(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();

        foreach ([1, 2, 5] as $lines) {
            $html = $this->printInvoice($owner, $this->finalizedInvoice($owner, $shop->id, $lines));

            $this->assertSame(0, substr_count($html, 'items-spacer-row"'), "{$lines} item(s): fixed blank rows are back");
            $this->assertSame(1, $this->fillerRows($html), "{$lines} item(s): exactly one elastic row");
            $this->assertSame($lines + 1, $this->bodyRows($html), "{$lines} item(s): the item rows, then the filler, and nothing else");
            $this->assertStringEndsWith('items-filler-row', $this->lastBodyRowClass($html), "{$lines} item(s): the filler is the last row");
        }
    }

    public function test_the_elastic_row_has_one_cell_for_every_column_the_shop_shows(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $invoice = $this->finalizedInvoice($owner, $shop->id, 1);

        foreach ([
            'every column' => ['show_huid' => true, 'show_stone_columns' => true, 'show_purity' => true],
            'no HUID, no stone columns, no purity' => ['show_huid' => false, 'show_stone_columns' => false, 'show_purity' => false],
        ] as $label => $columns) {
            $this->setBilling($shop->id, $columns);
            $html = $this->printInvoice($owner, $invoice);

            // The filler continues the table's column lines down the page: a
            // missing or extra cell would break them.
            $this->assertSame($this->headerCells($html), $this->fillerCells($html), $label);
        }
    }

    public function test_a_bill_with_no_item_lines_still_gets_the_elastic_row(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $html = $this->printInvoice($owner, $this->finalizedInvoice($owner, $shop->id, 0));

        $this->assertSame(1, $this->fillerRows($html));
        $this->assertSame(0, substr_count($html, 'items-spacer-row"'));
    }

    public function test_each_printed_copy_has_its_own_elastic_row(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $invoice = $this->finalizedInvoice($owner, $shop->id, 1);
        $this->setBilling($shop->id, ['copy_count' => 2]);

        $this->assertSame(2, $this->fillerRows($this->printInvoice($owner, $invoice)));
    }

    public function test_the_page_carries_the_rules_that_make_the_row_elastic(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $html = $this->printInvoice($owner, $this->finalizedInvoice($owner, $shop->id, 1));

        // The table takes the page's free height, and the filler row takes the table's.
        $this->assertMatchesRegularExpression('/\.invoice-body\s*>\s*\.items-table\s*\{[^}]*flex:\s*1 0 auto/', $html);
        $this->assertMatchesRegularExpression('/\.items-filler-row\s*\{[^}]*height:\s*100%/', $html);
        // On a phone screen there is no page to fill: the shell has no minimum height there, so the row collapses.
        $this->assertMatchesRegularExpression('/@media screen and \(max-width: 768px\)[\s\S]*\.invoice-shell\s*\{[^}]*min-height:\s*0/', $html);
    }

    public function test_a_quick_bill_follows_the_same_structure(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $owner->unsetRelation('shop');

        TenantContext::runFor((int) $shop->id, fn () => $this->actingAs($owner)->post(route('quick-bills.store'), [
            'bill_date' => now()->toDateString(),
            'pricing_mode' => 'gst_exclusive',
            'gst_rate' => 3,
            'save_action' => 'issue',
            'customer_name' => 'Walk-in',
            'items' => [
                ['description' => 'Gold chain', 'metal_type' => 'gold', 'net_weight' => 10, 'rate' => 7200, 'line_total' => 1000],
                ['description' => 'Gold ring', 'metal_type' => 'gold', 'net_weight' => 5, 'rate' => 7200, 'line_total' => 500],
            ],
            'payments' => [['payment_mode' => 'cash', 'amount' => 1545]],
        ])->assertSessionHasNoErrors());
        $billId = (int) DB::table('quick_bills')->where('shop_id', $shop->id)->orderByDesc('id')->value('id');
        $this->assertGreaterThan(0, $billId);

        $owner->unsetRelation('shop');
        $html = TenantContext::runFor((int) $shop->id, fn () => $this->actingAs($owner)->get(route('quick-bills.print', $billId))->assertOk())->getContent();

        $this->assertSame(0, substr_count($html, 'items-spacer-row"'));
        $this->assertSame(1, $this->fillerRows($html));
        $this->assertSame(3, $this->bodyRows($html), 'two item rows, then the filler');
        $this->assertSame($this->headerCells($html), $this->fillerCells($html));
        $this->assertMatchesRegularExpression('/\.items-filler-row\s*\{[^}]*height:\s*100%/', $html);
    }

    // ── what the page contains ───────────────────────────────────────────────

    private function fillerRows(string $html): int
    {
        return preg_match_all('/<tr class="items-filler-row">/', $html);
    }

    /** Rows of the first copy's items table body. */
    private function bodyRows(string $html): int
    {
        return preg_match_all('/<tr\b/', $this->itemsBody($html));
    }

    private function lastBodyRowClass(string $html): string
    {
        preg_match_all('/<tr\b(?:\s+class="([^"]*)")?/', $this->itemsBody($html), $m);

        return (string) end($m[1]);
    }

    private function headerCells(string $html): int
    {
        preg_match('/<table class="items-table">.*?<thead>(.*?)<\/thead>/s', $html, $m);

        return preg_match_all('/<th\b/', $m[1] ?? '');
    }

    private function fillerCells(string $html): int
    {
        preg_match('/<tr class="items-filler-row">(.*?)<\/tr>/s', $html, $m);

        return preg_match_all('/<td\b/', $m[1] ?? '');
    }

    private function itemsBody(string $html): string
    {
        preg_match('/<table class="items-table">.*?<tbody>(.*?)<\/tbody>/s', $html, $m);

        return $m[1] ?? '';
    }

    // ── fixtures ─────────────────────────────────────────────────────────────

    private function printInvoice(User $owner, Invoice $invoice): string
    {
        // A real request builds a fresh User; actingAs keeps one alive, with its settings cached.
        $owner->unsetRelation('shop');

        return TenantContext::runFor((int) $owner->shop_id, fn () => $this->actingAs($owner)
            ->get(route('invoices.print', $invoice))->assertOk())->getContent();
    }

    private function setBilling(int $shopId, array $values): void
    {
        foreach ($values as $column => $value) {
            DB::table('shop_billing_settings')->where('shop_id', $shopId)
                ->update([$column => is_bool($value) ? DB::raw($value ? 'true' : 'false') : $value]);
        }
    }

    /**
     * A finalized invoice with the given number of lines, made the way the
     * application makes one: draft and lines first, then finalized through the
     * controller (the finalized-invoice guards refuse lines added afterwards).
     */
    private function finalizedInvoice(User $owner, int $shopId, int $lines): Invoice
    {
        $customer = $this->createCustomer($shopId);

        $invoice = TenantContext::runFor($shopId, function () use ($shopId, $customer, $lines) {
            $invoice = new Invoice();
            $invoice->forceFill([
                'shop_id' => $shopId, 'customer_id' => $customer->id, 'gold_rate' => 7200,
                'subtotal' => 1000 * max(1, $lines), 'gst_rate' => 3, 'gst' => 0, 'total' => 0,
                'wastage_charge' => 0, 'discount' => 0, 'round_off' => 0, 'status' => Invoice::STATUS_DRAFT,
            ])->save();

            for ($i = 0; $i < $lines; $i++) {
                $item = $this->createItem($shopId, null, ['metal_type' => 'gold']);
                DB::table('invoice_items')->insert([
                    'invoice_id' => $invoice->id, 'item_id' => $item->id, 'metal_type' => 'gold', 'weight' => 10,
                    'rate' => 7200, 'making_charges' => 0, 'stone_amount' => 0, 'line_total' => 1000,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            return $invoice;
        });

        if ($lines > 0) {
            $owner->unsetRelation('shop');
            TenantContext::runFor($shopId, fn () => $this->actingAs($owner)
                ->put(route('invoices.update', $invoice), ['action' => 'finalize'])
                ->assertSessionHasNoErrors());
        } else {
            // Nothing to finalize through the controller: an invoice with no lines is how a repair bill looks.
            TenantContext::runFor($shopId, fn () => $invoice->forceFill([
                'status' => Invoice::STATUS_FINALIZED, 'finalized_at' => now(), 'gst' => 30, 'total' => 1030,
            ])->save());
        }

        return $invoice;
    }
}
