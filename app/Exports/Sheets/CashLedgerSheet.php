<?php

namespace App\Exports\Sheets;

use App\Models\CashTransaction;
use App\Support\TenantContext;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

class CashLedgerSheet implements FromCollection, WithHeadings
{
    public function __construct(private int $shopId)
    {
    }

    public function collection()
    {
        return TenantContext::runFor($this->shopId, fn () => CashTransaction::query()
            ->get(['type', 'amount', 'reference_type', 'reference_id', 'created_at']));
    }

    public function headings(): array
    {
        return ['Type', 'Amount', 'Ref Type', 'Ref ID', 'Date'];
    }
}
