<?php

namespace App\Exports\Sheets;

use App\Models\Invoice;
use App\Support\TenantContext;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

class InvoicesSheet implements FromCollection, WithHeadings
{
    public function __construct(private int $shopId)
    {
    }

    public function collection()
    {
        return TenantContext::runFor($this->shopId, fn () => Invoice::query()->get([
            'invoice_number',
            'customer_id',
            'gold_rate',
            'subtotal',
            'gst',
            'total',
            'status',
            'created_at'
        ]));
    }

    public function headings(): array
    {
        return [
            'Invoice No',
            'Customer ID',
            'Gold Rate',
            'Subtotal',
            'GST',
            'Total',
            'Status',
            'Date'
        ];
    }
}
