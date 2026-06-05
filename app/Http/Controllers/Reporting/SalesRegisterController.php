<?php

namespace App\Http\Controllers\Reporting;

use App\Http\Controllers\Controller;
use App\Services\Reporting\ColumnPolicy;
use App\Services\Reporting\Dataset\ReportRequest;
use App\Services\Reporting\Definition\ExportFormat;
use App\Services\Reporting\Definition\ReportProfile;
use App\Services\Reporting\Definition\ReportRegistry;
use App\Services\Reporting\Filters\DatePreset;
use App\Services\Reporting\Filters\FilterResolver;
use App\Services\Reporting\ProvenanceStamp;
use App\Services\Reporting\Render\ScreenRenderer;
use App\Services\Reporting\Reports\SalesRegisterDataset;
use App\Services\Reporting\WatermarkPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * Interactive screen for the Sales / Invoice Register (Phase 1 pilot). The
 * screen consumes the SAME canonical dataset the exports do (frozen §3.1), so
 * what you see equals what you export, to the paisa.
 */
class SalesRegisterController extends Controller
{
    public function __construct(
        private readonly ReportRegistry $registry,
        private readonly FilterResolver $filters,
        private readonly ColumnPolicy $columns,
        private readonly WatermarkPolicy $watermarks,
        private readonly ProvenanceStamp $provenance,
        private readonly ScreenRenderer $screen,
    ) {
    }

    public function index(Request $request): View
    {
        $definition = $this->registry->definition(SalesRegisterDataset::KEY);
        $user = $request->user();
        abort_unless($user !== null && $user->can($definition->permissions->effectiveViewGate()), 403);

        $canSensitive = $user->can($definition->permissions->sensitive);
        $profile = ReportProfile::tryFrom((string) $request->input('profile')) ?? ReportProfile::Detailed;
        $preset = DatePreset::tryFrom((string) $request->input('date_preset')) ?? DatePreset::ThisMonth;

        $period = $this->filters->resolve(
            $preset,
            $request->filled('date_from') ? CarbonImmutable::parse($request->date('date_from')) : null,
            $request->filled('date_to') ? CarbonImmutable::parse($request->date('date_to')) : null,
            $request->input('fy_name'),
        );

        $includeSensitive = $request->boolean('include_sensitive') && $canSensitive;
        $resolution = $this->columns->resolve($definition, $profile, $user, includeSensitive: $includeSensitive);

        $filterValues = ['period' => ['from' => $period->from, 'to' => $period->to]];
        $applied = ['Period' => $period->label];
        foreach (['operator', 'customer', 'status', 'metal_type', 'payment_mode'] as $key) {
            if ($request->filled($key)) {
                $filterValues[$key] = $request->input($key);
                $applied[ucfirst(str_replace('_', ' ', $key))] = (string) $request->input($key);
            }
        }

        $reportRequest = new ReportRequest(
            definition: $definition,
            shopId: (int) $user->shop_id,
            userId: (int) $user->id,
            userName: (string) $user->name,
            profile: $profile,
            format: ExportFormat::Screen,
            filters: $filterValues,
            columnKeys: $resolution->columnKeys,
            includeSensitive: $resolution->sensitiveIncluded,
        );

        $shop = $user->shop;
        $meta = $this->provenance->stamp(
            $definition,
            $reportRequest,
            [
                'legal_name' => (string) ($shop->legal_name ?? $shop->name ?? 'Shop'),
                'address' => $shop->address_line1 ?? null,
                'gstin' => $shop->gstin ?? null,
                'state_code' => $shop->state_code ?? null,
            ],
            $period,
            $applied,
            $this->watermarks->for($definition, $profile, $resolution->sensitiveIncluded),
        );

        $dataset = $this->registry->datasetService(SalesRegisterDataset::KEY)->build($reportRequest, $meta);

        return view('reporting.reports.sales-register.screen', [
            'definition' => $definition,
            'view' => $this->screen->present($dataset, $reportRequest),
            'presets' => DatePreset::cases(),
            'profile' => $profile,
            'canExportSensitive' => $canSensitive,
        ]);
    }
}
