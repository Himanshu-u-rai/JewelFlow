<?php

namespace App\Http\Controllers\Reporting;

use App\Http\Controllers\Controller;
use App\Http\Requests\Reporting\ExportRequest;
use App\Jobs\Reporting\GenerateQueuedExportJob;
use App\Services\Reporting\ColumnPolicy;
use App\Services\Reporting\Dataset\ReportRequest as DatasetRequest;
use App\Services\Reporting\Definition\ExportFormat;
use App\Services\Reporting\Definition\ReportProfile;
use App\Services\Reporting\Definition\ReportRegistry;
use App\Services\Reporting\ExportAuditService;
use App\Services\Reporting\ExportMode;
use App\Services\Reporting\ExportPipeline;
use App\Services\Reporting\ExportSizeRouter;
use App\Services\Reporting\Filters\DatePreset;
use App\Services\Reporting\Filters\FilterResolver;
use App\Services\Reporting\Filters\ResolvedPeriod;
use App\Services\Reporting\ProvenanceStamp;
use App\Services\Reporting\WatermarkPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\View;

/**
 * The export entry point (frozen §6). Pre-fills/edits scope, resolves the gated
 * column set, stamps provenance, then either renders synchronously or enqueues a
 * job for large exports (frozen §20). Every path writes exactly one
 * report_exports audit row (frozen §16).
 *
 * Generic over the report key (the registry resolves the definition + dataset
 * service); per-report routes are added as each report registers.
 */
class ExportController extends Controller
{
    public function __construct(
        private readonly ReportRegistry $registry,
        private readonly FilterResolver $filters,
        private readonly ColumnPolicy $columns,
        private readonly WatermarkPolicy $watermarks,
        private readonly ProvenanceStamp $provenance,
        private readonly ExportSizeRouter $sizeRouter,
        private readonly ExportPipeline $pipeline,
        private readonly ExportAuditService $audit,
    ) {
    }

    /** The pre-filled, scope-editable export panel for a report (frozen §6.1). */
    public function panel(string $report): Response
    {
        abort_unless($this->registry->has($report), 404);
        $definition = $this->registry->definition($report);

        $user = request()->user();
        abort_unless($user !== null && $user->can($definition->permissions->effectiveViewGate()), 403);

        $canExportSensitive = $user->can($definition->permissions->sensitive);
        $canManagePresets = $user->can($definition->permissions->export)
            && ($user->isOwner() || $user->isManager());

        // Shop-wide saved presets for this report (BelongsToShop scopes them).
        $savedPresets = \App\Models\Reporting\ReportingPreset::query()
            ->where('report_key', $report)
            ->orderBy('name')
            ->get();

        // Optional ?preset= pre-fills the form. A preset only seeds the form;
        // the export POST still re-validates and re-gates (ExportRequest), so a
        // preset can never widen what the running user may export.
        $applied = null;
        if (request()->filled('preset')) {
            $applied = $savedPresets->firstWhere('id', (int) request('preset'));
        }

        return new Response(View::make('reporting.export-panel', [
            'definition' => $definition,
            'presets' => DatePreset::cases(),
            'canExportSensitive' => $canExportSensitive,
            'canManagePresets' => $canManagePresets,
            'savedPresets' => $savedPresets,
            'appliedPreset' => $applied,
        ]));
    }

    public function export(ExportRequest $request, string $report): Response|RedirectResponse
    {
        $definition = $this->registry->definition($report);
        $user = $request->user();

        $profile = ReportProfile::from($request->string('profile')->value());
        $format = ExportFormat::from($request->string('format')->value());

        $period = $this->resolvePeriod($request);
        $filterValues = $this->collectFilters($definition, $request, $period);
        $filtersApplied = $this->humanizeFilters($definition, $request, $period);

        $resolution = $this->columns->resolve(
            definition: $definition,
            profile: $profile,
            user: $user,
            includeSensitive: $request->boolean('include_sensitive'),
            selectedOptional: $request->input('columns'),
            selectedSensitive: $request->input('sensitive'),
            requestRevealMasked: $request->boolean('reveal_masked'),
        );

        $datasetRequest = new DatasetRequest(
            definition: $definition,
            shopId: (int) $user->shop_id,
            userId: (int) $user->id,
            userName: (string) $user->name,
            profile: $profile,
            format: $format,
            filters: $filterValues,
            columnKeys: $resolution->columnKeys,
            includeSensitive: $resolution->sensitiveIncluded,
            revealMasked: $resolution->revealMasked,
        );

        $watermark = $this->watermarks->for($definition, $profile, $resolution->sensitiveIncluded);
        $meta = $this->provenance->stamp(
            $definition,
            $datasetRequest,
            $this->shopInfo($user),
            $period,
            $filtersApplied,
            $watermark,
        );

        $estimate = $this->registry->datasetService($report)->estimateRowCount($datasetRequest);
        $mode = $this->sizeRouter->mode($format, $estimate ?? 0);

        if ($mode === ExportMode::Queued) {
            return $this->dispatchQueued($datasetRequest, $resolution->sensitiveIncluded, $request, $period, $filtersApplied, $watermark);
        }

        $result = $this->pipeline->run($datasetRequest, $meta);
        $this->audit->recordSync($datasetRequest, $resolution->sensitiveIncluded, $result->rowCount);

        return new Response($result->output->contents, 200, [
            'Content-Type' => $result->output->mimeType,
            'Content-Disposition' => 'attachment; filename="' . $result->output->filename . '"',
        ]);
    }

