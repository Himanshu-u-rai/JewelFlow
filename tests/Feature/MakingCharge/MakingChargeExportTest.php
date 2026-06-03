<?php

namespace Tests\Feature\MakingCharge;

use App\Exports\Sheets\InvoiceItemsSheet;
use PHPUnit\Framework\TestCase;

/**
 * MC-7: the invoice-items export exposes making mode (additive). Guards the
 * heading/column alignment so the "Making Type" column can't silently drift
 * out of position relative to the selected columns.
 */
class MakingChargeExportTest extends TestCase
{
    public function test_invoice_items_export_has_aligned_making_type_column(): void
    {
        $headings = (new InvoiceItemsSheet(1))->headings();

        // 8 columns: Invoice ID, Item ID, Weight, Rate, Making, Making Type, Stone, Total
        $this->assertCount(8, $headings);
        $this->assertSame('Making', $headings[4]);
        $this->assertSame('Making Type', $headings[5], 'Making Type must sit right after Making');
        $this->assertSame('Stone', $headings[6]);
    }
}
