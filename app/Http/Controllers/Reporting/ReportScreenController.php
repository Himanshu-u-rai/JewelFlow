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
use App\Services\Reporting\WatermarkPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * Generic interactive screen for ANY registered spine report (frozen §3.1) —
 * one path, no per-report controllers. Rigid compliance reports are forced to
 * the Fixed profile with no column toggles (frozen §9); flexible reports honour
 * the profile selector and the sensitive opt-in (gated by permission). The
 * screen consumes the same canonical dataset the exports do.
 */
class ReportScreenController extends Controller
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

    public function show(Request $request, string $report): View
    {
        abort_unless($this->registry->has($report), 404);
        $definition = $this->registry->definition($report);
        $user = $request->user();
        abort_unless($user !== null && $user->can($definition->permissions->effectiveViewGate()), 403);
        if ($definition->permissions->familyGate !== null) {
            abort_unless($user->can($definition->permissions->familyGate), 403);
        }

        $isRigid = $definition->classification->isRigid();
        $canSensitive = $user->can($definition->permissions->sensitive);

        $profile = $this->resolveProfile($definition, $request, $isRigid);
        $period = $this->resolvePeriod($request);

        $includeSensitive = ! $isRigid && $request->boolean('include_sensitive') && $canSensitive;
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

        $dataset = $this->registry->datasetService($report)->build($reportRequest, $meta);

        return view('reporting.reports.generic-screen', [
            'definition' => $definition,
            'view' => $this->screen->present($dataset, $reportRequest),
            'presets' => DatePreset::cases(),
            'profile' => $profile,
            'isRigid' => $isRigid,
            'canExportSensitive' => $canSensitive,
        ]);
    }

    /**
     * Resolve the screen period. Honours legacy ?month=&year= bookmarks from the
     * pre-spine report URLs (bookmark preservation), else the FY-first presets.
     */
    private function resolvePeriod(Request $request): \App\Services\Reporting\Filters\ResolvedPeriod
    {
        // Legacy single-date bookmark (?date=YYYY-MM-DD) — e.g. Daily Closing / Cash.
        if ($request->filled('date') && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->input('date'))) {
            $day = CarbonImmutable::parse($request->input('date'));

            return $this->filters->resolve(DatePreset::Custom, $day, $day);
        }

        if ($request->filled('month') && $request->filled('year')) {
            $from = CarbonImmutable::create((int) $request->input('year'), (int) $request->input('month'), 1)->startOfDay();

            return $this->filters->resolve(DatePreset::Custom, $from, $from->endOfMonth());
        }

        $preset = DatePreset::tryFrom((string) $request->input('date_preset')) ?? DatePreset::ThisMonth;

        return $this->filters->resolve(
            $preset,
            $request->filled('date_from') ? CarbonImmutable::parse($request->date('date_from')) : null,
            $request->filled('date_to') ? CarbonImmutable::parse($request->date('date_to')) : null,
            $request->input('fy_name'),
        );
    }

    private function resolveProfile($definition, Request $request, bool $isRigid): ReportProfile
    {
        if ($isRigid) {
            return ReportProfile::Fixed;
        }

        $requested = ReportProfile::tryFrom((string) $request->input('profile'));
        if ($requested !== null && $definition->supportsProfile($requested)) {
            return $requested;
        }

        return $definition->supportsProfile(ReportProfile::Detailed)
            ? ReportProfile::Detailed
            : $definition->profiles[0];
    }
}
