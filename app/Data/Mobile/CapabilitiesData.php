<?php

namespace App\Data\Mobile;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class CapabilitiesData extends Data
{
    public function __construct(
        public bool $items,
        public bool $stock,
        public bool $customers,
        public bool $suppliers,
        public bool $purchases,
        public bool $pos,
        public bool $quick_bill,
        public bool $invoice,
        public bool $repairs,
        public bool $expenses,
        public bool $catalog,
        public bool $dashboard,
        public bool $scanner,
        public bool $schemes,
        public bool $loyalty,
        public bool $installments,
        public bool $cashbook,
    ) {}
}
