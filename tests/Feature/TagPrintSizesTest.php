<?php

namespace Tests\Feature;

use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Tag printing fits the tags a shop actually buys: folded jewellery tags and
 * flat labels in standard sizes or a custom size, with a QR and a barcode that
 * carry the same code so either one scans when the other is smudged.
 */
class TagPrintSizesTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
    }

    private function printTags(array $options)
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $item = TenantContext::runFor($shop->id, fn () => $this->createItem($shop->id, null, ['barcode' => 'JF-0042']));

        return TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)
            ->post(route('tags.print'), array_merge(['item_ids' => [$item->id]], $options)));
    }

    public function test_a_folded_jewellery_tag_prints_both_codes_on_its_ends(): void
    {
        $html = $this->printTags(['print_format' => 'folded', 'tag_size' => '95x15'])->assertOk()->getContent();

        $this->assertStringContainsString('width: 95mm', $html);
        $this->assertStringContainsString('height: 15mm', $html);
        // Same code in the QR and the barcode.
        $this->assertMatchesRegularExpression('/class="[^"]*js-qr[^"]*"[^>]*data-code="JF-0042"/', $html);
        $this->assertMatchesRegularExpression('/class="[^"]*js-barcode[^"]*"[^>]*data-barcode="JF-0042"/', $html);
    }

    public function test_a_custom_folded_size_uses_the_shops_own_measurements(): void
    {
        $html = $this->printTags([
            'print_format' => 'folded', 'tag_size' => 'custom',
            'custom_length' => 80, 'custom_height' => 10, 'custom_end' => 22,
        ])->assertOk()->getContent();

        $this->assertStringContainsString('width: 80mm', $html);
        $this->assertStringContainsString('height: 10mm', $html);
        $this->assertStringContainsString('width: 22mm', $html);
    }

    public function test_flat_labels_come_in_standard_sizes(): void
    {
        $html = $this->printTags(['print_format' => 'standard', 'tag_size' => '38x25'])->assertOk()->getContent();

        $this->assertStringContainsString('width: 38mm', $html);
        $this->assertStringContainsString('height: 25mm', $html);
    }

    public function test_the_shop_can_choose_qr_only(): void
    {
        $html = $this->printTags(['print_format' => 'folded', 'tag_size' => '95x12', 'codes' => 'qr'])->assertOk()->getContent();

        $this->assertStringContainsString('js-qr', $html);
        $this->assertStringNotContainsString('data-barcode=', $html);
    }

    public function test_a_custom_size_that_cannot_fit_is_refused(): void
    {
        // Two 50 mm ends do not fit on a 60 mm tag.
        $this->printTags([
            'print_format' => 'folded', 'tag_size' => 'custom',
            'custom_length' => 60, 'custom_height' => 10, 'custom_end' => 50,
        ])->assertSessionHasErrors('custom_end');
    }

    public function test_the_print_page_is_a_plain_address_so_refresh_and_new_tabs_work(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $item = TenantContext::runFor($shop->id, fn () => $this->createItem($shop->id, null, ['barcode' => 'JF-0042']));

        $html = TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)
            ->get(route('tags.print', ['item_ids' => [$item->id], 'print_format' => 'folded', 'tag_size' => '95x12'])))
            ->assertOk()->getContent();
        $this->assertStringContainsString('data-code="JF-0042"', $html);

        // Opened with no tags chosen (an old bookmark, a stray reload): back to the tag list, not an error page.
        TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)->get(route('tags.print')))
            ->assertRedirect(route('tags.index'));
    }

    public function test_the_old_size_names_still_print(): void
    {
        $html = $this->printTags(['label_size' => 'small'])->assertOk()->getContent();
        $this->assertStringContainsString('width: 50mm', $html);

        $html = $this->printTags(['print_format' => 'folded', 'folded_size' => '95x12'])->assertOk()->getContent();
        $this->assertStringContainsString('width: 95mm', $html);
    }
}
