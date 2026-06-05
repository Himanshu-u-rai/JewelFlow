<?php

namespace Tests\Unit\Reporting;

use App\Services\Reporting\Dataset\ReportRequest;
use App\Services\Reporting\Definition\ColumnDefinition as Col;
use App\Services\Reporting\Definition\ColumnType as T;
use App\Services\Reporting\Definition\ExportFormat as F;
use App\Services\Reporting\Definition\ReportClassification as Cls;
use App\Services\Reporting\Definition\ReportDefinition as Def;
use App\Services\Reporting\Definition\ReportPermissions as Perm;
use App\Services\Reporting\Definition\ReportProfile as P;
use App\Services\Reporting\Filters\DatePreset;
use App\Services\Reporting\Filters\FilterResolver;
use App\Services\Reporting\ProvenanceStamp;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class ProvenanceStampTest extends TestCase
{
    public function test_stamp_carries_all_provenance_fields(): void
    {
        $def = new Def('sales-register', 'sales-register@3', 'Sales / Invoice Register', Cls::Accounting, [Col::mandatory('a', 'A', T::Money)], [P::CaStandard, P::Detailed], [], [F::Pdf, F::Excel], Perm::default());
        $request = new ReportRequest($def, shopId: 1, userId: 7, userName: 'Asha Owner', profile: P::CaStandard, format: F::Pdf, filters: [], columnKeys: ['a']);
        $period = (new FilterResolver(4))->resolve(DatePreset::ThisFy, now: CarbonImmutable::parse('2026-06-04'));

        $meta = (new ProvenanceStamp())->stamp(
            $def, $request,
            shop: ['legal_name' => 'Goldlux Jewellers', 'address' => 'MG Road', 'gstin' => '29ABCDE1234F1Z5', 'state_code' => '29'],
            period: $period,
            filtersApplied: ['Operator' => 'All', 'Status' => 'Finalized'],
            watermark: null,
            generatorTag: 'jewelflow-reporting/test',
        );

        $this->assertSame('sales-register', $meta->reportKey);
        $this->assertSame('sales-register@3', $meta->reportVersion);
        $this->assertSame('CA Standard', $meta->profileLabel);
        $this->assertSame('pdf', $meta->format);
        $this->assertSame('FY 2026-27', $meta->periodLabel);
        $this->assertSame('Goldlux Jewellers', $meta->shopLegalName);
        $this->assertSame('29ABCDE1234F1Z5', $meta->shopGstin);
        $this->assertSame('Asha Owner', $meta->generatedByName);
        $this->assertSame('jewelflow-reporting/test', $meta->generatorTag);
        $this->assertSame(['Operator' => 'All', 'Status' => 'Finalized'], $meta->filtersApplied);
        $this->assertFalse($meta->hasWatermark());
        $this->assertNotNull($meta->generatedAt);
    }
}
