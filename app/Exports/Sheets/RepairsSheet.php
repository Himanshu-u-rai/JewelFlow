<?php

namespace App\Exports\Sheets;

use App\Models\Repair;
use App\Support\TenantContext;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

class RepairsSheet implements FromCollection, WithHeadings
{
    public function __construct(private int $shopId)
    {
    }

    public function collection()
    {
        return TenantContext::runFor($this->shopId, fn () => Repair::query()
            ->get(['customer_id', 'item_description', 'gross_weight', 'purity', 'estimated_cost', 'final_cost', 'status', 'created_at']));
    }

    public function headings(): array
    {
        return ['Customer ID', 'Item', 'Weight', 'Purity', 'Est. Cost', 'Final Cost', 'Status', 'Date'];
    }
}