    private function dispatchQueued(
        DatasetRequest $datasetRequest,
        bool $sensitiveIncluded,
        ExportRequest $request,
        ResolvedPeriod $period,
        array $filtersApplied,
        ?string $watermark,
    ): RedirectResponse {
        $export = $this->audit->recordQueued($datasetRequest, $sensitiveIncluded);

        GenerateQueuedExportJob::dispatch([
            'export_id' => $export->id,
            'report_key' => $datasetRequest->definition->key,
            'shop_id' => $datasetRequest->shopId,
            'user_id' => $datasetRequest->userId,
            'user_name' => $datasetRequest->userName,
            'profile' => $datasetRequest->profile->value,
            'format' => $datasetRequest->format->value,
            'date_preset' => $request->input('date_preset', DatePreset::ThisMonth->value),
            'date_from' => $period->from->toIso8601String(),
            'date_to' => $period->to->toIso8601String(),
            'fy_name' => $request->input('fy_name'),
            'filters' => $datasetRequest->filters,
            'column_keys' => $datasetRequest->columnKeys,
            'include_sensitive' => $datasetRequest->includeSensitive,
            'reveal_masked' => $datasetRequest->revealMasked,
            'filters_applied' => $filtersApplied,
            'watermark' => $watermark,
            'shop' => $this->shopInfo($request->user()),
        ]);

        return back()->with('status', "Your {$datasetRequest->definition->title} export is being prepared. You'll be notified when it's ready to download.");
    }

    private function resolvePeriod(ExportRequest $request): ResolvedPeriod
    {
        $preset = DatePreset::from($request->input('date_preset', DatePreset::ThisMonth->value));
        $from = $request->filled('date_from') ? CarbonImmutable::parse($request->date('date_from')) : null;
        $to = $request->filled('date_to') ? CarbonImmutable::parse($request->date('date_to')) : null;

        return $this->filters->resolve($preset, $from, $to, $request->input('fy_name'));
    }

    /** @return array<string, mixed> resolved filter values the dataset service reads. */
    private function collectFilters($definition, ExportRequest $request, ResolvedPeriod $period): array
    {
        $values = ['period' => ['from' => $period->from, 'to' => $period->to]];

        foreach (['operator', 'customer', 'status', 'metal_type', 'payment_mode', 'karigar', 'lot', 'movement_type', 'days_overdue', 'age_band'] as $key) {
            if ($request->filled($key)) {
                $values[$key] = $request->input($key);
            }
        }

        return $values;
    }

    /** @return array<string, string> human-readable echo stamped on the document (frozen §4.2/§6.1). */
    private function humanizeFilters($definition, ExportRequest $request, ResolvedPeriod $period): array
    {
        $applied = ['Period' => $period->label];

        $labels = [
            'operator' => 'Operator', 'customer' => 'Customer', 'status' => 'Status',
            'metal_type' => 'Metal', 'payment_mode' => 'Payment mode', 'karigar' => 'Karigar',
            'movement_type' => 'Movement type', 'days_overdue' => 'Days overdue', 'age_band' => 'Age band',
        ];
        foreach ($labels as $key => $label) {
            $applied[$label] = $request->filled($key) ? (string) $request->input($key) : 'All';
        }

        return $applied;
    }

    /** @return array{legal_name: string, address: ?string, gstin: ?string, state_code: ?string} */
    private function shopInfo($user): array
    {
        $shop = $user->shop;

        return [
            'legal_name' => (string) ($shop->legal_name ?? $shop->name ?? 'Shop'),
            'address' => $shop->address_line1 ?? null,
            'gstin' => $shop->gstin ?? null,
            'state_code' => $shop->state_code ?? null,
        ];
    }
}
