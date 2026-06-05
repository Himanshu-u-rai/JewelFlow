<?php

namespace Tests\Unit\Reporting\Render;

use App\Services\Reporting\Dataset\ReportDataset;
use App\Services\Reporting\Dataset\ReportMeta;
use App\Services\Reporting\Dataset\ReportRequest;
use App\Services\Reporting\Dataset\ReportSection;
use App\Services\Reporting\Definition\ColumnDefinition as Col;
use App\Services\Reporting\Definition\ColumnType as T;
use App\Services\Reporting\Definition\ExportFormat as F;
use App\Services\Reporting\Definition\ReportClassification as Cls;
use App\Services\Reporting\Definition\ReportDefinition as Def;
use App\Services\Reporting\Definition\ReportPermissions as Perm;
use App\Services\Reporting\Definition\ReportProfile as P;
use Carbon\Carbon;

/**
 * In-memory ReportDataset fixtures for renderer tests. NO database — renderers
 * consume the dataset only (frozen §3.1, §13), so the tests must too.
 */
trait RendererFixtures
{
    private function fixtureMeta(string $reportKey = 'sales-register', ?string $watermark = null): ReportMeta
    {
        return new ReportMeta(
            reportKey: $reportKey,
            reportVersion: $reportKey . '@1',
            title: 'Sales Register',
            profileLabel: 'CA Format',
            format: 'pdf',
            filtersApplied: ['Operator' => 'All', 'Status' => 'Finalized'],
            periodLabel: '1 Apr 2025 – 31 Mar 2026',
            shopLegalName: 'Shyam Jewellers Pvt Ltd',
            shopAddress: '12 Market Road, Jaipur, Rajasthan',
            shopGstin: '08ABCDE1234F1Z5',
            shopStateCode: '08',
            generatedByName: 'Ramesh Kumar',
            generatedAt: Carbon::create(2026, 6, 5, 14, 30, 0, 'Asia/Kolkata'),
            generatorTag: 'JewelFlow/reporting',
            watermark: $watermark,
        );
    }

    /** @return Col[] */
    private function fixtureColumns(): array
    {
        return [
            new Col('invoice_no', 'Invoice No', T::String),
            new Col('date', 'Date', T::Date),
            new Col('weight', 'Weight (g)', T::Weight),
            new Col('rate', 'Rate', T::Money),
            new Col('amount', 'Amount', T::Money),
            new Col('gst_pct', 'GST %', T::Percent),
            new Col('finalized', 'Finalized', T::Boolean),
        ];
    }

    private function singleSectionDataset(?string $watermark = null): ReportDataset
    {
        $columns = $this->fixtureColumns();

        $rows = [
            [
                'invoice_no' => 'INV-001',
                'date'       => Carbon::create(2025, 4, 12),
                'weight'     => 12.345,
                'rate'       => 6500.00,
                'amount'     => 1234567.50,
                'gst_pct'    => 3.0,
                'finalized'  => true,
            ],
            [
                'invoice_no' => 'INV-002',
                'date'       => Carbon::create(2025, 5, 3),
                'weight'     => 8.5,
                'rate'       => 6600.0,
                'amount'     => 56100.0,
                'gst_pct'    => 3.0,
                'finalized'  => false,
            ],
        ];

        $section = new ReportSection(
            key: 'sales',
            title: 'Sales',
            columns: $columns,
            rows: $rows,
            totals: ['amount' => 1290667.50, 'weight' => 20.845],
        );

        return new ReportDataset([$section], $this->fixtureMeta(watermark: $watermark));
    }

    private function multiSectionDataset(): ReportDataset
    {
        $b2bCols = [
            new Col('gstin', 'GSTIN', T::String),
            new Col('taxable', 'Taxable', T::Money),
            new Col('igst', 'IGST', T::Money),
        ];
        $b2b = new ReportSection(
            key: 'b2b',
            title: 'B2B',
            columns: $b2bCols,
            rows: [
                ['gstin' => '08AAA1234A1Z5', 'taxable' => 100000.0, 'igst' => 3000.0],
                ['gstin' => '27BBB5678B1Z2', 'taxable' => 50000.0, 'igst' => 1500.0],
            ],
            totals: ['taxable' => 150000.0, 'igst' => 4500.0],
        );

        $b2csCols = [
            new Col('place', 'Place', T::String),
            new Col('taxable', 'Taxable', T::Money),
            new Col('igst', 'IGST', T::Money),
        ];
        $b2cs = new ReportSection(
            key: 'b2cs',
            title: 'B2CS',
            columns: $b2csCols,
            rows: [
                ['place' => 'Rajasthan', 'taxable' => 20000.0, 'igst' => 600.0],
            ],
            totals: ['taxable' => 20000.0, 'igst' => 600.0],
        );

        $meta = new ReportMeta(
            reportKey: 'gstr1',
            reportVersion: 'gstr1@1',
            title: 'GSTR-1',
            profileLabel: 'Fixed',
            format: 'csv',
            filtersApplied: ['Period' => 'Apr 2025'],
            periodLabel: 'Apr 2025',
            shopLegalName: 'Shyam Jewellers Pvt Ltd',
            shopAddress: '12 Market Road, Jaipur',
            shopGstin: '08ABCDE1234F1Z5',
            shopStateCode: '08',
            generatedByName: 'Ramesh Kumar',
            generatedAt: Carbon::create(2026, 6, 5, 14, 30, 0, 'Asia/Kolkata'),
            generatorTag: 'JewelFlow/reporting',
            watermark: null,
        );

        return new ReportDataset([$b2b, $b2cs], $meta);
    }

    private function fixtureRequest(ReportDataset $dataset, F $format): ReportRequest
    {
        $section = $dataset->sections[0];
        $def = new Def(
            key: $dataset->meta->reportKey,
            version: $dataset->meta->reportVersion,
            title: $dataset->meta->title,
            classification: Cls::Operational,
            columns: $section->columns,
            profiles: [P::Detailed],
            filters: [],
            formats: [F::Pdf, F::Excel, F::Csv],
            permissions: Perm::default(),
        );

        return new ReportRequest(
            definition: $def,
            shopId: 1,
            userId: 7,
            userName: 'Ramesh Kumar',
            profile: P::Detailed,
            format: $format,
            filters: [],
            columnKeys: $section->columnKeys(),
        );
    }
}
