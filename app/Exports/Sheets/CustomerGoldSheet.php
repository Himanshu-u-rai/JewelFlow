<?php

namespace App\Exports\Sheets;

use App\Models\CustomerGoldTransaction;
use App\Support\TenantContext;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

class CustomerGoldSheet implements FromCollection, WithHeadings
{
    public function __construct(private int $shopId)
    {
    }

    public function collection()
    {
        return TenantContext::runFor($this->shopId, fn () => CustomerGoldTransaction::query()
            ->get(['customer_id', 'fine_gold', 'type', 'reference_type', 'reference_id', 'created_at']));
    }

    public function headings(): array
    {
        return ['Customer ID', 'Fine Gold', 'Type', 'Ref Type', 'Ref ID', 'Date'];
    }
}
