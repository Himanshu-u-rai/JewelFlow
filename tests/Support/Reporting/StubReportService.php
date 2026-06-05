<?php

namespace Tests\Support\Reporting;

use App\Services\Reporting\Dataset\ReportDataset;
use App\Services\Reporting\Dataset\ReportMeta;
use App\Services\Reporting\Dataset\ReportRequest;
use App\Services\Reporting\Dataset\ReportDatasetService;
use App\Services\Reporting\Dataset\ReportSection;
use App\Services\Reporting\Definition\ColumnDefinition as Col;
use App\Services\Reporting\Definition\ColumnType as T;
use App\Services\Reporting\Definition\ExportFormat as F;
use App\Services\Reporting\Definition\FilterControl as Filter;
use App\Services\Reporting\Definition\FilterKey as FK;
use App\Services\Reporting\Definition\ReportClassification as Cls;
use App\Services\Reporting\Definition\ReportDefinition as Def;
use App\Services\Reporting\Definition\ReportPermissions as Perm;
use App\Services\Reporting\Definition\ReportProfile as P;

/**
 * Minimal in-test report for exercising the pipeline (no real data source).
 */
class StubReportService extends ReportDatasetService
{
    public const KEY = 'stub-report';

    public function definition(): Def
    {
        return new Def(
            key: self::KEY,
            version: self::KEY . '@1',
            title: 'Stub Report',
            classification: Cls::Accounting,
            columns: [Col::mandatory('a', 'A', T::Money), Col::sensitive('cost', 'Cost', T::Money)],
            profiles: [P::Summary, P::Detailed],
            filters: [Filter::for(FK::Period, true)],
            formats: [F::Pdf, F::Excel, F::Csv],
            permissions: Perm::default(),
        );
    }

    public function build(ReportRequest $request, ReportMeta $meta): ReportDataset
    {
        return new ReportDataset(
            [new ReportSection('main', 'Main', $request->columns(), [['a' => 100.0, 'cost' => 40.0]], ['a' => 100.0])],
            $meta,
        );
    }

    public function estimateRowCount(ReportRequest $request): ?int
    {
        return 1;
    }
}
