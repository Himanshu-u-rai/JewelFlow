<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class FullShopExport implements WithMultipleSheets
{
    public function __construct(private int $shopId)
    {
    }

    public function sheets(): array
    {
        return [
            new Sheets\InvoicesSheet($this->shopId),
            new Sheets\InvoiceItemsSheet($this->shopId),
            new Sheets\CustomersSheet($this->shopId),
            new Sheets\GoldLedgerSheet($this->shopId),
            new Sheets\CashLedgerSheet($this->shopId),
            new Sheets\RepairsSheet($this->shopId),
            new Sheets\CustomerGoldSheet($this->shopId),
        ];
    }
}
