<?php

namespace App\Http\Controllers;

use App\Services\Reporting\ColumnPolicy;
use App\Services\Reporting\Dataset\ReportMeta;
use App\Services\Reporting\Dataset\ReportRequest;
use App\Services\Reporting\Definition\ExportFormat;
use App\Services\Reporting\Definition\ReportProfile;
use App\Services\Reporting\Definition\ReportRegistry;
use App\Services\Reporting\Render\Excel\BackupWorkbook;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Maatwebsite\Excel\Excel as ExcelWriter;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Data Exports hub. The page (index) lists the data-export reports and links to
 * each report's standard export panel (/reports/{key}/export), where the owner
 * picks format / filters / columns. Individual dataset exports are produced by
 * the canonical reporting pipeline — this controller only owns the landing page
 * and the multi-sheet "Export everything" Excel backup.
 *
 * The legacy per-dataset CSV methods (customers/products/invoices/gold-ledger/
 * cash-transactions) were retired: those datasets now live in the reporting
 * framework (CSV/Excel/PDF, filters, sensitive-column gating, async-for-large).
 */
class ExportController extends Controller
{
    /**
     * Report keys included in the "Export everything" backup, in sheet order,
     * each with the sheet name. These are the bulk data-dump datasets registered
     * in ReportingServiceProvider.
     *
     * @var array<string, string>
     */
    private const BACKUP_REPORTS = [
        'customers' => 'Customers',
        'products' => 'Products',
        'inventory-items' => 'Inventory Items',
        'stock-purchases' => 'Stock Purchases',
        'karigars' => 'Karigars',
        'karigar-invoices' => 'Karigar Invoices',
        'job-orders' => 'Job Orders',
        'returns' => 'Returns',
        'credit-notes' => 'Credit Notes',
        'repairs' => 'Repairs',
        'installment-plans' => 'EMI Plans',
        'scheme-enrollments' => 'Scheme Enrolments',
        'store-credit' => 'Store Credit',
        'loyalty' => 'Loyalty Points',
        'old-gold' => 'Old Gold',
    ];

    public function index()
    {
        return view('export.index');
    }

    /**
     * "Export everything" — one Excel workbook with a sheet per data set. Each
     * sheet is built from the registered dataset (no filters → full data),
     * respecting the sensitive-column gate (PII included only when the user holds
     * reports.export_sensitive). Reuses the framework's SectionSheet renderer.
     */
    public function exportAllWorkbook(
        ReportRegistry $registry,
        ColumnPolicy $columns,
        ExcelWriter $excel,
    ): StreamedResponse|RedirectResponse {
        $user = Auth::user();
        $shopId = (int) $user->shop_id;

        // The backup builds every data set synchronously and holds the whole
        // workbook in memory, so it bypasses the per-report sync/queue router.
        // Guard against a very large shop OOM/timeout: if the total estimated
        // rows exceed the ceiling, refuse and point the owner at the individual
        // exports (those auto-queue large data sets). Default ceiling is well
        // above a normal shop's lifetime data; tune via REPORTING_BACKUP_MAX_ROWS.
        $ceiling = (int) config('reporting.backup_max_rows', 50000);
        $estimatedTotal = 0;
        foreach (self::BACKUP_REPORTS as $key => $sheetTitle) {
            if ($registry->has($key)) {
                $estimatedTotal += (int) ($registry->datasetService($key)->estimateRowCount(
                    $this->backupRequest($registry->definition($key), $columns, $user, $shopId)
                ) ?? 0);
            }
        }
        if ($estimatedTotal > $ceiling) {
            return redirect()->route('export.index')->with('error',
                'Your shop has too much data for a single combined backup ('
                . number_format($estimatedTotal) . ' rows). Please export each data set individually — '
                . 'large data sets download in the background.');
        }

        $sheets = [];
        foreach (self::BACKUP_REPORTS as $key => $sheetTitle) {
            if (! $registry->has($key)) {
                continue;
            }

            $definition = $registry->definition($key);
            $request = $this->backupRequest($definition, $columns, $user, $shopId);

            $meta = $this->backupMeta($request, $user);
            $dataset = $registry->datasetService($key)->build($request, $meta);

            $section = $dataset->sections[0] ?? null;
            if ($section !== null) {
                $sheets[] = ['title' => $sheetTitle, 'section' => $section];
            }
        }

        $filename = 'shop-data-backup-' . now()->format('Ymd-His') . '.xlsx';
        $contents = $excel->raw(new BackupWorkbook($sheets), ExcelWriter::XLSX);

        return response()->streamDownload(function () use ($contents) {
            echo $contents;
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /**
     * Build the canonical (full-data, no-filter) ReportRequest for one dataset in
     * the backup. Sensitive columns are included only if the user is permitted.
     */
    private function backupRequest(
        \App\Services\Reporting\Definition\ReportDefinition $definition,
        ColumnPolicy $columns,
        $user,
        int $shopId,
    ): ReportRequest {
        $resolution = $columns->resolve(
            definition: $definition,
            profile: ReportProfile::Detailed,
            user: $user,
            includeSensitive: $user->can($definition->permissions->sensitive),
        );

        return new ReportRequest(
            definition: $definition,
            shopId: $shopId,
            userId: (int) $user->id,
            userName: (string) $user->name,
            profile: ReportProfile::Detailed,
            format: ExportFormat::Excel,
            filters: [],
            columnKeys: $resolution->columnKeys,
            includeSensitive: $resolution->sensitiveIncluded,
            revealMasked: $resolution->revealMasked,
        );
    }

    private function backupMeta(ReportRequest $request, $user): ReportMeta
    {
        $shop = $user->shop;

        return new ReportMeta(
            reportKey: $request->definition->key,
            reportVersion: $request->definition->version,
            title: $request->definition->title,
            profileLabel: 'Detailed',
            format: $request->format->value,
            filtersApplied: [],
            periodLabel: 'All data',
            shopLegalName: $shop?->name ?? 'Shop',
            shopAddress: $shop?->address ?? null,
            shopGstin: $shop?->gstin ?? null,
            shopStateCode: null,
            generatedByName: (string) $user->name,
            generatedAt: now(),
            generatorTag: 'data-export-backup',
        );
    }
}
