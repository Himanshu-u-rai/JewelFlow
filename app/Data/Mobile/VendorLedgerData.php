<?php

namespace App\Data\Mobile;

use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class VendorLedgerData extends Data
{
    public function __construct(
        public VendorData $vendor,
        public VendorLedgerSummaryData $summary,
        /** @var DataCollection<int, VendorLedgerEntryData> */
        public DataCollection $entries,
        public int $current_page,
        public int $per_page,
        public int $total,
        public int $last_page,
    ) {}
}
