<?php

namespace App\Data\Mobile;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class VendorLedgerSummaryData extends Data
{
    public function __construct(
        public int $total_items,
        public int $active_items,
        public int $sold_items,
        public float $total_cost_value,
        public float $total_selling_value,
        public float $total_purchases,
        public float $total_payments,
        public float $outstanding_balance,
    ) {}
}
