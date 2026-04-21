<?php

namespace App\Data\Mobile;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class VendorLedgerEntryData extends Data
{
    public function __construct(
        public string $id,
        public string $date,
        public string $type,
        public string $description,
        public float $amount,
        public ?int $reference_id,
        public ?string $reference_type,
        public ?string $meta,
    ) {}
}
