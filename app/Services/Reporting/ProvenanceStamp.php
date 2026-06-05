<?php

namespace App\Services\Reporting;

use App\Services\Reporting\Dataset\ReportMeta;
use App\Services\Reporting\Dataset\ReportRequest;
use App\Services\Reporting\Definition\ReportDefinition;
use App\Services\Reporting\Definition\ReportProfile;
use App\Services\Reporting\Filters\ResolvedPeriod;
use Illuminate\Support\Carbon;

/**
 * Assembles the provenance block + document furniture into a ReportMeta
 * (frozen §15, §4.2). Identical across formats so two exports are diffable
 * field-by-field: Report Version + Generated At/By + Export Profile + Filters
 * Applied, plus the generator (renderer build) tag.
 *
 * @phpstan-type ShopInfo array{legal_name: string, address?: ?string, gstin?: ?string, state_code?: ?string}
 */
class ProvenanceStamp
{
    /**
     * @param ShopInfo              $shop
     * @param array<string, string> $filtersApplied human-readable echo (frozen §4.2/§6.1)
     */
    public function stamp(
        ReportDefinition $definition,
        ReportRequest $request,
        array $shop,
        ResolvedPeriod $period,
        array $filtersApplied,
        ?string $watermark = null,
        ?string $generatorTag = null,
    ): ReportMeta {
        return new ReportMeta(
            reportKey: $definition->key,
            reportVersion: $definition->version,
            title: $definition->title,
            profileLabel: $this->profileLabel($request->profile),
            format: $request->format->value,
            filtersApplied: $filtersApplied,
            periodLabel: $period->label,
            shopLegalName: $shop['legal_name'],
            shopAddress: $shop['address'] ?? null,
            shopGstin: $shop['gstin'] ?? null,
            shopStateCode: $shop['state_code'] ?? null,
            generatedByName: $request->userName,
            generatedAt: Carbon::now(),
            generatorTag: $generatorTag ?? (string) config('reporting.generator_tag', 'jewelflow-reporting'),
            watermark: $watermark,
        );
    }

    private function profileLabel(ReportProfile $profile): string
    {
        return match ($profile) {
            ReportProfile::Summary => 'Summary',
            ReportProfile::Detailed => 'Detailed',
            ReportProfile::Ca => 'CA',
            ReportProfile::CaStandard => 'CA Standard',
            ReportProfile::Raw => 'Raw',
            ReportProfile::Fixed => 'Standard',
        };
    }
}
