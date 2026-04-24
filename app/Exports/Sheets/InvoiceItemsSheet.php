<?php

namespace App\Exports\Sheets;

use App\Models\InvoiceItem;
use App\Support\TenantContext;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

class InvoiceItemsSheet implements FromCollection, WithHeadings
{
    public function __construct(private int $shopId)
    {
    }

    public function collection()
    {
        return TenantContext::runFor($this->shopId, fn () => InvoiceItem::whereHas('invoice')
            ->get(['invoice_id', 'item_id', 'weight', 'rate', 'making_charges', 'stone_amount', 'line_total']));
    }

    public function headings(): array
    {
        return ['Invoice ID', 'Item ID', 'Weight', 'Rate', 'Making', 'Stone', 'Total'];
    }
}
