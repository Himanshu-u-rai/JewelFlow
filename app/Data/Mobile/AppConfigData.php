<?php

namespace App\Data\Mobile;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class AppConfigData extends Data
{
    public function __construct(
        public string $server_time,
        public string $min_mobile_version,
        public string $api_version,
        public string $environment,
        // "Fixed ₹ / % of metal / ₹ per gram" making on item and quick bill forms (features.making_charge_modes).
        public bool $making_charge_modes = false,
    ) {}
}
