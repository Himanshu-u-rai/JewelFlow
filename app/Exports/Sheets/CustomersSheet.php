<?php

namespace App\Exports\Sheets;

use App\Models\Customer;
use App\Support\TenantContext;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

class CustomersSheet implements FromCollection, WithHeadings
{
    public function __construct(private int $shopId)
    {
    }

    public function collection()
    {
        return TenantContext::runFor($this->shopId, fn () => Customer::query()
            ->get(['name', 'phone', 'address', 'created_at']));
    }

    public function headings(): array
    {
        return ['Name', 'Phone', 'Address', 'Created'];
    }
}
