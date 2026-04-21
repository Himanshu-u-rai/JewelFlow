<?php

namespace App\Data\Mobile;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class ShopSummaryData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
        public string $shop_type,
        public ?string $gst_number,
        public float $gst_rate,
        public string $currency,
        public ?string $logo_url,
    ) {}
}
