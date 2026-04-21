<?php

namespace App\Exports\Sheets;

use App\Models\MetalMovement;
use App\Support\TenantContext;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

class GoldLedgerSheet implements FromCollection, WithHeadings
{
    public function __construct(private int $shopId)
    {
    }

    public function collection()
    {
        return TenantContext::runFor($this->shopId, fn () => MetalMovement::query()
            ->get(['from_lot_id', 'to_lot_id', 'fine_weight', 'type', 'reference_type', 'reference_id', 'created_at']));
    }

    public function headings(): array
    {
        return ['From Lot', 'To Lot', 'Fine Weight', 'Type', 'Ref Type', 'Ref ID', 'Date'];
    }
}
