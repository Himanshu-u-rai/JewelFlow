<?php

namespace App\Services\Reporting\Dataset;

use Carbon\CarbonInterface;

/**
 * The document furniture + provenance carried by every dataset (frozen §4.2, §15).
 * Identical across formats so two exports are diffable field-by-field (§15).
 * `filtersApplied` is the human-readable echo stamped on the document (§4.2/§6.1);
 * `periodLabel` is the resolved, stamped date range (§17). `watermark` is the
 * policy-derived label (§19) or null.
 */
final class ReportMeta
{
    /**
     * @param array<string, string> $filtersApplied human-readable: "Operator" => "All"
     */
    public function __construct(
        public readonly string $reportKey,
        public readonly string $reportVersion,
        public readonly string $title,
        public readonly string $profileLabel,
        public readonly string $format,
        public readonly array $filtersApplied,
        public readonly ?string $periodLabel,
        public readonly string $shopLegalName,
        public readonly ?string $shopAddress,
        public readonly ?string $shopGstin,
        public readonly ?string $shopStateCode,
        public readonly string $generatedByName,
        public readonly CarbonInterface $generatedAt,
        public readonly string $generatorTag,
        public readonly ?string $watermark = null,
    ) {
    }

    public function hasWatermark(): bool
    {
        return $this->watermark !== null && $this->watermark !== '';
    }
}
