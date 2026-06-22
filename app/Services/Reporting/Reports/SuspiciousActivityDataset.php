<?php

namespace App\Services\Reporting\Reports;

use App\Reporting\AuditService;
use App\Reporting\ReportPeriod;
use App\Services\Reporting\Dataset\ReportDataset;
use App\Services\Reporting\Dataset\ReportDatasetService;
use App\Services\Reporting\Dataset\ReportMeta;
use App\Services\Reporting\Dataset\ReportRequest;
use App\Services\Reporting\Dataset\ReportSection;
use App\Services\Reporting\Definition\ColumnDefinition;
use App\Services\Reporting\Definition\ColumnDefinition as Col;
use App\Services\Reporting\Definition\ColumnType as T;
use App\Services\Reporting\Definition\ExportFormat as F;
use App\Services\Reporting\Definition\FilterControl as Filter;
use App\Services\Reporting\Definition\FilterKey as FK;
use App\Services\Reporting\Definition\ReportClassification as Cls;
use App\Services\Reporting\Definition\ReportDefinition;
use App\Services\Reporting\Definition\ReportPermissions as Perm;
use App\Services\Reporting\Definition\ReportProfile as P;

/**
 * Suspicious Activity — the compliance alerts the system already detects (split
 * transaction / missing PAN / threshold breach) over a period, for owner review
 * (Audit class; GAP 2). Wraps AuditService::suspiciousActivity() VERBATIM —
 * reads compliance_alerts only, never re-derives. The customer name is the only
 * potentially-personal field; it comes straight from the alert row (already on
 * the legacy CSV), so no new exposure is introduced.
 *
 * Audit class → owner/manager surface gate (reports.audit), no CA Standard.
 */
class SuspiciousActivityDataset extends ReportDatasetService
{
    public const KEY = 'suspicious-activity';
    public const VERSION = 'suspicious-activity@1';

    public function __construct(private readonly AuditService $audit)
    {
    }

    public function definition(): ReportDefinition
    {
        return new ReportDefinition(
            key: self::KEY,
            version: self::VERSION,
            title: 'Suspicious Activity',
            classification: Cls::Audit,
            columns: [
                Col::mandatory('type', 'Type', T::String),
                Col::mandatory('customer', 'Customer', T::String),
                Col::optional('invoice', 'Invoice', T::String),
                Col::mandatory('date', 'Date', T::Date),
                Col::mandatory('resolved', 'Resolved', T::Boolean),
            ],
            profiles: [P::Summary, P::Detailed],
            filters: [Filter::for(FK::Period, true)],
            formats: [F::Pdf, F::Excel, F::Csv, F::Screen],
            permissions: Perm::default()->withSurfaceGate('reports.audit'),
        );
    }

    public function build(ReportRequest $request, ReportMeta $meta): ReportDataset
    {
        $def = $request->definition;
        $data = $this->audit->suspiciousActivity($request->shopId, $this->period($request));

        $cols = $this->cols($def, $this->keep(
            ['type', 'customer', 'invoice', 'date', 'resolved'],
            $request->columnKeys,
        ));

        $rows = [];
        foreach ($data->rows as $r) {
            $rows[] = [
                'type' => (string) $r->label,
                'customer' => (string) $r->customer_name,
                'invoice' => (string) ($r->invoice_number ?? ''),
                'date' => $r->created_at ? \Carbon\Carbon::parse($r->created_at)->toDateString() : '',
                'resolved' => (bool) $r->resolved,
            ];
        }

        $section = new ReportSection('alerts', 'Alerts', $cols, $rows, []);

        return new ReportDataset([$section], $meta);
    }

    public function estimateRowCount(ReportRequest $request): ?int
    {
        return $this->audit->suspiciousActivity($request->shopId, $this->period($request))->totalCount;
    }

    private function period(ReportRequest $request): ReportPeriod
    {
        $period = $request->filter('period', []);
        $from = $period['from'] ?? null;
        $to = $period['to'] ?? null;

        return ReportPeriod::range($from ? $from->toDateString() : null, $to ? $to->toDateString() : null);
    }

    /**
     * @param  string[]  $candidate
     * @param  string[]  $allowed
     * @return string[]
     */
    private function keep(array $candidate, array $allowed): array
    {
        return array_values(array_filter($candidate, static fn ($k) => in_array($k, $allowed, true)));
    }

    /**
     * @param  string[]  $keys
     * @return ColumnDefinition[]
     */
    private function cols(ReportDefinition $def, array $keys): array
    {
        $out = [];
        foreach ($keys as $key) {
            $column = $def->column($key);
            if ($column !== null) {
                $out[] = $column;
            }
        }

        return $out;
    }
}
